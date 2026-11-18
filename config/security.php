<?php

declare(strict_types=1);

/**
 * Security configuration (Phase 5).
 *
 * These values are consumed by App\Core\Security (passwords, CSRF, headers),
 * App\Core\Session (the `session` block of config/app.php) and
 * App\Helpers\Validator (the password policy). Nothing here is secret: the
 * database and SMTP credentials live in config/database.php and
 * config/mail.php and are overridden from config/local.php.
 *
 * The reasoning behind every default is documented in docs/SECURITY.md.
 */

$bool = static fn (string $key, bool $default): bool => filter_var(
    getenv($key) === false ? $default : getenv($key),
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
) ?? $default;

$int = static fn (string $key, int $default): int => (int) (getenv($key) === false ? $default : getenv($key));

return [
    // -----------------------------------------------------------------------
    // Password storage (AUTH-02).
    //
    // `algorithm` is a name, not a PHP constant, so the value stays portable
    // across PHP builds where PASSWORD_ARGON2ID may be missing. Supported:
    // default | bcrypt | argon2id. Options are ignored for an algorithm that
    // does not accept them (see Security::passwordOptions()).
    // -----------------------------------------------------------------------
    'password' => [
        'algorithm' => getenv('PASSWORD_ALGORITHM') ?: 'default',
        'options' => [
            // Cost 12 ≈ 200–400 ms per hash on commodity hardware (2026).
            'cost' => max(4, min(31, $int('PASSWORD_BCRYPT_COST', 12))),
        ],
        'argon2_options' => [
            'memory_cost' => 65536, // 64 MiB
            'time_cost' => 4,
            'threads' => 2,
        ],

        // Policy enforced by Validator's `password` rule. The maximum is 72
        // *bytes* because bcrypt silently truncates longer input; capping it
        // in validation keeps the visible policy equal to the real one.
        'min_length' => 10,
        'max_length' => 72,
        'require_mixed_case' => true,
        'require_number' => true,
        'require_symbol' => false,

        // Phase 7 re-hashes on successful login when the cost/algorithm has
        // been raised, so old hashes upgrade without a password reset.
        'rehash_on_login' => true,

        // AUTH-05: remember-me selector/validator lifetime.
        'remember_token_days' => 30,
    ],

    // -----------------------------------------------------------------------
    // CSRF (AUTH-09). One token per session, rotated on login, logout and
    // privilege change. `lifetime_minutes` bounds how long an opened form can
    // sit before its token is refused; 0 disables the age check.
    // -----------------------------------------------------------------------
    'csrf' => [
        'token_bytes' => 32,
        'lifetime_minutes' => 120,
        'field_name' => '_token',
        'header_name' => 'X-CSRF-Token',
    ],

    // -----------------------------------------------------------------------
    // Login throttling thresholds (AUTH-07). The counters live in the
    // login_attempts table; these values drive the window and lockout.
    // -----------------------------------------------------------------------
    'throttle' => [
        'max_attempts' => 5,
        'window_minutes' => 15,
        'lockout_minutes' => 15,
    ],

    // -----------------------------------------------------------------------
    // Response headers. Sent by App\Core\Security::sendSecurityHeaders() from
    // the Phase 6 middleware pipeline. CSP uses a per-response nonce; the
    // {nonce} placeholder is replaced at send time.
    // -----------------------------------------------------------------------
    'headers' => [
        // Set to '' to omit X-Frame-Options (for example when a deployment
        // must embed the app in a cross-origin dashboard or preview frame).
        'frame_options' => getenv('SECURITY_FRAME_OPTIONS') !== false
            ? (string) getenv('SECURITY_FRAME_OPTIONS')
            : 'SAMEORIGIN',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'content_security_policy' => "default-src 'self'; base-uri 'self'; object-src 'none'; "
            . "frame-ancestors 'self'; form-action 'self'; img-src 'self' data:; "
            . "font-src 'self' data:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self' 'nonce-{nonce}'; connect-src 'self'",
        'csp_report_only' => false,
        'hsts_max_age' => 31536000, // 1 year, only sent over HTTPS
        'hsts_include_subdomains' => true,
        'hsts_preload' => false,
    ],

    // Set false only for a host that terminates headers at a reverse proxy.
    'headers_enabled' => $bool('SECURITY_HEADERS_ENABLED', true),
];
