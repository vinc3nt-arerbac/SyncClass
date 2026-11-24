<?php

/**
 * SeederRunner
 *
 * CLI entry point to run all database seeders for SyncClass.
 * Usage: php database/seeders/SeederRunner.php
 *
 * @package Database\Seeders
 */

declare(strict_types=1);

namespace;

require __DIR__.'/../vendor/autoload.php';

// Bootstrap the application
$config = require __DIR__.'/../../config/bootstrap.php';

$container = new \App\Core\Container();
$router = new \App\Core\Router();

// Base path for the application
define('BASE_PATH', __DIR__);

// Register core container bindings
$container->instance('config', new \App\Core\Config($config));
$container->instance('router', $router);
$container->instance('db', new \App\Repositories\DatabaseRepository($config));

echo "=" . str_repeat('=', 58) . "\n";
echo "  SyncClass - Database Seeder Runner\n";
echo "=" . str_repeat('=', 58) . "\n";
echo "\n";

// Initialize the main DatabaseSeeder
$dbSeeder = new \Database\Seeders\DatabaseSeeder();
$dbSeeder->run();

echo "\n" . "=" . str_repeat('=', 58) . "\n";
echo "  Seeding completed successfully!\n";
echo "=" . str_repeat('=', 58) . "\n";
echo "\n";
echo "Demo login credentials:\n";
echo "  admin@sac.edu.ph / SyncClass2026!   (System Administrator)\n";
echo "  secretary.csm@sac.edu.ph / SyncClass2026!  (Department Secretary)\n";
echo "\n";