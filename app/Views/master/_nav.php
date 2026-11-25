<?php

/**
 * Master-data sub-navigation (Phase 9).
 *
 * Shared by every master-data page so the modules feel like one section. Each
 * link is gated by the permission the route itself requires: hiding a link the
 * user cannot open is a convenience, not the control (the route middleware and
 * the scope in the repository remain authoritative).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(string): bool $can
 */

$links = require __DIR__ . '/_nav-links.php';
?>
<nav class="subnav" aria-label="Master data">
    <?php foreach ($links as $link): ?>
        <?php if ($can($link['permission'])): ?>
            <a href="<?= $e($route($link['route'])) ?>"><?= $e($link['label']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
