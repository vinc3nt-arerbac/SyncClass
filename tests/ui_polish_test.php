<?php

declare(strict_types=1);

/**
 * UI polish test — Phase 17 exit criterion (NFR-08, NFR-09).
 *
 *     php tests/ui_polish_test.php
 *
 * A browser cannot be driven from the test suite, so this file asserts the
 * structural contract the CSS and layout promise:
 *
 *   1. the shell ships a skip link, a main landmark and a mobile navigation
 *      toggle wired to `primary-nav` with `aria-expanded`;
 *   2. the active section carries `aria-current="page"` (driven by the route
 *      name the kernel shares with the view);
 *   3. the behaviour file is loaded from the local asset path (the CSP forbids
 *      third-party scripts);
 *   4. the stylesheet defines the component layer the views actually use —
 *      Bootstrap-named buttons, form controls, list groups, modals and metrics
 *      — plus the focus, reduced-motion and small-screen rules.
 *
 * Visual appearance at 360/768/1280 px was reviewed in CSS, not screenshotted;
 * this suite guards against the markup or rule set regressing.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Services\RbacService;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/auth_schema.php';
require __DIR__ . '/support/master_schema.php';
require __DIR__ . '/support/schedule_schema.php';

/** @var array<int, array{label: string, passed: bool, detail: string}> $checks */
$checks = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks;

    $checks[] = ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

$testConfig = $config;
$testConfig['security']['password']['options']['cost'] = 4;
$testConfig['app']['session']['name'] = 'syncclass_ui_test';

$configObject = new Config($testConfig);
$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
auth_schema($database);
master_schema($database);
schedule_schema($database);
$database->pdo()->exec(
    'CREATE TABLE schedule_conflicts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        semester_id INTEGER NOT NULL,
        primary_schedule_id INTEGER NOT NULL,
        rule_code TEXT NOT NULL,
        conflict_type TEXT NOT NULL,
        severity TEXT NOT NULL DEFAULT "error",
        status TEXT NOT NULL DEFAULT "open",
        message TEXT NULL,
        detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

(new RbacService(new RoleRepository($database), new PermissionRepository($database), $configObject))->synchronise();

$adminId = auth_create_user($database, [
    'email' => 'admin@sac.edu.ph',
    'role_code' => 'administrator',
    'first_name' => 'Ana',
    'last_name' => 'Reyes',
]);

$kernel = Kernel::boot($testConfig);
$container = $kernel->container();
$container->instance(Database::class, $database);

$session = $container->get(Session::class);
$session->start();
$container->get(App\Services\AuthService::class)->loginUser($adminId);

$home = $kernel->handle(new Request('GET', '/'));
$body = $home->body();

// ---------------------------------------------------------------------------
// 1. Landmarks, skip link and the mobile toggle
// ---------------------------------------------------------------------------
check('the dashboard renders', $home->status() === 200);
check('the shell starts with a skip link to the main landmark', str_contains($body, 'class="skip-link" href="#main"'));
check('the main landmark is focusable', str_contains($body, '<main class="shell" id="main" tabindex="-1">'));
check('the primary nav has an id and an accessible name', str_contains($body, 'id="primary-nav"')
    && str_contains($body, 'aria-label="Primary"'));
check('the mobile toggle controls the nav and starts collapsed', str_contains($body, 'class="nav-toggle"')
    && str_contains($body, 'aria-controls="primary-nav"')
    && str_contains($body, 'aria-expanded="false"'));
check('the current section is announced', str_contains($body, 'href="/SyncClass/" aria-current="page"')
    || str_contains($body, 'href="/" aria-current="page"'));

// ---------------------------------------------------------------------------
// 2. Assets and document metadata
// ---------------------------------------------------------------------------
check('the behaviour script is local', str_contains($body, 'assets/js/app.js'));
check('the theme colour matches the masthead', str_contains($body, 'name="theme-color" content="#8c1d40"'));
check('the document declares a light colour scheme', str_contains($body, 'name="color-scheme" content="light"'));

// ---------------------------------------------------------------------------
// 3. Stylesheet contract (NFR-08/NFR-09)
// ---------------------------------------------------------------------------
$css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');

foreach ([
    '.skip-link',
    ':focus-visible',
    '.nav-toggle',
    '.btn-primary',
    '.btn-outline-secondary',
    '.form-select',
    '.form-floating',
    '.list-group-item',
    '.modal-content',
    '.metric-value',
    '.badge',
    '.alert-danger',
] as $selector) {
    check('the stylesheet defines ' . $selector, str_contains($css, $selector));
}

check('navigation collapses below 900 px', str_contains($css, '@media (max-width: 900px)'));
check('the layout tightens below 640 px', str_contains($css, '@media (max-width: 640px)'));
check('reduced motion is respected', str_contains($css, 'prefers-reduced-motion: reduce'));
check('print hides the shell chrome', str_contains($css, '@media print'));

// ---------------------------------------------------------------------------
// 4. The modal behaviour file exists and is dependency-free
// ---------------------------------------------------------------------------
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');

check('the behaviour file handles the Bootstrap modal markup', str_contains($js, 'data-bs-toggle="modal"')
    && str_contains($js, 'data-bs-dismiss="modal"'));
check('the behaviour file has no third-party dependency', !str_contains($js, 'cdn.')
    && !str_contains($js, 'import '));
check('the behaviour file restores focus after closing a modal', str_contains($js, 'returnFocus'));

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$failures = 0;

foreach ($checks as $result) {
    if ($result['passed']) {
        fwrite(STDOUT, 'PASS ' . $result['label'] . PHP_EOL);
        continue;
    }

    $failures++;
    fwrite(STDOUT, 'FAIL ' . $result['label'] . ($result['detail'] !== '' ? ' — ' . $result['detail'] : '') . PHP_EOL);
}

fwrite(STDOUT, PHP_EOL . count($checks) . ' check(s), ' . $failures . ' failure(s).' . PHP_EOL);

exit($failures === 0 ? 0 : 1);
