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
