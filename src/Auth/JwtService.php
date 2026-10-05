<?php
namespace CMS\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Issues and verifies JWT access tokens.
 *
 * Refresh tokens are opaque random strings, not JWTs — they are stored
 * hashed in the sessions table so they can be revoked server-side.
 */
class JwtService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config['jwt'] ?? [];
        if (empty($this->config['secret'])) {
            throw new RuntimeException(
                'JWT_SECRET is not set. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }
    }

    /**
     * @param array $claims Extra claims merged into the payload.
     */
    public function issue(array $claims = []): string
    {
        $now = time();

        $payload = array_merge($claims, [
            'iss' => $this->config['issuer'],
            'iat' => $now,
            'exp' => $now + (int) $this->config['ttl'],
            'jti' => bin2hex(random_bytes(16)),
        ]);

        return JWT::encode($payload, $this->config['secret'], $this->config['algorithm']);
    }

    /**
     * Verify and decode a token. Returns null when invalid or expired.
     */
    public function verify(string $token): ?array
    {
        try {
            $decoded = JWT::decode(
                $token,
                new Key($this->config['secret'], $this->config['algorithm'])
            );
            return (array) $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Generate an opaque, high-entropy refresh token (returned to client once). */
    public static function newRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Refresh tokens are stored as sha256 so a DB leak does not expose them. */
    public static function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }
}