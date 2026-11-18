<?php

declare(strict_types=1);

/**
 * SyncClass — root fallback front controller.
 *
 * Only used when the host cannot point its document root at public/. Apache
 * routes clean URLs here through the root .htaccess, and this file boots the
 * same application as public/index.php — there is no second implementation.
 *
 * Security: the root .htaccess denies app/, config/, database/, storage/,
 * vendor/, tests/ and docs/, so running from the project root does not expose
 * source or secrets.
 */

if (!defined('SYNCLASS_ROOT_FALLBACK')) {
    define('SYNCLASS_ROOT_FALLBACK', true);
}

require __DIR__ . '/public/index.php';
