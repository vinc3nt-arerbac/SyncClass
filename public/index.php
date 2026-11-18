<?php

declare(strict_types=1);

/**
 * SyncClass front controller (Phase 6).
 *
 * The whole HTTP entry point is four steps:
 *
 *   1. bootstrap.php loads the autoloader, configuration and error handling;
 *   2. Kernel::boot() wires the container and the route table;
 *   3. Request::capture() is built from the superglobals, with the mount prefix
 *      (/SyncClass on XAMPP) removed;
 *   4. handle() returns a Response, which sends itself.
 *
 * Everything else — routing, middleware, controllers, views — is application
 * code that can be unit-tested without Apache. This file contains no business
 * logic on purpose: it must never grow into a second entry point.
 */

use App\Core\Kernel;
use App\Core\Request;

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$kernel = Kernel::boot($config);

$response = $kernel->handle(Request::capture(
    (string) parse_url((string) ($config['app']['url'] ?? ''), PHP_URL_PATH)
));

$response->send();
