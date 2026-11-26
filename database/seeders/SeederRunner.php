<?php

declare(strict_types=1);

/**
 * SeederRunner — compatibility entry point.
 *
 * The demo dataset lives in `bin/seed-demo.php`, which is the supported seeder
 * (see AGENTS.md: schema → RBAC → `php bin/seed-demo.php` → export). This file
 * used to carry its own bootstrap and declared an empty `namespace;`, which is
 * not valid PHP and made `php bin/lint.php` fail. It now simply delegates, so
 * both commands seed the same rows through the same code path.
 *
 * Usage: php database/seeders/SeederRunner.php
 */

require dirname(__DIR__, 2) . '/bin/seed-demo.php';
