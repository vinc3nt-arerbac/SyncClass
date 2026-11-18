<?php

/**
 * Authentication layout (Phase 7).
 *
 * Deliberately separate from layouts/app.php: the login page must not show the
 * application navigation to a visitor who is not signed in.
 *
 * @var callable(mixed): string $e
 * @var callable(string): string $asset
 * @var string $csrf_meta
 * @var string $content
 * @var string $title
 */

$title = $title ?? 'Sign in';
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
<body class="auth-body">
<main class="auth-shell">
    <div class="auth-brand">
        <span class="brand-mark" aria-hidden="true">SC</span>
        <span>
            <strong>SyncClass</strong>
            <small>Classroom &amp; Subject Scheduler</small>
        </span>
    </div>

    <?= $content ?>

    <p class="auth-foot">Santa Ana College · Academic Scheduling Office</p>
</main>
</body>
</html>
