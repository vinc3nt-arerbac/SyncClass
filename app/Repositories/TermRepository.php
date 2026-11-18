<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Academic years and semesters (MD-06).
 *
 * A term is two tables: `academic_years` (2026–2027) and `semesters` (1st, 2nd,
 * summer). Both carry an `is_current` flag, and the storage layer enforces
 * "at most one current semester per academic year" with a generated column and
 * a unique key. This repository still clears the previous current row inside a
 * transaction before setting the new one, because a duplicate-key exception is
 * a poor way to tell a user "you already have a current semester".
 *
 * Academic years have no active flag: they are historical records. A semester
 * is closed through its status (`planned → active → closed`) rather than
 * archived, which is what reports and the calendar will filter on.
 */
final class TermRepository extends MasterDataRepository
{
    public const SEMESTER_STATUSES = ['planned', 'active', 'closed'];

    private const YEAR_SELECT = <<<'SQL'
        SELECT y.id, y.code, y.start_date, y.end_date, y.is_current, y.created_at,
               (SELECT COUNT(*) FROM semesters s WHERE s.academic_year_id = y.id) AS semester_count
          FROM academic_years y
        SQL;

    private const SEMESTER_SELECT = <<<'SQL'
        SELECT s.id, s.academic_year_id, s.term_no, s.name, s.code, s.start_date, s.end_date,
               s.status, s.is_current, s.created_at,
               y.code AS year_code
          FROM semesters s
          INNER JOIN academic_years y ON y.id = s.academic_year_id
        SQL;

    // -----------------------------------------------------------------------
    // Academic years
    // -----------------------------------------------------------------------

    /**
     * @param array{search?: string, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function years(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE 1 = 1';
        $where .= $this->searchClause($filters['search'] ?? null, ['y.code'], $bindings);

        $order = $this->orderBy(
            ['code' => 'y.code', 'start' => 'y.start_date', 'current' => 'y.is_current'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            'y.code'
        );

        return $this->fetchPage(
            self::YEAR_SELECT . $where . $order,
            'SELECT COUNT(*) FROM academic_years y' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findYear(int $id): ?array
    {
        return $this->database->selectOne(self::YEAR_SELECT . ' WHERE y.id = :id LIMIT 1', ['id' => $id]);
    }

    public function yearCodeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM academic_years WHERE code = :code';
        $bindings = ['code' => trim($code)];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @return array<int, array{id: int, code: string}>
     */
    public function yearOptions(): array
    {
        $rows = $this->database->select('SELECT id, code FROM academic_years ORDER BY code DESC');

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
        ], $rows);
    }

    /**
     * @param array{code: string, start_date: string, end_date: string} $data
     */
    public function createYear(array $data, bool $current): int
    {
        return (int) $this->database->transaction(function () use ($data, $current): int {
            if ($current) {
                $this->clearCurrentYear();
            }

            return $this->database->insert('academic_years', [
                'code' => trim($data['code']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_current' => $current ? 1 : 0,
            ]);
        });
    }

    /**
     * @param array{code: string, start_date: string, end_date: string} $data
     */
    public function updateYear(int $id, array $data, bool $current): void
    {
        $this->database->transaction(function () use ($id, $data, $current): void {
            if ($current) {
                $this->clearCurrentYear($id);
            }

            $this->database->update('academic_years', [
                'code' => trim($data['code']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_current' => $current ? 1 : 0,
            ], 'id = :id', ['id' => $id]);
        });
    }

    public function setCurrentYear(int $id): void
    {
        $this->database->transaction(function () use ($id): void {
            $this->clearCurrentYear($id);
            $this->database->update('academic_years', ['is_current' => 1], 'id = :id', ['id' => $id]);
        });
    }

    // -----------------------------------------------------------------------
    // Semesters
    // -----------------------------------------------------------------------

    /**
     * @param array{search?: string, academic_year_id?: int|null, status?: string, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function semesters(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE 1 = 1';
        $where .= $this->searchClause($filters['search'] ?? null, ['s.code', 's.name', 'y.code'], $bindings);

        $yearId = $this->idOrNull($filters['academic_year_id'] ?? null);

        if ($yearId !== null) {
            $where .= ' AND s.academic_year_id = :academic_year_id';
            $bindings['academic_year_id'] = $yearId;
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));

        if (in_array($status, self::SEMESTER_STATUSES, true)) {
            $where .= ' AND s.status = :status';
            $bindings['status'] = $status;
        }

        $order = $this->orderBy(
            ['code' => 's.code', 'start' => 's.start_date', 'year' => 'y.code, s.term_no', 'status' => 's.status'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            'y.code, s.term_no'
        );

        return $this->fetchPage(
            self::SEMESTER_SELECT . $where . $order,
            'SELECT COUNT(*) FROM semesters s INNER JOIN academic_years y ON y.id = s.academic_year_id' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSemester(int $id): ?array
    {
        return $this->database->selectOne(self::SEMESTER_SELECT . ' WHERE s.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * The current semester with its academic year, for later phases
     * (schedules default to this term).
     *
     * @return array<string, mixed>|null
     */
    public function currentSemester(): ?array
    {
        return $this->database->selectOne(self::SEMESTER_SELECT . ' WHERE s.is_current = 1 LIMIT 1');
    }

    /**
     * Semesters for the schedule form, newest year first.
     *
     * @return array<int, array{id: int, code: string, name: string, status: string, year_code: string}>
     */
    public function semesterOptions(): array
    {
        $rows = $this->database->select(
            'SELECT s.id, s.code, s.name, s.status, y.code AS year_code
               FROM semesters s
               INNER JOIN academic_years y ON y.id = s.academic_year_id
              ORDER BY y.code DESC, s.term_no'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'year_code' => (string) $row['year_code'],
        ], $rows);
    }

    public function semesterCodeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM semesters WHERE code = :code';
        $bindings = ['code' => trim($code)];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * The unique key is (academic_year_id, term_no); a second "1st semester"
     * in the same year must be reported as a field error, not a PDO exception.
     */
    public function termTaken(int $academicYearId, int $termNo, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM semesters WHERE academic_year_id = :academic_year_id AND term_no = :term_no';
        $bindings = ['academic_year_id' => $academicYearId, 'term_no' => $termNo];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @param array{academic_year_id: int, term_no: int, name: string, code: string, start_date: string, end_date: string, status: string} $data
     */
    public function createSemester(array $data, bool $current): int
    {
        return (int) $this->database->transaction(function () use ($data, $current): int {
            if ($current) {
                $this->clearCurrentSemester((int) $data['academic_year_id']);
            }

            return $this->database->insert('semesters', $this->semesterPayload($data, $current));
        });
    }

    /**
     * @param array{academic_year_id: int, term_no: int, name: string, code: string, start_date: string, end_date: string, status: string} $data
     */
    public function updateSemester(int $id, array $data, bool $current): void
    {
        $this->database->transaction(function () use ($id, $data, $current): void {
            if ($current) {
                $this->clearCurrentSemester((int) $data['academic_year_id'], $id);
            }

            $this->database->update('semesters', $this->semesterPayload($data, $current), 'id = :id', ['id' => $id]);
        });
    }

    /**
     * Promote a semester to current. `status` moves from planned to active so
     * the list never shows "current but planned".
     */
    public function setCurrentSemester(int $id): void
    {
        $semester = $this->findSemester($id);

        if ($semester === null) {
            return;
        }

        $this->database->transaction(function () use ($semester): void {
            $this->clearCurrentSemester((int) $semester['academic_year_id'], (int) $semester['id']);

            $this->database->update('semesters', [
                'is_current' => 1,
                'status' => $semester['status'] === 'planned' ? 'active' : $semester['status'],
            ], 'id = :id', ['id' => (int) $semester['id']]);
        });
    }

    /**
     * @param array{academic_year_id: int, term_no: int, name: string, code: string, start_date: string, end_date: string, status: string} $data
     * @return array<string, mixed>
     */
    private function semesterPayload(array $data, bool $current): array
    {
        $status = strtolower((string) $data['status']);
        $status = in_array($status, self::SEMESTER_STATUSES, true) ? $status : 'planned';

        // "Current but planned" is a contradiction: making a semester current
        // also starts it, exactly as setCurrentSemester() does.
        if ($current && $status === 'planned') {
            $status = 'active';
        }

        return [
            'academic_year_id' => (int) $data['academic_year_id'],
            'term_no' => max(1, min(3, (int) $data['term_no'])),
            'name' => trim((string) $data['name']),
            'code' => trim((string) $data['code']),
            'start_date' => (string) $data['start_date'],
            'end_date' => (string) $data['end_date'],
            'status' => $status,
            'is_current' => $current ? 1 : 0,
        ];
    }

    private function clearCurrentYear(?int $exceptId = null): void
    {
        $sql = 'UPDATE academic_years SET is_current = 0 WHERE is_current = 1';
        $bindings = [];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        $this->database->statement($sql, $bindings);
    }

    private function clearCurrentSemester(int $academicYearId, ?int $exceptId = null): void
    {
        $sql = 'UPDATE semesters SET is_current = 0 WHERE academic_year_id = :academic_year_id AND is_current = 1';
        $bindings = ['academic_year_id' => $academicYearId];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        $this->database->statement($sql, $bindings);
    }
}
