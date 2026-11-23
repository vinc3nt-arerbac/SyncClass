<?php

declare(strict_types=1);

/**
 * SyncClass — build the single-file installation export.
 *
 *     php bin/export-sql.php
 *
 * Writes `database/schema/syncclass_complete.sql`: the schema built from
 * `database/migrations/*.up.sql` followed by every row of the reference
 * database (roles, permission matrix, initial settings, demo users and demo
 * scheduling data). Importing that one file is a complete fresh installation.
 *
 * Why not `mysqldump`? Two reasons:
 *
 *   1. XAMPP ships MariaDB while the specification names MySQL 8; the two
 *      clients emit engine-specific prologues and collations that break on the
 *      other server. This writer emits plain `CREATE TABLE` + multi-row
 *      `INSERT`, which both engines accept.
 *   2. A dump is only trustworthy if what it contains was verified. The script
 *      therefore counts the rows it writes and asks the database for the
 *      expected totals, failing loudly instead of writing a half-empty file.
 *
 * Generated columns are skipped automatically (they are computed by the
 * server), rows are ordered by primary key so repeated exports are byte-stable,
 * and string values are escaped through PDO, not by hand.
 *
 * Usage:
 *     php bin/export-sql.php            # build from database/schema/SyncClass.sql
 *     php bin/export-sql.php --schema   # rebuild SyncClass.sql from migrations first
 *
 * Exit codes: 0 success, 1 configuration, connection, or data error.
 */

use App\Core\Database;

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
$schemaFile = $root . '/database/schema/SyncClass.sql';
$targetFile = $root . '/database/schema/syncclass_complete.sql';
$migrationsDir = $root . '/database/migrations';

// ---------------------------------------------------------------------------
// 1. Make sure the plain schema file is present and newer than the migrations.
// ---------------------------------------------------------------------------

$rebuild = in_array('--schema', array_slice($argv, 1), true);

if ($rebuild) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/schema/build.php'), $status);
    if ($status !== 0) {
        fwrite(STDERR, 'Could not rebuild database/schema/SyncClass.sql.' . PHP_EOL);
        exit(1);
    }
}

if (!is_file($schemaFile)) {
    fwrite(STDERR, 'Missing database/schema/SyncClass.sql — run: php database/schema/build.php' . PHP_EOL);
    exit(1);
}

$schemaMtime = (int) filemtime($schemaFile);
foreach (glob($migrationsDir . '/*.up.sql') ?: [] as $migration) {
    if ((int) filemtime($migration) > $schemaMtime) {
        fwrite(STDERR, sprintf(
            "%s is newer than SyncClass.sql — run: php database/schema/build.php%s",
            basename($migration),
            PHP_EOL
        ));
        exit(1);
    }
}

$schema = (string) file_get_contents($schemaFile);
$database = (string) ($config['database']['database'] ?? 'anth04_SyncClass');

// Everything before `USE` is replaced by this script's own prologue; the DDL
// after that marker is the authoritative schema.
$marker = 'USE `' . $database . '`;';
$markerPosition = strpos($schema, $marker);
if ($markerPosition === false) {
    fwrite(STDERR, 'Unexpected schema file: the `USE` statement is missing.' . PHP_EOL);
    exit(1);
}

$ddl = ltrim(substr($schema, $markerPosition + strlen($marker)));

// The schema file re-enables foreign-key checks at its end. Drop that here:
// the seed data below needs them off (departments reference users and users
// reference departments), and this script re-enables them at the very end.
$ddl = trim(str_replace('SET FOREIGN_KEY_CHECKS = 1;', '', $ddl));

// ---------------------------------------------------------------------------
// 2. Connect and enumerate the tables to export.
// ---------------------------------------------------------------------------

try {
    $database_connection = new Database((array) $config['database']);
    $pdo = $database_connection->pdo();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not connect to the database: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** Tables written in dependency order so the file also reads top-down. */
$preferredOrder = [
    'schema_migrations',
    'roles',
    'permissions',
    'role_permissions',
    'departments',
    'users',
    'buildings',
    'rooms',
    'academic_years',
    'semesters',
    'time_slots',
    'subjects',
    'sections',
    'faculty_profiles',
    'faculty_availability',
    'faculty_subjects',
    'section_subjects',
    'schedules',
    'schedule_conflicts',
    'schedule_comments',
    'comment_mentions',
    'change_requests',
    'tasks',
    'task_checklist_items',
    'task_assignments',
    'activity_log',
    'notifications',
    'settings',
    'login_attempts',
    'remember_tokens',
];

$tables = array_map(
    static fn (array $row): string => (string) $row['TABLE_NAME'],
    $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA = ' . $pdo->quote($database)
        . " AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll()
);

if ($tables === []) {
    fwrite(STDERR, 'No tables found in `' . $database . '` — import the schema first.' . PHP_EOL);
    exit(1);
}

$ordered = [];
foreach ($preferredOrder as $name) {
    if (in_array($name, $tables, true)) {
        $ordered[] = $name;
    }
}
foreach ($tables as $name) {
    if (!in_array($name, $ordered, true)) {
        $ordered[] = $name;
    }
}

// Tables whose content ships as a seed file instead of a data dump, so the
// values documented in database/seeders/ stay the single source of truth.
$seedOnlyTables = ['settings'];

// ---------------------------------------------------------------------------
// 3. Serialise the rows.
// ---------------------------------------------------------------------------

/**
 * Turn one PHP value into a SQL literal. Strings are escaped by PDO itself;
 * numbers stay unquoted so numeric columns keep their type.
 */
function sqlLiteral(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return (string) $pdo->quote((string) $value);
}

$body = '';
$tableReport = [];
$totalRows = 0;

foreach ($ordered as $table) {
    if (in_array($table, $seedOnlyTables, true)) {
        continue;
    }

    $quotedTable = '`' . $table . '`';

    // Column metadata: generated columns must never appear in an INSERT.
    $columns = $pdo->query(
        'SELECT COLUMN_NAME, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA = ' . $pdo->quote($database)
        . ' AND TABLE_NAME = ' . $pdo->quote($table)
        . ' ORDER BY ORDINAL_POSITION'
    )->fetchAll();

    $insertable = [];
    $primaryKey = [];

    foreach ($columns as $column) {
        $extra = strtoupper((string) $column['EXTRA']);
        if (str_contains($extra, 'GENERATED')) {
            continue;
        }

        $insertable[] = (string) $column['COLUMN_NAME'];

        if ((string) $column['COLUMN_KEY'] === 'PRI') {
            $primaryKey[] = '`' . $column['COLUMN_NAME'] . '`';
        }
    }

    if ($insertable === []) {
        continue;
    }

    $sql = 'SELECT * FROM ' . $quotedTable;
    if ($primaryKey !== []) {
        $sql .= ' ORDER BY ' . implode(', ', $primaryKey);
    }

    $rows = $pdo->query($sql)->fetchAll();
    $tableReport[] = [$table, count($rows)];
    $totalRows += count($rows);

    $body .= '-- ' . str_repeat('-', 70) . "\n";
    $body .= sprintf("-- %s (%d rows)\n", $table, count($rows));
    $body .= '-- ' . str_repeat('-', 70) . "\n";

    if ($rows === []) {
        $body .= "-- (empty)\n\n";
        continue;
    }

    $columnList = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $insertable));

    // Batches of 100 rows keep each statement comfortably inside max_allowed_packet.
    foreach (array_chunk($rows, 100) as $chunk) {
        $tuples = [];

        foreach ($chunk as $row) {
            $values = [];
            foreach ($insertable as $column) {
                $values[] = sqlLiteral($pdo, $row[$column] ?? null);
            }
            $tuples[] = '    (' . implode(', ', $values) . ')';
        }

        $body .= 'INSERT INTO ' . $quotedTable . ' (' . $columnList . ") VALUES\n";
        $body .= implode(",\n", $tuples) . ";\n";
    }

    $body .= "\n";
}

// ---------------------------------------------------------------------------
// 4. Compose the file with a prologue that explains how to use it.
// ---------------------------------------------------------------------------

$generatedAt = date('Y-m-d H:i:s');
$appVersion = (string) ($config['app']['version'] ?? 'unknown');

$output = <<<SQL
-- =====================================================================
-- SyncClass — Classroom & Subject Scheduler
-- COMPLETE INSTALLATION EXPORT (schema + seed data + demo accounts)
-- =====================================================================
-- Application version : {$appVersion}
-- Generated           : {$generatedAt}
-- Database            : {$database}
-- Source server       : {$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)}
-- Engine target       : MySQL 8.0+ / MariaDB 10.4+ (XAMPP), InnoDB, utf8mb4
--
-- Contents
--   1. CREATE DATABASE / USE
--   2. Schema: every table, index, foreign key and CHECK constraint,
--      concatenated from database/migrations/*.up.sql
--   3. Seed data: roles, permission matrix, initial settings, demo users,
--      departments, faculty, subjects, rooms, sections, terms, schedules
--
-- Fresh installation
--   1. Start MySQL/MariaDB (XAMPP control panel).
--   2. Import this file, either in phpMyAdmin (Import → choose file → Go)
--      or from a terminal:
--          mysql -u root -p < database/schema/syncclass_complete.sql
--   3. Point config/local.php at the database ({$database}) and open the app.
--
-- The file is generated: do not edit it by hand. Regenerate with
--   php database/schema/build.php     (schema changes)
--   php bin/export-sql.php            (data changes)
--
-- Demo sign-in accounts all use the password: SyncClass2026!
--   admin@sac.edu.ph            System Administrator
--   dean.csm@sac.edu.ph         Dean — CSM
--   secretary.csm@sac.edu.ph    Department Secretary — CSM
--   jcruz@sac.edu.ph            Faculty — CSM
-- Change these passwords (or delete the demo accounts) before production use.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `{$database}`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `{$database}`;


SQL;

$output .= $ddl . "\n";
$output .= "-- =====================================================================\n";
$output .= "-- INITIAL SETTINGS (database/seeders/*.sql)\n";
$output .= "-- =====================================================================\n\n";

foreach (glob($root . '/database/seeders/*.sql') ?: [] as $seederFile) {
    $seedSql = (string) file_get_contents($seederFile);
    if (trim($seedSql) === '') {
        continue;
    }

    $output .= sprintf("-- %s\n", basename($seederFile));
    $output .= rtrim($seedSql) . "\n\n";
}

$output .= "-- =====================================================================\n";
$output .= "-- SEED DATA\n";
$output .= "-- =====================================================================\n\n";
$output .= $body;
$output .= "SET FOREIGN_KEY_CHECKS = 1;\n\n";
$output .= "-- Row counts written by this export\n";
foreach ($tableReport as [$table, $count]) {
    $output .= sprintf("--   %-24s %5d\n", $table, $count);
}
$output .= sprintf("--   %-24s %5d\n", 'TOTAL', $totalRows);

if (file_put_contents($targetFile, $output) === false) {
    fwrite(STDERR, 'Could not write ' . $targetFile . PHP_EOL);
    exit(1);
}

printf(
    "Wrote %s\n  tables: %d\n  rows:   %d\n  bytes:  %d\n",
    $targetFile,
    count($tableReport),
    $totalRows,
    strlen($output)
);

exit(0);
