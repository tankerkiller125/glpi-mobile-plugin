<?php

namespace GlpiPlugin\Glpimobile;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use CommonGLPI;
use Config;
use GLPIKey;
use Preference;
use Session;
use Throwable;

/**
 * "Mobile app" tab on the personal Settings page. Renders a short-lived QR the
 * glpi-mobile app scans to pair. Rendering mints real OAuth tokens for the
 * viewing user (via OAuthBroker) and stashes them behind a one-time code; the
 * QR carries only that code, never the tokens.
 */
class QrTab extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Mobile app');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            return self::createTabEntry(__('Mobile app'), 0, $item::getType(), 'ti ti-device-mobile');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Preference)) {
            return false;
        }

        $user_id = (int) Session::getLoginUserID();
        if ($user_id <= 0) {
            echo self::notice('danger', __('You must be logged in to pair a device.'));
            return true;
        }
        if (OAuthBroker::configuredClientId() === null) {
            echo self::notice('warning', __('The mobile OAuth client is not provisioned. Reinstall the GLPI Mobile plugin.'));
            return true;
        }

        // Mint tokens for this user via their current web session, then store
        // them behind a one-time pairing code.
        $cookie_header = $_SERVER['HTTP_COOKIE'] ?? '';
        try {
            $tokens = OAuthBroker::mintForUser($cookie_header);
        } catch (Throwable $e) {
            echo self::notice('danger', __('Could not prepare a pairing code: ') . htmlspecialchars($e->getMessage()));
            return true;
        }

        $code = bin2hex(random_bytes(24));
        self::storePairing($code, $user_id, $tokens);

        $payload = json_encode([
            'v'    => 1,
            'url'  => self::detectBaseUrl(),
            'code' => $code,
        ]);

        echo self::render($payload, $code);
        return true;
    }

    private static function storePairing(string $code, int $user_id, array $tokens): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $key = new GLPIKey();
        $DB->insert('glpi_plugin_glpimobile_pairings', [
            'code'          => $code,
            'users_id'      => $user_id,
            'access_token'  => $key->encrypt($tokens['access_token']),
            'refresh_token' => $key->encrypt($tokens['refresh_token']),
            'expires_at'    => time() + PLUGIN_GLPIMOBILE_PAIR_TTL,
            'used'          => 0,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Best-effort public base URL for the app to call. */
    private static function detectBaseUrl(): string
    {
        global $CFG_GLPI;
        $base = $CFG_GLPI['url_base'] ?? '';
        if ($base === '') {
            $override = Config::getConfigurationValues(PLUGIN_GLPIMOBILE_CONFIG_CONTEXT, ['public_url']);
            $base = $override['public_url'] ?? '';
        }
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
            $base = $scheme . '://' . $host;
        }
        return rtrim($base, '/');
    }

    private static function render(string $payload, string $code): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($payload);
        // Strip the XML prolog so the SVG embeds cleanly inline.
        $svg = preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg);

        $ttl = (int) round(PLUGIN_GLPIMOBILE_PAIR_TTL / 60);
        $expires_msg = sprintf(__('This code expires in about %d minutes and can be scanned once.'), max(1, $ttl));

        return "<div class='card' style='max-width:520px;margin:1rem auto'>"
            . "<div class='card-body text-center'>"
            . "<h3 class='card-title'><i class='ti ti-device-mobile me-2'></i>" . __('Pair the GLPI mobile app') . '</h3>'
            . "<p class='text-muted'>" . __('Open the GLPI app, choose "Scan to sign in", and point it at this code.') . '</p>'
            . "<div class='d-flex justify-content-center my-3'>"
            . "<div style='width:240px;height:240px'>$svg</div>"
            . '</div>'
            . "<p class='small text-muted'>$expires_msg</p>"
            . "<details class='mt-2'><summary class='small text-muted'>" . __('Enter code manually') . '</summary>'
            . "<code class='d-block mt-2 p-2 bg-secondary-lt text-break' style='word-break:break-all'>"
            . htmlspecialchars($code) . '</code></details>'
            . "<button type='button' class='btn btn-outline-secondary mt-3' onclick='window.location.reload()'>"
            . "<i class='ti ti-refresh me-1'></i>" . __('Generate a new code') . '</button>'
            . '</div></div>';
    }

    private static function notice(string $type, string $msg): string
    {
        return "<div class='alert alert-$type m-3' role='alert'>$msg</div>";
    }
}
