<?php
/**
 * Database configuration.
 *
 * Every schema file in db/schema/ is SQLite-specific (AUTOINCREMENT,
 * datetime('now'), PRAGMA), so sqlite is the default and only fully
 * supported driver. MySQL is retained only as an opt-in for local work.
 */
return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', dirname(__DIR__) . '/storage/cms.db'),

            // Read by CMS\Database\Connection::configureSqlite().
            'busy_timeout' => 5000,   // ms to wait for a contended write lock
            'journal_mode'  => 'WAL', // readers + one writer, concurrently

            // NOT a live setting. Listed so the intent is on the record, but
            // deliberately inert: see Connection::configureSqlite(). Turning
            // enforcement on is a data migration (reconcile the orphaned rows
            // the schema's REFERENCES clauses have always permitted), not a
            // config change — and until it happens, honouring this key rejects
            // writes the CMS has always accepted.
            'foreign_keys'  => false,
        ],

        'mysql' => [
            'driver'   => 'mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '3306'),
            'database' => env('DB_NAME', 'cms'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8mb4',
        ],
    ],

    // Consumed by public/index.php when building the CORS middleware
    'cors' => [
        'allowed_origins' => array_filter(
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
        ),
        'credentials' => env_bool('CORS_CREDENTIALS', false),
    ],
];