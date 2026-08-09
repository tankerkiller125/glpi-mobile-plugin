<?php
/**
 * GLPI Mobile companion plugin.
 *
 * Two jobs for the glpi-mobile app, both kept server-side:
 * 1. Auth broker — a QR shown in the user's settings pairs the device; the
 *    plugin holds the OAuth client secret and hands the app genuine tokens, so
 *    no password/secret lives in the app.
 * 2. Push notifications — ticket events (assign / approval / new reply) are
 *    enqueued in-process and delivered from cron over UnifiedPush (self-hosted,
 *    Firebase-free, via Web Push encryption) and, when configured, APNs / FCM.
 */

use GlpiPlugin\Glpimobile\AssetController;
use GlpiPlugin\Glpimobile\Menu;
use GlpiPlugin\Glpimobile\FormController;
use GlpiPlugin\Glpimobile\ItilController;
use GlpiPlugin\Glpimobile\PairController;
use GlpiPlugin\Glpimobile\PlanningController;
use GlpiPlugin\Glpimobile\QrTab;

define('PLUGIN_GLPIMOBILE_VERSION', '0.1.0');
define('PLUGIN_GLPIMOBILE_MIN_GLPI', '11.0');

// OAuth client config lives here (context) with the redirect scheme the app registers.
define('PLUGIN_GLPIMOBILE_CONFIG_CONTEXT', 'plugin:glpimobile');
define('PLUGIN_GLPIMOBILE_REDIRECT_URI', 'glpimobile://paired');
// Pairing codes are single-use and expire quickly.
define('PLUGIN_GLPIMOBILE_PAIR_TTL', 120);

function plugin_init_glpimobile()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpimobile'] = true;

    // HL API controller: pairing/refresh broker + device registration + config.
    $PLUGIN_HOOKS['api_controllers']['glpimobile'] = [
        PairController::class,
        FormController::class,
        ItilController::class,
        PlanningController::class,
        AssetController::class,
    ];

    // Notification secrets stored GLPIKey-encrypted (decrypted on read). Only
    // true secrets are secured; APNs key/team/bundle ids are plain identifiers.
    $PLUGIN_HOOKS['secured_configs']['glpimobile'] = [
        'vapid_private_key',
        'fcm_service_account',
        'apns_p8',
    ];

    // A persistent Setup-menu entry + the plugin-list gear both reach the
    // notification config page (so it's reachable after install).
    $PLUGIN_HOOKS['menu_toadd']['glpimobile'] = ['config' => Menu::class];
    $PLUGIN_HOOKS['config_page']['glpimobile'] = 'front/config.php';

    // Ticket events → enqueue a push (drained + sent by cron). The callback gets
    // the just-added item object with its fields populated.
    $PLUGIN_HOOKS['item_add']['glpimobile'] = [
        'Ticket_User'      => 'plugin_glpimobile_ticket_user_added',   // assigned to me
        'Group_Ticket'     => 'plugin_glpimobile_group_ticket_added',  // assigned to my group
        'TicketValidation' => 'plugin_glpimobile_validation_added',    // approval requested (user/group)
        'ITILFollowup'     => 'plugin_glpimobile_followup_added',      // new reply
        'TicketTask'       => 'plugin_glpimobile_task_added',          // new / assigned task
    ];

    // "Mobile app" tab on the personal Settings page (self-only: the QR always
    // pairs the *viewing* user, and only they can open their own settings).
    Plugin::registerClass(QrTab::class, ['addtabon' => ['Preference']]);
}

function plugin_version_glpimobile()
{
    return [
        'name'         => 'GLPI Mobile',
        'version'      => PLUGIN_GLPIMOBILE_VERSION,
        'author'       => 'Norsewave',
        'license'      => 'MIT',
        'homepage'     => 'https://github.com/tankerkiller125/glpimobile',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPIMOBILE_MIN_GLPI]],
    ];
}

function plugin_glpimobile_check_prerequisites()
{
    return true;
}

function plugin_glpimobile_check_config($verbose = false)
{
    return true;
}
