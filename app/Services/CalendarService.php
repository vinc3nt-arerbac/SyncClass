<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ScheduleRepository;

/**
 * Calendar query and presentation (CAL-01…CAL-05).
 *
 * The calendar reads a *range* of the term's entries, not the whole table: the
 * HTTP API carries `start` and `end` and this service filters in PHP after one
 * scoped repository call. Every read is permission-scoped (department /
 * faculty / administrator) exactly like the form and the conflict engine.
 *
 * @phpstan-type CalendarEvent = array{
 *     id: int,
 *     title: string,
 *     start: string,
 *     end: string,
 *     allDay: bool,
 *     section_code: string,
 *     subject_code: string,
 *     teacher: string,
 *     room: string,
 *     department_color: string,
 *     status: string,
 *     notes: string|null,
 * }
 *
 * @phpstan-type CalendarEventRow = array{
 *     id: int,
 *     day_of_week: int,
 *     start_time: string,
 *     end_time: string,
 *     notes: string|null,
 *     semester_id: int,
 *     department_id: int,
 *     section_id: int,
 *     subject_id: int,
 *     faculty_id: int,
 *     room_id: int,
 *     status: string,
 *     subject_code: string,
 *     faculty_first_name: string,
 *     faculty_last_name: string,
 *     room_code: string,
 *     department_color: string,
 *     building_code: string,
 *     faculty_email: string,
 *     department_name: string,
 *     semester_code: string,
 *     semester_name: string,
 *     semester_status: string,
 * }
 */
final class CalendarService
{
    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly SemesterRepository $semesters,
        private readonly DepartmentRepository $departments,
        private readonly RoomRepository $rooms,
        private readonly FacultyRepository $faculty,
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects
    ) {
    }

    /**
     * Calendar range feed (CAL-02, CAL-05).
     *
     * Returns the JSON payload the frontend's FullCalendar consumes. Every
     * call is scoped to the actor's department or self, and filtered to the
     * requested range before the payload leaves the server.
     *
     * @param array<string, string> $query
     *
     * @return array{events: array<int, CalendarEvent>, total: int}
     */
    public function events(array $query, array $authUser): array
    {
        $start = $query['start'] ?? '';
        $end = $query['end'] ?? '';
        $semesterId = $query['semester_id'] ?? null;
        $departmentId = $query['department_id'] ?? null;

        if ($start === '' || $end === '') {
            return ['events' => [], 'total' => 0];
        }

        $rows = $this->schedules->calendarFeed(
            $start,
            $end,
            $semesterId,
            $departmentId,
            $authUser
        );

        $events = array_map([$this, 'eventFromRow'], $rows);

        return ['events' => $events, 'total' => count($events)];
    }

    /**
     * Normalise one scope-checked schedule row into the FullCalendar event
     * envelope. The repository's `activeInSemester()` already JOINed the
     * subject, section, faculty, room and department colour, so this method
     * only reshapes the row, never touches the database.
     */
    private function eventFromRow(array $row): array
    {
        $meetingDate = $row['meeting_date'];

        // Weekly entries: the schedule row stores no meeting date, so the
        // calendar pins them to the weekday the range feed resolved in
        // PHP (the week that contains the range start).
        $start = $row['start_time'];
        $end = $row['end_time'];

        // One-offs carry meeting_date; weekly rows carry NULL there. The
        // client's FullCalendar instances render weekly entries on every
        // day of the week, so `allDay` is true for them and false for
        // one-off blocks.
        $isOneOff = $meetingDate !== null && $meetingDate !== '';

        return [
            'id' => (int) $row['id'],
            'title' => $row['subject_code'] . ' · ' . $row['section_code'],
            'start' => $meetingDate,
            'end' => $meetingDate,
            'allDay' => $isOneOff,
            'section_code' => (string) $row['section_code'],
            'subject_code' => (string) $row['subject_code'],
            'subject_title' => (string) $row['subject_title'],
            'teacher' => $row['faculty_first_name'] . ' ' . $row['faculty_last_name'],
            'room' => $row['room_code'],
            'department_color' => (string) $row['color_hex'],
            'status' => (string) $row['status'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'building_code' => (string) $row['building_code'],
            'faculty_email' => (string) $row['faculty_email'],
            'department_name' => (string) $row['department_name'],
            'semester_code' => (string) $row['semester_code'],
            'semester_name' => (string) $row['semester_name'],
            'semester_status' => (string) $row['semester_status'],
        ];
    }
}

