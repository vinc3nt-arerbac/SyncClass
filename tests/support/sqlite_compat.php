<?php

declare(strict_types=1);

/**
 * Test support: MySQL functions mirrored on SQLite (Phase 18).
 *
 * The production queries are MySQL/MariaDB SQL. The in-memory SQLite mirror
 * used by the suites has no `CONCAT`, `TIME_TO_SEC`, `TIMEDIFF` or `FIELD`, so
 * this helper registers faithful equivalents on the test connection. The
 * repository SQL itself runs unchanged — nothing here is loaded by the
 * application.
 *
 * Usage:
 *
 *     require __DIR__ . '/support/sqlite_compat.php';
 *     $database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
 *     sqlite_mysql_compat($database);
 *
 * Notes:
 *   - `TIME_TO_SEC` returns a float so `TIME_TO_SEC(...) / 3600` divides like
 *     MySQL's `/` instead of truncating, which matters for half-hour meetings;
 *   - `CONCAT` is variadic and NULL-safe, matching MySQL's NULL propagation.
 */

use App\Core\Database;

function sqlite_mysql_compat(Database $database): void
{
    $pdo = $database->pdo();

    $pdo->sqliteCreateFunction('CONCAT', static function (mixed ...$parts): ?string {
        $out = '';

        foreach ($parts as $part) {
            if ($part === null) {
                return null;
            }

            $out .= (string) $part;
        }

        return $out;
    }, -1);

    $pdo->sqliteCreateFunction('TIME_TO_SEC', static function (mixed $time): float {
        if (!is_string($time) || preg_match('/^-?(\d+):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            return 0.0;
        }

        return (float) (((int) $parts[1]) * 3600 + ((int) $parts[2]) * 60 + (int) $parts[3]);
    }, 1);

    $pdo->sqliteCreateFunction('TIMEDIFF', static function (mixed $end, mixed $start): string {
        $secondsOf = static function (mixed $time): int {
            if (!is_string($time) || preg_match('/^(\d+):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
                return 0;
            }

            return ((int) $parts[1]) * 3600 + ((int) $parts[2]) * 60 + (int) $parts[3];
        };

        $seconds = $secondsOf($end) - $secondsOf($start);
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }, 2);

    $pdo->sqliteCreateFunction('FIELD', static function (mixed $value, mixed ...$list): int {
        foreach ($list as $index => $candidate) {
            if ((string) $candidate === (string) $value) {
                return $index + 1;
            }
        }

        return 0;
    }, -1);
}
