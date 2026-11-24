<?php

/**
 * ScheduleSeeder
 *
 * Seeds 100 schedule records across subjects, rooms, faculty,
 * sections, and academic years for realistic scheduling data.
 *
 * @package Database\Seeders
 */

namespace Database\Seeders;

use App\Models\Schedule;
use App\Models\Subject;
use App\Models\Room;
use App\Models\Faculty;
use App\Models\Section;
use App\Models\AcademicYear;
use Carbon\Carbon;

class ScheduleSeeder
{
    /**
     * Run the database seed.
     *
     * @return void
     */
    public function run()
    {
        // Load related models
        $subjects = Subject::inRandomOrder()->limit(30)->get();
        $rooms = Room::inRandomOrder()->limit(20)->get();
        $faculties = Faculty::inRandomOrder()->limit(15)->get();
        $sections = Section::inRandomOrder()->limit(10)->get();
        $academicYears = AcademicYear::all();

        if ($academicYears->isEmpty()) {
            // Create fallback academic years
            $ay1 = AcademicYear::firstOrCreate(
                ['code' => 'SEM-1'],
                ['name' => '1st Semester', 'code' => 'SEM-1', 'start_date' => '2026-08-19', 'end_date' => '2026-12-18', 'is_active' => true]
            );
            $ay2 = AcademicYear::firstOrCreate(
                ['code' => 'SEM-2'],
                ['name' => '2nd Semester', 'code' => 'SEM-2', 'start_date' => '2026-01-05', 'end_date' => '2026-05-01', 'is_active' => false]
            );
            $academicYears = [$ay1, $ay2];
        }

        $daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $timeSlots = [
            '07:00-08:30', '08:30-10:00', '10:00-11:30', '11:30-13:00',
            '13:00-14:30', '14:30-16:00', '16:00-17:30', '17:30-19:00',
        ];

        $scheduleCount = 0;

        for ($i = 0; $i < 100; $i++) {
            $subject = $subjects->random();
            $room = $rooms->random();
            $faculty = $faculties->random();
            $section = $sections->random();
            $academicYear = $academicYears[array_rand($academicYears)];
            $day = $daysOfWeek[array_rand($daysOfWeek)];
            $timeSlot = $timeSlots[array_rand($timeSlots)];

            // Parse time slot
            $parts = explode('-', $timeSlot);
            $startTime = $parts[0];
            $endTime = $parts[1];

            // Get department from subject
            $deptId = $subject->department_id ?? 1;

            // Determine semester code from academic year
            $semester = $academicYear->code;

            // Check if a schedule with same combination already exists
            $existing = Schedule::where('subject_id', $subject->id)
                ->where('room_id', $room->id)
                ->where('faculty_id', $faculty->id)
                ->where('section_id', $section->id)
                ->where('academic_year_id', $academicYear->id)
                ->where('day_of_week', $day)
                ->where('start_time', $startTime)
                ->first();

            if ($existing) {
                // Slightly modify the time
                $startTime = date('H:i', strtotime($startTime . ' +' . rand(15, 90) . ' minutes'));
                $endTime = date('H:i', strtotime($endTime . ' +' . rand(15, 90) . ' minutes'));
            }

            Schedule::firstOrCreate(
                [
                    'subject_id'          => $subject->id,
                    'room_id'             => $room->id,
                    'faculty_id'          => $faculty->id,
                    'section_id'          => $section->id,
                    'academic_year_id'    => $academicYear->id,
                    'day_of_week'         => $day,
                    'start_time'          => $startTime,
                    'end_time'            => $endTime,
                    'semester'            => $semester,
                    'status'              => rand(0, 2) === 0 ? 'approved' : (rand(0, 1) === 0 ? 'pending' : 'conflict'),
                ],
                [
                    'room_id'             => $room->id,
                    'faculty_id'          => $faculty->id,
                    'section_id'          => $section->id,
                    'academic_year_id'    => $academicYear->id,
                    'day_of_week'         => $day,
                    'start_time'          => $startTime,
                    'end_time'            => $endTime,
                    'semester'            => $semester,
                    'notes'               => 'Seed data entry - ' . $subject->code . ' - ' . $section->code,
                ]
            );

            $scheduleCount++;
        }

        echo "100 schedule records seeded successfully\n";
    }
}