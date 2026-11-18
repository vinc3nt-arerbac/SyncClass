<?php

declare(strict_types=1);

/**
 * SyncClass — single-file schema builder.
 *
 * Concatenates every numbered `*.up.sql` migration, in filename order, into
 * `database/schema/SyncClass.sql` so a fresh install is one import (SYS-03).
 * The migrations remain the source of truth; never hand-edit the generated
 * file.
 *
 * Usage (from the project root):
 *   php database/schema/build.php
 */

$schemaDir     = __DIR__;
$migrationsDir = dirname(__DIR__) . '/migrations';
$target        = $schemaDir . '/SyncClass.sql';

$files = glob($migrationsDir . '/*.up.sql');
if ($files === false || $files === []) {
    fwrite(STDERR, "No migrations found in {$migrationsDir}\n");
    exit(1);
}

sort($files, SORT_STRING);

$database = 'anth04_SyncClass';

$output  = "-- =====================================================================\n";
$output .= "-- SyncClass — Classroom & Subject Scheduler\n";
$output .= "-- Single-file schema generated from database/migrations/*.up.sql\n";
$output .= "-- DO NOT EDIT BY HAND — run: php database/schema/build.php\n";
$output .= "-- Server: MySQL 8.0+ / MariaDB 10.4+ · Engine: InnoDB · Charset: utf8mb4\n";
$output .= "-- =====================================================================\n\n";
// No session time_zone is forced here: the application sets it from
// config/app.php so DATETIME values stay in the institution's local time.
$output .= "SET NAMES utf8mb4;\n";
$output .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
$output .= "CREATE DATABASE IF NOT EXISTS `{$database}`\n";
$output .= "    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
$output .= "USE `{$database}`;\n\n";

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Unable to read {$file}\n");
        exit(1);
    }

    $output .= "-- ---------------------------------------------------------------------\n";
    $output .= '-- ' . basename($file) . "\n";
    $output .= "-- ---------------------------------------------------------------------\n";
    $output .= rtrim($sql) . "\n\n";
}

$output .= "SET FOREIGN_KEY_CHECKS = 1;\n";

if (file_put_contents($target, $output) === false) {
    fwrite(STDERR, "Unable to write {$target}\n");
    exit(1);
}

printf("Wrote %s (%d migrations, %d bytes)\n", $target, count($files), strlen($output));
