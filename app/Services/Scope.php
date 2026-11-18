<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Data scope of the signed-in user (Phase 8, O-4).
 *
 * Scope answers a different question from permissions:
 *
 *   - a **permission** says *what* the user may do (`schedules.update`);
 *   - a **scope** says *whose rows* those actions may touch.
 *
 * Role is a ceiling, scope is a filter. Faculty may "view schedules" — the
 * permission — but the scope limits the rows to the ones they teach.
 *
 * Repositories take a Scope as a required argument and add the WHERE clause
 * themselves (see UserRepository::paginateForScope()). There is deliberately
 * no "scope optional" path: forgetting to pass a scope would silently widen a
 * query to the whole institution.
 *
 * Three shapes exist:
 *
 *   administrator  → every department (isAdministrator() true)
 *   dean, secretary → one department (departmentId() is an int)
 *   faculty        → only the user's own rows (isSelfOnly() true)
 *
 * A non-administrator without a department belongs to no scope and therefore
 * sees nothing; that is safer than falling back to "everything".
 */
final class Scope
{
    public const ADMIN_ROLE = 'administrator';
    public const SELF_ROLE = 'faculty';

    private function __construct(
        private readonly int $userId,
        private readonly ?int $departmentId,
        private readonly string $roleCode
    ) {
    }

    /**
     * Build the scope of a user row produced by UserRepository.
     *
     * @param array<string, mixed> $user
     */
    public static function forUser(array $user): self
    {
        $userId = (int) ($user['id'] ?? 0);
        $departmentId = isset($user['department_id']) && $user['department_id'] !== null
            ? (int) $user['department_id']
            : null;
        $roleCode = strtolower((string) ($user['role_code'] ?? ''));

        if ($userId <= 0 || $roleCode === '') {
            throw new InvalidArgumentException('A scope can only be built from a persisted user with a role.');
        }

        return new self($userId, $departmentId, $roleCode);
    }

    /**
     * Explicit system scope for console commands and seeders that legitimately
     * operate institution-wide (Phase 19). It is not reachable from a request:
     * nothing in the HTTP layer constructs it from user input.
     */
    public static function system(): self
    {
        return new self(0, null, self::ADMIN_ROLE);
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function departmentId(): ?int
    {
        return $this->departmentId;
    }

    public function roleCode(): string
    {
        return $this->roleCode;
    }

    /**
     * True when the scope is not filtered by department.
     */
    public function isAdministrator(): bool
    {
        return $this->roleCode === self::ADMIN_ROLE;
    }

    /**
     * Faculty see their own rows only, in every module that has an owner column.
     */
    public function isSelfOnly(): bool
    {
        return !$this->isAdministrator() && $this->roleCode === self::SELF_ROLE;
    }

    /**
     * True when the scope is restricted to a department (dean, secretary).
     */
    public function isDepartmental(): bool
    {
        return !$this->isAdministrator() && !$this->isSelfOnly();
    }

    /**
     * Whether a row that belongs to $departmentId is inside this scope.
     *
     * Used for object-level checks after loading a row: a dean may approve only
     * schedules whose department is theirs. A null department on the row is
     * never visible to a departmental user.
     */
    public function coversDepartment(?int $departmentId): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        if ($this->departmentId === null || $departmentId === null) {
            return false;
        }

        return $this->departmentId === $departmentId;
    }

    /**
     * Whether the scope may see the given user row (own department, or self).
     *
     * @param array<string, mixed> $user
     */
    public function coversUser(array $user): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        if ($this->isSelfOnly()) {
            return (int) ($user['id'] ?? 0) === $this->userId;
        }

        return $this->coversDepartment(
            isset($user['department_id']) ? (int) $user['department_id'] : null
        );
    }

    /**
     * A short, non-sensitive label for the UI and for audit records.
     */
    public function label(): string
    {
        if ($this->isAdministrator()) {
            return 'Institution-wide';
        }

        if ($this->isSelfOnly()) {
            return 'Own records only';
        }

        return $this->departmentId === null ? 'No department (no access)' : 'Department #' . $this->departmentId;
    }

    /**
     * @return array{user_id: int, department_id: int|null, role_code: string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'department_id' => $this->departmentId,
            'role_code' => $this->roleCode,
        ];
    }
}
