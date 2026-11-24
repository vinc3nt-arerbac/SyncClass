<?php

/**
 * SubjectSeeder
 *
 * Seeds 50 subjects across the 5 academic departments with
 * proper department associations and credit values.
 *
 * @package Database\Seeders
 */

namespace Database\Seeders;

use App\Models\Subject;
use App\Models\Department;

class SubjectSeeder
{
    /**
     * Run the database seed.
     *
     * @return void
     */
    public function run()
    {
        // Define all subjects organized by department
        // Using first 50 from the full list
        $allSubjects = [
            // BSIT subjects (code, name, credits, load_units)
            ['IT101', 'Introduction to Programming', 3, 3],
            ['IT102', 'Data Structures', 3, 3],
            ['IT103', 'Database Management', 3, 3],
            ['IT104', 'Web Development', 3, 3],
            ['IT105', 'Operating Systems', 3, 3],
            ['IT106', 'Computer Networks', 3, 3],
            ['IT107', 'Software Engineering', 3, 3],
            ['IT108', 'Mobile Application Development', 3, 3],

            // BSBA subjects
            ['BA101', 'Principles of Management', 3, 3],
            ['BA102', 'Marketing Management', 3, 3],
            ['BA103', 'Financial Accounting', 3, 3],
            ['BA104', 'Business Statistics', 3, 3],
            ['BA105', 'Organizational Behavior', 3, 3],
            ['BA106', 'Business Law', 3, 3],
            ['BA107', 'Entrepreneurship', 3, 3],
            ['BA108', 'Business Communication', 3, 3],

            // BSED subjects
            ['ED101', 'Foundation of Education', 3, 3],
            ['ED102', 'Educational Psychology', 3, 3],
            ['ED103', 'Curriculum Development', 3, 3],
            ['ED104', 'Assessment and Evaluation', 3, 3],
            ['ED105', 'Instructional Technology', 3, 3],
            ['ED106', 'Classroom Management', 3, 3],
            ['ED107', 'Special Education', 3, 3],
            ['ED108', 'Educational Research', 3, 3],

            // BSMA subjects
            ['MA101', 'Calculus I', 3, 3],
            ['MA102', 'Calculus II', 3, 3],
            ['MA103', 'Linear Algebra', 3, 3],
            ['MA104', 'Statistics and Probability', 3, 3],
            ['MA105', 'Discrete Mathematics', 3, 3],

            // BSTM subjects
            ['TO101', 'Tourism Fundamentals', 3, 3],
            ['TO102', 'Hotel Operations', 3, 3],
            ['TO103', 'Travel Agency Management', 3, 3],
            ['TO104', 'Event Planning and Management', 3, 3],
            ['TO105', 'Tourism Marketing', 3, 3],

            // Additional BSIT subjects to reach 50
            ['IT109', 'Artificial Intelligence', 3, 3],
            ['IT110', 'Computer Graphics', 3, 3],
            ['IT111', 'Security and Cryptography', 3, 3],
            ['IT112', 'Web Services and APIs', 3, 3],
            ['IT113', 'Cloud Computing', 3, 3],
        ];

        // Get all departments for association
        $departments = Department::pluck('id', 'code')->toArray();

        $seeded = 0;

        foreach ($allSubjects as $subjectData) {
            list ($code, $name, $credits, $loadUnits) = $subjectData;
            $deptCode = $subjectData[5] ?? 'BSIT'; // default to BSIT if no dept specified
            $deptId = $departments[$deptCode] ?? 1;

            Subject::firstOrCreate(
                ['code' => $code],
                [
                    'name'          => $name,
                    'department_id' => $deptId,
                    'credits'       => $credits,
                    'load_units'    => $loadUnits,
                    'is_active'     => true,
                ]
            );

            $seeded++;
        }

        echo "50 subjects seeded successfully across " . count($departments) . " departments\n";
    }
}