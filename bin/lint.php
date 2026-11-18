<?php

declare(strict_types=1);

/**
 * SyncClass — development lint runner.
 *
 * Syntax-checks every PHP file in the project with the same binary running
 * this script (`php -l`). Development/CI helper only: the application itself
 * never shells out, so shared hosting that disables exec() is unaffected.
 *
 * Usage:
 *   php bin/lint.php            # exits 0 when clean, 1 on any failure
 */

$root = dirname(__DIR__);

$targets = ['app', 'config', 'database', 'public', 'routes', 'tests', 'bin'];
$files = [];

foreach ($targets as $target) {
    $directory = $root . '/' . $target;
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

foreach (['bootstrap.php', 'index.php'] as $entry) {
    $path = $root . '/' . $entry;
    if (is_file($path)) {
        $files[] = $path;
    }
}

sort($files);

$failures = 0;

foreach ($files as $file) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    if ($exitCode !== 0) {
        $failures++;
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    }
}

printf("%d file(s) checked, %d error(s).\n", count($files), $failures);

exit($failures === 0 ? 0 : 1);
