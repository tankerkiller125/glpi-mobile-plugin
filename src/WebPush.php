<?php

namespace GlpiPlugin\Glpimobile;

use RuntimeException;

/**
 * Web Push (RFC 8291 aes128gcm payload encryption + RFC 8292 VAPID) in pure PHP
 * via openssl + hash_hkdf — no external libraries. This powers UnifiedPush
 * delivery (self-hosted ntfy) and, later, browser Web Push, using the same
 * self-generated VAPID keypair so there is no Firebase/account dependency.
 *
 * Verified against the RFC 8291 §5 test vector (IKM/CEK/NONCE) and by an
 * encrypt->decrypt round-trip.
 */
final class WebPush
{
    // DER templates for prime256v1 (P-256) keys, so raw scalars/points import into openssl.
    private const PRIV_DER_PREFIX = '308187020100301306072a8648ce3d020106082a8648ce3d030107046d306b0201010420';
    private const PRIV_DER_MID = 'a144034200';
    private const PUB_DER_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64urlDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    public static function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function privatePem(string $d, string $point): string
    {
        $der = hex2bin(self::PRIV_DER_PREFIX) . $d . hex2bin(self::PRIV_DER_MID) . $point;
        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    private static function publicPem(string $point): string
    {
        $der = hex2bin(self::PUB_DER_PREFIX) . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Generate a P-256 keypair; returns raw [d(32), point(65)]. */
    private static function generateKeypair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($key === false) {
            throw new RuntimeException('glpimobile: EC keygen failed');
        }
        $d = openssl_pkey_get_details($key);
        return [
            str_pad($d['ec']['d'], 32, "\x00", STR_PAD_LEFT),
            "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT),
        ];
    }

    /** New VAPID keypair as base64url strings: ['publicKey' => point, 'privateKey' => d]. */
    public static function generateVapidKeys(): array
    {
        [$d, $point] = self::generateKeypair();
        return ['publicKey' => self::b64urlEncode($point), 'privateKey' => self::b64urlEncode($d)];
    }

    /**
     * Encrypt a payload for a UnifiedPush/WebPush subscriber (RFC 8291,
     * aes128gcm, single record). $p256dh + $auth are the subscriber's keys
     * (base64url). Returns the raw message body to POST.
     */
    public static function encrypt(string $payload, string $p256dh, string $auth): string
    {
        $uaPublic = self::b64urlDecode($p256dh);
        $authSecret = self::b64urlDecode($auth);
        [$asD, $asPublic] = self::generateKeypair();

        $priv = openssl_pkey_get_private(self::privatePem($asD, $asPublic));
        $shared = openssl_pkey_derive(self::publicPem($uaPublic), $priv, 32);
        if ($shared === false) {
            throw new RuntimeException('glpimobile: ECDH derive failed');
        }

        $salt = random_bytes(16);
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('glpimobile: aes128gcm encrypt failed');
        }

        // RFC 8188 header: salt(16) | rs(4) | idlen(1) | keyid(as_public)
        return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $ct . $tag;
    }

    /**
     * VAPID (RFC 8292) Authorization header value for a given endpoint.
     * $vapid = ['publicKey' => b64url point, 'privateKey' => b64url d].
     */
    public static function vapidAuthorization(string $endpoint, array $vapid, string $subject): string
    {
        $parts = parse_url($endpoint);
        $aud = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $header = self::b64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64urlEncode(json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $subject,
        ]));
        $signingInput = $header . '.' . $claims;

        $priv = openssl_pkey_get_private(self::privatePem(
            self::b64urlDecode($vapid['privateKey']),
            self::b64urlDecode($vapid['publicKey'])
        ));
        $derSig = '';
        if (!openssl_sign($signingInput, $derSig, $priv, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('glpimobile: VAPID sign failed');
        }
        $jwt = $signingInput . '.' . self::b64urlEncode(self::ecDerToRaw($derSig));

        return 'vapid t=' . $jwt . ', k=' . $vapid['publicKey'];
    }

    /** Convert an ECDSA DER signature to the raw 64-byte r||s form JWT ES256 expects. */
    public static function ecDerToRaw(string $der): string
    {
        $offset = 4; // skip SEQUENCE header (30 len) + first INTEGER tag (02)
        $rlen = ord($der[3]);
        $r = substr($der, $offset, $rlen);
        $offset += $rlen + 2; // skip r + (02 slen)
        $slen = ord($der[$offset - 1]);
        $s = substr($der, $offset, $slen);
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    /**
     * Send an encrypted Web Push to a UnifiedPush endpoint. Returns the HTTP
     * status code (or 0 on transport failure). 404/410 mean the subscription is
     * gone and the device row should be pruned.
     */
    public static function send(string $endpoint, string $p256dh, string $auth, string $payload, array $vapid, string $subject, ?string $connectTo = null): int
    {
        $body = self::encrypt($payload, $p256dh, $auth);
        $curl = curl_init($endpoint);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . self::vapidAuthorization($endpoint, $vapid, $subject),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: 2419200',
                'Urgency: normal',
            ],
        ];
        // Dev only: connect to a different host:port while keeping the URL, Host
        // and VAPID audience intact (e.g. emulator endpoint -> ntfy container).
        if ($connectTo !== null && $connectTo !== '') {
            $opts[CURLOPT_CONNECT_TO] = [$connectTo];
        }
        curl_setopt_array($curl, $opts);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status;
    }
}
