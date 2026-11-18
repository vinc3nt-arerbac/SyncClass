<?php

declare(strict_types=1);

/**
 * SyncClass front controller (public document root).
 *
 * Phase 4 scope: prove that the scaffold boots — autoloader, configuration,
 * timezone, storage and database extensions are wired — and answer a health
 * check. Phase 6 replaces the "dispatch" block below with the real kernel:
 *
 *     $kernel = App\Core\Kernel::boot($config);
 *     $kernel->handle(App\Core\Request::capture())->send();
 *
 * Until then, no route may be invented here: this file must not grow into a
 * second, competing router.
 */

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = '/' . trim(str_replace('\\', '/', $requestPath), '/');

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
        'ok' => class_exists(Composer\Autoload\ClassLoader::class),
        'detail' => class_exists(Composer\Autoload\ClassLoader::class) ? 'vendor/autoload.php' : 'not registered',
    ],
    'storage' => [
        'label' => 'Writable storage/logs',
        'ok' => is_dir(STORAGE_PATH . '/logs') && is_writable(STORAGE_PATH . '/logs'),
        'detail' => is_writable(STORAGE_PATH . '/logs') ? 'writable' : 'not writable',
    ],
];

$allOk = !in_array(false, array_column($checks, 'ok'), true);
$debug = (bool) ($config['app']['debug'] ?? false);

// ---------------------------------------------------------------------------
// Health endpoint: /health (JSON). Detailed output only in debug mode so a
// production install does not advertise its internals.
// ---------------------------------------------------------------------------
if (str_ends_with($requestPath, '/health')) {
    http_response_code($allOk ? 200 : 503);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $payload = ['status' => $allOk ? 'ok' : 'degraded'];

    if ($debug) {
        $payload['app'] = $config['app']['name'] ?? 'SyncClass';
        $payload['version'] = $config['app']['version'] ?? 'unknown';
        $payload['environment'] = $config['app']['env'] ?? 'unknown';
        $payload['timezone'] = date_default_timezone_get();
        $payload['checks'] = array_values($checks);
        $payload['boot_ms'] = round((microtime(true) - SYNC_CLASS_BOOT_AT) * 1000, 2);
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// Phase 4 scaffold page. Removed in Phase 6 when the dashboard takes over.
// ---------------------------------------------------------------------------
$isHome = in_array($requestPath, ['', '/'], true) || str_ends_with($requestPath, '/public');

if (!$isHome) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<title>404 — SyncClass</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#faf7f2;color:#2b2118;display:grid;place-items:center;height:100vh;margin:0}'
        . 'main{text-align:center}h1{font-size:3rem;margin:0;color:#8c1d40}p{color:#6b5b4b}</style></head><body><main>';
    echo '<h1>404</h1><p>No route matches ' . htmlspecialchars($requestPath, ENT_QUOTES, 'UTF-8') . '.</p>';
    echo '<p>Routing is installed in Phase 6.</p></main></body></html>';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$renderCheck = static function (array $check): string {
    $status = $check['ok'] ? 'ok' : 'fail';
    $mark = $check['ok'] ? '&#10003;' : '&#10007;';
    $label = htmlspecialchars((string) $check['label'], ENT_QUOTES, 'UTF-8');
    $detail = htmlspecialchars((string) $check['detail'], ENT_QUOTES, 'UTF-8');

    return '<li class="check ' . $status . '"><span class="mark">' . $mark . '</span>'
        . '<span class="label">' . $label . '</span>'
        . '<span class="detail">' . $detail . '</span></li>';
};

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SyncClass — scaffold status</title>
    <style>
        :root {
            --primary-gold: #c9a227;
            --primary-maroon: #8c1d40;
            --surface: #ffffff;
            --canvas: #faf7f2;
            --ink: #2b2118;
            --muted: #6b5b4b;
            --border: #e7ddcf;
            --ok: #1f7a4d;
            --fail: #b3261e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--canvas);
            color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            display: grid;
            place-items: center;
            padding: 2rem 1rem;
        }
        main {
            width: 100%;
            max-width: 44rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-top: 6px solid var(--primary-maroon);
            border-radius: .75rem;
            box-shadow: 0 10px 30px rgb(43 33 24 / 8%);
            padding: 2rem;
        }
        .eyebrow {
            font-size: .75rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--primary-maroon);
            font-weight: 700;
            margin: 0 0 .35rem;
        }
        h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
        .sub { color: var(--muted); margin: 0 0 1.5rem; font-size: .95rem; }
        ul.checks { list-style: none; margin: 0 0 1.5rem; padding: 0; border-top: 1px solid var(--border); }
        li.check {
            display: grid;
            grid-template-columns: 1.5rem 1fr auto;
            gap: .5rem;
            align-items: baseline;
            padding: .6rem .25rem;
            border-bottom: 1px solid var(--border);
            font-size: .92rem;
        }
        li.check .mark { font-weight: 700; }
        li.check.ok .mark { color: var(--ok); }
        li.check.fail .mark { color: var(--fail); }
        li.check .detail { color: var(--muted); font-size: .8rem; font-variant-numeric: tabular-nums; }
        .banner {
            display: flex;
            gap: .75rem;
            align-items: flex-start;
            padding: .85rem 1rem;
            border-radius: .5rem;
            background: color-mix(in srgb, var(--primary-gold) 16%, transparent);
            border: 1px solid color-mix(in srgb, var(--primary-gold) 45%, transparent);
            font-size: .88rem;
            color: #5a4310;
        }
        .banner strong { display: block; margin-bottom: .15rem; }
        footer { margin-top: 1.5rem; font-size: .78rem; color: var(--muted); display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        code { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: .8rem; }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">Phase 4 &middot; scaffold</p>
    <h1><?= htmlspecialchars((string) ($config['app']['name'] ?? 'SyncClass'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="sub">Classroom &amp; subject scheduler &mdash; the application boots and is ready for the MVC kernel.</p>

    <ul class="checks">
        <?= implode(PHP_EOL . '        ', array_map($renderCheck, array_values($checks))) ?>

    </ul>

    <div class="banner">
        <span aria-hidden="true">&#9873;</span>
        <span>
            <strong>What exists today</strong>
            Autoloading, configuration, error handling and the front controller.
            Routing, middleware, authentication and the UI arrive in Phases 6&ndash;8.
        </span>
    </div>

    <footer>
        <span>PHP <code><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></code> &middot; <code><?= htmlspecialchars(date_default_timezone_get(), ENT_QUOTES, 'UTF-8') ?></code></span>
        <span><a href="health">/health</a> &middot; boot <?= htmlspecialchars(number_format((microtime(true) - SYNC_CLASS_BOOT_AT) * 1000, 1), ENT_QUOTES, 'UTF-8') ?> ms</span>
    </footer>
</main>
</body>
</html>
