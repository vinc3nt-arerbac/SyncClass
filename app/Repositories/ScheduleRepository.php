<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Scope;

/**
 * Schedule entries (SCH-01…SCH-07).
 *
 * One row is one meeting: a Monday 09:00–10:30 lecture and its Wednesday
 * counterpart are two rows, never a serialised weekday string (SCH-04). That
 * makes the calendar, the conflict queries and the "today's classes" widget all
 * work against the same shape.
 *
 * Scope has three shapes here, and the faculty one is the interesting case:
 *
 *   administrator  → every entry
 *   dean/secretary → entries of their department
 *   faculty        → entries where *they* are the assigned teacher
 *
 * `faculty_id` points at `faculty_profiles`, not at `users`, so the faculty
 * scope joins through the profile. That join is also what makes the scope
 * clause index-friendly (`idx_schedules_faculty_slot`).
 *
 * Overlap is deliberately not checked here: `ConflictService` (Phase 11) owns
 * every overlap rule and every write path goes through `ScheduleService`, which
 * will call it before persisting. The indexes this repository queries were
 * designed for exactly that query shape.
 */
final class ScheduleRepository extends MasterDataRepository
{
    public const STATUSES = ['draft', 'pending', 'approved', 'published', 'rejected', 'cancelled'];
    public const RECURRENCES = ['weekly', 'one_off'];

    private const SELECT = <<<'SQL'
        SELECT s.id, s.semester_id, s.department_id, s.section_id, s.subject_id, s.faculty_id,
               s.room_id, s.day_of_week, s.start_time, s.end_time, s.meeting_date, s.recurrence,
               s.status, s.notes, s.created_by, s.updated_by, s.approved_by, s.approved_at,
               s.published_at, s.cancelled_at, s.cancellation_reason, s.created_at, s.updated_at,
               sub.code AS subject_code, sub.title AS subject_title, sub.units,
               sec.code AS section_code, sec.student_count,
               u.first_name AS faculty_first_name, u.last_name AS faculty_last_name, u.email AS faculty_email,
               r.code AS room_code, r.capacity AS room_capacity, r.room_type,
               b.code AS building_code,
               d.code AS department_code, d.name AS department_name, d.color_hex,
               sem.code AS semester_code, sem.name AS semester_name, sem.status AS semester_status
          FROM schedules s
          INNER JOIN subjects sub ON sub.id = s.subject_id
          INNER JOIN sections sec ON sec.id = s.section_id
          INNER JOIN faculty_profiles f ON f.id = s.faculty_id
          INNER JOIN users u ON u.id = f.user_id
          INNER JOIN rooms r ON r.id = s.room_id
          INNER JOIN buildings b ON b.id = r.building_id
          INNER JOIN departments d ON d.id = s.department_id
          INNER JOIN semesters sem ON sem.id = s.semester_id
        SQL;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE s.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * Scoped lookup: an out-of-scope entry is indistinguishable from a missing
     * one, which is what the controller turns into 404.
     *
     * @return array<string, mixed>|null
     */
    public function findInScope(Scope $scope, int $id): ?array
    {
        $bindings = [];
        $where = $this->scopeClause($scope, $bindings);

        return $this->database->selectOne(
            self::SELECT . ' WHERE s.id = :id AND ' . $where . ' LIMIT 1',
            array_replace(['id' => $id], $bindings)
        );
    }

    /**
     * @param array{search?: string, semester_id?: int|null, status?: string, department_id?: int|null,
     *              faculty_id?: int|null, room_id?: int|null, section_id?: int|null,
     *              day_of_week?: int|null, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(Scope $scope, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE ' . $this->scopeClause($scope, $bindings);
        $where .= $this->searchClause(
            $filters['search'] ?? null,
            ['sub.code', 'sub.title', 'sec.code', 'r.code', 'u.first_name', 'u.last_name'],
            $bindings
        );

        $semesterId = $this->idOrNull($filters['semester_id'] ?? null);

        if ($semesterId !== null) {
            $where .= ' AND s.semester_id = :semester_id';
            $bindings['semester_id'] = $semesterId;
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));

        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND s.status = :status';
            $bindings['status'] = $status;
        }

        foreach (['department_id' => 's.department_id', 'faculty_id' => 's.faculty_id', 'room_id' => 's.room_id', 'section_id' => 's.section_id'] as $key => $column) {
            $value = $this->idOrNull($filters[$key] ?? null);

            if ($value !== null) {
                $where .= ' AND ' . $column . ' = :' . $key;
                $bindings[$key] = $value;
            }
        }

        $day = $this->idOrNull($filters['day_of_week'] ?? null);

        if ($day !== null && $day >= 1 && $day <= 7) {
            $where .= ' AND s.day_of_week = :day_of_week';
            $bindings['day_of_week'] = $day;
        }

        $order = $this->orderBy(
            [
                'day' => 's.day_of_week, s.start_time',
                'start' => 's.start_time',
                'subject' => 'sub.code',
                'section' => 'sec.code',
                'status' => 's.status',
                'semester' => 'sem.code, s.day_of_week, s.start_time',
            ],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            's.day_of_week, s.start_time'
        );

        return $this->fetchPage(
            self::SELECT . $where . $order,
            'SELECT COUNT(*)
               FROM schedules s
               INNER JOIN subjects sub ON sub.id = s.subject_id
               INNER JOIN sections sec ON sec.id = s.section_id
               INNER JOIN faculty_profiles f ON f.id = s.faculty_id
               INNER JOIN users u ON u.id = f.user_id
               INNER JOIN rooms r ON r.id = s.room_id
               INNER JOIN buildings b ON b.id = r.building_id
               INNER JOIN departments d ON d.id = s.department_id
               INNER JOIN semesters sem ON sem.id = s.semester_id' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * Insert one meeting row.
     *
     * @param array<string, mixed> $data Already validated by ScheduleService.
     */
    public function create(array $data): int
    {
        return $this->database->insert('schedules', [
            'semester_id' => (int) $data['semester_id'],
            'department_id' => (int) $data['department_id'],
            'section_id' => (int) $data['section_id'],
            'subject_id' => (int) $data['subject_id'],
            'faculty_id' => (int) $data['faculty_id'],
            'room_id' => (int) $data['room_id'],
            'day_of_week' => (int) $data['day_of_week'],
            'start_time' => (string) $data['start_time'],
            'end_time' => (string) $data['end_time'],
            'meeting_date' => $data['meeting_date'] ?? null,
            'recurrence' => (string) $data['recurrence'],
            'status' => (string) $data['status'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['actor_id'] !== null ? (int) $data['actor_id'] : null,
            'updated_by' => $data['actor_id'] !== null ? (int) $data['actor_id'] : null,
        ]);
    }

    /**
     * Update the scheduling facts of one meeting (not its status).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('schedules', [
            'semester_id' => (int) $data['semester_id'],
            'department_id' => (int) $data['department_id'],
            'section_id' => (int) $data['section_id'],
            'subject_id' => (int) $data['subject_id'],
            'faculty_id' => (int) $data['faculty_id'],
            'room_id' => (int) $data['room_id'],
            'day_of_week' => (int) $data['day_of_week'],
            'start_time' => (string) $data['start_time'],
            'end_time' => (string) $data['end_time'],
            'meeting_date' => $data['meeting_date'] ?? null,
            'recurrence' => (string) $data['recurrence'],
            'notes' => $data['notes'] ?? null,
            'updated_by' => $data['actor_id'] !== null ? (int) $data['actor_id'] : null,
        ], 'id = :id', ['id' => $id]);
    }

    /**
     * Move an entry through its lifecycle, stamping the matching audit column.
     *
     * @param array<string, mixed> $fields Extra columns (approved_by, cancelled_at, …).
     */
    public function applyStatus(int $id, string $status, array $fields = []): void
    {
        $this->database->update('schedules', array_replace(['status' => $status], $fields), 'id = :id', ['id' => $id]);
    }

    /**
     * Every entry that is not cancelled in a term, for the conflict engine.
     *
     * Phase 11 reads this instead of pulling the whole table; the scope is the
     * term because two entries can only conflict inside the same semester.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeInSemester(int $semesterId): array
    {
        return $this->database->select(
            'SELECT id, semester_id, department_id, section_id, subject_id, faculty_id, room_id,
                    day_of_week, start_time, end_time, status, meeting_date
               FROM schedules
              WHERE semester_id = :semester_id AND status <> :cancelled
              ORDER BY day_of_week, start_time',
            ['semester_id' => $semesterId, 'cancelled' => 'cancelled']
        );
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function scopeClause(Scope $scope, array &$bindings): string
    {
        if ($scope->isAdministrator()) {
            return '1 = 1';
        }

        if ($scope->isSelfOnly()) {
            $bindings['scope_user_id'] = $scope->userId();

            return 'f.user_id = :scope_user_id';
        }

        $bindings['scope_department_id'] = $scope->departmentId() ?? 0;

        return 's.department_id = :scope_department_id';
    }
}
