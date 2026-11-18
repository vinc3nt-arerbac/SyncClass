<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Scope;

/**
 * Users and their authentication state (AUTH-01…AUTH-07).
 *
 * Every query that touches `users` lives here. The select list always joins the
 * role (needed by Phase 8 authorisation) and left-joins the department (null for
 * the system administrator), so a caller never has to issue a second query to
 * answer "who is this and what may they do".
 *
 * Passwords are stored and read as hashes only; no method here accepts or
 * returns a plaintext password.
 */
final class UserRepository
{
    /**
     * Shared column list. Aliases are stable and used by AuthService and the
     * views (`role_code`, `department_name`, …).
     */
    private const SELECT_COLUMNS = <<<'SQL'
        SELECT u.id, u.role_id, u.department_id, u.email, u.username, u.password_hash,
               u.first_name, u.middle_name, u.last_name, u.employee_no, u.is_active,
               u.must_change_password, u.failed_login_attempts, u.locked_until,
               u.last_login_at, u.created_at,
               r.code AS role_code, r.name AS role_name,
               d.code AS department_code, d.name AS department_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = u.department_id
        SQL;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Look up an account by email.
     *
     * Emails are stored lower-case (normalised on write) and the caller passes
     * a lower-cased value, so this stays a plain index lookup rather than a
     * LOWER() scan over the unique key.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->database->selectOne(
            self::SELECT_COLUMNS . ' WHERE u.email = :email LIMIT 1',
            ['email' => strtolower(trim($email))]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT_COLUMNS . ' WHERE u.id = :id LIMIT 1', ['id' => $id]);
    }

    public function isActive(array $user): bool
    {
        return (int) ($user['is_active'] ?? 0) === 1;
    }

    /**
     * Load one account only if it is inside the caller's scope.
     *
     * This is the object-level half of RBAC: the permission says the user may
     * view accounts, the scope says *which* ones. A miss returns null so the
     * controller answers 404 — a dean must not be able to tell an account in
     * another department from an account that does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdInScope(Scope $scope, int $id): ?array
    {
        [$where, $bindings] = $this->scopeClause($scope, 'u');

        return $this->database->selectOne(
            self::SELECT_COLUMNS . ' WHERE u.id = :id AND ' . $where . ' LIMIT 1',
            array_replace(['id' => $id], $bindings)
        );
    }

    /**
     * A page of accounts inside the caller's scope, newest first.
     *
     * Filters are applied in SQL after the scope clause, never instead of it:
     * a client-supplied `department_id` can only narrow what the scope already
     * allows, so a dean cannot widen their view by editing the query string.
     *
     * @param array{search?: string, department_id?: int|null, role_code?: string, is_active?: bool|null} $filters
     * @return array<int, array<string, mixed>>
     */
    public function paginateForScope(Scope $scope, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        [$where, $bindings] = $this->filterClause($scope, $filters);

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return $this->database->select(
            self::SELECT_COLUMNS . ' WHERE ' . $where
            . ' ORDER BY u.last_name, u.first_name, u.id LIMIT ' . $limit . ' OFFSET ' . $offset,
            $bindings
        );
    }

    /**
     * Total rows that paginateForScope() would return for the same filters.
     *
     * @param array{search?: string, department_id?: int|null, role_code?: string, is_active?: bool|null} $filters
     */
    public function countForScope(Scope $scope, array $filters = []): int
    {
        [$where, $bindings] = $this->filterClause($scope, $filters);

        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE ' . $where,
            $bindings
        );
    }

    /**
     * SQL fragment implementing the scope, shared by every scoped query.
     *
     * Administrator → no restriction. Faculty → own row only. Everyone else →
     * their department; a user without a department matches nothing, which is
     * the safe reading of an incomplete profile.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function scopeClause(Scope $scope, string $alias = 'u'): array
    {
        if ($scope->isAdministrator()) {
            return ['1 = 1', []];
        }

        if ($scope->isSelfOnly()) {
            return [$alias . '.id = :scope_user_id', ['scope_user_id' => $scope->userId()]];
        }

        if ($scope->departmentId() === null) {
            return ['1 = 0', []];
        }

        return [$alias . '.department_id = :scope_department_id', ['scope_department_id' => $scope->departmentId()]];
    }

    /**
     * Scope clause plus the optional list filters.
     *
     * @param array{search?: string, department_id?: int|null, role_code?: string, is_active?: bool|null} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterClause(Scope $scope, array $filters): array
    {
        [$where, $bindings] = $this->scopeClause($scope, 'u');

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            // Native prepared statements (MySQL with emulation off) do not
            // allow one named placeholder to be repeated, so each column gets
            // its own binding. `!` is the escape character because it needs no
            // backslash processing and therefore behaves identically on MySQL
            // and on the SQLite database the tests use.
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

            $where .= ' AND (u.email LIKE :search_email ESCAPE \'!\''
                . ' OR u.first_name LIKE :search_first ESCAPE \'!\''
                . ' OR u.last_name LIKE :search_last ESCAPE \'!\''
                . ' OR u.username LIKE :search_username ESCAPE \'!\')';

            $bindings['search_email'] = $term;
            $bindings['search_first'] = $term;
            $bindings['search_last'] = $term;
            $bindings['search_username'] = $term;
        }

        $roleCode = strtolower(trim((string) ($filters['role_code'] ?? '')));

        if ($roleCode !== '') {
            $where .= ' AND r.code = :role_code';
            $bindings['role_code'] = $roleCode;
        }

        $departmentId = $filters['department_id'] ?? null;

        if ($departmentId !== null) {
            $where .= ' AND u.department_id = :department_id';
            $bindings['department_id'] = (int) $departmentId;
        }

        $isActive = $filters['is_active'] ?? null;

        if ($isActive !== null) {
            $where .= ' AND u.is_active = :is_active';
            $bindings['is_active'] = $isActive ? 1 : 0;
        }

        return [$where, $bindings];
    }

    /**
     * Name for the UI. Never includes the email.
     */
    public function displayName(array $user): string
    {
        $name = trim(sprintf('%s %s', (string) ($user['first_name'] ?? ''), (string) ($user['last_name'] ?? '')));

        return $name !== '' ? $name : (string) ($user['email'] ?? 'Unknown user');
    }

    /**
     * Record a successful sign-in and clear the lockout counters.
     */
    public function recordSuccessfulLogin(int $id): void
    {
        $this->database->update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ], 'id = :id', ['id' => $id]);
    }

    /**
     * Increment the failure counter and lock the account once the threshold is
     * reached (defence in depth on top of the login_attempts throttle).
     *
     * The arithmetic happens in PHP so the statement is identical on MySQL and
     * on the SQLite database the test suite uses.
     */
    public function recordFailedLogin(int $id, int $threshold, int $lockoutMinutes): void
    {
        $user = $this->findById($id);

        if ($user === null) {
            return;
        }

        $attempts = (int) ($user['failed_login_attempts'] ?? 0) + 1;
        $lockedUntil = $user['locked_until'] ?? null;

        if ($threshold > 0 && $attempts >= $threshold && $lockoutMinutes > 0) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockoutMinutes * 60);
        }

        $this->database->update('users', [
            'failed_login_attempts' => $attempts,
            'locked_until' => $lockedUntil,
        ], 'id = :id', ['id' => $id]);
    }

    /**
     * Replace a stored hash after a transparent rehash on login (AUTH-02).
     */
    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->database->update('users', ['password_hash' => $hash], 'id = :id', ['id' => $id]);
    }

    /**
     * Create an account (used by bin/create-user.php until Phase 19 seeds the
     * demo data set).
     *
     * @param array<string, mixed> $data Already validated and normalised by the caller.
     */
    public function create(array $data): int
    {
        $row = [
            'role_id' => (int) $data['role_id'],
            'department_id' => isset($data['department_id']) ? (int) $data['department_id'] : null,
            'email' => strtolower(trim((string) $data['email'])),
            'username' => strtolower(trim((string) $data['username'])),
            'password_hash' => (string) $data['password_hash'],
            'first_name' => (string) $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => (string) $data['last_name'],
            'employee_no' => $data['employee_no'] ?? null,
            'is_active' => 1,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ];

        return $this->database->insert('users', $row);
    }
}
