<?php

declare(strict_types=1);

/**
 * Database configuration (MySQL 8 / MariaDB 10.4).
 *
 * The database name is fixed by the project brief. Credentials come from the
 * environment or config/local.php; they are never rendered in a view and never
 * written to a log.
 */

return [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_DATABASE') ?: 'anth04_SyncClass',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',

    // PDO options applied by App\Core\Database (Phase 6).
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ],
];
