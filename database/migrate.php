<?php
/**
 * Schema definition and migration runner.
 *
 * Usage:
 *   php database/migrate.php init      create the database if it does not exist
 *   php database/migrate.php status    compare live tables against the definition
 *   php database/migrate.php rebuild   DANGEROUS: drop everything and reapply
 *   php database/migrate.php export    write the current schema back to schema.sql
 *
 * Why this file exists: storage/cms.db was the only place the real 23-table
 * schema lived. database/schema.sql described an older, unused 16-table
 * layout, so a fresh install produced a database no repository could query.
 * schema.sql is now generated from this definition, and `export` keeps it
 * honest.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/helpers.php';

$dbPath  = $root . '/storage/cms.db';
$schemaFile = $root . '/database/schema.sql';

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$command = $argv[1] ?? 'status';

/**
 * The authoritative schema lives in database/schema.sql.
 *
 * That file is GENERATED (`php database/migrate.php export`) and must not be
 * hand-edited. Keeping it as plain SQL rather than a hand-maintained PHP
 * array is the point: the earlier PHP array had already drifted from reality
 * in over a dozen places (wrong CHECK values, invented columns such as
 * ai_runs.tokens_in, redirects.from_path) and nothing caught it. One
 * generated file cannot silently disagree with itself.
 *
 * Keep column names in sync with src/Repository — those query by name.
 */
function schemaStatements(): array
{
    $file = dirname(__DIR__) . '/database/schema.sql';
    if (!is_file($file)) {
        fwrite(STDERR, "Missing {$file}. Run: php database/migrate.php export\n");
        exit(1);
    }
    return splitSqlStatements((string) file_get_contents($file));
}

/**
 * Split a .sql file into individual statements.
 *
 * A naive explode on ';' corrupts statements whose CHECK bodies contain
 * semicolons, so track parenthesis depth and string state. Line comments are
 * stripped so exported DDL comments never reach exec().
 */
function splitSqlStatements(string $sql): array
{
    $out = [];
    $buf = '';
    $depth = 0;
    $inString = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($inString) {
            $buf .= $ch;
            if ($ch === "'" && $next === "'") { $buf .= $next; $i++; }
            elseif ($ch === "'") { $inString = false; }
            continue;
        }

        if ($ch === "'") { $inString = true; $buf .= $ch; continue; }
        if ($ch === '-' && $next === '-') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            $buf .= "\n";
            continue;
        }
        if ($ch === '(') { $depth++; }
        if ($ch === ')') { $depth--; }

        if ($ch === ';' && $depth === 0) {
            $stmt = trim($buf);
            if ($stmt !== '') { $out[] = $stmt; }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }

    $stmt = trim($buf);
    if ($stmt !== '') { $out[] = $stmt; }
    return $out;
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');

/**
 * Apply the schema. Idempotent.
 *
 * Each statement is guarded with CREATE ... IF NOT EXISTS so re-running
 * against a live database adds only what is missing. Bare CREATE TABLE is
 * NOT idempotent in SQLite: it throws "table X already exists" and aborts
 * the whole run at the first pre-existing table, so an `init` on a populated
 * database used to die before reaching any new statement.
 */
function applySchema(PDO $pdo): int
{
    $n = 0;
    foreach (schemaStatements() as $sql) {
        $pdo->exec(makeIdempotent($sql));
        $n++;
    }
    return $n;
}

/**
 * Rewrite a CREATE statement so it is safe to run against an existing DB.
 *
 * SQLite has no "CREATE OR REPLACE TABLE", and a bare CREATE INDEX has the
 * same problem. Rewriting to the IF NOT EXISTS forms is the supported way.
 * Anything that is not a CREATE is passed through untouched.
 */
function makeIdempotent(string $sql): string
{
    $trimmed = ltrim($sql);

    // CREATE VIRTUAL TABLE ... USING fts5 — supported by SQLite and has its own
    // IF NOT EXISTS form. Handled before the plain-CREATE branch because
    // "VIRTUAL" is not one of the keywords that branch knows how to inject.
    if (preg_match('/^CREATE\s+VIRTUAL\s+TABLE\b/i', $trimmed)) {
        return strpos($trimmed, 'IF NOT EXISTS') !== false
            ? $sql
            : (string) preg_replace(
                '/^CREATE\s+VIRTUAL\s+TABLE\b/i',
                'CREATE VIRTUAL TABLE IF NOT EXISTS',
                $trimmed,
                1
            );
    }

    if (!preg_match('/^CREATE\s+(UNIQUE\s+)?(TABLE|INDEX|VIEW|TRIGGER)\b/i', $trimmed, $m)) {
        return $sql;   // INSERT / UPDATE / anything else: not a DDL create.
    }

    $keyword = strtoupper($m[2]);
    $already = strpos($trimmed, 'IF NOT EXISTS') !== false
            || strpos($trimmed, 'IF EXISTS') !== false;

    if ($already) {
        return $sql;
    }

    // Unique indexes keep UNIQUE before INDEX: CREATE UNIQUE INDEX IF NOT EXISTS
    $prefix = ($m[1] ?? '') !== '' ? 'CREATE UNIQUE INDEX IF NOT EXISTS'
                                     : 'CREATE ' . $keyword . ' IF NOT EXISTS';

    return (string) preg_replace(
        '/^CREATE\s+(UNIQUE\s+)?' . $keyword . '\b/i',
        $prefix,
        $trimmed,
        1
    );
}

/**
 * Dump CREATE statements from a live database, sorted, for schema.sql.
 *
 * FTS5 shadow tables (posts_fts_data/_idx/_docsize/_config/_content) are
 * internal implementation rows in sqlite_master, not schema. Exporting them
 * makes a rebuild attempt to CREATE TABLE on tables SQLite creates itself,
 * which fails. Filter them out here so the generated file is replayable.
 */
function dumpSchema(PDO $pdo): string
{
    $rows = $pdo->query(
        "SELECT type, name, sql FROM sqlite_master
         WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
         ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END, name"
    )->fetchAll();

    // FTS5 shadow tables (posts_fts_data, _idx, _docsize, _config) appear in
    // sqlite_master as ordinary rows but SQLite creates them itself when the
    // virtual table is created. Replaying their CREATE TABLE fails, so drop
    // any table whose name is a shadow of an existing virtual table. The
    // virtual table itself IS exported — only its shadows are not.
    $ftsBase = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE sql LIKE '%VIRTUAL TABLE%'") as $r) {
        $ftsBase[] = (string) $r['name'];
    }
    $isShadowOfFts = static function (string $name) use ($ftsBase): bool {
        foreach ($ftsBase as $base) {
            if (str_starts_with($name, $base . '_')) {
                return true;
            }
        }
        return false;
    };

    $out = "-- CMS database schema\n"
         . "-- Generated by: php database/migrate.php export\n"
         . "-- Single source of truth. Apply with: php database/migrate.php init\n"
         . "-- To change the schema, change the live DB then re-run `export`.\n\n"
         . "PRAGMA foreign_keys = ON;\n";

    foreach ($rows as $row) {
        if ($isShadowOfFts((string) $row['name'])) {
            continue;
        }
        $sql = trim((string) $row['sql'], " \n\t;");
        $out .= "\n" . $sql . ";\n";
    }
    return $out;
}

switch ($command) {
    case 'init':
        $applied = applySchema($pdo);
        echo "Applied {$applied} schema statements to {$dbPath}\n";
        break;

    case 'rebuild':
        if (!in_array('--yes', $argv, true)) {
            fwrite(STDERR, "rebuild DROPS every table. Re-run with --yes to confirm.\n");
            exit(1);
        }
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS "{$t}"');
        }
        $applied = applySchema($pdo);
        $pdo->exec('PRAGMA foreign_keys = ON');
        file_put_contents($schemaFile, dumpSchema($pdo));
        echo "Rebuilt: dropped " . count($tables) . " tables, applied {$applied} statements.\n"
           . "Regenerated {$schemaFile}\n";
        break;

    case 'export':
        file_put_contents($schemaFile, dumpSchema($pdo));
        echo "Wrote {$schemaFile}\n";
        break;

    case 'status':
    default:
        // FTS5 creates shadow tables (posts_fts_data, _idx, _docsize, _config)
        // that are part of the virtual table, not tables in their own right.
        $isShadow = "name NOT LIKE 'sqlite_%'
                     AND name NOT LIKE '%\\_data' ESCAPE '\\'
                     AND name NOT LIKE '%\\_idx'  ESCAPE '\\'
                     AND name NOT LIKE '%\\_docsize' ESCAPE '\\'
                     AND name NOT LIKE '%\\_config' ESCAPE '\\'
                     AND name NOT LIKE '%\\_content' ESCAPE '\\'";

        $wanted = [];
        foreach (schemaStatements() as $sql) {
            // Match both "CREATE TABLE IF NOT EXISTS x" (hand-written) and
            // the bare "CREATE TABLE x" that `export` writes from SQLite.
            if (preg_match('/CREATE\s+(?:VIRTUAL\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?(\w+)/i', $sql, $m)) {
                $wanted[] = $m[1];
            }
        }
        $have = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND {$isShadow}")
            ->fetchAll(PDO::FETCH_COLUMN);

        $missing = array_diff($wanted, $have);
        $extra   = array_diff($have, $wanted);

        printf("Database: %s\n", $dbPath);
        printf("Defined tables: %d   Present: %d\n", count($wanted), count($have));

        if ($missing === [] && $extra === []) {
            echo "OK - schema matches the definition.\n";
        } else {
            if ($missing !== []) {
                echo "MISSING: " . implode(', ', $missing) . "\n";
                echo "  -> run: php database/migrate.php init\n";
            }
            if ($extra !== []) {
                echo "UNEXPECTED (not in definition): " . implode(', ', $extra) . "\n";
            }
            exit(1);
        }
        break;
}