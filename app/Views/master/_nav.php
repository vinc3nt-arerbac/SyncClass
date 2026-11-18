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

$links = [
    ['route' => 'master.departments.index', 'permission' => 'departments.view', 'label' => 'Departments'],
    ['route' => 'master.buildings.index', 'permission' => 'rooms.view', 'label' => 'Buildings'],
    ['route' => 'master.rooms.index', 'permission' => 'rooms.view', 'label' => 'Rooms'],
    ['route' => 'master.subjects.index', 'permission' => 'subjects.view', 'label' => 'Subjects'],
    ['route' => 'master.sections.index', 'permission' => 'sections.view', 'label' => 'Sections'],
    ['route' => 'master.faculty.index', 'permission' => 'faculty.view', 'label' => 'Faculty'],
    ['route' => 'master.years.index', 'permission' => 'terms.view', 'label' => 'Years'],
    ['route' => 'master.semesters.index', 'permission' => 'terms.view', 'label' => 'Semesters'],
];
?>
<nav class="subnav" aria-label="Master data">
    <?php foreach ($links as $link): ?>
        <?php if ($can($link['permission'])): ?>
            <a href="<?= $e($route($link['route'])) ?>"><?= $e($link['label']) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
