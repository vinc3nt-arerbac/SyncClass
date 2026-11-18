<?php

declare(strict_types=1);

/**
 * Mail configuration (Phase 14). Email is optional: with `enabled` false the
 * application still records in-app notifications and never blocks a request on
 * SMTP (NTF-04).
 */

$bool = static fn (string $key, bool $default): bool => filter_var(
    getenv($key) === false ? $default : getenv($key),
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
) ?? $default;

return [
    'enabled' => $bool('MAIL_ENABLED', false),
    'host' => getenv('MAIL_HOST') ?: 'smtp.example.edu',
    'port' => (int) (getenv('MAIL_PORT') ?: 587),
    'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls', // tls | ssl | none
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@sac.edu.ph',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'SyncClass',
    'timeout_seconds' => 10,
    'max_attempts' => 3,
];
