<?php

namespace GlpiPlugin\Glpimobile;

use Config;
use GLPIKey;
use OAuthClient;
use RuntimeException;

/**
 * Server-side OAuth broker. Holds the confidential client's secret and performs
 * the authorization_code + refresh_token exchanges on the app's behalf, so the
 * app never sees a secret.
 *
 * mintForUser() reuses the caller's live GLPI web session: it replays the
 * standard authorization_code flow against this same GLPI over loopback
 * (carrying the session cookie + &accept to auto-approve), captures the code
 * from the redirect, then exchanges it with the secret. The result is a genuine
 * GLPI OAuth access/refresh token pair the HL API accepts directly.
 */
final class OAuthBroker
{
    private const REDIRECT_URI = PLUGIN_GLPIMOBILE_REDIRECT_URI;
    private const SCOPE = 'api user email';

    /** Identifier of the auto-provisioned OAuth client, or null if not set up yet. */
    public static function configuredClientId(): ?string
    {
        $values = Config::getConfigurationValues(PLUGIN_GLPIMOBILE_CONFIG_CONTEXT, ['client_id']);
        $id = $values['client_id'] ?? '';
        return $id !== '' ? $id : null;
    }

    /**
     * Loopback target for self-calls: [connect_base_url, host_header].
     *
     * GLPI names its session cookie from hash(root_dir + HTTP_HOST + SERVER_PORT)
     * (SystemConfigurator), so to have the inner request recognise the caller's
     * session we must connect to loopback yet present the SAME Host + port the
     * original request used. A config 'self_base_url' overrides this outright
     * (for CLI/tests, where the token endpoint needs no session).
     */
    private static function loopback(): array
    {
        $cfg = Config::getConfigurationValues(
            PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
            ['self_base_url', 'self_port']
        );
        $base = $cfg['self_base_url'] ?? '';
        if ($base !== '') {
            return [rtrim($base, '/'), ''];
        }
        // Connect to the port the web server actually listens on internally
        // (usually 80), NOT the request's SERVER_PORT — behind a proxy/port-map
        // those differ. Present the original Host so the inner request derives
        // the same HTTP_HOST + SERVER_PORT, hence the same session cookie name.
        $internal_port = (string) ($cfg['self_port'] ?? '80');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        return ["http://127.0.0.1:$internal_port", $host];
    }

    /** Decrypt the confidential client secret from its stored row. */
    private static function clientSecret(string $client_id): string
    {
        $client = new OAuthClient();
        if (!$client->getFromDBByCrit(['identifier' => $client_id])) {
            throw new RuntimeException('glpimobile: OAuth client not found');
        }
        $secret = (new GLPIKey())->decrypt($client->fields['secret'] ?? '');
        if ($secret === '' || $secret === null) {
            throw new RuntimeException('glpimobile: OAuth client secret unreadable');
        }
        return $secret;
    }

    /**
     * Mint tokens for the user behind the given web-session cookie header.
     * Returns ['access_token','refresh_token','expires_in']. Throws on failure.
     */
    public static function mintForUser(string $cookie_header): array
    {
        $client_id = self::configuredClientId();
        if ($client_id === null) {
            throw new RuntimeException('glpimobile: OAuth client not provisioned');
        }
        $secret = self::clientSecret($client_id);
        [$base, $host] = self::loopback();

        // Release the PHP session lock before calling ourselves over HTTP, or the
        // inner authorize request would deadlock waiting on the same session file.
        if (\function_exists('session_write_close') && \session_status() === PHP_SESSION_ACTIVE) {
            \session_write_close();
        }

        $state = bin2hex(random_bytes(8));
        $authorize_url = $base . '/api.php/v2/authorize?' . http_build_query([
            'response_type'        => 'code',
            'client_id'            => $client_id,
            'redirect_uri'         => self::REDIRECT_URI,
            'scope'                => self::SCOPE,
            'state'                => $state,
            'accept'               => '1',
        ]);

        [$status, $headers] = self::httpGetHeaders($authorize_url, $cookie_header, $host);
        if ($status !== 302 || !isset($headers['location'])) {
            throw new RuntimeException("glpimobile: authorize did not redirect (HTTP $status)");
        }
        if (!preg_match('/[?&]code=([^&]+)/', $headers['location'], $m)) {
            throw new RuntimeException('glpimobile: authorization was not approved');
        }
        $code = urldecode($m[1]);

        return self::tokenRequest($base, $host, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT_URI,
            'client_id'     => $client_id,
            'client_secret' => $secret,
        ]);
    }

    /** Exchange a refresh token for a fresh access/refresh pair. */
    public static function refresh(string $refresh_token): array
    {
        $client_id = self::configuredClientId();
        if ($client_id === null) {
            throw new RuntimeException('glpimobile: OAuth client not provisioned');
        }
        $secret = self::clientSecret($client_id);
        [$base, $host] = self::loopback();

        return self::tokenRequest($base, $host, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $secret,
            'scope'         => self::SCOPE,
        ]);
    }

    /** POST the OAuth token endpoint and return the decoded token triple. */
    private static function tokenRequest(string $base, string $host, array $body): array
    {
        $curl = curl_init($base . '/api.php/token');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => array_filter([
                'Content-Type: application/json',
                'Accept: application/json',
                $host !== '' ? 'Host: ' . $host : null,
            ]),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            $error  = is_array($data) ? (string) ($data['error'] ?? '') : '';
            $detail = is_array($data) ? ($data['error_description'] ?? $error) : '';
            // A rejected grant is permanent — the credential is dead and the
            // device must re-pair. Everything else (timeout, 5xx, garbled
            // body) is transient and MUST stay retryable: mapping it to
            // "re-authenticate" is how a slow server signs everyone out.
            if ($error === 'invalid_grant' || $error === 'invalid_request') {
                throw new SessionRejected("glpimobile: grant rejected — $detail");
            }
            throw new RuntimeException("glpimobile: token request failed (HTTP $status) $detail");
        }
        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];
    }

    /** GET a URL without following redirects; return [status, lowercased-headers]. */
    private static function httpGetHeaders(string $url, string $cookie_header, string $host): array
    {
        $curl = curl_init($url);
        $headers = [];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => array_filter([
                $cookie_header !== '' ? 'Cookie: ' . $cookie_header : null,
                $host !== '' ? 'Host: ' . $host : null,
                'Accept: text/html',
            ]),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return [$status, $headers];
    }
}
