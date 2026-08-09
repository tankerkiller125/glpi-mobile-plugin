<?php

namespace GlpiPlugin\Glpimobile;

use CommonDBTM;
use CommonITILActor;
use Config;
use CronTask;
use GLPIKey;
use Group_Ticket;
use ITILFollowup;
use Session;
use Ticket;
use TicketTask;
use TicketValidation;
use Ticket_User;
use User;

/**
 * Notification engine: ticket-event hooks enqueue a row per target user
 * (fast, in-request), and the cron drains the queue and delivers over the
 * device's transport. Phase A ships UnifiedPush (Web Push, self-hosted ntfy);
 * APNs/FCM plug into dispatch() in Phase B.
 */
class Push extends CommonDBTM
{
    public static function getTypeName($nb = 0)
    {
        return __('Mobile push', 'glpimobile');
    }

    // --- Ticket-event handlers (called from the item_add hooks) ---

    public static function onTicketUserAdded(Ticket_User $item): void
    {
        if ((int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }
        $tid = (int) ($item->fields['tickets_id'] ?? 0);
        self::enqueue(
            (int) ($item->fields['users_id'] ?? 0),
            __('Assigned to you', 'glpimobile'),
            self::ticketLine($tid),
            $tid
        );
    }

    public static function onGroupTicketAdded(Group_Ticket $item): void
    {
        if ((int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
            return;
        }
        $tid = (int) ($item->fields['tickets_id'] ?? 0);
        self::enqueueMany(
            self::groupMembers((int) ($item->fields['groups_id'] ?? 0)),
            __('Assigned to your group', 'glpimobile'),
            self::ticketLine($tid),
            $tid
        );
    }

    public static function onValidationAdded(TicketValidation $item): void
    {
        $tid = (int) ($item->fields['tickets_id'] ?? 0);
        $target = (int) ($item->fields['items_id_target'] ?? 0);
        $title = __('Approval requested', 'glpimobile');
        $line = self::ticketLine($tid);
        switch ($item->fields['itemtype_target'] ?? '') {
            case 'User':
                self::enqueue($target, $title, $line, $tid);
                break;
            case 'Group':
                self::enqueueMany(self::groupMembers($target), $title, $line, $tid);
                break;
        }
    }

    public static function onFollowupAdded(ITILFollowup $item): void
    {
        if (($item->fields['itemtype'] ?? '') !== 'Ticket') {
            return;
        }
        $tid = (int) ($item->fields['items_id'] ?? 0);
        self::enqueueMany(
            self::assignedUsers($tid),
            __('New reply', 'glpimobile'),
            self::ticketLine($tid),
            $tid
        );
    }

    public static function onTaskAdded(TicketTask $item): void
    {
        $tid = (int) ($item->fields['tickets_id'] ?? 0);
        if ($tid <= 0) {
            return;
        }
        $line = self::ticketLine($tid);
        $tech = (int) ($item->fields['users_id_tech'] ?? 0);
        $techGroup = (int) ($item->fields['groups_id_tech'] ?? 0);

        if ($tech > 0) {
            self::enqueue($tech, __('Task assigned to you', 'glpimobile'), $line, $tid);
        }
        if ($techGroup > 0) {
            self::enqueueMany(self::groupMembers($techGroup), __('New task', 'glpimobile'), $line, $tid);
        }
        // No task-specific tech/group: fall back to the ticket's assignees.
        if ($tech <= 0 && $techGroup <= 0) {
            self::enqueueMany(self::assignedUsers($tid), __('New task', 'glpimobile'), $line, $tid);
        }
    }

    /** Queue a notification for a user, skipping the actor + undeliverable users. */
    public static function enqueue(int $users_id, string $title, string $body, int $ticket_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($users_id <= 0 || $ticket_id <= 0) {
            return;
        }
        if ($users_id === (int) Session::getLoginUserID()) {
            return; // don't notify whoever caused the event
        }
        if (!self::userDeliverable($users_id)) {
            return; // inactive/deleted, or no registered device
        }

        $DB->insert('glpi_plugin_glpimobile_notifqueue', [
            'users_id'        => $users_id,
            'title'           => $title,
            'body'            => $body,
            'ticket_id'       => $ticket_id,
            'data_json'       => json_encode(['ticket_id' => $ticket_id]),
            'state'           => 0,
            'attempts'        => 0,
            'next_attempt_at' => 0,
            'date_creation'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** Enqueue for a de-duplicated set of users (one notification each). */
    public static function enqueueMany(array $users_ids, string $title, string $body, int $ticket_id): void
    {
        foreach (array_unique(array_map('intval', $users_ids)) as $uid) {
            self::enqueue($uid, $title, $body, $ticket_id);
        }
    }

    // --- Cron sender ---

    public static function cronInfo($name)
    {
        return ['description' => __('Deliver queued mobile push notifications', 'glpimobile')];
    }

    public static function cronSend(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $vapid = self::vapidKeys();
        if ($vapid === null) {
            return 0;
        }
        $subject = self::vapidSubject();
        $sent = 0;

        $rows = $DB->request([
            'FROM'  => 'glpi_plugin_glpimobile_notifqueue',
            'WHERE' => ['state' => 0, 'next_attempt_at' => ['<=', time()]],
            'ORDER' => 'id ASC',
            'LIMIT' => 50,
        ]);

        foreach ($rows as $row) {
            $payload = json_encode([
                'ticket_id' => (int) $row['ticket_id'],
                'title'     => $row['title'],
                'body'      => $row['body'],
            ]);

            $anyDevice = false;
            $delivered = false;
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_plugin_glpimobile_devices',
                    'WHERE' => ['users_id' => $row['users_id']],
                ]) as $device
            ) {
                $anyDevice = true;
                try {
                    $status = self::dispatch($device, $payload, $vapid, $subject);
                } catch (\Throwable $e) {
                    $status = 0; // bad key/endpoint on one device must not abort the batch
                }
                if ($status >= 200 && $status < 300) {
                    $delivered = true;
                } elseif ($status === 404 || $status === 410) {
                    // Subscription gone — prune the dead device.
                    $DB->delete('glpi_plugin_glpimobile_devices', ['id' => $device['id']]);
                }
            }

            if ($delivered || !$anyDevice) {
                $DB->update('glpi_plugin_glpimobile_notifqueue', ['state' => 1], ['id' => $row['id']]);
                if ($delivered) {
                    $sent++;
                }
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $DB->update('glpi_plugin_glpimobile_notifqueue', [
                    'attempts'        => $attempts,
                    'state'           => $attempts >= 5 ? 2 : 0,
                    'next_attempt_at' => time() + min(1800, 30 * $attempts),
                ], ['id' => $row['id']]);
            }
        }

        if ($sent > 0) {
            $task->addVolume($sent);
        }
        return $sent > 0 ? 1 : 0;
    }

    /** Deliver one payload to one device by its transport. Returns HTTP status. */
    private static function dispatch(array $device, string $payload, array $vapid, string $subject): int
    {
        switch ($device['transport']) {
            case 'unifiedpush':
                // Dev-only reachability override (empty in production).
                $connectTo = Config::getConfigurationValue(
                    PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
                    'dev_connect_to'
                );
                return WebPush::send(
                    (string) $device['endpoint'],
                    (string) $device['p256dh'],
                    (string) $device['auth'],
                    $payload,
                    $vapid,
                    $subject,
                    $connectTo !== '' ? $connectTo : null
                );
            case 'fcm':
                return Fcm::isConfigured()
                    ? Fcm::send((string) $device['endpoint'], json_decode($payload, true) ?: [])
                    : 0;
            case 'apns':
                return Apns::isConfigured()
                    ? Apns::send((string) $device['endpoint'], json_decode($payload, true) ?: [])
                    : 0;
            default:
                return 0;
        }
    }

    /**
     * Send a test notification to the current user's devices (config page).
     * Returns a per-device [transport, status] list.
     */
    public static function sendTest(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $vapid = self::vapidKeys() ?? [];
        $subject = self::vapidSubject();
        $payload = json_encode([
            'ticket_id' => 0,
            'title'     => __('GLPI Mobile test', 'glpimobile'),
            'body'      => __('Push notifications are working.', 'glpimobile'),
        ]);

        $results = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_plugin_glpimobile_devices',
                'WHERE' => ['users_id' => $users_id],
            ]) as $device
        ) {
            if ($device['transport'] === 'unifiedpush' && empty($vapid)) {
                $results[] = ['transport' => $device['transport'], 'status' => 0];
                continue;
            }
            try {
                $status = self::dispatch($device, $payload, $vapid, $subject);
            } catch (\Throwable $e) {
                $status = 0;
            }
            $results[] = ['transport' => $device['transport'], 'status' => $status];
        }
        return $results;
    }

    // --- Helpers ---

    private static function userDeliverable(int $users_id): bool
    {
        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return false;
        }
        if ((int) $user->fields['is_active'] !== 1 || (int) $user->fields['is_deleted'] === 1) {
            return false;
        }
        return countElementsInTable('glpi_plugin_glpimobile_devices', ['users_id' => $users_id]) > 0;
    }

    private static function ticketLine(int $ticket_id): string
    {
        $ticket = new Ticket();
        $name = $ticket->getFromDB($ticket_id) ? (string) $ticket->fields['name'] : '';
        return "#$ticket_id" . ($name !== '' ? ' ' . $name : '');
    }

    private static function ticketAssignees(int $ticket_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'users_id',
                'FROM'   => 'glpi_tickets_users',
                'WHERE'  => ['tickets_id' => $ticket_id, 'type' => CommonITILActor::ASSIGN],
            ]) as $row
        ) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    /** Members of a group. */
    private static function groupMembers(int $groups_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($groups_id <= 0) {
            return [];
        }
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'users_id',
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => $groups_id],
            ]) as $row
        ) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    /** Everyone assigned to a ticket: individual assignees + members of assigned groups. */
    private static function assignedUsers(int $ticket_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $ids = self::ticketAssignees($ticket_id);
        foreach (
            $DB->request([
                'SELECT' => 'groups_id',
                'FROM'   => 'glpi_groups_tickets',
                'WHERE'  => ['tickets_id' => $ticket_id, 'type' => CommonITILActor::ASSIGN],
            ]) as $row
        ) {
            $ids = array_merge($ids, self::groupMembers((int) $row['groups_id']));
        }
        return array_values(array_unique($ids));
    }

    /** VAPID keypair from config (public plain, private GLPIKey-decrypted). */
    private static function vapidKeys(): ?array
    {
        $cfg = Config::getConfigurationValues(
            PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
            ['vapid_public_key', 'vapid_private_key']
        );
        if (empty($cfg['vapid_public_key']) || empty($cfg['vapid_private_key'])) {
            return null;
        }
        $private = (new GLPIKey())->decrypt($cfg['vapid_private_key']);
        if ($private === '' || $private === null) {
            return null;
        }
        return ['publicKey' => $cfg['vapid_public_key'], 'privateKey' => $private];
    }

    private static function vapidSubject(): string
    {
        global $CFG_GLPI;
        $email = $CFG_GLPI['admin_email'] ?? '';
        return 'mailto:' . ($email !== '' ? $email : 'noreply@glpi.local');
    }
}
