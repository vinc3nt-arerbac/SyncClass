<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Scope;

/**
 * Departments (MD-01).
 *
 * Departments are the root of every scope, so this repository is also the one
 * that answers "which departments may this user see?". A dean or secretary
 * sees only their own; faculty see their own; the administrator sees all. That
 * single rule feeds the list screen, the department dropdown on every other
 * master-data form, and the object-level checks in the base controller.
 *
 * Departments are archived, never deleted (MD-07): they are referenced by
 * users, subjects, sections and faculty profiles.
 */
final class DepartmentRepository extends MasterDataRepository
{
    private const SELECT = <<<'SQL'
        SELECT d.id, d.code, d.name, d.short_name, d.dean_user_id, d.email, d.phone,
               d.color_hex, d.is_active, d.created_at, d.updated_at,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS dean_name
          FROM departments d
          LEFT JOIN users u ON u.id = d.dean_user_id
        SQL;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE d.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @param array{search?: string, status?: string, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(Scope $scope, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE 1 = 1';

        if (!$scope->isAdministrator()) {
            $where .= ' AND d.id = :scope_department_id';
            $bindings['scope_department_id'] = $scope->departmentId() ?? 0;
        }

        $where .= $this->searchClause($filters['search'] ?? null, ['d.code', 'd.name', 'd.short_name'], $bindings);
        $where .= $this->activeClause($this->statusOrNull($filters['status'] ?? null));

        $order = $this->orderBy(
            ['code' => 'd.code', 'name' => 'd.name', 'created' => 'd.created_at'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            'd.code'
        );

        return $this->fetchPage(
            self::SELECT . $where . $order,
            'SELECT COUNT(*) FROM departments d' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * Department choices for a form or filter, already limited by scope.
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function options(Scope $scope, bool $activeOnly = true): array
    {
        $where = $activeOnly ? ' WHERE d.is_active = 1' : ' WHERE 1 = 1';
        $bindings = [];

        if (!$scope->isAdministrator()) {
            $where .= ' AND d.id = :scope_department_id';
            $bindings['scope_department_id'] = $scope->departmentId() ?? 0;
        }

        $rows = $this->database->select('SELECT d.id, d.code, d.name FROM departments d' . $where . ' ORDER BY d.code', $bindings);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM departments WHERE code = :code';
        $bindings = ['code' => strtoupper(trim($code))];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @param array{code: string, name: string, short_name?: string|null, email?: string|null, phone?: string|null, color_hex?: string|null} $data
     */
    public function create(array $data): int
    {
        return $this->database->insert('departments', [
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'short_name' => $this->nullable($data['short_name'] ?? null),
            'email' => $this->nullable($data['email'] ?? null),
            'phone' => $this->nullable($data['phone'] ?? null),
            'color_hex' => $this->colour($data['color_hex'] ?? null),
            'is_active' => 1,
        ]);
    }

    /**
     * @param array{code: string, name: string, short_name?: string|null, email?: string|null, phone?: string|null, color_hex?: string|null} $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('departments', [
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'short_name' => $this->nullable($data['short_name'] ?? null),
            'email' => $this->nullable($data['email'] ?? null),
            'phone' => $this->nullable($data['phone'] ?? null),
            'color_hex' => $this->colour($data['color_hex'] ?? null),
        ], 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('departments', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    /**
     * How many rows in the rest of the schema point at this department.
     *
     * Used to explain why archiving is the only destructive-looking action the
     * screen offers (MD-07): an active department with data cannot be removed.
     *
     * @return array{users: int, subjects: int, sections: int, faculty: int}
     */
    public function dependencies(int $id): array
    {
        return [
            'users' => (int) $this->database->scalar('SELECT COUNT(*) FROM users WHERE department_id = :id', ['id' => $id]),
            'subjects' => (int) $this->database->scalar('SELECT COUNT(*) FROM subjects WHERE department_id = :id', ['id' => $id]),
            'sections' => (int) $this->database->scalar('SELECT COUNT(*) FROM sections WHERE department_id = :id', ['id' => $id]),
            'faculty' => (int) $this->database->scalar('SELECT COUNT(*) FROM faculty_profiles WHERE department_id = :id', ['id' => $id]),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function colour(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^#[0-9A-F]{6}$/', $value) === 1 ? $value : '#8C1D40';
    }
}
