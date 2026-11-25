<?php

/**
 * Base layout (Phase 6, polished in Phase 17).
 *
 * Structure: skip link → masthead (brand, mobile toggle, primary nav, user
 * chip) → main landmark → footer. Phase 17 adds the skip link, the mobile
 * navigation toggle, `aria-current` on the active section and the small
 * `app.js` behaviour file (offcanvas nav + the Bootstrap-markup modal that the
 * notifications page uses).
 *
 * Receives the rendered page as $content plus the helpers provided by
 * App\Core\View: $e, $asset, $route, $csrf_meta, $can.
 *
 * @var callable(mixed): string $e
 * @var callable(string): string $asset
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(string): bool $can
 * @var callable(): string $csrf_field
 * @var string $csrf_meta
 * @var string $content
 * @var string $title
 * @var array<string, mixed>|null $auth_user
 * @var string|null $current_route Route name of the page being rendered.
 */

$title = $title ?? 'SyncClass';
$authUser = $auth_user ?? null;
$currentRoute = $current_route ?? '';

/**
 * Mark the nav link that owns the current page. Prefixes are matched on dot
 * boundaries so `master.departments.index` lights up Master data but
 * `reports.index` does not light up a hypothetical `reportsomething`.
 *
 * @param array<int, string> $prefixes
 */
$navCurrent = static function (array $prefixes) use ($currentRoute): string {
    foreach ($prefixes as $prefix) {
        if ($currentRoute === $prefix || str_starts_with($currentRoute, $prefix . '.')) {
            return ' aria-current="page"';
        }
    }

    return '';
};

/**
 * The Master data screens the signed-in user may open, filtered by the same
 * permission their routes enforce. Shared with app/Views/master/_nav.php
 * through _nav-links.php.
 *
 * @var array<int, array{route: string, permission: string, label: string}> $masterLinks
 */
$masterLinks = is_array($authUser)
    ? array_values(array_filter(
        require __DIR__ . '/../master/_nav-links.php',
        static fn (array $link): bool => $can($link['permission'])
    ))
    : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#8c1d40">
    <title><?= $e($title) ?> · SyncClass</title>
    <?= $csrf_meta ?>
    <link rel="stylesheet" href="<?= $e($asset('css/app.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
<header class="topbar">
    <a class="brand" href="<?= $e($route('home')) ?>">
        <span class="brand-mark" aria-hidden="true">SC</span>
        <span class="brand-name">SyncClass</span>
        <span class="brand-sub">Classroom &amp; Subject Scheduler</span>
    </a>

    <?php if (is_array($authUser)): ?>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav">
            <span class="nav-toggle-bars" aria-hidden="true"></span>
            Menu
        </button>
    <?php endif; ?>

    <nav class="topnav" id="primary-nav" aria-label="Primary">
        <a href="<?= $e($route('home')) ?>"<?= $navCurrent(['home']) ?>>Overview</a>

        <?php if (is_array($authUser) && $can('schedules.view')): ?>
            <a href="<?= $e($route('schedules.index')) ?>"<?= $navCurrent(['schedules']) ?>>Schedules</a>
        <?php endif; ?>

        <?php if (is_array($authUser) && $can('schedules.view')): ?>
            <a href="<?= $e($route('calendar.index')) ?>"<?= $navCurrent(['calendar']) ?>>Calendar</a>
        <?php endif; ?>

        <?php if (is_array($authUser) && $can('reports.view')): ?>
            <a href="<?= $e($route('reports.index')) ?>"<?= $navCurrent(['reports']) ?>>Reports</a>
        <?php endif; ?>

        <?php if ($masterLinks !== []): ?>
            <div class="nav-menu" data-nav-menu data-open="false">
                <button class="nav-menu-toggle<?= $navCurrent(['master']) !== '' ? ' is-current' : '' ?>"
                        type="button" aria-expanded="false" aria-controls="master-menu">
                    Master data
                    <span class="nav-menu-caret" aria-hidden="true"></span>
                </button>
                <ul class="nav-menu-list" id="master-menu" hidden>
                    <?php foreach ($masterLinks as $link): ?>
                        <?php
                        // Prefix match so the item also lights up on the
                        // family's create/edit screens (master.subjects.edit, …).
                        $prefix = substr($link['route'], 0, -strlen('.index'));
                        ?>
                        <li>
                            <a href="<?= $e($route($link['route'])) ?>"<?= $navCurrent([$prefix]) ?>><?= $e($link['label']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (is_array($authUser) && $can('users.view')): ?>
            <a href="<?= $e($route('admin.users.index')) ?>"<?= $navCurrent(['admin']) ?>>People</a>
        <?php endif; ?>

        <a href="<?= $e($route('health')) ?>"<?= $navCurrent(['health']) ?>>Health</a>

        <?php if (is_array($authUser)): ?>
            <a class="user-chip" href="<?= $e($route('account.password.edit')) ?>" title="Change your password">
                <span class="user-name"><?= $e($authUser['first_name'] . ' ' . $authUser['last_name']) ?></span>
                <span class="user-role"><?= $e($authUser['role_name'] ?? '') ?></span>
            </a>
            <form class="logout-form" method="post" action="<?= $e($route('logout')) ?>">
                <?= $csrf_field() ?>
                <button type="submit" class="link-button">Sign out</button>
            </form>
        <?php else: ?>
            <a href="<?= $e($route('login')) ?>">Sign in</a>
        <?php endif; ?>
    </nav>
</header>

<main class="shell" id="main" tabindex="-1">
    <?= $content ?>
</main>

<footer class="footer">
    <span>SyncClass 1.1.0-dev · native PHP · MySQL</span>
    <span>Pipeline: Request → Router → Middleware → Controller → View → Response</span>
</footer>

<script src="<?= $e($asset('js/app.js')) ?>" defer></script>
</body>
</html>
