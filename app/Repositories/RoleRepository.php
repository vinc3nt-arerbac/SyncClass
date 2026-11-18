<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Roles (Phase 7 bootstrap; extended by the Phase 8 RBAC module).
 *
 * The role row itself is small by design: the actual capability list lives in
 * `permissions` / `role_permissions` and is read by PermissionRepository. This
 * repository only resolves and maintains the four role records the matrix in
 * config/permissions.php declares.
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
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->database->selectOne(
            'SELECT id, code, name, description, sort_order, is_system FROM roles WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->database->select('SELECT id, code, name, sort_order FROM roles ORDER BY sort_order, name');
    }

    /**
     * Create or update a role from the catalogue, keeping its id stable so
     * existing users and role_permissions rows are never orphaned.
     *
     * `is_system` is only set on insert: a role declared by the application is
     * system-owned, and a later sync must not clear that flag.
     */
    public function save(string $code, string $name, ?string $description = null, int $sortOrder = 10): int
    {
        $code = strtolower(trim($code));
        $existing = $this->findByCode($code);

        if ($existing !== null) {
            $this->database->update('roles', [
                'name' => mb_substr($name, 0, 100),
                'description' => $description !== null ? mb_substr($description, 0, 255) : null,
                'sort_order' => max(0, min(255, $sortOrder)),
            ], 'id = :id', ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->database->insert('roles', [
            'code' => mb_substr($code, 0, 50),
            'name' => mb_substr($name, 0, 100),
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'sort_order' => max(0, min(255, $sortOrder)),
            'is_system' => 1,
        ]);
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
