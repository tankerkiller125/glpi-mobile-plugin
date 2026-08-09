<?php

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimobile\DeviceSession;
use GlpiPlugin\Glpimobile\Menu;
use GlpiPlugin\Glpimobile\Push;

Session::checkRight('config', READ);

$context = PLUGIN_GLPIMOBILE_CONFIG_CONTEXT;

// --- Save ---
if (!empty($_POST['update'])) {
    Session::checkRight('config', UPDATE);
    $values = [];
    // Secrets: only overwrite when a new value is pasted (blank keeps existing).
    if (trim((string) ($_POST['fcm_service_account'] ?? '')) !== '') {
        $values['fcm_service_account'] = trim((string) $_POST['fcm_service_account']);
    }
    if (trim((string) ($_POST['apns_p8'] ?? '')) !== '') {
        $values['apns_p8'] = trim((string) $_POST['apns_p8']);
    }
    // Identifiers (not secret) — always stored.
    $values['apns_key_id']    = trim((string) ($_POST['apns_key_id'] ?? ''));
    $values['apns_team_id']   = trim((string) ($_POST['apns_team_id'] ?? ''));
    $values['apns_bundle_id'] = trim((string) ($_POST['apns_bundle_id'] ?? ''));
    $values['apns_production'] = !empty($_POST['apns_production']) ? '1' : '0';
    // FCM client config (public — the app fetches it to init Firebase at
    // runtime, so there's no baked-in google-services.json / single project).
    $values['fcm_project_id'] = trim((string) ($_POST['fcm_project_id'] ?? ''));
    $values['fcm_app_id']     = trim((string) ($_POST['fcm_app_id'] ?? ''));
    $values['fcm_api_key']    = trim((string) ($_POST['fcm_api_key'] ?? ''));
    $values['fcm_sender_id']  = trim((string) ($_POST['fcm_sender_id'] ?? ''));
    Config::setConfigurationValues($context, $values);
    Session::addMessageAfterRedirect(__s('Settings saved.', 'glpimobile'));
    Html::back();
}

// --- Revoke a paired device ---
if (!empty($_POST['revoke_session'])) {
    // No explicit Session::checkCSRF here: GLPI 11's CheckCsrfListener already
    // validated *and consumed* the token before this page ran, so a second
    // check always fails (403). The hidden field in the form is what matters.
    Session::checkRight('config', UPDATE);
    DeviceSession::revoke((int) $_POST['revoke_session']);
    Session::addMessageAfterRedirect(
        __s('Device signed out. It will lose access within the hour.', 'glpimobile')
    );
    Html::back();
}

// --- Send a test to my devices ---
if (!empty($_POST['test'])) {
    Session::checkRight('config', UPDATE);
    $results = Push::sendTest((int) Session::getLoginUserID());
    if (empty($results)) {
        Session::addMessageAfterRedirect(
            __s('No device registered for your account — pair the mobile app first.', 'glpimobile'),
            false,
            WARNING
        );
    } else {
        foreach ($results as $r) {
            $ok = $r['status'] >= 200 && $r['status'] < 300;
            Session::addMessageAfterRedirect(
                sprintf('%s → HTTP %d', htmlspecialchars($r['transport']), $r['status']),
                false,
                $ok ? INFO : ERROR
            );
        }
    }
    Html::back();
}

Html::header(Menu::getTypeName(), $_SERVER['PHP_SELF'], 'config', Menu::class);

$cfg = Config::getConfigurationValues($context, [
    'vapid_public_key', 'fcm_service_account', 'apns_p8',
    'apns_key_id', 'apns_team_id', 'apns_bundle_id', 'apns_production',
    'fcm_project_id', 'fcm_app_id', 'fcm_api_key', 'fcm_sender_id',
]);
$fcm_set  = !empty($cfg['fcm_service_account']);
$apns_set = !empty($cfg['apns_p8']);
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$badge = static fn(bool $on) => $on
    ? "<span class='badge bg-green-lt'>" . __s('configured', 'glpimobile') . '</span>'
    : "<span class='badge bg-secondary-lt'>" . __s('not set', 'glpimobile') . '</span>';

$csrf = Session::getNewCSRFToken();

echo "<div class='container-fluid' style='max-width:900px'>";
// No action = post to this page's own URL. Do NOT use $_SERVER['PHP_SELF'] — under
// GLPI 11's front controller it resolves to /index.php, whose path-info is '/',
// which CatchInventoryAgentRequestListener grabs as an inventory-agent request
// ("Inventory is disabled").
echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);

// UnifiedPush / VAPID (self-hosted, no account)
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . "<i class='ti ti-cloud me-2'></i>" . __s('UnifiedPush (self-hosted)', 'glpimobile')
    . "</h3></div><div class='card-body'>";
echo '<p class="text-muted">'
    . __s('Firebase-free push for Android. Point a UnifiedPush distributor (e.g. ntfy) at your server; no account is needed. This VAPID public key identifies this server.', 'glpimobile')
    . '</p>';
echo "<label class='form-label'>" . __s('VAPID public key', 'glpimobile') . '</label>';
echo "<input class='form-control' readonly value='" . $e($cfg['vapid_public_key'] ?? '') . "'>";
echo '</div></div>';

// FCM
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . "<i class='ti ti-brand-google me-2'></i>" . __s('Firebase Cloud Messaging (Android)', 'glpimobile')
    . ' ' . $badge($fcm_set) . "</h3></div><div class='card-body'>";
echo '<p class="text-muted">'
    . __s('Optional Android fallback when no UnifiedPush distributor is installed. Create your own Firebase project, add an Android app with this app\'s package name, and enter its client config below — the app fetches it at runtime (no baked-in google-services.json). The service account is used to send.', 'glpimobile')
    . '</p>';
echo '<label class="form-label">' . __s('Service account JSON', 'glpimobile') . '</label>';
echo "<textarea class='form-control mb-3' name='fcm_service_account' rows='4' "
    . "placeholder='" . ($fcm_set ? __s('Stored — paste new JSON to replace', 'glpimobile') : '{ &quot;type&quot;: &quot;service_account&quot;, ... }') . "'></textarea>";
echo "<div class='row'>";
echo "<div class='col-md-6 mb-2'><label class='form-label'>" . __s('Project ID', 'glpimobile') . "</label>"
    . "<input class='form-control' name='fcm_project_id' value='" . $e($cfg['fcm_project_id'] ?? '') . "'></div>";
echo "<div class='col-md-6 mb-2'><label class='form-label'>" . __s('Sender ID (project number)', 'glpimobile') . "</label>"
    . "<input class='form-control' name='fcm_sender_id' value='" . $e($cfg['fcm_sender_id'] ?? '') . "'></div>";
echo "<div class='col-md-6 mb-2'><label class='form-label'>" . __s('App ID', 'glpimobile') . "</label>"
    . "<input class='form-control' name='fcm_app_id' value='" . $e($cfg['fcm_app_id'] ?? '') . "'></div>";
echo "<div class='col-md-6 mb-2'><label class='form-label'>" . __s('API key', 'glpimobile') . "</label>"
    . "<input class='form-control' name='fcm_api_key' value='" . $e($cfg['fcm_api_key'] ?? '') . "'></div>";
echo '</div>';
echo '</div></div>';

// APNs
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . "<i class='ti ti-brand-apple me-2'></i>" . __s('Apple Push Notification service (iOS)', 'glpimobile')
    . ' ' . $badge($apns_set) . "</h3></div><div class='card-body'>";
echo '<label class="form-label">' . __s('Auth key (.p8 contents)', 'glpimobile') . '</label>';
echo "<textarea class='form-control mb-2' name='apns_p8' rows='4' "
    . "placeholder='" . ($apns_set ? __s('Stored — paste new .p8 to replace', 'glpimobile') : '-----BEGIN PRIVATE KEY-----') . "'></textarea>";
echo "<div class='row'>";
echo "<div class='col-md-4 mb-2'><label class='form-label'>" . __s('Key ID', 'glpimobile') . "</label>"
    . "<input class='form-control' name='apns_key_id' value='" . $e($cfg['apns_key_id'] ?? '') . "'></div>";
echo "<div class='col-md-4 mb-2'><label class='form-label'>" . __s('Team ID', 'glpimobile') . "</label>"
    . "<input class='form-control' name='apns_team_id' value='" . $e($cfg['apns_team_id'] ?? '') . "'></div>";
echo "<div class='col-md-4 mb-2'><label class='form-label'>" . __s('Bundle ID (apns-topic)', 'glpimobile') . "</label>"
    . "<input class='form-control' name='apns_bundle_id' value='" . $e($cfg['apns_bundle_id'] ?? '') . "'></div>";
echo '</div>';
$prod = !empty($cfg['apns_production']);
echo "<label class='form-check'><input type='checkbox' class='form-check-input' name='apns_production' value='1' "
    . ($prod ? 'checked' : '') . "> <span class='form-check-label'>"
    . __s('Production APNs (unchecked = sandbox)', 'glpimobile') . '</span></label>';
echo '</div></div>';

echo "<div class='d-flex gap-2'>";
echo "<button type='submit' name='update' value='1' class='btn btn-primary'>"
    . "<i class='ti ti-device-floppy me-1'></i>" . __s('Save', 'glpimobile') . '</button>';
echo "<button type='submit' name='test' value='1' class='btn btn-outline-secondary'>"
    . "<i class='ti ti-send me-1'></i>" . __s('Send test to my devices', 'glpimobile') . '</button>';
echo '</div>';

echo '</form>';

// --- Paired devices ---
$sessions = DeviceSession::listAll();
echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>"
    . __s('Paired devices', 'glpimobile') . '</h3></div>';
echo "<div class='card-body'>";
echo "<p class='text-muted'>"
    . sprintf(
        __s(
            'Each paired app holds a device credential instead of a password. '
            . 'Revoking one signs that device out within the hour. Devices unused '
            . 'for %d days expire on their own.',
            'glpimobile'
        ),
        DeviceSession::IDLE_LIMIT_DAYS
    )
    . '</p>';

if ($sessions === []) {
    echo "<p class='text-muted'>" . __s('No paired devices.', 'glpimobile') . '</p>';
} else {
    echo "<table class='table table-hover'><thead><tr>"
        . '<th>' . __s('User', 'glpimobile') . '</th>'
        . '<th>' . __s('Platform', 'glpimobile') . '</th>'
        . '<th>' . __s('Paired', 'glpimobile') . '</th>'
        . '<th>' . __s('Last seen', 'glpimobile') . '</th>'
        . '<th></th></tr></thead><tbody>';
    foreach ($sessions as $row) {
        echo '<tr>';
        echo '<td>' . $e($row['user_name'] ?? ('#' . $row['users_id'])) . '</td>';
        echo '<td>' . $e($row['platform'] ?? '—') . '</td>';
        echo '<td>' . Html::convDateTime($row['date_creation']) . '</td>';
        echo '<td>' . Html::convDateTime($row['last_seen']) . '</td>';
        echo "<td class='text-end'>";
        // Its own form: revoking must not also submit the settings above.
        echo "<form method='post' class='d-inline'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
        echo "<button type='submit' name='revoke_session' value='" . (int) $row['id'] . "' "
            . "class='btn btn-sm btn-outline-danger'>"
            . "<i class='ti ti-plug-connected-x me-1'></i>" . __s('Revoke', 'glpimobile')
            . '</button>';
        echo '</form>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

echo '</div>';

Html::footer();
