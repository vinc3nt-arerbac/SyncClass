<?php

declare(strict_types=1);

/**
 * SyncClass — write the permission catalogue and role matrix to the database.
 *
 *     php bin/sync-rbac.php
 *
 * Run once after importing the schema, and again after editing
 * config/permissions.php. The command is idempotent: roles and permissions are
 * updated in place (ids survive), and each role's links are replaced in one
 * transaction. Grants removed from the file are removed from the database.
 *
 * This is deliberately a console command rather than a web installer: the
 * matrix is security-critical, and changing it should be an explicit,
 * auditable deployment step.
 *
 * Exit codes: 0 success, 1 database or configuration error.
 */

use App\Core\Config;
use App\Core\Database;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Services\RbacService;

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$configObject = new Config($config);
$database = new Database((array) $config['database']);

try {
    $service = new RbacService(new RoleRepository($database), new PermissionRepository($database), $configObject);
    $result = $service->synchronise();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not synchronise roles and permissions: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

printf(
    "Synchronised RBAC\n  roles:       %d\n  permissions: %d\n  links:       %d\n",
    $result['roles'],
    $result['permissions'],
    $result['links']
);

exit(0);
