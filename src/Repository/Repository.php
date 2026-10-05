<?php
namespace CMS\Repository;

use CMS\Database\Connection;
use PDO;

/**
 * Base repository.
 *
 * Holds the shared query plumbing so concrete repositories only declare
 * their columns, filters and relations. Every value reaching SQL goes
 * through a bound parameter — the table/column names come from class
 * constants, never from caller input.
 */
abstract class Repository
{
    protected Connection $db;

    /** Table this repository owns. */
    protected const TABLE = '';

    /** Primary key column. */
    protected const PK = 'id';

    /** Columns returned by a read. Narrow reads avoid leaking password_hash etc. */
    protected const COLUMNS = ['*'];

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? new Connection(require base_path('config/database.php'));
    }

    protected function table(): string
    {
        return static::TABLE;
    }

    protected function columns(): string
    {
        return implode(', ', static::COLUMNS);
    }

    /**
     * @param array $limitParams Bound params for $extra (e.g. LIMIT ? OFFSET ?).
     * @return array<int,array<string,mixed>>
     */
    protected function select(
        string $where = '',
        array $params = [],
        string $order = '',
        string $extra = '',
        array $limitParams = []
    ): array {
        $sql = 'SELECT ' . $this->columns() . ' FROM ' . $this->table();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        if ($extra !== '') {
            $sql .= ' ' . $extra;
        }
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute([...$params, ...$limitParams]);
        return $stmt->fetchAll();
    }

    protected function selectOne(string $where, array $params = []): ?array
    {
        $rows = $this->select($where, $params, '', 'LIMIT 1');
        return $rows[0] ?? null;
    }

    public function count(string $where = '', array $params = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->table();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Insert an associative array of column => value. */
    protected function insertRow(array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = 'INSERT INTO ' . $this->table()
             . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute(array_values($data));
        return (int) $this->db->getPdo()->lastInsertId();
    }

    /** Update by primary key. Returns rows affected. */
    protected function updateRow(int $id, array $data): int
    {
        if ($data === []) {
            return 0;
        }
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($data)));
        $sql = 'UPDATE ' . $this->table() . " SET $set WHERE " . static::PK . ' = ?';

        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute([...array_values($data), $id]);
        return $stmt->rowCount();
    }

    protected function deleteRow(int $id): int
    {
        $stmt = $this->db->getPdo()->prepare('DELETE FROM ' . $this->table() . ' WHERE ' . static::PK . ' = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount();
    }

    /** True when the table exists — used by tests and health checks. */
    public function tableExists(): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?"
        );
        $stmt->execute([$this->table()]);
        return (bool) $stmt->fetchColumn();
    }
}