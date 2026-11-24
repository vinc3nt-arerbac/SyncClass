<?php

/**
 * DepartmentSeeder
 *
 * Seeds 5 academic departments with realistic data including
 * dean email contacts and department color codes.
 *
 * @package Database\Seeders
 */

namespace Database\Seeders;

use App\Models\Department;

class DepartmentSeeder
{
    /**
     * Run the database seed.
     *
     * @return void
     */
    public function run()
    {
        $departments = [
            [
                'code'        => 'BSIT',
                'name'        => 'Bachelor of Science in Information Technology',
                'short_name'  => 'BSIT',
                'color_hex'   => '#1A73E8',
                'email'       => 'bsit@sac.edu.ph',
                'phone'       => '+63 2 123 4567',
                'is_active'   => true,
            ],
            [
                'code'        => 'BSBA',
                'name'        => 'Bachelor of Science in Business Administration',
                'short_name'  => 'BSBA',
                'color_hex'   => '#34A853',
                'email'       => 'bsba@sac.edu.ph',
                'phone'       => '+63 2 234 5678',
                'is_active'   => true,
            ],
            [
                'code'        => 'BSED',
                'name'        => 'Bachelor of Secondary Education',
                'short_name'  => 'BSED',
                'color_hex'   => '#FB8C00',
                'email'       => 'bed@sac.edu.ph',
                'phone'       => '+63 2 345 6789',
                'is_active'   => true,
            ],
            [
                'code'        => 'BSMA',
                'name'        => 'Bachelor of Science in Mathematics',
                'short_name'  => 'BSMA',
                'color_hex'   => '#E040FB',
                'email'       => 'bsma@sac.edu.ph',
                'phone'       => '+63 2 456 7890',
                'is_active'   => true,
            ],
            [
                'code'        => 'BSTM',
                'name'        => 'Bachelor of Science in Tourism Management',
                'short_name'  => 'BSTM',
                'color_hex'   => '#5C6BC0',
                'email'       => 'bstm@sac.edu.ph',
                'phone'       => '+63 2 567 8901',
                'is_active'   => true,
            ],
        ];

        foreach ($departments as $dept) {
            Department::firstOrCreate(
                ['code' => $dept['code']],
                $dept
            );
        }

        echo "5 departments seeded successfully\n";
    }
}