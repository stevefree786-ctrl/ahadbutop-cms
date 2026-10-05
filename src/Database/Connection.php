<?php
namespace CMS\Database;

use PDO;
use PDOException;

class Connection
{
    private PDO $pdo;
    private array $config;

    /**
     * Every SQLite handle this process has opened, keyed by object id.
     *
     * DIAGNOSTIC ONLY, and it is the reason it exists rather than a leak: SQLite
     * reports a contended write as "database is locked", which reads as "someone
     * else is writing" and sends you looking at other processes. Most of the time
     * the writer is this process — a second Connection opened by the router, or by
     * a repository, sitting in a transaction nobody remembers starting. There is
     * no public way to enumerate PDO handles, so without this registry the only
     * way to find that handle is to add debug output and re-run until you stumble
     * on it.
     *
     * Entries are plain references to handles that already exist, so holding them
     * extends no lifetime the caller did not already have.
     *
     * @var array<int, PDO>
     */
    private static array $openHandles = [];

    /**
     * Debug backtraces for each open handle, keyed like self::$openHandles.
     *
     * Holds traces only when CMS_DEBUG_SQLITE is set in the environment, so
     * there is no cost in normal operation. Set it when a "database is locked"
     * error names a handle you cannot account for.
     *
     * @var array<int, array<int,string>>
     */
    private static array $openTraces = [];

    /** Enable handle tracing for this process. */
    public static function traceHandles(bool $on = true): void
    {
        self::$traceHandles = $on;
        if (!$on) {
            self::$openTraces = [];
        }
    }

    private static bool $traceHandles = false;

    /**
     * Handles this process currently holds open, for lock debugging.
     *
     * @return array<int, PDO>
     */
    public static function openHandles(): array
    {
        return self::$openHandles;
    }

    /**
     * Construction backtraces for the open handles, when tracing is enabled.
     *
     * @return array<int, array<int,string>>
     */
    public static function openTraces(): array
    {
        return self::$openTraces;
    }

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    private function connect(): void
    {
        $conn = $this->config['connections'][$this->config['default']] ?? null;
        if (!$conn) {
            throw new \RuntimeException('Database configuration not found');
        }

        try {
            if ($conn['driver'] === 'sqlite') {
                $this->pdo = new PDO('sqlite:' . $conn['database'], null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                $this->configureSqlite();
                $oid = spl_object_id($this->pdo);
                self::$openHandles[$oid] = $this->pdo;

                if (self::$traceHandles) {
                    $frames = [];
                    foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1, 6) as $f) {
                        $frames[] = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?')
                            . ' @ ' . basename((string) ($f['file'] ?? '?')) . ':' . ($f['line'] ?? 0);
                    }
                    self::$openTraces[$oid] = $frames;
                }
            } else {
                $dsn = "{$conn['driver']}:host={$conn['host']};port={$conn['port']};dbname={$conn['database']};charset={$conn['charset']}";
                $this->pdo = new PDO($dsn, $conn['username'], $conn['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            }
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Make concurrent SQLite access wait instead of throwing.
     *
     * Three pragmas, and all of them matter:
     *
     * busy_timeout — SQLite's default is to fail IMMEDIATELY with SQLITE_BUSY
     * when another process holds a write lock. This CMS opens one Connection per
     * request AND one per background worker, all against the same file, so
     * "another process holds a lock" is the normal case, not an exceptional
     * one: publish a post while a queue worker drains, and the loser used to get
     * a fatal 'database is locked' instead of a retry. Waiting up to five
     * seconds turns that race into a slightly slower response.
     *
     * journal_mode=WAL — in the default rollback-journal mode a writer blocks
     * ALL readers, so a long read (a page render, a sitemap, an admin list)
     * stalls an agent's write and vice versa. WAL lets readers and one writer
     * proceed concurrently. It is persistent, written once into the file header.
     *
     * synchronous=NORMAL is the companion: under WAL it is safe because the
     * write-ahead log carries the data, and it trades an fsync per commit for
     * throughput. FULL remains the default on the read path below in case the
     * database is on a filesystem where that distinction does not hold.
     *
     * None of these are durability settings, so the failure mode of a power cut
     * is unchanged from SQLite's default; the CMS is a content site, not a
     * ledger, which is the right trade for a five-second busy window.
     *
     * The values come from config/database.php rather than being baked in here.
     * That config already declared busy_timeout, journal_mode and foreign_keys,
     * but nothing read them — the keys were documentation pretending to be
     * configuration. Reading them now is what makes them real.
     */
    private function configureSqlite(): void
    {
        $conn = $this->config['connections'][$this->config['default']] ?? [];

        // Bound every value. A PRAGMA argument is interpolated, never bound, so
        // an unvalidated one from .env would be an injection point. Anything
        // non-numeric is dropped, and the default applies.
        $int = static function ($value, int $default): int {
            return is_numeric($value) ? (int) $value : $default;
        };

        $busyTimeout = $int($conn['busy_timeout'] ?? null, 5000);
        $synchronous = $int($conn['synchronous'] ?? null, 1);

        // WAL and DELETE are the only journal modes SQLite accepts here, and
        // MEMORY/OFF would trade durability for speed on a content site, which
        // is the wrong side of the bargain.
        $journal = strtoupper((string) ($conn['journal_mode'] ?? 'WAL'));
        if (!in_array($journal, ['WAL', 'DELETE'], true)) {
            $journal = 'WAL';
        }

        try {
            $this->pdo->exec('PRAGMA busy_timeout = ' . $busyTimeout);
            $this->pdo->exec('PRAGMA journal_mode = ' . $journal);
            $this->pdo->exec('PRAGMA synchronous = ' . max(0, min(2, $synchronous)));

            // foreign_keys is deliberately NOT read from the config.
            //
            // config/database.php declares 'foreign_keys' => true, and honouring
            // it was tried: it immediately broke three test files with
            // "FOREIGN KEY constraint failed", because the schema's REFERENCES
            // clauses were written as documentation rather than as constraints —
            // nothing was migrated to satisfy them. SQLite defaults this OFF for
            // exactly that reason, and turning it on does not start enforcing the
            // existing clauses; it starts enforcing them NOW, against rows and
            // writes that were created for years without them.
            //
            // Enforcing them is a migration, not a config flag: it needs the
            // orphaned rows reconciled first. Until then, leaving it OFF is the
            // only choice that does not reject writes the CMS has always
            // accepted. See the note in config/database.php.
        } catch (PDOException $e) {
            // A read-only mount, or a database already open in a mode these
            // pragmas cannot change. The CMS still works; it just keeps
            // SQLite's fail-fast behaviour. Never fatal a boot over it.
            error_log('[Connection] SQLite pragmas not applied: ' . $e->getMessage());
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll(string $table, array $where = [], string $orderBy = 'id ASC'): array
    {
        $sql = "SELECT * FROM $table";
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $conditions[] = "$key = ?";
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY $orderBy";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($where));
        return $stmt->fetchAll();
    }

    public function fetchOne(string $table, array $where = []): ?array
    {
        $sql = "SELECT * FROM $table";
        if (!empty($where)) {
            $conditions = [];
            foreach ($where as $key => $value) {
                $conditions[] = "$key = ?";
            }
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($where));
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
        $conditions = implode(' AND ', array_map(fn($k) => "$k = ?", array_keys($where)));
        $sql = "UPDATE $table SET $set WHERE $conditions";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...array_values($data), ...array_values($where)]);
        return $stmt->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $conditions = implode(' AND ', array_map(fn($k) => "$k = ?", array_keys($where)));
        $sql = "DELETE FROM $table WHERE $conditions";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($where));
        return $stmt->rowCount();
    }
}