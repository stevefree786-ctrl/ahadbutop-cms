<?php
namespace CMS\Repository;

/**
 * Users, sessions and settings.
 *
 * password_hash is deliberately excluded from COLUMNS: no read path in
 * the admin UI or API should ever receive it.
 */
class UserRepository extends Repository
{
    protected const TABLE = 'users';
    protected const COLUMNS = [
        'id', 'uuid', 'email', 'username', 'display_name', 'role',
        'avatar_media_id', 'locale', 'status', 'last_login_at', 'created_at', 'updated_at',
    ];

    public function find(int $id): ?array
    {
        return $this->selectOne('id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->selectOne('email = ?', [$email]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->selectOne('username = ?', [$username]);
    }

    /**
     * Includes password_hash — ONLY for the login path.
     *
     * COLUMNS deliberately omits password_hash so it can never leak through a
     * listing, find() or a serialised API response. That means this method
     * cannot use the inherited select(): it issues its own projection naming
     * the columns it wants, including the hash.
     *
     * The filter is on email, case-insensitively: addresses are stored as typed
     * but people do not distinguish Foo@x.com from foo@x.com when logging in.
     */
    public function findForAuth(string $email): ?array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT id, uuid, email, username, password_hash, role, status,
                    failed_attempts, locked_until
               FROM users
              WHERE email = ? COLLATE NOCASE
              LIMIT 1'
        );
        $stmt->execute([$email]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function emailExists(string $email): bool
    {
        return $this->count('email = ?', [$email]) > 0;
    }

    public function create(array $data): int
    {
        return $this->insertRow([
            'uuid'         => $data['uuid'] ?? bin2hex(random_bytes(16)),
            'email'        => strtolower(trim($data['email'])),
            'username'     => $data['username'] ?? strstr($data['email'], '@', true),
            'password_hash'=> $data['password_hash'],
            'display_name' => $data['display_name'] ?? null,
            'role'         => $data['role'] ?? 'author',
            'locale'       => $data['locale'] ?? 'en',
            'status'       => $data['status'] ?? 'active',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $allowed = array_intersect_key($data, array_flip([
            'email', 'username', 'display_name', 'role', 'locale', 'status', 'avatar_media_id',
        ]));
        if ($allowed === []) {
            return false;
        }
        $allowed['updated_at'] = date('Y-m-d H:i:s');
        return $this->updateRow($id, $allowed) >= 0;
    }

    public function setPassword(int $id, string $hash): bool
    {
        return $this->updateRow($id, ['password_hash' => $hash, 'updated_at' => date('Y-m-d H:i:s')]) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->deleteRow($id) > 0;
    }

    public function paginate(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['role'])) {
            $where[] = 'role = ?';
            $params[] = $filters['role'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(username LIKE ? OR email LIKE ? OR display_name LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $clause = implode(' AND ', $where);
        $limit  = max(1, min($limit, 200));
        $offset = max(0, $offset);

        return [
            'items' => $this->select($clause, $params, 'created_at DESC', 'LIMIT ? OFFSET ?', [$limit, $offset]),
            'total' => $this->count($clause, $params),
            'limit' => $limit,
            'offset'=> $offset,
        ];
    }

    /**
     * A public author profile by slug (the username column).
     *
     * Suspended accounts are excluded: a suspended user's byline has no
     * business rendering on a public page. The caller supplies the column
     * filter so this never exposes an unpublished post, but `password_hash`
     * is still absent because COLUMNS omits it.
     */
    public function findPublicByUsername(string $username): ?array
    {
        return $this->selectOne(
            "username = ? AND status = 'active'",
            [$username]
        );
    }

    public function countByRole(string $role): int
    {
        return $this->count('role = ?', [$role]);
    }

    /** Guard against removing the last administrator. */
    public function isLastAdmin(int $userId): bool
    {
        $user = $this->find($userId);
        if ($user === null || $user['role'] !== 'admin') {
            return false;
        }
        return $this->countByRole('admin') <= 1;
    }

    // ---- sessions -------------------------------------------------------

    public function purgeExpiredSessions(): int
    {
        $stmt = $this->db->getPdo()->prepare("DELETE FROM sessions WHERE expires_at <= datetime('now')");
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function activeSessions(int $userId): array
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT id, user_agent, ip_hash, created_at, last_seen_at, expires_at
             FROM sessions
             WHERE user_id = ? AND expires_at > datetime('now')
             ORDER BY last_seen_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}