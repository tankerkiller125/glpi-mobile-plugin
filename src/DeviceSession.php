<?php

namespace GlpiPlugin\Glpimobile;

use GLPIKey;
use RuntimeException;

/**
 * A paired device's long-lived session.
 *
 * GLPI rotates refresh tokens: exchanging one revokes it before the reply is
 * sent. If that reply is lost — dropped signal, the OS killing the app
 * mid-refresh — a device that held the refresh token itself would be left with
 * a revoked credential and no way back except re-scanning the pairing QR.
 *
 * So the server keeps the rotating GLPI refresh token and hands the app a
 * `device_secret` that never changes. A lost response is then harmless: the
 * app simply asks again with the same secret. It also makes revocation real —
 * delete the row and the device is signed out at its next refresh (≤1 h).
 */
final class DeviceSession
{
    public const TABLE = 'glpi_plugin_glpimobile_sessions';

    /** Result of a successful refresh: what the app is handed back. */
    public const IDLE_LIMIT_DAYS = 30;

    /**
     * Create a session for a freshly-paired device.
     *
     * @param array{access_token:string,refresh_token:string,expires_in:int} $tokens
     * @return array{device_id:string,device_secret:string}
     */
    public static function create(int $usersId, array $tokens, ?string $platform): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $deviceId = bin2hex(random_bytes(16));
        $secret   = bin2hex(random_bytes(32));

        $DB->insert(self::TABLE, [
            'device_id'     => $deviceId,
            'secret_hash'   => password_hash($secret, PASSWORD_DEFAULT),
            'users_id'      => $usersId,
            'refresh_token' => self::encrypt((string) ($tokens['refresh_token'] ?? '')),
            'platform'      => $platform,
            'last_seen'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);

        return ['device_id' => $deviceId, 'device_secret' => $secret];
    }

    /**
     * Look up and authenticate a device.
     *
     * @return array|null the row, or null when unknown / secret mismatch.
     */
    public static function authenticate(string $deviceId, string $secret): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($deviceId === '' || $secret === '') {
            return null;
        }
        $row = null;
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['device_id' => $deviceId]]) as $r) {
            $row = $r;
            break;
        }
        if ($row === null || !password_verify($secret, (string) $row['secret_hash'])) {
            return null;
        }
        return $row;
    }

    /**
     * Exchange this session's stored refresh token for a fresh access token,
     * persisting the rotated refresh token.
     *
     * @return array{access_token:string,expires_in:int}
     * @throws RuntimeException on a transport/server failure (retryable — the
     *         session is left intact); {@see SessionRejected} when GLPI itself
     *         rejects the token (the device must re-pair).
     */
    public static function refresh(array $row): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $refreshToken = self::decrypt((string) ($row['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new SessionRejected('session has no refresh token');
        }

        $tokens = OAuthBroker::refresh($refreshToken);

        $DB->update(self::TABLE, [
            'refresh_token' => self::encrypt((string) ($tokens['refresh_token'] ?? '')),
            'last_seen'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $row['id']]);

        return [
            'access_token' => (string) $tokens['access_token'],
            'expires_in'   => (int) ($tokens['expires_in'] ?? 3600),
        ];
    }

    /** Sign a device out. */
    public static function revoke(int $id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, ['id' => $id]);
    }

    /**
     * Sessions for the admin panel, newest first, with the owner's name.
     *
     * @return array<int,array>
     */
    public static function listAll(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $rows = $DB->request([
            'SELECT'    => [
                's.id AS id', 's.device_id AS device_id', 's.users_id AS users_id',
                's.platform AS platform', 's.app_version AS app_version',
                's.last_seen AS last_seen', 's.date_creation AS date_creation',
                'u.name AS user_name',
            ],
            'FROM'      => self::TABLE . ' AS s',
            'LEFT JOIN' => [
                'glpi_users AS u' => ['ON' => ['s' => 'users_id', 'u' => 'id']],
            ],
            'ORDER'     => ['s.last_seen DESC'],
        ]);
        foreach ($rows as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Drop sessions unused for longer than the idle limit. Their GLPI refresh
     * token has expired by then anyway (GLPI issues them with a 1-month TTL),
     * so the row is dead weight — and a stale row is a credential that still
     * looks live in the admin panel.
     */
    public static function purgeIdle(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::IDLE_LIMIT_DAYS . ' days'));
        $DB->delete(self::TABLE, ['last_seen' => ['<', $cutoff]]);
        return $DB->affectedRows();
    }

    private static function encrypt(string $value): string
    {
        return $value === '' ? '' : (new GLPIKey())->encrypt($value);
    }

    private static function decrypt(string $value): string
    {
        return $value === '' ? '' : (string) (new GLPIKey())->decrypt($value);
    }
}
