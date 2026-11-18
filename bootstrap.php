<?php

declare(strict_types=1);

/**
 * SyncClass — application bootstrap.
 *
 * Responsibilities (Phase 4 scope):
 *   1. define the path constants the rest of the codebase relies on;
 *   2. load the Composer autoloader, failing with an actionable message;
 *   3. load configuration (defaults, then config/local.php overrides);
 *   4. configure timezone, error reporting and the log destination;
 *   5. install a last-resort exception handler that never leaks internals
 *      when debug mode is off.
 *
 * Phase 6 replaces steps 3–5 with the Config, Logger and Kernel services; the
 * constants and the return contract (a configuration array) stay the same so
 * the front controllers do not change.
 *
 * @return array{app: array<string, mixed>, database: array<string, mixed>, mail: array<string, mixed>, schedule: array<string, mixed>, security: array<string, mixed>}
 */

define('BASE_PATH', __DIR__);
define('APP_PATH', BASE_PATH . '/app');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('SYNC_CLASS_BOOT_AT', microtime(true));

$autoloadPath = BASE_PATH . '/vendor/autoload.php';

if (!is_file($autoloadPath)) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    echo "SyncClass is not installed yet.\n\n";
    echo "The Composer autoloader was not found at:\n  {$autoloadPath}\n\n";
    echo "From the project root run:\n  composer install\n";
    exit(1);
}

require $autoloadPath;

/** @var array<string, mixed> $config */
$config = [
    'app' => require BASE_PATH . '/config/app.php',
    'database' => require BASE_PATH . '/config/database.php',
    'mail' => require BASE_PATH . '/config/mail.php',
    'schedule' => require BASE_PATH . '/config/schedule.php',
    'security' => require BASE_PATH . '/config/security.php',
];

// Local overrides are merged last so a deployment never edits tracked files.
$localConfigPath = BASE_PATH . '/config/local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

$debug = (bool) ($config['app']['debug'] ?? false);

date_default_timezone_set((string) ($config['app']['timezone'] ?? 'UTC'));
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDirectory = STORAGE_PATH . '/logs';
if (!is_dir($logDirectory)) {
    @mkdir($logDirectory, 0775, true);
}
ini_set('error_log', $logDirectory . '/php-error.log');

/**
 * Last-resort handler: log everything, reveal details only in debug mode.
 */
set_exception_handler(static function (Throwable $exception) use ($debug): void {
    $requestId = bin2hex(random_bytes(6));
    $logFile = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';

    $entry = sprintf(
        "[%s] request=%s %s: %s in %s:%d%s%s%s",
        date('c'),
        $requestId,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        PHP_EOL,
        $exception->getTraceAsString(),
        PHP_EOL
    );

    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    if ($debug) {
        echo "SyncClass — unhandled error\n\n";
        echo $exception->getMessage() . "\n\n";
        echo $exception->getFile() . ':' . $exception->getLine() . "\n\n";
        echo $exception->getTraceAsString() . "\n";

        exit(1);
    }

    echo "Something went wrong. Please contact the administrator with reference {$requestId}.\n";

    // Non-zero exit so a CLI run (tests, cron) never looks successful after a
    // fatal error; HTTP clients already received the 500 status above.
    exit(1);
});

return $config;
