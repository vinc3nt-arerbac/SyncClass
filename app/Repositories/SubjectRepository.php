<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Scope;

/**
 * Subjects (MD-03).
 *
 * Subjects belong to a department and are therefore scoped: a dean or
 * secretary works with their own catalogue, faculty read it, and the
 * administrator sees everything. Scope is applied here, not in the controller,
 * so the create/update path can pass the same object to `findInScope()` and get
 * a 404 for a subject that exists in another department.
 *
 * `contact_hours` is a generated column (lecture + lab), so it is read but
 * never written. Archived, never deleted (MD-07).
 */
final class SubjectRepository extends MasterDataRepository
{
    public const TYPES = ['lecture', 'laboratory', 'mixed'];
    public const ROOM_TYPES = ['lecture', 'laboratory', 'seminar'];

    private const SELECT = <<<'SQL'
        SELECT s.id, s.code, s.title, s.department_id, s.units, s.lecture_hours, s.lab_hours,
               s.contact_hours, s.subject_type, s.preferred_room_type, s.description,
               s.is_active, s.created_at,
               d.code AS department_code, d.name AS department_name, d.color_hex
          FROM subjects s
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
     * Scoped lookup: out-of-scope rows are indistinguishable from missing ones.
     *
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
     * @param array{search?: string, status?: string, department_id?: int|null, subject_type?: string, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(Scope $scope, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE ' . $this->scopeClause($scope, 's', $bindings);
        $where .= $this->searchClause($filters['search'] ?? null, ['s.code', 's.title'], $bindings);
        $where .= $this->activeClause($this->statusOrNull($filters['status'] ?? null), 's.is_active');

        // A client filter can only narrow the scope, never widen it.
        $departmentId = $this->idOrNull($filters['department_id'] ?? null);

        if ($departmentId !== null) {
            $where .= ' AND s.department_id = :department_id';
            $bindings['department_id'] = $departmentId;
        }

        $type = strtolower(trim((string) ($filters['subject_type'] ?? '')));

        if (in_array($type, self::TYPES, true)) {
            $where .= ' AND s.subject_type = :subject_type';
            $bindings['subject_type'] = $type;
        }

        $order = $this->orderBy(
            ['code' => 's.code', 'title' => 's.title', 'units' => 's.units', 'department' => 'd.code'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            's.code'
        );

        return $this->fetchPage(
            self::SELECT . $where . $order,
            'SELECT COUNT(*) FROM subjects s INNER JOIN departments d ON d.id = s.department_id' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * @param array<string, mixed> $bindings Extended with scope bindings.
     */
    public function scopeClause(Scope $scope, string $alias, array &$bindings): string
    {
        if ($scope->isAdministrator()) {
            return '1 = 1';
        }

        if ($scope->isSelfOnly()) {
            // Faculty read the catalogue of the department they belong to.
            $bindings['scope_department_id'] = $scope->departmentId() ?? 0;

            return $alias . '.department_id = :scope_department_id';
        }

        $bindings['scope_department_id'] = $scope->departmentId() ?? 0;

        return $alias . '.department_id = :scope_department_id';
    }

    /**
     * @return array<int, array{id: int, code: string, title: string}>
     */
    public function options(Scope $scope, bool $activeOnly = true): array
    {
        $bindings = [];
        $where = ' WHERE ' . $this->scopeClause($scope, 's', $bindings);

        if ($activeOnly) {
            $where .= ' AND s.is_active = 1';
        }

        $rows = $this->database->select('SELECT s.id, s.code, s.title FROM subjects s' . $where . ' ORDER BY s.code', $bindings);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'title' => (string) $row['title'],
        ], $rows);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM subjects WHERE code = :code';
        $bindings = ['code' => strtoupper(trim($code))];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @param array{code: string, title: string, department_id: int, units: float|int|string, lecture_hours: float|int|string, lab_hours: float|int|string, subject_type: string, preferred_room_type?: string|null, description?: string|null} $data
     */
    public function create(array $data): int
    {
        return $this->database->insert('subjects', $this->payload($data) + ['is_active' => 1]);
    }

    /**
     * @param array{code: string, title: string, department_id: int, units: float|int|string, lecture_hours: float|int|string, lab_hours: float|int|string, subject_type: string, preferred_room_type?: string|null, description?: string|null} $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('subjects', $this->payload($data), 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('subjects', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $preferred = strtolower(trim((string) ($data['preferred_room_type'] ?? '')));

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'title' => trim((string) $data['title']),
            'department_id' => (int) $data['department_id'],
            'units' => round(max(0, (float) $data['units']), 1),
            'lecture_hours' => round(max(0, (float) $data['lecture_hours']), 1),
            'lab_hours' => round(max(0, (float) $data['lab_hours']), 1),
            'subject_type' => in_array((string) $data['subject_type'], self::TYPES, true) ? (string) $data['subject_type'] : 'lecture',
            'preferred_room_type' => in_array($preferred, self::ROOM_TYPES, true) ? $preferred : null,
            'description' => $this->nullable($data['description'] ?? null),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Subjects a section takes in a term, for the timetable generator.
     *
     * The curriculum table (`section_subjects`) is authoritative when it has
     * rows. A school that has not entered a curriculum yet would otherwise get
     * an empty draft on every run, so an empty result falls back to the active
     * subjects of the section's own department — the catalogue is the demand.
     *
     * @param array<int, int|string> $subjectIds Optional extra filter.
     * @return array<int, array<string, mixed>>
     */
    public function forSection(int $sectionId, int $semesterId, array $subjectIds = []): array
    {
        $section = $this->database->selectOne(
            'SELECT id, department_id FROM sections WHERE id = :id LIMIT 1',
            ['id' => $sectionId]
        );

        if ($section === null) {
            return [];
        }

        $bindings = ['section_id' => $sectionId, 'semester_id' => $semesterId];
        $filter = $this->subjectFilter($subjectIds, $bindings);

        $curriculum = $this->database->select(
            self::SELECT
            . ' INNER JOIN section_subjects ss ON ss.subject_id = s.id'
            . ' WHERE ss.section_id = :section_id AND ss.is_active = 1'
            . ' AND (ss.semester_id = :semester_id OR ss.semester_id IS NULL)'
            . ' AND s.is_active = 1' . $filter
            . ' ORDER BY s.code',
            $bindings
        );

        if ($curriculum !== []) {
            return $curriculum;
        }

        $bindings = ['department_id' => (int) $section['department_id']];
        $filter = $this->subjectFilter($subjectIds, $bindings);

        return $this->database->select(
            self::SELECT . ' WHERE s.department_id = :department_id AND s.is_active = 1' . $filter
            . ' ORDER BY s.code',
            $bindings
        );
    }

    /**
     * `AND s.id IN (…)` for an optional id list, extending the bindings.
     *
     * @param array<int, int|string> $subjectIds
     * @param array<string, mixed>   $bindings
     */
    private function subjectFilter(array $subjectIds, array &$bindings): string
    {
        if ($subjectIds === []) {
            return '';
        }

        $placeholders = [];
        foreach (array_values($subjectIds) as $index => $subjectId) {
            $key = 'subject_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = (int) $subjectId;
        }

        return ' AND s.id IN (' . implode(', ', $placeholders) . ')';
    }
}
