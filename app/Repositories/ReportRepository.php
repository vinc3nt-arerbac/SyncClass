<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Scope;

/**
 * Read models for the reports module (Phase 15, RPT-01…RPT-05).
 *
 * Reporting is read-only by nature, so the repository exposes one query per
 * report instead of a generic finder: each statement is shaped for its table
 * (grouped by teacher, room, department, conflict, …) and every statement
 * applies the actor's scope in SQL, never in the template.
 *
 * Scope rules, identical to the schedule list and the calendar feed:
 *   administrator     → the whole institution
 *   dean / secretary  → the actor's department (`schedules.department_id`)
 *   faculty           → rows where the actor is the teacher (`f.user_id`)
 *
 * Cancelled entries are excluded everywhere: a cancelled meeting frees its
 * room and its teacher, so counting it would overstate load and utilization.
 * Rows are returned as plain arrays so the service can add the derived numbers
 * (percentages, totals) in one place instead of in every query.
 */
final class ReportRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * RPT-01 — teaching load per faculty profile in one term.
     *
     * Units are credited once per (subject, section) allocation, not once per
     * weekly meeting: a subject taught on two days carries its units once. The
     * hours column is contact time, so it does grow with the number of
     * meetings. `max_load_units` comes from the profile for the over/under
     * comparison the service computes.
     *
     * Only active profiles are listed — an archived teacher is history, not a
     * current load.
     *
     * @return array<int, array<string, mixed>>
     */
    public function facultyLoad(Scope $scope, int $semesterId): array
    {
        $bindings = ['semester_meetings' => $semesterId, 'semester_units' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 'f.department_id', 'f.user_id');

        return $this->database->select(
            "SELECT f.id AS faculty_id,
                    u.first_name, u.last_name, u.email,
                    d.code AS department_code, d.name AS department_name,
                    f.employment_type, f.max_load_units,
                    COALESCE(m.meetings, 0) AS meetings,
                    COALESCE(m.hours, 0) AS hours,
                    COALESCE(m.subjects, 0) AS subjects,
                    COALESCE(m.sections, 0) AS sections,
                    COALESCE(a.units, 0) AS units
               FROM faculty_profiles f
               INNER JOIN users u ON u.id = f.user_id
               INNER JOIN departments d ON d.id = f.department_id
               LEFT JOIN (
                     SELECT s.faculty_id,
                            COUNT(*) AS meetings,
                            SUM(TIME_TO_SEC(TIMEDIFF(s.end_time, s.start_time)) / 3600) AS hours,
                            COUNT(DISTINCT s.subject_id) AS subjects,
                            COUNT(DISTINCT s.section_id) AS sections
                       FROM schedules s
                      WHERE s.semester_id = :semester_meetings
                        AND s.status <> 'cancelled'
                      GROUP BY s.faculty_id
               ) m ON m.faculty_id = f.id
               LEFT JOIN (
                     SELECT alloc.faculty_id, SUM(sub.units) AS units
                       FROM (
                             SELECT DISTINCT s2.faculty_id, s2.subject_id, s2.section_id
                               FROM schedules s2
                              WHERE s2.semester_id = :semester_units
                                AND s2.status <> 'cancelled'
                       ) alloc
                       INNER JOIN subjects sub ON sub.id = alloc.subject_id
                      GROUP BY alloc.faculty_id
               ) a ON a.faculty_id = f.id
              WHERE f.is_active = 1
                AND {$scopeSql}
              ORDER BY units DESC, u.last_name, u.first_name",
            $bindings
        );
    }

    /**
     * RPT-02 — booked minutes per room in one term.
     *
     * The available minutes are not a column: they depend on the term's length
     * and the policy window (config/schedule.php), so the service derives them
     * and divides. This query only answers "how much is booked, by whom".
     *
     * For a departmental or faculty scope the LEFT JOIN becomes an implicit
     * INNER JOIN (`bk.room_id IS NOT NULL`): a dean sees the rooms their own
     * department uses, not the institution's room inventory.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roomUtilization(Scope $scope, int $semesterId): array
    {
        $bindings = ['semester_id' => $semesterId];

        // The scope is applied inside the aggregate so the LEFT JOIN keeps its
        // meaning: every room, with only the visible bookings counted.
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');
        $visibleOnly = $scope->isAdministrator() ? '' : ' AND bk.room_id IS NOT NULL';

        return $this->database->select(
            "SELECT r.id AS room_id, r.code AS room_code, r.name AS room_name,
                    r.room_type, r.capacity,
                    b.code AS building_code, b.name AS building_name,
                    COALESCE(bk.meetings, 0) AS meetings,
                    COALESCE(bk.weekly_hours, 0) AS weekly_hours,
                    COALESCE(bk.one_off_hours, 0) AS one_off_hours,
                    COALESCE(bk.departments, 0) AS departments,
                    COALESCE(bk.students, 0) AS students
               FROM rooms r
               INNER JOIN buildings b ON b.id = r.building_id
               LEFT JOIN (
                     SELECT s.room_id,
                            COUNT(*) AS meetings,
                            SUM(CASE WHEN s.recurrence = 'weekly'
                                     THEN TIME_TO_SEC(TIMEDIFF(s.end_time, s.start_time)) / 3600
                                     ELSE 0 END) AS weekly_hours,
                            SUM(CASE WHEN s.recurrence = 'one_off'
                                     THEN TIME_TO_SEC(TIMEDIFF(s.end_time, s.start_time)) / 3600
                                     ELSE 0 END) AS one_off_hours,
                            COUNT(DISTINCT s.department_id) AS departments,
                            SUM(sec.student_count) AS students
                       FROM schedules s
                       INNER JOIN faculty_profiles f ON f.id = s.faculty_id
                       INNER JOIN sections sec ON sec.id = s.section_id
                      WHERE s.semester_id = :semester_id
                        AND s.status <> 'cancelled'
                        AND {$scopeSql}
                      GROUP BY s.room_id
               ) bk ON bk.room_id = r.id
              WHERE r.is_active = 1{$visibleOnly}
              ORDER BY weekly_hours DESC, b.code, r.code",
            $bindings
        );
    }

    /**
     * RPT-03 — one row per department for the term.
     *
     * @return array<int, array<string, mixed>>
     */
    public function departmentSchedule(Scope $scope, int $semesterId, ?int $departmentId = null): array
    {
        $bindings = ['semester_id' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        $departmentSql = '';

        if ($departmentId !== null && $departmentId > 0) {
            $bindings['department_id'] = $departmentId;
            $departmentSql = ' AND s.department_id = :department_id';
        }

        return $this->database->select(
            "SELECT d.id AS department_id, d.code AS department_code, d.name AS department_name,
                    d.color_hex,
                    COUNT(*) AS meetings,
                    SUM(TIME_TO_SEC(TIMEDIFF(s.end_time, s.start_time)) / 3600) AS hours,
                    COUNT(DISTINCT s.subject_id) AS subjects,
                    COUNT(DISTINCT s.section_id) AS sections,
                    COUNT(DISTINCT s.faculty_id) AS faculty,
                    COUNT(DISTINCT s.room_id) AS rooms,
                    COUNT(DISTINCT s.semester_id) AS terms
               FROM schedules s
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
               INNER JOIN departments d ON d.id = s.department_id
              WHERE s.semester_id = :semester_id
                AND s.status <> 'cancelled'
                AND {$scopeSql}{$departmentSql}
              GROUP BY d.id, d.code, d.name, d.color_hex
              ORDER BY hours DESC, d.code",
            $bindings
        );
    }

    /**
     * RPT-04 — the persisted conflict log for a term.
     *
     * `schedule_conflicts` is written by the conflict engine; this report reads
     * it back with the schedule context so an open row is actionable. The
     * optional `$status` filter is whitelisted by the service.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conflicts(Scope $scope, int $semesterId, string $status = ''): array
    {
        $bindings = ['semester_id' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        $statusSql = '';

        if ($status !== '') {
            $bindings['status'] = $status;
            $statusSql = ' AND c.status = :status';
        }

        return $this->database->select(
            "SELECT c.id, c.rule_code, c.conflict_type, c.severity, c.status, c.message,
                    c.detected_at, c.resolved_at, c.resolution_note,
                    s.id AS schedule_id, s.day_of_week, s.start_time, s.end_time,
                    sub.code AS subject_code, sub.title AS subject_title,
                    sec.code AS section_code,
                    r.code AS room_code, b.code AS building_code,
                    d.code AS department_code,
                    u.first_name AS faculty_first_name, u.last_name AS faculty_last_name
               FROM schedule_conflicts c
               INNER JOIN schedules s ON s.id = c.primary_schedule_id
               INNER JOIN subjects sub ON sub.id = s.subject_id
               INNER JOIN sections sec ON sec.id = s.section_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
               INNER JOIN users u ON u.id = f.user_id
               INNER JOIN rooms r ON r.id = s.room_id
               INNER JOIN buildings b ON b.id = r.building_id
               INNER JOIN departments d ON d.id = s.department_id
              WHERE c.semester_id = :semester_id
                AND {$scopeSql}{$statusSql}
              ORDER BY FIELD(c.status, 'open', 'resolved', 'ignored'), c.detected_at DESC
              LIMIT 500",
            $bindings
        );
    }

    /**
     * RPT-03/RPT-05 — the meetings a weekly or semester timetable prints.
     *
     * The three optional filters narrow one actor's view further: a dean may
     * inspect one teacher, one section or one department inside their scope.
     * They are validated as integers by the controller and never widen the
     * scope clause above, so a hand-edited `?faculty=` for another department
     * returns nothing rather than someone else's timetable.
     *
     * @param array{faculty_id?: int, department_id?: int, section_id?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function timetable(Scope $scope, int $semesterId, array $filters = []): array
    {
        $bindings = ['semester_id' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        $extra = '';

        if (($filters['faculty_id'] ?? 0) > 0) {
            $bindings['faculty_id'] = (int) $filters['faculty_id'];
            $extra .= ' AND s.faculty_id = :faculty_id';
        }

        if (($filters['department_id'] ?? 0) > 0) {
            $bindings['department_id'] = (int) $filters['department_id'];
            $extra .= ' AND s.department_id = :department_id';
        }

        if (($filters['section_id'] ?? 0) > 0) {
            $bindings['section_id'] = (int) $filters['section_id'];
            $extra .= ' AND s.section_id = :section_id';
        }

        return $this->database->select(
            "SELECT s.id, s.day_of_week, s.start_time, s.end_time, s.meeting_date, s.recurrence,
                    s.status, s.notes,
                    sub.code AS subject_code, sub.title AS subject_title, sub.units,
                    sec.code AS section_code, sec.student_count,
                    u.first_name AS faculty_first_name, u.last_name AS faculty_last_name,
                    f.id AS faculty_id,
                    r.code AS room_code, r.name AS room_name,
                    b.code AS building_code,
                    d.id AS department_id, d.code AS department_code, d.name AS department_name,
                    d.color_hex, sem.code AS semester_code
               FROM schedules s
               INNER JOIN subjects sub ON sub.id = s.subject_id
               INNER JOIN sections sec ON sec.id = s.section_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
               INNER JOIN users u ON u.id = f.user_id
               INNER JOIN rooms r ON r.id = s.room_id
               INNER JOIN buildings b ON b.id = r.building_id
               INNER JOIN departments d ON d.id = s.department_id
               INNER JOIN semesters sem ON sem.id = s.semester_id
              WHERE s.semester_id = :semester_id
                AND s.status <> 'cancelled'
                AND {$scopeSql}{$extra}
              ORDER BY s.day_of_week, s.start_time, sec.code, sub.code
              LIMIT 2000",
            $bindings
        );
    }

    /**
     * The four numbers on the reports hub (no per-row detail).
     *
     * @return array{meetings: int, teachers: int, rooms: int, open_conflicts: int}
     */
    public function termSummary(Scope $scope, int $semesterId): array
    {
        $bindings = ['semester_id' => $semesterId];
        $scopeSql = $this->scopeSql($scope, $bindings, 's.department_id', 'f.user_id');

        $row = $this->database->selectOne(
            "SELECT COUNT(*) AS meetings,
                    COUNT(DISTINCT s.faculty_id) AS teachers,
                    COUNT(DISTINCT s.room_id) AS rooms
               FROM schedules s
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
              WHERE s.semester_id = :semester_id
                AND s.status <> 'cancelled'
                AND {$scopeSql}",
            $bindings
        );

        $conflictBindings = ['semester_id' => $semesterId];
        $conflictScopeSql = $this->scopeSql($scope, $conflictBindings, 's.department_id', 'f.user_id');

        $openConflicts = (int) $this->database->scalar(
            "SELECT COUNT(*)
               FROM schedule_conflicts c
               INNER JOIN schedules s ON s.id = c.primary_schedule_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
              WHERE c.semester_id = :semester_id
                AND c.status = 'open'
                AND {$conflictScopeSql}",
            $conflictBindings
        );

        return [
            'meetings' => (int) ($row['meetings'] ?? 0),
            'teachers' => (int) ($row['teachers'] ?? 0),
            'rooms' => (int) ($row['rooms'] ?? 0),
            'open_conflicts' => $openConflicts,
        ];
    }

    /**
     * The one scope predicate every query above shares.
     *
     * `$departmentColumn` is the column that carries the row's department and
     * `$facultyUserColumn` the column that carries the teacher's user id; the
     * caller chooses them so the same rule works on `schedules` and on
     * `faculty_profiles`. Bindings are collected in `$bindings` because PDO
     * cannot reuse a named placeholder twice in one statement.
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
