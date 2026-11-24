<?php

/**
 * Reports sub-navigation (Phase 15).
 *
 * Shared by every report page so the module reads as one section. Each link is
 * gated by `reports.view`, the permission the routes themselves require.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 */

$links = [
    ['route' => 'reports.index', 'label' => 'Overview'],
    ['route' => 'reports.faculty-load', 'label' => 'Faculty load'],
    ['route' => 'reports.rooms', 'label' => 'Room utilization'],
    ['route' => 'reports.departments', 'label' => 'Departments'],
    ['route' => 'reports.conflicts', 'label' => 'Conflicts'],
    ['route' => 'reports.timetable', 'label' => 'Timetable'],
];
?>
<nav class="subnav" aria-label="Reports">
    <?php foreach ($links as $link): ?>
        <a href="<?= $e($route($link['route'])) ?>"><?= $e($link['label']) ?></a>
    <?php endforeach; ?>
</nav>
