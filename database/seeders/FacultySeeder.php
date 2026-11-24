<?php

/**
 * FacultySeeder
 *
 * Seeds 20 faculty members across 5 academic departments
 * with proper assignments and employee numbers.
 *
 * @package Database\Seeders
 */

namespace Database\Seeders;

use App\Models\Faculty;
use App\Models\Department;
use App\Models\User;

class FacultySeeder
{
    /**
     * Run the database seed.
     *
     * @return void
     */
    public function run()
    {
        $facultyNames = [
            ['first' => 'Maria', 'last' => 'Santos'],
            ['first' => 'Juan', 'last' => 'Dela Cruz'],
            ['first' => 'Ana', 'last' => 'Reyes'],
            ['first' => 'Pedro', 'last' => 'Pena'],
            ['first' => 'Sofia', 'last' => 'Gomez'],
            ['first' => 'Ramon', 'last' => 'Alvarez'],
            ['first' => 'Carla', 'last' => 'Vargas'],
            ['first' => 'Miguel', 'last' => 'Ortega'],
            ['first' => 'Luisa', 'last' => 'Mendoza'],
            ['first' => 'Diego', 'last' => 'Ramos'],
            ['first' => 'Elena', 'last' => 'Cruz'],
            ['first' => 'Francis', 'last' => 'Bautista'],
            ['first' => 'Rose', 'last' => 'Lim'],
            ['first' => 'Alfred', 'last' => 'Fernandez'],
            ['first' => 'Vanessa', 'last' => 'Sy'],
            ['first' => 'Karen', 'last' => 'Tan'],
            ['first' => 'Paul', 'last' => 'Lim'],
            ['first' => 'Genevieve', 'last' => 'Alvarez'],
            ['first' => 'Victor', 'last' => 'Ong'],
        ];

        $facultyEmails = [
            'msantos@sac.edu.ph', 'jdelacruz@sac.edu.ph', 'areyes@sac.edu.ph',
            'pena@sac.edu.ph', 'sgomez@sac.edu.ph', 'ralvarez@sac.edu.ph',
            'cvargas@sac.edu.ph', 'mortega@sac.edu.ph', 'lmendoza@sac.edu.ph',
            'dramos@sac.edu.ph', 'ecruz@sac.edu.ph', 'fbautista@sac.edu.ph',
            'slim@sac.edu.ph', 'afernandez@sac.edu.ph', 'vsy@sac.edu.ph',
            'ktan@sac.edu.ph', 'plim@sac.edu.ph', 'galvarez@sac.edu.ph',
            'vong@sac.edu.ph',
        ];

        $facultyDepartments = [
            0 => 'BSIT', 1 => 'BSIT', 2 => 'BSIT', 3 => 'BSIT', 4 => 'BSIT',
            5 => 'BSBA', 6 => 'BSBA', 7 => 'BSBA', 8 => 'BSBA', 9 => 'BSBA',
            10 => 'BSED', 11 => 'BSED', 12 => 'BSED', 13 => 'BSED', 14 => 'BSED',
            15 => 'BSMA', 16 => 'BSMA', 17 => 'BSMA', 18 => 'BSTM', 19 => 'BSTM',
        ];

        $facultyPositions = [
            'Professor', 'Associate Professor', 'Assistant Professor',
            'Instructor', 'Lecturer'
        ];

        // Ensure departments exist
        $departments = Department::all()->pluck('id', 'code')->toArray();

        for ($i = 0; $i < 20; $i++) {
            $deptCode = $facultyDepartments[$i];
            $department_id = $departments[$deptCode] ?? 1;

            // Find or create user for this faculty
            $email = $facultyEmails[$i];
            $existingUser = User::where('email', $email)->first();

            if ($existingUser) {
                $user = $existingUser;
            } else {
                $username = str_replace(['.', '@', ' '], '', $email);
                $user = User::create([
                    'role_id'        => 4, // faculty role
                    'department_id'  => $department_id,
                    'email'          => $email,
                    'username'       => $username,
                    'first_name'     => $facultyNames[$i]['first'],
                    'middle_name'    => '',
                    'last_name'      => $facultyNames[$i]['last'],
                    'employee_no'    => 'FAC' . sprintf('%03d', $i + 1),
                    'phone'          => '+63 2 ' . sprintf('%04d', rand(1000, 9999)),
                    'avatar_path'    => null,
                    'is_active'      => true,
                    'must_change_password' => false,
                    'failed_login_attempts' => 0,
                    'locked_until'   => null,
                    'last_login_at'  => null,
                    'password_changed_at' => null,
                ]);
            }

            // Create or update faculty record
            $faculty = Faculty::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'user_id'        => $user->id,
                    'first_name'     => $facultyNames[$i]['first'],
                    'middle_name'    => '',
                    'last_name'      => $facultyNames[$i]['last'],
                    'employee_no'    => 'FAC' . sprintf('%03d', $i + 1),
                    'phone'          => '+63 2 ' . sprintf('%04d', rand(1000, 9999)),
                    'position'       => $facultyPositions[array_rand($facultyPositions)],
                    'department_id'  => $department_id,
                    'is_active'      => true,
                    'rank'           => $i < 10 ? 'Senior' : 'Junior',
                ]
            );
        }

        echo "20 faculty members seeded successfully across 5 departments\n";
    }
}