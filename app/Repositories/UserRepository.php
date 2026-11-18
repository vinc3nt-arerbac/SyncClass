<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

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
