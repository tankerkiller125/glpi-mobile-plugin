<?php

namespace GlpiPlugin\Glpimobile;

use Config;
use GLPIKey;
use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Session;
use Throwable;

/**
 * The mobile app's HL API endpoints under /GlpiMobile: the pairing/refresh
 * broker (SECURITY_NONE — the code/refresh token is itself the credential) and
 * device registration + push config (authenticated via the app's OAuth bearer).
 */
#[Route(path: '/GlpiMobile', tags: ['GlpiMobile'])]
final class PairController extends AbstractController
{
    protected static function getRawKnownSchemas(): array
    {
        return [];
    }

    /**
     * Redeem a one-time pairing code (from the QR) for GLPI OAuth tokens.
     * The code is single-use and short-lived; tokens are stored encrypted.
     */
    #[Route(path: '/pair', methods: ['POST'], security_level: Route::SECURITY_NONE)]
    #[RouteVersion(introduced: '2.0')]
    public function pair(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $code = trim((string) $request->getParameter('code'));
        if ($code === '') {
            return new JSONResponse(['error' => 'missing_code'], 400);
        }

        $table = 'glpi_plugin_glpimobile_pairings';
        $row = null;
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['code' => $code]]) as $r) {
            $row = $r;
            break;
        }
        if ($row === null) {
            return new JSONResponse(['error' => 'invalid_code'], 404);
        }
        if ((int) $row['used'] === 1) {
            return new JSONResponse(['error' => 'code_used'], 410);
        }
        if ((int) $row['expires_at'] < time()) {
            return new JSONResponse(['error' => 'code_expired'], 410);
        }

        // Single-use: burn it before handing the tokens back.
        $DB->update($table, ['used' => 1], ['id' => $row['id']]);

        $key = new GLPIKey();
        $access = $key->decrypt((string) $row['access_token']);
        $refresh = $key->decrypt((string) $row['refresh_token']);
        if ($access === '' || $access === null) {
            return new JSONResponse(['error' => 'token_unavailable'], 500);
        }

        // The rotating GLPI refresh token stays here; the device gets a stable
        // secret instead, so a lost refresh response can never strand it.
        $session = DeviceSession::create(
            (int) $row['users_id'],
            ['access_token' => $access, 'refresh_token' => (string) $refresh],
            self::platform($request)
        );

        return new JSONResponse([
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => 3600,
            'device_id'     => $session['device_id'],
            'device_secret' => $session['device_secret'],
        ], 200);
    }

    /**
     * Exchange a refresh token for a new access/refresh pair. The app has no
     * client secret, so it refreshes through the broker (which adds it).
     */
    #[Route(path: '/refresh', methods: ['POST'], security_level: Route::SECURITY_NONE)]
    #[RouteVersion(introduced: '2.0')]
    public function refresh(Request $request): Response
    {
        $deviceId = trim((string) $request->getParameter('device_id'));
        $secret   = trim((string) $request->getParameter('device_secret'));

        if ($deviceId !== '' || $secret !== '') {
            return self::refreshSession($deviceId, $secret);
        }
        return self::refreshLegacy($request);
    }

    /** The current path: a device secret that never rotates. */
    private static function refreshSession(string $deviceId, string $secret): Response
    {
        $row = DeviceSession::authenticate($deviceId, $secret);
        if ($row === null) {
            // Unknown or revoked device — this one really must re-pair.
            return new JSONResponse(['error' => 'unknown_device'], 401);
        }

        try {
            $tokens = DeviceSession::refresh($row);
        } catch (SessionRejected $e) {
            DeviceSession::revoke((int) $row['id']);
            return new JSONResponse(['error' => 'session_rejected'], 401);
        } catch (Throwable $e) {
            // Transport or server failure. 503 (not 401) so the app retries
            // instead of wiping a perfectly good session.
            return new JSONResponse(['error' => 'refresh_unavailable'], 503);
        }

        return new JSONResponse([
            'access_token' => $tokens['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => $tokens['expires_in'],
        ], 200);
    }

    /**
     * Legacy path for apps paired before device sessions existed: they still
     * hold a GLPI refresh token. Exchange it, then hand back device
     * credentials so the app upgrades itself — nobody has to re-scan.
     */
    private static function refreshLegacy(Request $request): Response
    {
        $refresh_token = trim((string) $request->getParameter('refresh_token'));
        if ($refresh_token === '') {
            return new JSONResponse(['error' => 'missing_credentials'], 400);
        }

        try {
            $tokens = OAuthBroker::refresh($refresh_token);
        } catch (SessionRejected $e) {
            return new JSONResponse(['error' => 'session_rejected'], 401);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => 'refresh_unavailable'], 503);
        }

        $usersId = self::userIdForToken($tokens['access_token']);
        $body = [
            'access_token' => $tokens['access_token'],
            'token_type'   => 'Bearer',
            'expires_in'   => $tokens['expires_in'],
        ];
        if ($usersId > 0) {
            $session = DeviceSession::create($usersId, $tokens, self::platform($request));
            $body['device_id']     = $session['device_id'];
            $body['device_secret'] = $session['device_secret'];
        } else {
            // Couldn't identify the user, so no session to migrate into —
            // keep the app on the legacy path rather than stranding it.
            $body['refresh_token'] = $tokens['refresh_token'];
        }
        return new JSONResponse($body, 200);
    }

    /** The `sub` claim of a GLPI access token (its user id), or 0. */
    private static function userIdForToken(string $accessToken): int
    {
        $parts = explode('.', $accessToken);
        if (count($parts) !== 3) {
            return 0;
        }
        $payload = json_decode(
            (string) base64_decode(strtr($parts[1], '-_', '+/'), false),
            true
        );
        return is_array($payload) ? (int) ($payload['sub'] ?? 0) : 0;
    }

    private static function platform(Request $request): ?string
    {
        $platform = trim((string) $request->getParameter('platform'));
        return $platform === '' ? null : substr($platform, 0, 20);
    }

    /**
     * Push config the app needs before registering: the server VAPID public key
     * and which transports are configured. Public (no secrets leak here).
     */
    #[Route(path: '/config', methods: ['GET'], security_level: Route::SECURITY_NONE)]
    #[RouteVersion(introduced: '2.0')]
    public function pushConfig(Request $request): Response
    {
        $cfg = Config::getConfigurationValues(
            PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
            [
                'vapid_public_key', 'fcm_service_account', 'apns_p8',
                'fcm_project_id', 'fcm_app_id', 'fcm_api_key', 'fcm_sender_id',
            ]
        );
        // FCM is usable by the app only when both the sender (service account)
        // and the client config are present. The client config is public — the
        // app fetches it to initialise Firebase at runtime (no google-services.json).
        $fcm_ready = !empty($cfg['fcm_service_account'])
            && !empty($cfg['fcm_app_id'])
            && !empty($cfg['fcm_api_key'])
            && !empty($cfg['fcm_sender_id'])
            && !empty($cfg['fcm_project_id']);

        return new JSONResponse([
            'vapid_public_key' => $cfg['vapid_public_key'] ?? '',
            'transports'       => [
                'unifiedpush' => true,
                'fcm'         => $fcm_ready,
                'apns'        => !empty($cfg['apns_p8']),
            ],
            'fcm' => $fcm_ready ? [
                'project_id' => $cfg['fcm_project_id'],
                'app_id'     => $cfg['fcm_app_id'],
                'api_key'    => $cfg['fcm_api_key'],
                'sender_id'  => $cfg['fcm_sender_id'],
            ] : null,
        ], 200);
    }

    /**
     * Register (or refresh) a push device for the authenticated user. Upserts on
     * the endpoint so re-registration replaces the stored keys.
     */
    #[Route(path: '/devices', methods: ['POST'])]
    #[RouteVersion(introduced: '2.0')]
    public function registerDevice(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $transport = (string) $request->getParameter('transport');
        $endpoint = (string) $request->getParameter('endpoint');
        if (!in_array($transport, ['unifiedpush', 'apns', 'fcm'], true) || $endpoint === '') {
            return new JSONResponse(['error' => 'invalid_device'], 400);
        }

        $data = [
            'users_id'    => $uid,
            'transport'   => $transport,
            'endpoint'    => $endpoint,
            'p256dh'      => (string) $request->getParameter('p256dh'),
            'auth'        => (string) $request->getParameter('auth'),
            'platform'    => (string) $request->getParameter('platform'),
            'app_version' => (string) $request->getParameter('app_version'),
            'date_mod'    => date('Y-m-d H:i:s'),
        ];

        $existing = null;
        foreach (
            $DB->request([
                'FROM'  => 'glpi_plugin_glpimobile_devices',
                'WHERE' => ['endpoint' => $endpoint],
                'LIMIT' => 1,
            ]) as $row
        ) {
            $existing = $row;
        }
        if ($existing !== null) {
            $DB->update('glpi_plugin_glpimobile_devices', $data, ['id' => $existing['id']]);
        } else {
            $data['date_creation'] = date('Y-m-d H:i:s');
            $DB->insert('glpi_plugin_glpimobile_devices', $data);
        }

        return new JSONResponse(['ok' => true], 200);
    }

    /**
     * Attach a file to a ticket as a GLPI Document, the way the web UI does
     * (store into the documents area + create the Document_Item link) — the HL
     * API's generic Document create can't accept the file bytes over OAuth.
     *
     * Multipart: a `file` part plus optional `name` and `marker`. `marker` (the
     * outbox op uuid) makes this idempotent: a retried upload returns the doc
     * already linked for that marker instead of creating a duplicate.
     */
    #[Route(path: '/items/{itemtype}/{id}/documents', methods: ['POST'], requirements: [
        'itemtype' => '[A-Za-z][A-Za-z0-9_\\\\]*',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function uploadTicketDocument(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        $itemtype = (string) $request->getAttribute('itemtype');
        $class = self::attachableClass($itemtype);
        if ($class === null) {
            return new JSONResponse(['error' => 'invalid_itemtype'], 400);
        }
        $ticketId = (int) $request->getAttribute('id');
        $ticket = new $class();
        if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
            return new JSONResponse(['error' => 'item_not_found'], 404);
        }

        $marker = trim((string) $request->getParameter('marker'));

        // Idempotency: if this marker already produced a linked document, return
        // it (a retried/duplicated upload must not create a second attachment).
        if ($marker !== '') {
            foreach (
                $DB->request([
                    'SELECT'     => ['d.id AS id', 'd.filename AS filename', 'd.mime AS mime'],
                    'FROM'       => 'glpi_documents AS d',
                    'INNER JOIN' => [
                        'glpi_documents_items AS di' => [
                            'ON' => ['di' => 'documents_id', 'd' => 'id'],
                        ],
                    ],
                    'WHERE' => [
                        'di.itemtype' => $itemtype,
                        'di.items_id' => $ticketId,
                        'd.comment'   => self::docMarker($marker),
                    ],
                    'LIMIT' => 1,
                ]) as $row
            ) {
                return new JSONResponse(
                    self::docPayload((int) $row['id'], $row['filename'], $row['mime']),
                    200
                );
            }
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return new JSONResponse(['error' => 'no_file'], 400);
        }

        $orig  = (string) ($file['name'] ?? 'upload');
        $name  = trim((string) $request->getParameter('name'));
        $name  = $name !== '' ? $name : $orig;
        // Filenames must not carry path separators; keep a safe, GLPI-checkable name.
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($orig)) ?: 'upload';

        $prefix = uniqid('gm', true) . '_';
        $dest   = GLPI_TMP_DIR . '/' . $prefix . $clean;
        // COPY (not move): the request runs under Symfony HttpFoundation, which
        // wraps this as an UploadedFile and references PHP's temp file again when
        // finalizing the request. move_uploaded_file() would remove it, causing a
        // FileNotFoundException at request teardown → 500 *after* we already
        // returned. Copy it and let PHP clean up the original.
        if (!copy((string) $file['tmp_name'], $dest)) {
            return new JSONResponse(['error' => 'store_failed'], 500);
        }

        // Add the document WITHOUT linking it to the ticket. Document::add with
        // itemtype/items_id touches the ticket and queues a notification whose
        // deferred render scans the ticket's plain-text content for @mentions;
        // simplexml_import_dom then fails ("Invalid Nodetype") and crashes the
        // request at shutdown (500) even though everything committed — the add
        // itself is clean (verified via CLI). We create the link ourselves with
        // a plain row insert, which the timeline reads directly: no ticket
        // touch, no notification, no crash.
        $doc   = new \Document();
        $docId = $doc->add([
            'name'                    => $name,
            'entities_id'             => $ticket->fields['entities_id'],
            'is_recursive'            => 1,
            'comment'                 => $marker !== '' ? self::docMarker($marker) : '',
            '_filename'               => [$prefix . $clean],
            '_prefix_filename'        => [$prefix],
            '_only_if_upload_succeed' => 1,
        ]);
        @unlink($dest); // GLPI copied it into the documents area.

        if (!$docId) {
            // Most often an unsupported extension (Document::isValidDoc rejected it).
            return new JSONResponse(['error' => 'add_failed'], 422);
        }

        $now = date('Y-m-d H:i:s');
        $DB->insert('glpi_documents_items', [
            'documents_id'      => (int) $docId,
            'itemtype'          => $itemtype,
            'items_id'          => $ticketId,
            'entities_id'       => (int) $ticket->fields['entities_id'],
            'is_recursive'      => (int) ($ticket->fields['is_recursive'] ?? 1),
            'users_id'          => $uid,
            'timeline_position' => 1,
            'date'              => $now,
            'date_creation'     => $now,
            'date_mod'          => $now,
        ]);

        $doc->getFromDB($docId);
        return new JSONResponse(
            self::docPayload((int) $docId, $doc->fields['filename'], $doc->fields['mime']),
            201
        );
    }

    /** List a ticket's attached documents (id, name, filename, mime). */
    #[Route(path: '/items/{itemtype}/{id}/documents', methods: ['GET'], requirements: [
        'itemtype' => '[A-Za-z][A-Za-z0-9_\\\\]*',
        'id'       => '\d+',
    ])]
    #[RouteVersion(introduced: '2.0')]
    public function listTicketDocuments(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $itemtype = (string) $request->getAttribute('itemtype');
        $class = self::attachableClass($itemtype);
        if ($class === null) {
            return new JSONResponse(['error' => 'invalid_itemtype'], 400);
        }
        $ticketId = (int) $request->getAttribute('id');
        $ticket = new $class();
        if (!$ticket->getFromDB($ticketId) || !$ticket->canViewItem()) {
            return new JSONResponse(['error' => 'item_not_found'], 404);
        }

        $items = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'd.id AS id', 'd.name AS name', 'd.filename AS filename',
                    'd.mime AS mime', 'di.date AS date',
                ],
                'FROM'       => 'glpi_documents AS d',
                'INNER JOIN' => [
                    'glpi_documents_items AS di' => [
                        'ON' => ['di' => 'documents_id', 'd' => 'id'],
                    ],
                ],
                'WHERE'   => ['di.itemtype' => $itemtype, 'di.items_id' => $ticketId],
                'ORDER'   => 'di.id ASC',
            ]) as $row
        ) {
            $items[] = self::docPayload((int) $row['id'], $row['filename'], $row['mime'])
                + ['name' => (string) $row['name'], 'date' => $row['date']];
        }
        return new JSONResponse($items, 200);
    }

    /** ITIL itemtypes documents can be attached to. */
    private const ITIL_CLASSES = [
        'Ticket'  => \Ticket::class,
        'Change'  => \Change::class,
        'Problem' => \Problem::class,
    ];

    /**
     * Management itemtypes that accept attachments alongside the ITIL and
     * asset types. (Assets come from `$CFG_GLPI['asset_types']`, which also
     * covers custom asset definitions.)
     */
    private const MANAGEMENT_TYPES = [
        'Contract', 'Supplier', 'Contact', 'SoftwareLicense', 'Certificate',
        'Budget', 'Line', 'Domain', 'Appliance', 'Datacenter', 'Cluster',
        'DatabaseInstance', 'Database', 'Project', 'Software',
    ];

    /**
     * The GLPI class documents may be attached to, or null when the itemtype
     * isn't one the app is allowed to touch.
     */
    private static function attachableClass(string $itemtype): ?string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (isset(self::ITIL_CLASSES[$itemtype])) {
            return self::ITIL_CLASSES[$itemtype];
        }
        $allowed = array_merge(
            $CFG_GLPI['asset_types'] ?? [],
            self::MANAGEMENT_TYPES
        );
        if (
            in_array($itemtype, $allowed, true)
            && class_exists($itemtype)
            && is_subclass_of($itemtype, \CommonDBTM::class)
        ) {
            return $itemtype;
        }
        return null;
    }

    private static function docMarker(string $uuid): string
    {
        return 'gm-op:' . $uuid;
    }

    private static function docPayload(int $id, ?string $filename, ?string $mime): array
    {
        return [
            'id'           => $id,
            'filename'     => (string) $filename,
            'mime'         => (string) $mime,
            'download_url' => '/Management/Document/' . $id . '/Download',
        ];
    }

    /** Unregister a device (on logout). Scoped to the authenticated user. */
    #[Route(path: '/devices', methods: ['DELETE'], security_level: Route::SECURITY_NONE)]
    #[RouteVersion(introduced: '2.0')]
    public function unregisterDevice(Request $request): Response
    {
        /** @var \DBmysql $DB */
        global $DB;

        $endpoint = (string) $request->getParameter('endpoint');
        if ($endpoint !== '') {
            // The endpoint is an unguessable per-device secret; deleting by it is safe.
            $DB->delete('glpi_plugin_glpimobile_devices', ['endpoint' => $endpoint]);
        }
        return new JSONResponse(['ok' => true], 200);
    }
}
