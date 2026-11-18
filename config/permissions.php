<?php

declare(strict_types=1);

/**
 * Permission catalogue and the role → permission matrix (Phase 8).
 *
 * This file is the *source of truth* for authorisation:
 *
 *   - `modules` documents the UI grouping (one row per module in the matrix in
 *     docs/REQUIREMENTS.md §4);
 *   - `codes` is the flattened catalogue written to the `permissions` table by
 *     `php bin/sync-rbac.php`;
 *   - `roles` is the matrix written to `role_permissions` by the same command.
 *
 * The database is what `AuthorizationService` reads at runtime, so a deployment
 * can grant or revoke a permission without touching code — but a *new* code
 * must be added here first, because the middleware refuses a permission that
 * no role holds. That is the deny-by-default rule: shipping a route guarded by
 * `permission:foo.bar` cannot silently expose it to the administrator role,
 * because "administrator" is an explicit list, not a wildcard.
 *
 * Adding a permission is therefore a two-step change:
 *   1. add it to the module below (and to the roles that should hold it);
 *   2. run `php bin/sync-rbac.php`.
 */

$modules = [
    'users' => [
        'label' => 'Users & accounts',
        'permissions' => [
            'users.view' => 'View user accounts',
            'users.create' => 'Create user accounts',
            'users.update' => 'Edit user accounts',
            'users.delete' => 'Deactivate user accounts',
        ],
    ],
    'roles' => [
        'label' => 'Roles & permissions',
        'permissions' => [
            'roles.view' => 'View roles and their permissions',
            'roles.manage' => 'Create roles and change the permission matrix',
        ],
    ],
    'departments' => [
        'label' => 'Departments',
        'permissions' => [
            'departments.view' => 'View departments',
            'departments.create' => 'Create departments',
            'departments.update' => 'Edit departments',
            'departments.delete' => 'Archive departments',
        ],
    ],
    'rooms' => [
        'label' => 'Buildings & rooms',
        'permissions' => [
            'rooms.view' => 'View buildings and rooms',
            'rooms.create' => 'Create buildings and rooms',
            'rooms.update' => 'Edit rooms and department allocations',
            'rooms.delete' => 'Archive rooms',
        ],
    ],
    'subjects' => [
        'label' => 'Subjects',
        'permissions' => [
            'subjects.view' => 'View subjects',
            'subjects.create' => 'Create subjects',
            'subjects.update' => 'Edit subjects',
            'subjects.delete' => 'Archive subjects',
        ],
    ],
    'sections' => [
        'label' => 'Sections',
        'permissions' => [
            'sections.view' => 'View class sections',
            'sections.create' => 'Create sections',
            'sections.update' => 'Edit sections',
            'sections.delete' => 'Archive sections',
        ],
    ],
    'faculty' => [
        'label' => 'Faculty profiles',
        'permissions' => [
            'faculty.view' => 'View faculty profiles',
            'faculty.create' => 'Create faculty profiles',
            'faculty.update' => 'Edit faculty profiles',
            'faculty.delete' => 'Archive faculty profiles',
        ],
    ],
    'availability' => [
        'label' => 'Faculty availability',
        'permissions' => [
            'availability.view' => 'View availability windows',
            'availability.update' => 'Edit availability windows (own department, or own profile)',
        ],
    ],
    'terms' => [
        'label' => 'Academic terms',
        'permissions' => [
            'terms.view' => 'View academic years and semesters',
            'terms.manage' => 'Create terms and set the current semester',
        ],
    ],
    'schedules' => [
        'label' => 'Schedules',
        'permissions' => [
            'schedules.view' => 'View class schedules',
            'schedules.create' => 'Create class schedules',
            'schedules.update' => 'Edit class schedules',
            'schedules.delete' => 'Cancel class schedules',
            'schedules.approve' => 'Approve or reject schedules',
        ],
    ],
    'requests' => [
        'label' => 'Change requests',
        'permissions' => [
            'requests.view' => 'View schedule change requests',
            'requests.create' => 'File a schedule change request',
            'requests.update' => 'Edit or cancel own change requests',
            'requests.approve' => 'Approve or reject change requests',
        ],
    ],
    'conflicts' => [
        'label' => 'Conflict records',
        'permissions' => [
            'conflicts.view' => 'View detected schedule conflicts',
        ],
    ],
    'generator' => [
        'label' => 'Timetable generator',
        'permissions' => [
            'generator.run' => 'Run the timetable generator',
            'generator.approve' => 'Accept a generated draft timetable',
        ],
    ],
    'reports' => [
        'label' => 'Reports & exports',
        'permissions' => [
            'reports.view' => 'View reports',
            'reports.export' => 'Export reports for the user\'s own scope',
            'reports.export.all' => 'Export reports for the whole institution',
        ],
    ],
    'notifications' => [
        'label' => 'Notifications',
        'permissions' => [
            'notifications.view' => 'Read in-app notifications',
        ],
    ],
    'settings' => [
        'label' => 'Settings & audit log',
        'permissions' => [
            'settings.manage' => 'Change system settings',
            'audit.view' => 'Read the audit log',
        ],
    ],
];

// Flatten the catalogue into `code => ['module' => …, 'name' => …]` so the CLI
// and the tests read exactly the same structure that is written to the table.
$codes = [];

foreach ($modules as $module => $definition) {
    foreach ($definition['permissions'] as $code => $name) {
        $codes[$code] = ['module' => $module, 'name' => $name];
    }
}

$all = array_keys($codes);

return [
    'modules' => $modules,

    // Runtime catalogue: what bin/sync-rbac.php inserts into `permissions`.
    'codes' => $codes,

    /**
     * Role matrix (docs/REQUIREMENTS.md §4).
     *
     * Explicit lists only: there is no "*" wildcard, no inheritance and no
     * implicit grant. Role is a ceiling; department scope is a filter applied
     * by the repositories (see App\Services\Scope).
     */
    'roles' => [
        'administrator' => [
            'name' => 'System Administrator',
            'description' => 'Full access to every module and setting.',
            'sort_order' => 10,
            'permissions' => $all,
        ],
        'dean' => [
            'name' => 'Dean',
            'description' => 'Owns the academic programme of one department and approves its timetable.',
            'sort_order' => 20,
            'permissions' => [
                'users.view',
                'departments.view', 'departments.update',
                'rooms.view',
                'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
                'sections.view', 'sections.create', 'sections.update', 'sections.delete',
                'faculty.view', 'faculty.create', 'faculty.update',
                'availability.view', 'availability.update',
                'terms.view',
                'schedules.view', 'schedules.approve',
                'requests.view', 'requests.approve',
                'conflicts.view',
                'generator.run', 'generator.approve',
                'reports.view', 'reports.export',
                'notifications.view',
            ],
        ],
        'secretary' => [
            'name' => 'Department Secretary',
            'description' => 'Builds and maintains the department timetable day to day.',
            'sort_order' => 30,
            'permissions' => [
                'users.view',
                'departments.view',
                'rooms.view', 'rooms.update',
                'subjects.view',
                'sections.view',
                'faculty.view',
                'availability.view',
                'terms.view',
                'schedules.view', 'schedules.create', 'schedules.update', 'schedules.delete',
                'requests.view', 'requests.create', 'requests.update',
                'conflicts.view',
                'generator.run',
                'reports.view', 'reports.export',
                'notifications.view',
            ],
        ],
        'faculty' => [
            'name' => 'Faculty',
            'description' => 'Reads the assigned timetable and files change requests.',
            'sort_order' => 40,
            'permissions' => [
                'users.view',
                'departments.view',
                'rooms.view',
                'subjects.view',
                'sections.view',
                'faculty.view',
                'availability.view', 'availability.update',
                'terms.view',
                'schedules.view',
                'requests.view', 'requests.create',
                'conflicts.view',
                'reports.view', 'reports.export',
                'notifications.view',
            ],
        ],
    ],
];
