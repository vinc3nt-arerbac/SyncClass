<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Scope;

/**
 * Faculty profiles (MD-05).
 *
 * A profile links a user account to a department and records the teaching
 * capacity the scheduler cares about: employment type, maximum load in units,
 * specialisation and office. The user row itself is not duplicated here — the
 * profile reads the name and email from `users`.
 *
 * Scope is two-shaped, unlike the catalogue tables:
 *
 *   - dean / secretary → profiles in their department;
 *   - faculty          → their own profile only (`user_id = scope user`);
 *   - administrator    → all.
 *
 * Archived, never deleted: schedules will reference the profile.
 */
final class FacultyRepository extends MasterDataRepository
{
    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'visiting'];

    private const SELECT = <<<'SQL'
        SELECT f.id, f.user_id, f.department_id, f.employment_type, f.max_load_units,
               f.specialization, f.office, f.is_active, f.created_at,
               u.first_name, u.last_name, u.email,
               d.code AS department_code, d.name AS department_name
          FROM faculty_profiles f
          INNER JOIN users u ON u.id = f.user_id
          INNER JOIN departments d ON d.id = f.department_id
        SQL;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE f.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE f.user_id = :user_id LIMIT 1', ['user_id' => $userId]);
    }

    /**
     * Scoped lookup used by the edit and availability pages: a faculty member
     * asking for a colleague's profile gets 404, not 403.
     *
     * @return array<string, mixed>|null
     */
    public function findInScope(Scope $scope, int $id): ?array
    {
        $row = $this->find($id);

        if ($row === null) {
            return null;
        }

        if ($scope->isSelfOnly()) {
            return (int) $row['user_id'] === $scope->userId() ? $row : null;
        }

        return $scope->coversDepartment((int) $row['department_id']) ? $row : null;
    }

    /**
     * @param array{search?: string, status?: string, department_id?: int|null, employment_type?: string, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(Scope $scope, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE ' . $this->scopeClause($scope, 'f', $bindings);
        $where .= $this->searchClause($filters['search'] ?? null, ['u.first_name', 'u.last_name', 'u.email', 'f.specialization'], $bindings);
        $where .= $this->activeClause($this->statusOrNull($filters['status'] ?? null), 'f.is_active');

        $departmentId = $this->idOrNull($filters['department_id'] ?? null);

        if ($departmentId !== null) {
            $where .= ' AND f.department_id = :department_id';
            $bindings['department_id'] = $departmentId;
        }

        $type = strtolower(trim((string) ($filters['employment_type'] ?? '')));

        if (in_array($type, self::EMPLOYMENT_TYPES, true)) {
            $where .= ' AND f.employment_type = :employment_type';
            $bindings['employment_type'] = $type;
        }

        $order = $this->orderBy(
            ['name' => 'u.last_name, u.first_name', 'department' => 'd.code', 'load' => 'f.max_load_units', 'employment' => 'f.employment_type'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            'u.last_name, u.first_name'
        );

        return $this->fetchPage(
            self::SELECT . $where . $order,
            'SELECT COUNT(*) FROM faculty_profiles f
               INNER JOIN users u ON u.id = f.user_id
               INNER JOIN departments d ON d.id = f.department_id' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * Scope clause: faculty see their own profile, department heads their
     * department, the administrator everything.
     *
     * @param array<string, mixed> $bindings
     */
    private function scopeClause(Scope $scope, string $alias, array &$bindings): string
    {
        if ($scope->isAdministrator()) {
            return '1 = 1';
        }

        if ($scope->isSelfOnly()) {
            $bindings['scope_user_id'] = $scope->userId();

            return $alias . '.user_id = :scope_user_id';
        }

        $bindings['scope_department_id'] = $scope->departmentId() ?? 0;

        return $alias . '.department_id = :scope_department_id';
    }

    /**
     * Faculty choices for the schedule form, already scoped.
     *
     * @return array<int, array{id: int, name: string, department_id: int, max_load_units: float}>
     */
    public function options(Scope $scope): array
    {
        $bindings = [];
        $where = ' WHERE f.is_active = 1 AND ' . $this->scopeClause($scope, 'f', $bindings);

        $rows = $this->database->select(
            'SELECT f.id, f.department_id, f.max_load_units, u.first_name, u.last_name
               FROM faculty_profiles f
               INNER JOIN users u ON u.id = f.user_id' . $where . ' ORDER BY u.last_name, u.first_name',
            $bindings
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'department_id' => (int) $row['department_id'],
            'max_load_units' => (float) $row['max_load_units'],
        ], $rows);
    }

    /**
     * Faculty accounts that do not have a profile yet.
     *
     * Only users whose role is `faculty` are eligible: a dean is a department
     * head, not a teaching profile, and the unique key on user_id would reject
     * a duplicate anyway. The list is scoped so a dean can only add teachers to
     * their own department.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public function availableUsers(Scope $scope, ?int $exceptUserId = null): array
    {
        $bindings = ['role_code' => 'faculty'];
        $where = ' WHERE r.code = :role_code AND u.is_active = 1';

        if ($exceptUserId !== null) {
            // On the edit form the profile's own user must remain selectable.
            $where .= ' AND (u.id = :except_user_id OR NOT EXISTS (SELECT 1 FROM faculty_profiles f WHERE f.user_id = u.id))';
            $bindings['except_user_id'] = $exceptUserId;
        } else {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM faculty_profiles f WHERE f.user_id = u.id)';
        }

        if (!$scope->isAdministrator() && $scope->departmentId() !== null) {
            $where .= ' AND u.department_id = :scope_department_id';
            $bindings['scope_department_id'] = $scope->departmentId();
        } elseif (!$scope->isAdministrator()) {
            $where .= ' AND 1 = 0';
        }

        $rows = $this->database->select(
            'SELECT u.id, u.first_name, u.last_name, u.email
               FROM users u
               INNER JOIN roles r ON r.id = u.role_id' . $where . ' ORDER BY u.last_name, u.first_name',
            $bindings
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'email' => (string) $row['email'],
        ], $rows);
    }

    /**
     * @param array{faculty_id: int, department_id: int, employment_type: string, max_load_units: float|int|string, specialization?: string|null, office?: string|null} $data
     */
    public function create(array $data): int
    {
        return $this->database->insert('faculty_profiles', $this->payload($data) + ['is_active' => 1]);
    }

    /**
     * @param array{faculty_id?: int, department_id: int, employment_type: string, max_load_units: float|int|string, specialization?: string|null, office?: string|null} $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('faculty_profiles', $this->payload($data), 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('faculty_profiles', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $payload = [
            'department_id' => (int) $data['department_id'],
            'employment_type' => in_array((string) $data['employment_type'], self::EMPLOYMENT_TYPES, true)
                ? (string) $data['employment_type']
                : 'full_time',
            'max_load_units' => round(max(1, (float) $data['max_load_units']), 1),
            'specialization' => $this->nullable($data['specialization'] ?? null),
            'office' => $this->nullable($data['office'] ?? null),
        ];

        if (isset($data['faculty_id'])) {
            $payload = ['user_id' => (int) $data['faculty_id']] + $payload;
        }

        return $payload;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Active teachers the actor may see, for the calendar's teacher filter.
     *
     * @param array<string, mixed> $authUser
     * @return array<int, array<string, mixed>>
     */
    public function optionsFor(array $authUser): array
    {
        $scope = Scope::forUser($authUser);

        $rows = $this->database->select(
            self::SELECT . ' WHERE f.is_active = 1 ORDER BY u.last_name, u.first_name'
        );

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => $scope->coversDepartment((int) $row['department_id'])
        ));
    }

    /**
     * Every active teacher, for the generator's candidate space.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        return $this->database->select(
            self::SELECT . ' WHERE f.is_active = 1 ORDER BY f.department_id, u.last_name'
        );
    }

    /**
     * Active teachers of one department within the actor's scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDepartment(Scope $scope, int $departmentId): array
    {
        if (!$scope->coversDepartment($departmentId)) {
            return [];
        }

        return $this->database->select(
            self::SELECT . ' WHERE f.is_active = 1 AND f.department_id = :department_id'
            . ' ORDER BY u.last_name, u.first_name',
            ['department_id' => $departmentId]
        );
    }
}
