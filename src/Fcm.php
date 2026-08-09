<?php

namespace GlpiPlugin\Glpimobile;

use Config;
use GLPIKey;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1) sender, pure PHP: mints a Google OAuth2
 * access token from the service-account key (RS256 JWT) and posts the message.
 * The service-account JSON is stored GLPIKey-encrypted in plugin config.
 */
final class Fcm
{
    private static ?string $accessToken = null;
    private static ?string $projectId = null;

    public static function isConfigured(): bool
    {
        $v = Config::getConfigurationValues(PLUGIN_GLPIMOBILE_CONFIG_CONTEXT, ['fcm_service_account']);
        return !empty($v['fcm_service_account']);
    }

    /** Send to one FCM registration token. Returns the HTTP status. */
    public static function send(string $token, array $payload): int
    {
        [$access, $projectId] = self::auth();
        $body = json_encode([
            'message' => [
                'token'        => $token,
                'notification' => [
                    'title' => (string) ($payload['title'] ?? 'GLPI'),
                    'body'  => (string) ($payload['body'] ?? ''),
                ],
                'data'    => ['ticket_id' => (string) ($payload['ticket_id'] ?? '')],
                'android' => ['priority' => 'high'],
            ],
        ]);
        $curl = curl_init("https://fcm.googleapis.com/v1/projects/$projectId/messages:send");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $access,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status;
    }

    /** Mint (and cache within the request) an OAuth2 access token; returns [token, projectId]. */
    private static function auth(): array
    {
        $raw = (new GLPIKey())->decrypt(
            Config::getConfigurationValue(PLUGIN_GLPIMOBILE_CONFIG_CONTEXT, 'fcm_service_account')
        );
        $sa = json_decode((string) $raw, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
            throw new RuntimeException('glpimobile: invalid FCM service account');
        }
        if (self::$accessToken !== null && self::$projectId === $sa['project_id']) {
            return [self::$accessToken, self::$projectId];
        }

        $now = time();
        $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $header = WebPush::b64urlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = WebPush::b64urlEncode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $signingInput = $header . '.' . $claims;
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('glpimobile: FCM JWT sign failed');
        }
        $jwt = $signingInput . '.' . WebPush::b64urlEncode($sig);

        $curl = curl_init($tokenUri);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $data = is_string($resp) ? json_decode($resp, true) : null;
        if ($code !== 200 || empty($data['access_token'])) {
            throw new RuntimeException('glpimobile: FCM token exchange failed (HTTP ' . $code . ')');
        }
        self::$accessToken = (string) $data['access_token'];
        self::$projectId = (string) $sa['project_id'];
        return [self::$accessToken, self::$projectId];
    }
}
