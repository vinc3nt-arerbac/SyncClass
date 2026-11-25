<?php

/**
 * Master-data navigation links — one source of truth.
 *
 * Read by the master-data sub-navigation (app/Views/master/_nav.php) and by the
 * top-bar "Master data" disclosure menu (app/Views/layouts/app.php), so the two
 * never drift apart. Each entry names the route it opens, the permission that
 * route itself requires, and the label it shows.
 *
 * @return array<int, array{route: string, permission: string, label: string}>
 */

return [
    ['route' => 'master.departments.index', 'permission' => 'departments.view', 'label' => 'Departments'],
    ['route' => 'master.buildings.index', 'permission' => 'rooms.view', 'label' => 'Buildings'],
    ['route' => 'master.rooms.index', 'permission' => 'rooms.view', 'label' => 'Rooms'],
    ['route' => 'master.subjects.index', 'permission' => 'subjects.view', 'label' => 'Subjects'],
    ['route' => 'master.sections.index', 'permission' => 'sections.view', 'label' => 'Sections'],
    ['route' => 'master.faculty.index', 'permission' => 'faculty.view', 'label' => 'Faculty'],
    ['route' => 'master.years.index', 'permission' => 'terms.view', 'label' => 'Academic years'],
    ['route' => 'master.semesters.index', 'permission' => 'terms.view', 'label' => 'Semesters'],
];
