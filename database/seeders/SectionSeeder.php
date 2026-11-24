<?php

/**
 * SectionSeeder
 *
 * Seeds academic sections across all 5 departments
 * with proper department associations and level information.
 *
 * @package Database\Seeders
 */

namespace Database\Seeders;

use App\Models\Section;
use App\Models\Department;

class SectionSeeder
{
    /**
     * Run the database seed.
     *
     * @return void
     */
    public function run()
    {
        // Get all departments
        $departments = Department::all()->pluck('id', 'code')->toArray();

        // Define sections per department
        $sections = [
            // BSIT sections (Year 1 & 2)
            ['code' => 'BSIT-1A', 'name' => 'BSIT Section A', 'level' => 'Year 1'],
            ['code' => 'BSIT-1B', 'name' => 'BSIT Section B', 'level' => 'Year 1'],
            ['code' => 'BSIT-2A', 'name' => 'BSIT Section A', 'level' => 'Year 2'],
            ['code' => 'BSIT-2B', 'name' => 'BSIT Section B', 'level' => 'Year 2'],

            // BSBA sections (Year 1 & 2)
            ['code' => 'BSBA-1A', 'name' => 'BSBA Section A', 'level' => 'Year 1'],
            ['code' => 'BSBA-1B', 'name' => 'BSBA Section B', 'level' => 'Year 1'],
            ['code' => 'BSBA-2A', 'name' => 'BSBA Section A', 'level' => 'Year 2'],

            // BSED sections (Year 1)
            ['code' => 'BSED-1A', 'name' => 'BSED Section A', 'level' => 'Year 1'],
            ['code' => 'BSED-1B', 'name' => 'BSED Section B', 'level' => 'Year 1'],

            // BSMA sections (Year 1)
            ['code' => 'BSMA-1A', 'name' => 'BSMA Section A', 'level' => 'Year 1'],

            // BSTM sections (Year 1)
            ['code' => 'BSTM-1A', 'name' => 'BSTM Section A', 'level' => 'Year 1'],
        ];

        $seeded = 0;

        foreach ($sections as $section) {
            $deptCode = substr($section['code'], 0, 4); // extract dept code from section code
            $department_id = $departments[$deptCode] ?? 1;

            Section::firstOrCreate(
                ['code' => $section['code']],
                [
                    'name'          => $section['name'],
                    'department_id' => $department_id,
                    'level'         => $section['level'],
                    'is_active'     => true,
                ]
            );

            $seeded++;
        }

        echo count($sections) . " sections seeded successfully across " . count($departments) . " departments\n";
    }
}