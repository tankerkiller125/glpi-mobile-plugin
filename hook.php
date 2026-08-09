<?php

use GlpiPlugin\Glpimobile\OAuthBroker;
use GlpiPlugin\Glpimobile\Push;
use GlpiPlugin\Glpimobile\WebPush;

/**
 * Install: create the pairing + notification tables, auto-provision the app's
 * OAuth client, generate the VAPID keypair, and register the push cron.
 *
 * The OAuth client is confidential (authorization_code + refresh_token grants).
 * Its secret is generated + stored GLPIKey-encrypted by OAuthClient; the broker
 * decrypts it at runtime. We only stash the generated identifier in config.
 */
function plugin_glpimobile_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    if (!$DB->tableExists('glpi_plugin_glpimobile_pairings')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimobile_pairings` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(128) NOT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `access_token` TEXT NULL,
                `refresh_token` TEXT NULL,
                `expires_at` INT NOT NULL DEFAULT 0,
                `used` TINYINT NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `code` (`code`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Long-lived app sessions: one row per paired device.
    //
    // The app used to hold the GLPI refresh token itself, but GLPI revokes a
    // refresh token the instant it is exchanged — so a response lost in
    // transit (dropped signal, app killed mid-refresh) left the device holding
    // a dead token with no way back except re-scanning the QR. Now the server
    // keeps the rotating token and the app holds a `device_secret` that never
    // changes, which makes a lost response harmless: it just retries.
    if (!$DB->tableExists('glpi_plugin_glpimobile_sessions')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimobile_sessions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `device_id` VARCHAR(64) NOT NULL,
                `secret_hash` VARCHAR(255) NOT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `refresh_token` TEXT NULL,
                `platform` VARCHAR(20) NULL,
                `app_version` VARCHAR(40) NULL,
                `last_seen` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `device_id` (`device_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Registered push devices: one row per (user, device). `transport` is
    // unifiedpush|apns|fcm; `endpoint` is the UnifiedPush URL or the push token.
    if (!$DB->tableExists('glpi_plugin_glpimobile_devices')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimobile_devices` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `transport` VARCHAR(20) NOT NULL,
                `endpoint` TEXT NOT NULL,
                `p256dh` VARCHAR(255) NULL,
                `auth` VARCHAR(255) NULL,
                `platform` VARCHAR(20) NULL,
                `app_version` VARCHAR(40) NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `endpoint` (`endpoint`(191)),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Outbox for notifications: enqueued in the ticket-event hook, drained + sent by cron.
    if (!$DB->tableExists('glpi_plugin_glpimobile_notifqueue')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimobile_notifqueue` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `users_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `body` TEXT NULL,
                `ticket_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `data_json` TEXT NULL,
                `state` TINYINT NOT NULL DEFAULT 0,
                `attempts` INT NOT NULL DEFAULT 0,
                `next_attempt_at` INT NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `state` (`state`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Idempotency ledger for offline form submissions: the app sends the outbox
    // op uuid as a marker, so a retried submit returns the original result
    // instead of filing a second ticket.
    if (!$DB->tableExists('glpi_plugin_glpimobile_formsubmits')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimobile_formsubmits` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `marker` VARCHAR(64) NOT NULL,
                `users_id` INT UNSIGNED NOT NULL,
                `forms_id` INT UNSIGNED NOT NULL,
                `answers_set_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_json` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `marker` (`marker`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    plugin_glpimobile_ensure_oauth_client();
    plugin_glpimobile_ensure_vapid_keys();

    CronTask::register(
        Push::class,
        'Send',
        60,
        ['state' => CronTask::STATE_WAITING, 'mode' => CronTask::MODE_EXTERNAL]
    );

    return true;
}

function plugin_glpimobile_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (['pairings', 'sessions', 'devices', 'notifqueue', 'formsubmits'] as $suffix) {
        $table = "glpi_plugin_glpimobile_$suffix";
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $DB->delete('glpi_crontasks', ['itemtype' => Push::class]);

    // Deactivate + remove the OAuth client we created, then forget it.
    $client_id = OAuthBroker::configuredClientId();
    if ($client_id !== null) {
        $client = new OAuthClient();
        if ($client->getFromDBByCrit(['identifier' => $client_id])) {
            $client->delete(['id' => $client->getID()], true);
        }
    }
    Config::deleteConfigurationValues(
        PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
        ['client_id', 'vapid_public_key', 'vapid_private_key']
    );

    return true;
}

/**
 * Generate the server VAPID keypair once (self-generated, no account). Public
 * key is shared with the app; the private key is stored GLPIKey-encrypted (it
 * is registered as a secured config in setup.php).
 */
function plugin_glpimobile_ensure_vapid_keys(): void
{
    $existing = Config::getConfigurationValues(
        PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
        ['vapid_public_key']
    );
    if (!empty($existing['vapid_public_key'])) {
        return;
    }
    $keys = WebPush::generateVapidKeys();
    Config::setConfigurationValues(PLUGIN_GLPIMOBILE_CONFIG_CONTEXT, [
        'vapid_public_key'  => $keys['publicKey'],
        'vapid_private_key' => $keys['privateKey'], // secured → auto-encrypted
    ]);
}

/**
 * Create the mobile OAuth client once and remember its identifier. Idempotent:
 * if a client id is already stored and still exists, do nothing.
 */
function plugin_glpimobile_ensure_oauth_client(): void
{
    $existing = OAuthBroker::configuredClientId();
    if ($existing !== null) {
        $probe = new OAuthClient();
        if ($probe->getFromDBByCrit(['identifier' => $existing])) {
            return;
        }
    }

    $client = new OAuthClient();
    $id = $client->add([
        'name'          => 'GLPI Mobile',
        'entities_id'   => 0,
        'is_recursive'  => 1,
        'is_active'     => 1,
        'is_confidential' => 1,
        'comment'       => 'Auto-created by the glpimobile plugin for QR pairing login.',
        'grants'        => ['authorization_code', 'refresh_token'],
        'scopes'        => ['api', 'user', 'email'],
        'redirect_uri'  => [PLUGIN_GLPIMOBILE_REDIRECT_URI],
    ]);

    if ($id && $client->getFromDB($id)) {
        Config::setConfigurationValues(
            PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
            ['client_id' => $client->fields['identifier']]
        );
    }
}

// --- Ticket-event hooks (item_add). Each receives the just-added item object;
//     the logic lives in Push so it stays testable. ---

function plugin_glpimobile_ticket_user_added(Ticket_User $item): void
{
    Push::onTicketUserAdded($item);
}

function plugin_glpimobile_group_ticket_added(Group_Ticket $item): void
{
    Push::onGroupTicketAdded($item);
}

function plugin_glpimobile_validation_added(TicketValidation $item): void
{
    Push::onValidationAdded($item);
}

function plugin_glpimobile_followup_added(ITILFollowup $item): void
{
    Push::onFollowupAdded($item);
}

function plugin_glpimobile_task_added(TicketTask $item): void
{
    Push::onTaskAdded($item);
}
