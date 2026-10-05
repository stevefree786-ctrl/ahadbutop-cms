<?php
namespace CMS\Auth;

use CMS\Database\Connection;
use PDO;

/**
 * Login, logout, refresh and user lookup.
 *
 * Sessions live in the `sessions` table keyed by sha256(refresh_token);
 * the raw token only ever exists on the client.
 */
class AuthService
{
    private Connection $db;
    private JwtService $jwt;
    private array $config;

    public function __construct(Connection $db, JwtService $jwt, array $config)
    {
        $this->db = $db;
        $this->jwt = $jwt;
        $this->config = $config;
    }

    /**
     * Attempt login. Returns [tokens] on success or ['error' => reason].
     */
    public function login(string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $pdo = $this->db->getPdo();

        $stmt = $pdo->prepare(
            'SELECT id, uuid, email, password_hash, role, status, failed_attempts, locked_until
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Uniform failure message: never reveal whether the email exists.
        $invalid = ['error' => 'Invalid credentials'];

        if (!$user) {
            return $invalid;
        }

        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return ['error' => 'Account temporarily locked'];
        }

        if ($user['status'] !== 'active') {
            return ['error' => 'Account is not active'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->registerFailure((int) $user['id'], (int) $user['failed_attempts']);
            return $invalid;
        }

        // Transparently upgrade legacy hashes.
        if (password_needs_rehash($user['password_hash'], $this->config['password']['algo'])) {
            $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $up->execute([password_hash($password, $this->config['password']['algo']), $user['id']]);
        }

        $this->clearFailures((int) $user['id']);

        $tokens = $this->createSession($user, $ip, $userAgent);

        $pdo->prepare('UPDATE users SET last_login_at = datetime(\'now\') WHERE id = ?')->execute([$user['id']]);

        return [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            // Surfaced so the caller can mirror it into the readable
            // cms_csrf cookie. The column existed from the start and was
            // written on every login; nothing ever read it back.
            'csrf_token'    => $tokens['csrf_token'],
            'expires_in'    => $this->config['jwt']['ttl'],
            'user' => [
                'id'    => (int) $user['id'],
                'uuid'  => $user['uuid'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ];
    }

    /** Create a session row + access/refresh token pair. */
    private function createSession(array $user, ?string $ip, ?string $userAgent): array
    {
        $refresh = JwtService::newRefreshToken();
        $csrf    = bin2hex(random_bytes(16));

        $this->db->insert('sessions', [
            // The primary key IS the sha256 of the refresh token, so a DB
            // leak never exposes a usable credential.
            'id'          => JwtService::hashRefreshToken($refresh),
            'user_id'     => (int) $user['id'],
            'csrf_token'  => $csrf,
            'ip_hash'     => $ip ? hash('sha256', $ip) : null,
            'user_agent'  => $userAgent ? substr($userAgent, 0, 255) : null,
            'expires_at'  => date('Y-m-d H:i:s', time() + (int) $this->config['jwt']['refresh_ttl']),
            'created_at'  => date('Y-m-d H:i:s'),
            'last_seen_at'=> date('Y-m-d H:i:s'),
        ]);

        return [
            'access_token' => $this->jwt->issue([
                'sub'  => (int) $user['id'],
                'uuid' => $user['uuid'],
                'role' => $user['role'],
                'email'=> $user['email'],
            ]),
            'refresh_token' => $refresh,
            'csrf_token'    => $csrf,
        ];
    }

    /**
     * Exchange a refresh token for a new access token (rotating the refresh).
     */
    public function refresh(string $refreshToken): array
    {
        $pdo = $this->db->getPdo();
        $hash = JwtService::hashRefreshToken($refreshToken);

        $stmt = $pdo->prepare(
            'SELECT s.id AS session_id, s.user_id, u.uuid, u.email, u.role, u.status
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > datetime(\'now\') LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            return ['error' => 'Invalid refresh token'];
        }

        // Rotate: invalidate the old token so a stolen refresh token is single-use.
        $pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$row['session_id']]);

        $tokens = $this->createSession(
            ['id' => $row['user_id'], 'uuid' => $row['uuid'], 'email' => $row['email'], 'role' => $row['role']],
            null,
            null
        );

        return [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in'    => $this->config['jwt']['ttl'],
        ];
    }

    /** Revoke a single session. */
    public function logout(string $refreshToken): bool
    {
        return $this->db->delete('sessions', [
            'id' => JwtService::hashRefreshToken($refreshToken),
        ]) > 0;
    }

    /** Revoke every session for a user. */
    public function logoutAll(int $userId): int
    {
        return $this->db->delete('sessions', ['user_id' => $userId]);
    }

    private function registerFailure(int $userId, int $currentAttempts): void
    {
        $attempts = $currentAttempts + 1;
        $max = (int) $this->config['lockout']['max_attempts'];

        if ($attempts >= $max) {
            // Lock the account and reset the counter so the next window starts clean.
            $this->db->getPdo()->prepare(
                'UPDATE users
                 SET failed_attempts = 0,
                     locked_until = ?,
                     last_login_at = datetime(\'now\')
                 WHERE id = ?'
            )->execute([
                date('Y-m-d H:i:s', time() + (int) $this->config['lockout']['window']),
                $userId,
            ]);
            return;
        }

        $this->db->getPdo()->prepare(
            'UPDATE users SET failed_attempts = ?, last_login_at = datetime(\'now\') WHERE id = ?'
        )->execute([$attempts, $userId]);
    }

    private function clearFailures(int $userId): void
    {
        $this->db->getPdo()
            ->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$userId]);
    }
}