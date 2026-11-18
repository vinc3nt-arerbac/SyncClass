<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Roles (Phase 7 bootstrap; extended by the Phase 8 RBAC module).
 *
 * Phase 7 only needs to resolve a role id when creating the first account, so
 * this repository stays deliberately small: read one, list all, create if
 * absent. Phase 8 adds permissions and the role → permission mapping.
 */
final class RoleRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        return $this->database->selectOne(
            'SELECT id, code, name, description, sort_order, is_system FROM roles WHERE code = :code LIMIT 1',
            ['code' => strtolower(trim($code))]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->database->select('SELECT id, code, name FROM roles ORDER BY sort_order, name');
    }

    /**
     * Create a role if the code does not exist yet, returning its id.
     */
    public function firstOrCreate(string $code, string $name, ?string $description = null, int $sortOrder = 10): int
    {
        $existing = $this->findByCode($code);

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        return $this->database->insert('roles', [
            'code' => strtolower(trim($code)),
            'name' => $name,
            'description' => $description,
            'sort_order' => $sortOrder,
            'is_system' => 1,
        ]);
    }
}
