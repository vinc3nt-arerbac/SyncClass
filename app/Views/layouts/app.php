<?php

/**
 * Base layout (Phase 6).
 *
 * Receives the rendered page as $content plus the helpers provided by
 * App\Core\View: $e, $asset, $route, $csrf_meta. Phase 17 replaces this shell
 * with the sidebar/topbar design system; the structure — document, header,
 * main, footer — is what later phases build on.
 *
 * @var callable(mixed): string $e
 * @var callable(string): string $asset
 * @var callable(string, array<string, mixed>): string $route
 * @var string $csrf_meta
 * @var string $content
 * @var string $title
 */

$title = $title ?? 'SyncClass';
$authUser = $auth_user ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · SyncClass</title>
    <?= $csrf_meta ?>
    <link rel="stylesheet" href="<?= $e($asset('css/app.css')) ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= $e($route('home')) ?>">
        <span class="brand-mark" aria-hidden="true">SC</span>
        <span class="brand-name">SyncClass</span>
        <span class="brand-sub">Classroom &amp; Subject Scheduler</span>
    </a>
    <nav class="topnav" aria-label="Primary">
        <a href="<?= $e($route('home')) ?>">Overview</a>
        <a href="<?= $e($route('health')) ?>">Health</a>

        <?php if (is_array($authUser)): ?>
            <span class="user-chip">
                <span class="user-name"><?= $e($authUser['first_name'] . ' ' . $authUser['last_name']) ?></span>
                <span class="user-role"><?= $e($authUser['role_name'] ?? '') ?></span>
            </span>
            <form class="logout-form" method="post" action="<?= $e($route('logout')) ?>">
                <?= $csrf_field() ?>
                <button type="submit" class="link-button">Sign out</button>
            </form>
        <?php else: ?>
            <a href="<?= $e($route('login')) ?>">Sign in</a>
        <?php endif; ?>
    </nav>
</header>

<main class="shell">
    <?= $content ?>
</main>

<footer class="footer">
    <span>SyncClass 1.0.0-dev · native PHP · MySQL</span>
    <span>Pipeline: Request → Router → Middleware → Controller → View → Response</span>
</footer>
</body>
</html>
