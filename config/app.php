<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Values resolve from environment variables with safe local defaults. A
 * deployment overrides them in config/local.php (never committed), which
 * bootstrap.php merges over this array.
 */

$bool = static fn (string $key, bool $default): bool => filter_var(
    getenv($key) === false ? $default : getenv($key),
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
) ?? $default;

return [
    'name' => getenv('APP_NAME') ?: 'SyncClass',
    'institution' => getenv('APP_INSTITUTION') ?: 'Santa Ana College',
    'version' => '1.0.0-dev',
    'env' => getenv('APP_ENV') ?: 'local',
    'debug' => $bool('APP_DEBUG', true),
    'url' => rtrim(getenv('APP_URL') ?: 'http://localhost/syncclass', '/'),
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Manila',
    'locale' => 'en',
    'charset' => 'UTF-8',

    // AUTH-06: idle timeout plus an absolute session lifetime.
    'session' => [
        'name' => 'syncclass_session',
        'lifetime_minutes' => 480,
        'idle_timeout_minutes' => 30,
        'cookie_path' => '/',
        'same_site' => 'Lax',
        'secure' => $bool('APP_HTTPS', false),
        'http_only' => true,
    ],

    // SYS-01 defaults; the settings table can override these at runtime.
    'uploads' => [
        'max_bytes' => 2 * 1024 * 1024,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'pagination' => [
        'per_page' => 25,
        'max_per_page' => 100,
    ],
];
