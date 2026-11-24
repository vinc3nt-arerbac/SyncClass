<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Scope;

/**
 * Read models for the dashboard (Phase 16, DASH-01…DASH-03).
 *
 * The dashboard is a set of aggregates, not a CRUD screen, so this repository
 * exposes one query per widget instead of a generic finder. Two rules hold for
 * every statement here:
 *
 *   1. scope is applied in SQL, never in the template. The administrator sees
 *      the institution, a dean or secretary their department, and faculty only
 *      the meetings where they are the assigned teacher;
 *   2. cancelled meetings are excluded, because a cancelled class frees its
 *      room and its teacher (the same rule the reports module follows).
 *
 * Unlike ReportRepository this file deliberately avoids MySQL-only functions
 * (`TIME_TO_SEC`, `TIMEDIFF`, `FIELD`). The dashboard only needs counts and raw
 * `H:i:s` boundaries, which the service turns into hours in PHP; that keeps the
 * queries portable to the SQLite mirror the test suite uses.
 */
final class AnalyticsRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * The four inventory numbers the role-aware widget row shows.
     *
     * Teachers are active faculty profiles inside the scope. Subjects are the
     * active catalogue for an administrator, the department's subjects for a
     * dean or secretary, and the subjects a teacher actually teaches in the
     * selected term. Rooms and sections follow the same audience: the whole
     * active inventory for the administrator, and the ones the department (or
     * the teacher) actually uses in the term for everyone else. A departmental
     * or faculty scope with no term therefore reports zero rooms and sections —
     * "nothing scheduled yet", which is the truthful answer.
     *
     * @return array{teachers: int, subjects: int, rooms: int, sections: int}
     */
    public function counts(Scope $scope, ?int $semesterId): array
    {
        $teacherBindings = [];
        $teacherScope = $this->scopeSql($scope, $teacherBindings, 'f.department_id', 'f.user_id');

        $teachers = (int) $this->database->scalar(
            "SELECT COUNT(*)
               FROM faculty_profiles f
               INNER JOIN users u ON u.id = f.user_id
              WHERE f.is_active = 1
                AND {$teacherScope}",
            $teacherBindings
        );

        return [
            'teachers' => $teachers,
            'subjects' => $this->subjectCount($scope, $semesterId),
            'rooms' => $this->resourceCount($scope, $semesterId, 's.room_id'),
            'sections' => $this->resourceCount($scope, $semesterId, 's.section_id'),
        ];
    }

    /**
     * Department statistics for the chart and the department widget.
     *
     * One row per department that has at least one visible meeting in the term.
     * A departmental or faculty scope produces one row, because the scope
     * clause already narrowed the schedules to their department.
     *
     * @return array<int, array<string, mixed>>
     */
    public function departmentStatistics(Scope $scope, int $semesterId): array
    {
        $bindings = ['semester_id' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        return $this->database->select(
            "SELECT d.id AS department_id, d.code AS department_code, d.name AS department_name,
                    d.color_hex,
                    COUNT(*) AS meetings,
                    COUNT(DISTINCT s.faculty_id) AS teachers,
                    COUNT(DISTINCT s.room_id) AS rooms
               FROM schedules s
               INNER JOIN departments d ON d.id = s.department_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
              WHERE s.semester_id = :semester_id
                AND s.status <> 'cancelled'
                AND {$scopeSql}
              GROUP BY d.id, d.code, d.name, d.color_hex
              ORDER BY meetings DESC, d.code",
            $bindings
        );
    }

    /**
     * The meetings that happen on one calendar date.
     *
     * A weekly meeting belongs to the date when its `day_of_week` matches; a
     * one-off belongs to its `meeting_date`, whatever weekday that is. Both
     * shapes live in the same table (SCH-04), so one predicate selects them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function classesOn(Scope $scope, int $semesterId, int $dayOfWeek, string $date): array
    {
        $bindings = [
            'semester_id' => $semesterId,
            'day_of_week' => $dayOfWeek,
            'meeting_date' => $date,
        ];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        return $this->database->select(
            'SELECT s.id, s.start_time, s.end_time, s.recurrence, s.meeting_date, s.status,
                    sub.code AS subject_code, sub.title AS subject_title,
                    sec.code AS section_code, sec.student_count,
                    r.code AS room_code, r.name AS room_name,
                    b.code AS building_code,
                    u.first_name AS faculty_first_name, u.last_name AS faculty_last_name,
                    d.code AS department_code, d.color_hex
               FROM schedules s
               INNER JOIN subjects sub ON sub.id = s.subject_id
               INNER JOIN sections sec ON sec.id = s.section_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
               INNER JOIN users u ON u.id = f.user_id
               INNER JOIN rooms r ON r.id = s.room_id
               INNER JOIN buildings b ON b.id = r.building_id
               INNER JOIN departments d ON d.id = s.department_id
              WHERE s.semester_id = :semester_id
                AND s.status <> \'cancelled\'
                AND (
                      (s.recurrence = \'weekly\' AND s.day_of_week = :day_of_week)
                   OR (s.recurrence = \'one_off\' AND s.meeting_date = :meeting_date)
                )
                AND ' . $scopeSql . '
              ORDER BY s.start_time, sub.code',
            $bindings
        );
    }

    /**
     * Every meeting that can occur inside a seven-day window.
     *
     * The query returns the weekly rows for the weekdays in `$days` plus every
     * one-off whose date falls inside the range. Expanding those rows onto
     * actual dates is calendar arithmetic, so the service owns it (the same
     * division of labour as the reports timetable).
     *
     * @param array<int, int> $days ISO weekday numbers present in the window
     * @return array<int, array<string, mixed>>
     */
    public function meetingsBetween(Scope $scope, int $semesterId, array $days, string $start, string $end): array
    {
        $bindings = [
            'semester_id' => $semesterId,
            'range_start' => $start,
            'range_end' => $end,
        ];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        $placeholders = [];

        foreach (array_values(array_unique(array_filter($days, static fn (int $day): bool => $day >= 1 && $day <= 7))) as $index => $day) {
            $key = 'day_' . $index;
            $bindings[$key] = $day;
            $placeholders[] = ':' . $key;
        }

        $daySql = $placeholders === [] ? '0 = 1' : 's.day_of_week IN (' . implode(', ', $placeholders) . ')';

        return $this->database->select(
            'SELECT s.id, s.day_of_week, s.start_time, s.end_time, s.recurrence, s.meeting_date,
                    sub.code AS subject_code, sub.title AS subject_title,
                    sec.code AS section_code,
                    r.code AS room_code, b.code AS building_code,
                    u.first_name AS faculty_first_name, u.last_name AS faculty_last_name,
                    d.code AS department_code, d.color_hex
               FROM schedules s
               INNER JOIN subjects sub ON sub.id = s.subject_id
               INNER JOIN sections sec ON sec.id = s.section_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
               INNER JOIN users u ON u.id = f.user_id
               INNER JOIN rooms r ON r.id = s.room_id
               INNER JOIN buildings b ON b.id = r.building_id
               INNER JOIN departments d ON d.id = s.department_id
              WHERE s.semester_id = :semester_id
                AND s.status <> \'cancelled\'
                AND (
                      (s.recurrence = \'weekly\' AND ' . $daySql . ')
                   OR (s.recurrence = \'one_off\' AND s.meeting_date BETWEEN :range_start AND :range_end)
                )
                AND ' . $scopeSql . '
              ORDER BY s.start_time, sub.code',
            $bindings
        );
    }

    /**
     * Counts of persisted conflicts by status for the selected term.
     *
     * @return array{open: int, resolved: int, ignored: int, total: int}
     */
    public function conflictCounts(Scope $scope, int $semesterId): array
    {
        $bindings = ['semester_id' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        $rows = $this->database->select(
            "SELECT c.status, COUNT(*) AS total
               FROM schedule_conflicts c
               INNER JOIN schedules s ON s.id = c.primary_schedule_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
              WHERE c.semester_id = :semester_id
                AND {$scopeSql}
              GROUP BY c.status",
            $bindings
        );

        $counts = ['open' => 0, 'resolved' => 0, 'ignored' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $total = (int) ($row['total'] ?? 0);

            if (isset($counts[$status])) {
                $counts[$status] = $total;
            }

            $counts['total'] += $total;
        }

        return $counts;
    }

    /**
     * Active subjects visible to the scope.
     *
     * The administrator and a department see the catalogue rows that belong to
     * their department; a teacher sees the subjects they teach in the selected
     * term. `$semesterId` is only used for the faculty branch, because a
     * teacher's catalogue is defined by what is on their timetable.
     */
    private function subjectCount(Scope $scope, ?int $semesterId): int
    {
        if ($scope->isSelfOnly()) {
            if ($semesterId === null) {
                return 0;
            }

            return (int) $this->database->scalar(
                "SELECT COUNT(DISTINCT s.subject_id)
                   FROM schedules s
                   INNER JOIN faculty_profiles f ON f.id = s.faculty_id
                  WHERE s.semester_id = :semester_id
                    AND s.status <> 'cancelled'
                    AND f.user_id = :scope_user_id",
                ['semester_id' => $semesterId, 'scope_user_id' => $scope->userId()]
            );
        }

        $bindings = [];
        $where = 'sub.is_active = 1';

        if ($scope->isDepartmental()) {
            $bindings['scope_department_id'] = $scope->departmentId() ?? 0;
            $where .= ' AND sub.department_id = :scope_department_id';
        }

        return (int) $this->database->scalar(
            "SELECT COUNT(*) FROM subjects sub WHERE {$where}",
            $bindings
        );
    }

    /**
     * Distinct rooms or sections the scope actually uses in the term.
     *
     * The administrator gets the active inventory (rooms) or every active
     * section; a department and a teacher get what their schedules touch. The
     * column name is one of two constants passed by `counts()`, never request
     * input, so interpolating it cannot be injected.
     */
    private function resourceCount(Scope $scope, ?int $semesterId, string $column): int
    {
        if ($scope->isAdministrator()) {
            $table = $column === 's.room_id' ? 'rooms' : 'sections';

            return (int) $this->database->scalar(
                "SELECT COUNT(*) FROM {$table} t WHERE t.is_active = 1"
            );
        }

        if ($semesterId === null) {
            return 0;
        }

        $bindings = ['semester_id' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        return (int) $this->database->scalar(
            "SELECT COUNT(DISTINCT {$column})
               FROM schedules s
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
              WHERE s.semester_id = :semester_id
                AND s.status <> 'cancelled'
                AND {$scopeSql}",
            $bindings
        );
    }

    /**
     * The one scope predicate every query above shares.
     *
     * `$departmentColumn` carries the row's department and `$facultyUserColumn`
     * carries the teacher's user id; callers pick them per table. Bindings are
     * collected here because PDO cannot reuse one named placeholder twice.
     *
     * @param array<string, mixed> $bindings
     */
    private function scopeSql(Scope $scope, array &$bindings, string $departmentColumn, string $facultyUserColumn): string
    {
        if ($scope->isAdministrator()) {
            return '1 = 1';
        }

        if ($scope->isSelfOnly()) {
            $bindings['scope_user_id'] = $scope->userId();

            return $facultyUserColumn . ' = :scope_user_id';
        }

        $bindings['scope_department_id'] = $scope->departmentId() ?? 0;

        return $departmentColumn . ' = :scope_department_id';
    }
}
