<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use RuntimeException;

/**
 * Writes config/permissions.php into the database (Phase 8).
 *
 * `php bin/sync-rbac.php` calls this once after installation and again after
 * every change to the catalogue. It is safe to run repeatedly:
 *
 *   - roles are updated in place, so their ids (and every user pointing at
 *     them) survive a rename;
 *   - permissions are updated in place for the same reason;
 *   - each role's links are replaced inside a transaction, so a request either
 *     sees the previous matrix or the new one, never a half-written one;
 *   - grants that disappeared from the file are removed, which is what makes
 *     "revoke" a sync rather than a manual database edit.
 *
 * Unknown permission codes in the matrix are a hard error. Silently ignoring
 * them would turn a typo into a missing permission, and a missing permission
 * looks exactly like a bug at runtime — better to fail the deploy step.
 */
final class RbacService
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly Config $config
    ) {
    }

    /**
     * @return array{roles: int, permissions: int, links: int}
     */
    public function synchronise(): array
    {
        /** @var array<string, array{module?: string, name?: string, description?: string}> $codes */
        $codes = (array) $this->config->get('permissions.codes', []);

        /** @var array<string, array{name?: string, description?: string, sort_order?: int, permissions?: array<int, string>}> $matrix */
        $matrix = (array) $this->config->get('permissions.roles', []);

        if ($codes === [] || $matrix === []) {
            throw new RuntimeException('config/permissions.php defines no permissions or no roles.');
        }

        $known = array_keys($codes);
        $roleCount = 0;
        $links = 0;

        foreach ($matrix as $roleCode => $definition) {
            $granted = array_values((array) ($definition['permissions'] ?? []));
            $unknown = array_values(array_diff($granted, $known));

            if ($unknown !== []) {
                throw new RuntimeException(sprintf(
                    'Role "%s" grants unknown permission code(s): %s. Add them to config/permissions.php first.',
                    $roleCode,
                    implode(', ', $unknown)
                ));
            }
        }

        // Catalogue first: role_permissions has a foreign key to permissions.
        foreach ($codes as $code => $definition) {
            $this->permissions->save(
                (string) $code,
                (string) ($definition['module'] ?? 'general'),
                (string) ($definition['name'] ?? $code),
                isset($definition['description']) ? (string) $definition['description'] : null
            );
        }

        foreach ($matrix as $roleCode => $definition) {
            $roleId = $this->roles->save(
                (string) $roleCode,
                (string) ($definition['name'] ?? ucfirst((string) $roleCode)),
                isset($definition['description']) ? (string) $definition['description'] : null,
                (int) ($definition['sort_order'] ?? 10)
            );

            $links += $this->permissions->replaceRolePermissions($roleId, (array) ($definition['permissions'] ?? []));
            $roleCount++;
        }

        return [
            'roles' => $roleCount,
            'permissions' => count($codes),
            'links' => $links,
        ];
    }
}
