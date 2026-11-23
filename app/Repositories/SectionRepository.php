<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Scope;

/**
 * Class sections (MD-04).
 *
 * A section is a cohort (BSIT-2A) that belongs to a department and carries the
 * student count the conflict engine compares against room capacity (CNF-05).
 * Scope is department-shaped: dean and secretary manage their own, faculty
 * read their department's sections, the administrator sees all.
 *
 * Archived, never deleted (MD-07): schedules and section_subjects reference it.
 */
final class SectionRepository extends MasterDataRepository
{
    private const SELECT = <<<'SQL'
        SELECT s.id, s.code, s.name, s.program, s.year_level, s.department_id, s.student_count,
               s.is_active, s.created_at,
               d.code AS department_code, d.name AS department_name, d.color_hex
          FROM sections s
          INNER JOIN departments d ON d.id = s.department_id
        SQL;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE s.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInScope(Scope $scope, int $id): ?array
    {
        $row = $this->find($id);

        if ($row === null || !$scope->coversDepartment((int) $row['department_id'])) {
            return null;
        }

        return $row;
    }

    /**
     * @param array{search?: string, status?: string, department_id?: int|null, year_level?: int|null, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(Scope $scope, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE ' . $this->departmentScope($scope, 's', $bindings);
        $where .= $this->searchClause($filters['search'] ?? null, ['s.code', 's.name', 's.program'], $bindings);
        $where .= $this->activeClause($this->statusOrNull($filters['status'] ?? null), 's.is_active');

        $departmentId = $this->idOrNull($filters['department_id'] ?? null);

        if ($departmentId !== null) {
            $where .= ' AND s.department_id = :department_id';
            $bindings['department_id'] = $departmentId;
        }

        $yearLevel = $this->idOrNull($filters['year_level'] ?? null);

        if ($yearLevel !== null) {
            $where .= ' AND s.year_level = :year_level';
            $bindings['year_level'] = $yearLevel;
        }

        $order = $this->orderBy(
            ['code' => 's.code', 'name' => 's.name', 'year' => 's.year_level', 'students' => 's.student_count', 'department' => 'd.code'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            's.code'
        );

        return $this->fetchPage(
            self::SELECT . $where . $order,
            'SELECT COUNT(*) FROM sections s INNER JOIN departments d ON d.id = s.department_id' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function options(Scope $scope, bool $activeOnly = true): array
    {
        $bindings = [];
        $where = ' WHERE ' . $this->departmentScope($scope, 's', $bindings);

        if ($activeOnly) {
            $where .= ' AND s.is_active = 1';
        }

        $rows = $this->database->select('SELECT s.id, s.code, s.name FROM sections s' . $where . ' ORDER BY s.code', $bindings);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) ($row['name'] ?? $row['code']),
        ], $rows);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM sections WHERE code = :code';
        $bindings = ['code' => strtoupper(trim($code))];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @param array{code: string, name?: string|null, program?: string|null, year_level: int, department_id: int, student_count: int} $data
     */
    public function create(array $data): int
    {
        return $this->database->insert('sections', $this->payload($data) + ['is_active' => 1]);
    }

    /**
     * @param array{code: string, name?: string|null, program?: string|null, year_level: int, department_id: int, student_count: int} $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('sections', $this->payload($data), 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('sections', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => $this->nullable($data['name'] ?? null),
            'program' => $this->nullable($data['program'] ?? null),
            'year_level' => max(1, min(6, (int) $data['year_level'])),
            'department_id' => (int) $data['department_id'],
            'student_count' => max(1, (int) $data['student_count']),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Active sections of the supplied departments, for the timetable generator.
     *
     * Sections are not bound to one term in this schema (they carry a year
     * level and a department), so the term id is accepted for call-site
     * symmetry and used only once a curriculum row ties a section to a term.
     *
     * @param array<int, int|string> $departmentIds Empty = every department.
     * @return array<int, array<string, mixed>>
     */
    public function bySemesterAndDepartments(int $semesterId, array $departmentIds = []): array
    {
        $bindings = [];
        $sql = self::SELECT . ' WHERE s.is_active = 1';

        if ($departmentIds !== []) {
            $placeholders = [];
            foreach (array_values($departmentIds) as $index => $departmentId) {
                $key = 'dept_' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = (int) $departmentId;
            }

            $sql .= ' AND s.department_id IN (' . implode(', ', $placeholders) . ')';
        }

        return $this->database->select($sql . ' ORDER BY s.department_id, s.year_level, s.code', $bindings);
    }
}
