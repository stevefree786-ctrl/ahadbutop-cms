<?php
namespace CMS\Agents;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is acting, and what are they acting on.
 *
 * Every agent needs the caller's identity before it can do anything
 * interesting: AdminAgent authorizes intents against `userRole`, BlogAgent
 * stamps `author_id` on the drafts it saves, and the LLM prompts need the
 * site title to write on-brand copy.
 *
 * Before this class existed, nothing populated `user_role` — AdminAgent read
 * `$context['user_role'] ?? 'author'` and therefore silently downgraded every
 * caller to author, including admins. `fromRequest()` closes that gap by
 * reading the attributes AuthMiddleware already attaches
 * (user_id / user_uuid / user_role / user_email).
 *
 * Immutable: with*() returns a copy, so a Context can be shared across
 * agents without one of them mutating the caller's identity.
 */
final class Context
{
    public const ROLES = ['author' => 1, 'editor' => 2, 'admin' => 3];

    public function __construct(
        public readonly ?int $userId = null,
        public readonly string $userRole = 'author',
        public readonly string $siteTitle = '',
        public readonly string $siteDescription = '',
        public readonly ?string $userEmail = null,
        public readonly ?string $userUuid = null,
    ) {
    }

    /**
     * An unknown or missing role becomes 'author' — the least privileged
     * value the users table allows. Never trust a missing role with 'admin'.
     */
    public static function normalizeRole(mixed $role): string
    {
        $role = strtolower(trim((string) ($role ?? '')));
        return isset(self::ROLES[$role]) ? $role : 'author';
    }

    /**
     * Build from the request attributes AuthMiddleware attaches. A request
     * that never passed through auth yields an anonymous author context.
     *
     * @param array $site site.* settings, as returned by siteSettings().
     */
    public static function fromRequest(
        ServerRequestInterface $request,
        ?int $fallbackUserId = null,
        array $site = []
    ): self {
        return new self(
            userId: self::intOrNull($request->getAttribute('user_id')) ?? $fallbackUserId,
            userRole: self::normalizeRole($request->getAttribute('user_role')),
            siteTitle: (string) ($site['title'] ?? ''),
            siteDescription: (string) ($site['description'] ?? ''),
            userEmail: $request->getAttribute('user_email') !== null
                ? (string) $request->getAttribute('user_email')
                : null,
            userUuid: $request->getAttribute('user_uuid') !== null
                ? (string) $request->getAttribute('user_uuid')
                : null,
        );
    }

    /**
     * Rebuild from toArray(). This is the round-trip agents use: an agent
     * turns a Context into the array its execute() receives, and back again.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: self::intOrNull($data['user_id'] ?? null),
            userRole: self::normalizeRole($data['user_role'] ?? null),
            siteTitle: (string) ($data['site_title'] ?? ''),
            siteDescription: (string) ($data['site_description'] ?? ''),
            userEmail: isset($data['user_email']) ? (string) $data['user_email'] : null,
            userUuid: isset($data['user_uuid']) ? (string) $data['user_uuid'] : null,
        );
    }

    /**
     * The canonical array shape handed to BaseAgent::execute(). Keys match
     * what AdminAgent/BlogAgent already read ('user_id', 'user_role').
     */
    public function toArray(): array
    {
        return [
            'user_id'          => $this->userId,
            'user_role'        => $this->userRole,
            'site_title'       => $this->siteTitle,
            'site_description' => $this->siteDescription,
            'user_email'       => $this->userEmail,
            'user_uuid'        => $this->userUuid,
        ];
    }

    public function with(?int $userId = null, ?string $userRole = null, ?string $siteTitle = null, ?string $siteDescription = null): self
    {
        return new self(
            $userId ?? $this->userId,
            self::normalizeRole($userRole ?? $this->userRole),
            $siteTitle ?? $this->siteTitle,
            $siteDescription ?? $this->siteDescription,
            $this->userEmail,
            $this->userUuid,
        );
    }

    public function isAtLeast(string $role): bool
    {
        $rank = self::ROLES[$role] ?? PHP_INT_MAX;
        return (self::ROLES[$this->userRole] ?? 0) >= $rank;
    }

    /**
     * A compact identity block for prompt injection. Deliberately excludes
     * user_email/uuid: the model has no business seeing PII, and a prompt is
     * the one place where untrusted text can steer a model.
     */
    public function toPromptBlock(): string
    {
        return implode("\n", [
            'Acting user id: ' . ($this->userId ?? 'anonymous'),
            'Acting user role: ' . $this->userRole,
            'Site title: ' . ($this->siteTitle !== '' ? $this->siteTitle : '(unset)'),
            'Site description: ' . ($this->siteDescription !== '' ? $this->siteDescription : '(unset)'),
        ]);
    }

    /**
     * site.title / site.description, falling back to the seeded home page so
     * prompts are never handed an empty brand. Static per process.
     */
    public static function siteSettings(\CMS\Database\Connection $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $site = [];
        try {
            $stmt = $db->getPdo()->prepare('SELECT key, value FROM settings WHERE key IN (?, ?)');
            $stmt->execute(['site.title', 'site.description']);
            foreach ($stmt->fetchAll() as $row) {
                $site[$row['key']] = self::decodeSetting((string) $row['value']);
            }
        } catch (\Throwable) {
            // A missing settings table must not stop an agent from running.
            $site = [];
        }

        $home = ['title' => '', 'description' => ''];
        try {
            $stmt = $db->getPdo()->prepare(
                "SELECT title, excerpt FROM posts WHERE type = 'page' AND status = 'published'
                 ORDER BY menu_order ASC, id ASC LIMIT 1"
            );
            $stmt->execute();
            if ($page = $stmt->fetch()) {
                $home = [
                    'title'       => (string) ($page['title'] ?? ''),
                    'description' => (string) ($page['excerpt'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // Same rationale as above.
        }

        return $cache = [
            'title'       => (string) ($site['site.title'] ?? $home['title']),
            'description' => (string) ($site['site.description'] ?? $home['description']),
        ];
    }

    /** Settings store JSON; tolerate legacy plain values. */
    private static function decodeSetting(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (is_string($decoded)) {
            return $decoded;
        }
        if (is_scalar($decoded)) {
            return (string) $decoded;
        }
        return $raw;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }
        return null;
    }
}