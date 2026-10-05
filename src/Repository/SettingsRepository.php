<?php
namespace CMS\Repository;

/**
 * Key/value settings, scoped by `scope` (system | theme | user).
 *
 * Values are stored as JSON so booleans, numbers and objects round-trip
 * with their type intact rather than coming back as strings.
 */
class SettingsRepository extends Repository
{
    protected const TABLE = 'settings';
    protected const COLUMNS = ['key', 'value', 'scope', 'updated_at'];

    /** Fallbacks used when a key has never been written. */
    public const DEFAULTS = [
        'site_title'         => 'Untitled Site',
        'site_description'   => '',
        'site_url'           => 'http://localhost:8080',
        'posts_per_page'     => 10,
        'default_post_status'=> 'draft',
        'theme'              => 'default',
        'comments_require_moderation' => true,
        'ai_enabled'         => false,
        'seo_auto_score'     => true,
    ];

    /** Keys only an admin may change — mirrors Actions::update_setting. */
    public const ADMIN_ONLY = [
        'site_title', 'site_description', 'site_url', 'ai_enabled',
        'seo_auto_score', 'comments_require_moderation',
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->selectOne('key = ?', [$key]);
        if ($row === null) {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }
        $decoded = json_decode((string) $row['value'], true);
        // json_decode fails on a bare string written before typing existed.
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row['value'];
    }

    public function set(string $key, mixed $value, string $scope = 'system'): void
    {
        $this->db->getPdo()->prepare(
            'INSERT INTO settings (key, value, scope, updated_at)
             VALUES (?, ?, ?, datetime(\'now\'))
             ON CONFLICT(key) DO UPDATE
                SET value = excluded.value,
                    scope = excluded.scope,
                    updated_at = excluded.updated_at'
        )->execute([$key, json_encode($value), $scope]);
    }

    public function all(?string $scope = null): array
    {
        if ($scope === null) {
            $rows = $this->select('', [], 'key ASC');
        } else {
            $rows = $this->select('scope = ?', [$scope], 'key ASC');
        }

        $out = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            $out[$row['key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['value'];
        }
        return $out;
    }

    /** Defaults merged under stored values — the shape the renderer wants. */
    public function resolved(): array
    {
        return array_merge(self::DEFAULTS, $this->all());
    }

    /**
     * Raw rows, keyed shape, for rendering an editable form.
     *
     * all() returns key => decoded value, which is right for reading a single
     * setting and wrong for the settings screen: the form needs the key, the
     * value and the timestamp per row so it can label each field and show when
     * it last changed. Kept separate rather than reshaping all(), because every
     * existing caller expects the map.
     *
     * The value stays JSON-encoded here. A textarea has to render the stored
     * bytes, and json_encode of a decoded scalar would strip the quotes off a
     * string setting and silently rewrite it on the next save.
     *
     * @return array<int,array{key:string,value:string,scope:string,updated_at:?string}>
     */
    public function rows(?string $scope = null): array
    {
        $where = $scope === null ? '' : 'scope = ?';
        $sql   = 'SELECT key, value, scope, updated_at FROM settings'
               . ($where !== '' ? ' WHERE ' . $where : '')
               . ' ORDER BY key ASC';

        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute($scope === null ? [] : [$scope]);
        return $stmt->fetchAll();
    }

    public function delete(string $key): bool
    {
        $stmt = $this->db->getPdo()->prepare('DELETE FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        return $stmt->rowCount() > 0;
    }

    public function requiresAdmin(string $key): bool
    {
        return in_array($key, self::ADMIN_ONLY, true);
    }

    // ---- audit log -------------------------------------------------------

    public function audit(
        ?int $actorId,
        string $action,
        ?string $entity = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ipHash = null
    ): void {
        $this->db->getPdo()->prepare(
            'INSERT INTO audit_log (actor_id, action, entity, entity_id, before_json, after_json, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorId,
            $action,
            $entity,
            $entityId,
            $before === null ? null : json_encode($before),
            $after  === null ? null : json_encode($after),
            $ipHash,
        ]);
    }

    public function auditTrail(int $limit = 50): array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT a.id, a.action, a.entity, a.entity_id, a.before_json, a.after_json,
                    a.created_at, u.username AS actor
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.actor_id
             ORDER BY a.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, max(1, min($limit, 200)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}