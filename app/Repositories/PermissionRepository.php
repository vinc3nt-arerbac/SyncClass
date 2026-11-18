<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Permission catalogue and the role → permission mapping (Phase 8).
 *
 * `permissions` holds one row per capability (`schedules.approve`), and
 * `role_permissions` is the additive many-to-many link. AuthorizationService
 * asks this repository for a role's codes and caches the answer for the
 * request; nothing else reads the table.
 *
 * Writes happen only in `bin/sync-rbac.php` (through App\Services\RbacService),
 * which is why replaceRolePermissions() is a full replacement inside one
 * transaction: a half-synced matrix must never be visible to a request.
 */
final class PermissionRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Every permission, ordered for a deterministic admin screen and audit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->database->select(
            'SELECT id, code, module, name, description FROM permissions ORDER BY module, code'
        );
    }

    /**
     * Same rows keyed by code.
     *
     * @return array<string, array<string, mixed>>
     */
    public function allByCode(): array
    {
        $coded = [];

        foreach ($this->all() as $permission) {
            $coded[(string) $permission['code']] = $permission;
        }

        return $coded;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        return $this->database->selectOne(
            'SELECT id, code, module, name, description FROM permissions WHERE code = :code LIMIT 1',
            ['code' => strtolower(trim($code))]
        );
    }

    /**
     * Permission codes granted to one role.
     *
     * @return array<int, string>
     */
    public function codesForRole(int $roleId): array
    {
        $rows = $this->database->select(
            'SELECT p.code
               FROM permissions p
               INNER JOIN role_permissions rp ON rp.permission_id = p.id
              WHERE rp.role_id = :role_id
              ORDER BY p.code',
            ['role_id' => $roleId]
        );

        return array_map(static fn (array $row): string => (string) $row['code'], $rows);
    }

    /**
     * Create or update one permission row.
     *
     * @return int The permission id.
     */
    public function save(string $code, string $module, string $name, ?string $description = null): int
    {
        $code = strtolower(trim($code));
        $existing = $this->findByCode($code);

        if ($existing !== null) {
            $this->database->update('permissions', [
                'module' => mb_substr($module, 0, 40),
                'name' => mb_substr($name, 0, 120),
                'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            ], 'id = :id', ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->database->insert('permissions', [
            'code' => mb_substr($code, 0, 80),
            'module' => mb_substr($module, 0, 40),
            'name' => mb_substr($name, 0, 120),
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
        ]);
    }

    /**
     * Replace the full permission set of a role in one transaction.
     *
     * Unknown codes are the caller's error: RbacService validates them against
     * the catalogue before reaching this method, so a typo cannot silently
     * remove access by inserting nothing.
     *
     * @param array<int, string> $codes
     * @return int Number of links written.
     */
    public function replaceRolePermissions(int $roleId, array $codes): int
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $code): string => strtolower(trim((string) $code)),
            $codes
        ))));

        return (int) $this->database->transaction(function () use ($roleId, $codes): int {
            $this->database->delete('role_permissions', 'role_id = :role_id', ['role_id' => $roleId]);

            if ($codes === []) {
                return 0;
            }

            $byCode = $this->allByCode();
            $written = 0;

            foreach ($codes as $code) {
                if (!isset($byCode[$code])) {
                    continue;
                }

                $this->database->insert('role_permissions', [
                    'role_id' => $roleId,
                    'permission_id' => (int) $byCode[$code]['id'],
                ]);

                $written++;
            }

            return $written;
        });
    }
}
