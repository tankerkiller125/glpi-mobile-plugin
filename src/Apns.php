<?php

namespace GlpiPlugin\Glpimobile;

use Config;
use GLPIKey;
use RuntimeException;

/**
 * Apple Push Notification service sender, pure PHP: signs a provider JWT
 * (ES256, .p8 key) and posts over HTTP/2. The .p8 is stored GLPIKey-encrypted;
 * key id / team id / bundle id are plain config identifiers.
 */
final class Apns
{
    private static ?string $jwt = null;
    private static int $jwtIat = 0;

    public static function isConfigured(): bool
    {
        $v = Config::getConfigurationValues(PLUGIN_GLPIMOBILE_CONFIG_CONTEXT, ['apns_p8']);
        return !empty($v['apns_p8']);
    }

    /** Send to one APNs device token. Returns the HTTP status (410 = unregistered). */
    public static function send(string $token, array $payload): int
    {
        $cfg = Config::getConfigurationValues(
            PLUGIN_GLPIMOBILE_CONFIG_CONTEXT,
            ['apns_key_id', 'apns_team_id', 'apns_bundle_id', 'apns_production']
        );
        $bundle = (string) ($cfg['apns_bundle_id'] ?? '');
        if ($bundle === '') {
            throw new RuntimeException('glpimobile: APNs bundle id not set');
        }
        $host = !empty($cfg['apns_production'])
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $jwt = self::jwt((string) ($cfg['apns_key_id'] ?? ''), (string) ($cfg['apns_team_id'] ?? ''));
        $body = json_encode([
            'aps' => [
                'alert' => [
                    'title' => (string) ($payload['title'] ?? 'GLPI'),
                    'body'  => (string) ($payload['body'] ?? ''),
                ],
                'sound' => 'default',
            ],
            'ticket_id' => (int) ($payload['ticket_id'] ?? 0),
        ]);

        $curl = curl_init("$host/3/device/$token");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_HTTPHEADER     => [
                'authorization: bearer ' . $jwt,
                'apns-topic: ' . $bundle,
                'apns-push-type: alert',
                'apns-priority: 10',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status;
    }

    /** Provider JWT, cached ~30 min (APNs accepts a token for up to 1 h). */
    private static function jwt(string $keyId, string $teamId): string
    {
        $now = time();
        if (self::$jwt !== null && ($now - self::$jwtIat) < 1800) {
            return self::$jwt;
        }
        $p8 = (new GLPIKey())->decrypt(
            Config::getConfigurationValue(PLUGIN_GLPIMOBILE_CONFIG_CONTEXT, 'apns_p8')
        );
        $key = openssl_pkey_get_private((string) $p8);
        if ($key === false) {
            throw new RuntimeException('glpimobile: invalid APNs .p8 key');
        }
        $header = WebPush::b64urlEncode(json_encode(['alg' => 'ES256', 'kid' => $keyId]));
        $claims = WebPush::b64urlEncode(json_encode(['iss' => $teamId, 'iat' => $now]));
        $signingInput = $header . '.' . $claims;
        $der = '';
        if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('glpimobile: APNs JWT sign failed');
        }
        self::$jwt = $signingInput . '.' . WebPush::b64urlEncode(WebPush::ecDerToRaw($der));
        self::$jwtIat = $now;
        return self::$jwt;
    }
}
