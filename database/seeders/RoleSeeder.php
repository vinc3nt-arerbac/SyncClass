<?php

/**
 * RoleSeeder
 *
 * Seeds the 4 user roles with complete permission matrix per RBAC requirements.
 * Roles: admin, dean, secretary, faculty
 * Each role gets appropriate permissions for their scope.
 *
 * @package Database\Seeders
 */

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;

class RoleSeeder
{
    /**
     * Run the database seed.
     *
     * @return void
     */
    public function run()
    {
        // Define 4 role codes with names and descriptions
        $roleDefinitions = [
            'admin'    => ['System Administrator',    'Full access to all system features and administration'],
            'dean'     => ['Dean',                      'Can manage teachers, subjects, department schedules and approvals'],
            'secretary'=> ['Department Secretary',        'Can manage class schedules, room assignments, and notifications'],
            'faculty'  => ['Faculty',                    'Can view assigned schedules and own profile'],
        ];

        // Define all permissions organized by module
        // Format: 'code' => ['name', 'module']
        $allPermissions = [
            // Admin module permissions
            'admin.users.create'  => ['Create users',        'admin'],
            'admin.users.edit'    => ['Edit users',          'admin'],
            'admin.users.delete'  => ['Delete users',        'admin'],
            'admin.rooms.create'  => ['Create rooms',        'admin'],
            'admin.rooms.edit'    => ['Edit rooms',          'admin'],
            'admin.subjects.create'=> ['Create subjects',     'admin'],
            'admin.subjects.edit' => ['Edit subjects',       'admin'],
            'admin.departments.create'=> ['Create departments', 'admin'],
            'admin.departments.edit'=> ['Edit departments',    'admin'],
            'admin.schedules.create'=> ['Create schedules',   'admin'],
            'admin.schedules.edit'  => ['Edit schedules',      'admin'],
            'admin.reports.view'    => ['View reports',        'admin'],
            'admin.settings.edit'   => ['Edit settings',       'admin'],

            // Dean module permissions
            'dean.faculty.manage' => ['Manage faculty',      'dean'],
            'dean.subjects.manage'=> ['Manage subjects',     'dean'],
            'dean.schedules.approve'=> ['Approve schedules',  'dean'],
            'dean.reports.view'   => ['View reports',        'dean'],

            // Secretary module permissions
            'secretary.schedules.create'=> ['Create schedules',    'secretary'],
            'secretary.schedules.edit'  => ['Edit schedules',      'secretary'],
            'secretary.rooms.manage'    => ['Manage rooms',        'secretary'],
            'secretary.notifications.view'=> ['View notifications', 'secretary'],

            // Faculty module permissions
            'faculty.schedules.view' => ['View own schedule',   'faculty'],
            'faculty.profile.view'   => ['View own profile',    'faculty'],
        ];

        // Create roles and assign permissions
        foreach ($roleDefinitions as $code => [$name, $description]) {
            // Create or find the role
            $role = Role::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'is_system' => true]
            );

            // Assign permissions based on role scope
            foreach ($allPermissions as $permCode => [$permName, $module]) {
                $grantPermission = false;

                // Admin gets all permissions
                if ($code === 'admin') {
                    $grantPermission = true;
                }
                // Dean gets dean-scoped permissions
                elseif ($code === 'dean' && $module === 'dean') {
                    $grantPermission = true;
                }
                // Secretary gets secretary-scoped permissions
                elseif ($code === 'secretary' && $module === 'secretary') {
                    $grantPermission = true;
                }
                // Faculty gets faculty-scoped permissions
                elseif ($code === 'faculty' && $module === 'faculty') {
                    $grantPermission = true;
                }

                if ($grantPermission) {
                    $permission = Permission::firstOrCreate(
                        ['code' => $permCode],
                        ['name' => $permName, 'module' => $module]
                    );
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        }

        echo "Roles and permissions seeded: 4 roles with " . count($allPermissions) . " permission assignments\n";
    }
}