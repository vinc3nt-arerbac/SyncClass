<?php

/**
 * HTTP error page (Phase 6).
 *
 * Rendered by the Kernel for expected client-side failures (404, 405, 419,
 * 422…). The message is user-facing by construction — HttpException never
 * carries stack traces — and is escaped like any other dynamic value.
 *
 * @var callable(mixed): string $e
 * @var callable(string): string $route
 * @var int $status
 * @var string $message
 * @var array<string, string> $errors
 */
?>
<section class="hero error">
    <p class="eyebrow">HTTP <?= $e($status) ?></p>
    <h1><?= $e($message) ?></h1>

    <?php if ($errors !== []): ?>
        <ul class="field-errors">
            <?php foreach ($errors as $field => $error): ?>
                <li><strong><?= $e($field) ?></strong> — <?= $e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="actions">
        <a class="button" href="<?= $e($route('home')) ?>">Back to overview</a>
        <a class="button button-ghost" href="<?= $e($route('health')) ?>">Health check</a>
    </div>

    <p class="hint">
        If you followed a link and expected something else, the route table on the overview page lists
        every URL this build serves.
    </p>
</section>
