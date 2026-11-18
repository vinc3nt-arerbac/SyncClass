<?php

declare(strict_types=1);

/**
 * Local configuration template.
 *
 * Copy this file to config/local.php (git-ignored) and fill in only the values
 * that differ from config/*.php. bootstrap.php merges the file recursively over
 * the defaults, so a partial array is valid.
 *
 * Never commit config/local.php and never paste real credentials into chat,
 * tickets or documentation.
 */

return [
    'app' => [
        'env' => 'local',
        'debug' => true,
        'url' => 'http://localhost/syncclass',
    ],

    'database' => [
        'host' => '127.0.0.1',
        'database' => 'anth04_SyncClass',
        'username' => 'root',
        'password' => '',
    ],

    'mail' => [
        'enabled' => false,
        'host' => 'smtp.example.edu',
        'port' => 587,
        'username' => '',
        'password' => '',
        'from_address' => 'no-reply@sac.edu.ph',
    ],
];
