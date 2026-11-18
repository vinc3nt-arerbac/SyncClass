<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use Composer\Autoload\ClassLoader;

/**
 * `/health` — deployment and support diagnostic (SYS-04 seed).
 *
 * Reports only what the environment can answer without side effects: it does
 * not open a database connection and does not start a session of its own, so
 * it stays useful precisely when the application is broken.
 *
 * In debug mode it adds versions, timezone and per-check detail. In production
 * (debug=false) the response is the bare `{"status":"ok"}`/503 — a public host
 * should not advertise its internals.
 */
final class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        $checks = [
            'php' => [
                'label' => 'PHP 8.1 or newer',
                'ok' => PHP_VERSION_ID >= 80100,
                'detail' => PHP_VERSION,
            ],
            'pdo_mysql' => [
                'label' => 'PDO MySQL driver',
                'ok' => extension_loaded('pdo_mysql'),
                'detail' => extension_loaded('pdo_mysql') ? 'loaded' : 'missing',
            ],
            'mbstring' => [
                'label' => 'mbstring extension',
                'ok' => extension_loaded('mbstring'),
                'detail' => extension_loaded('mbstring') ? 'loaded' : 'missing',
            ],
            'json' => [
                'label' => 'JSON extension',
                'ok' => extension_loaded('json'),
                'detail' => extension_loaded('json') ? 'loaded' : 'missing',
            ],
            'autoload' => [
                'label' => 'Composer autoloader (App\\)',
                'ok' => class_exists(ClassLoader::class),
                'detail' => class_exists(ClassLoader::class) ? 'vendor/autoload.php' : 'not registered',
            ],
            'storage' => [
                'label' => 'Writable storage/logs',
                'ok' => is_dir(STORAGE_PATH . '/logs') && is_writable(STORAGE_PATH . '/logs'),
                'detail' => is_writable(STORAGE_PATH . '/logs') ? 'writable' : 'not writable',
            ],
        ];

        $healthy = !in_array(false, array_column($checks, 'ok'), true);
        $debug = (bool) $this->config->get('app.debug', false);

        $payload = ['status' => $healthy ? 'ok' : 'degraded'];

        if ($debug) {
            $payload['app'] = (string) $this->config->get('app.name', 'SyncClass');
            $payload['version'] = (string) $this->config->get('app.version', 'unknown');
            $payload['environment'] = (string) $this->config->get('app.env', 'unknown');
            $payload['timezone'] = date_default_timezone_get();
            $payload['route'] = (string) $request->attribute('route_name', 'health');
            $payload['checks'] = array_values($checks);
            $payload['boot_ms'] = defined('SYNC_CLASS_BOOT_AT')
                ? round((microtime(true) - SYNC_CLASS_BOOT_AT) * 1000, 2)
                : null;
        }

        return $this->json($payload, $healthy ? 200 : 503);
    }
}
