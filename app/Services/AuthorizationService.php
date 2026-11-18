<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\PermissionRepository;

/**
 * Permission decisions (Phase 8, denies by default).
 *
 * The only question this service answers is "may this user perform this
 * permission?". It knows nothing about HTTP, routes or views, which is what
 * makes it usable from three places without duplication:
 *
 *   - Authorize middleware, for route-level rules;
 *   - controllers/services, for object-level decisions after loading a row;
 *   - views (through the `$can` helper), to hide what the user cannot use.
 *
 * Rules that are not negotiable here:
 *
 *   1. **Unknown means denied.** A code that is not in `permissions` is false
 *      for everyone, including the administrator. There is no `isAdmin()`
 *      bypass: the administrator role holds an explicit, complete list, so a
 *      newly invented permission stays closed until it is catalogued and
 *      granted. A typo in a route therefore locks the page instead of opening
 *      it.
 *   2. **Inactive means denied.** A deactivated account keeps no access even
 *      if its role still holds the permission.
 *   3. **No user means denied.** Null, missing role id and malformed rows all
 *      return false rather than throwing, because this is called from views
 *      that also render for anonymous error pages.
 *   4. **Permissions are additive and flat.** No role implies another and
 *      nothing is inherited; the list in config/permissions.php is the whole
 *      truth.
 *
 * The grant set is cached per role for the lifetime of the request: a page
 * typically asks a dozen questions and they must not become a dozen queries.
 * flush() exists for console commands and tests that change the matrix mid-run.
 */
final class AuthorizationService
{
    /** @var array<int, array<string, true>> role id => granted codes */
    private array $grants = [];

    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    /**
     * Sorted permission codes granted to the user's role.
     *
     * @param array<string, mixed>|null $user
     * @return array<int, string>
     */
    public function permissionsFor(?array $user): array
    {
        $roleId = $this->roleId($user);

        if ($roleId === null) {
            return [];
        }

        $codes = array_keys($this->grantsFor($roleId));
        sort($codes);

        return $codes;
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function can(?array $user, string $permission): bool
    {
        $permission = strtolower(trim($permission));

        if ($permission === '' || !$this->isUsable($user)) {
            return false;
        }

        $roleId = $this->roleId($user);

        return $roleId !== null && isset($this->grantsFor($roleId)[$permission]);
    }

    /**
     * Any-of check for route rules: `permission:schedules.create,schedules.update`.
     *
     * @param array<string, mixed>|null $user
     * @param array<int, string> $permissions
     */
    public function canAny(?array $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All-of check, for a controller that needs two capabilities at once.
     *
     * @param array<string, mixed>|null $user
     * @param array<int, string> $permissions
     */
    public function canAll(?array $user, array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->can($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Throw 403 unless the user holds the permission.
     *
     * Used for decisions that depend on the loaded row ("the schedule exists,
     * but it is not yours"), where the middleware cannot help.
     *
     * @param array<string, mixed>|null $user
     * @throws HttpException 403
     */
    public function authorize(?array $user, string $permission, string $message = ''): void
    {
        if ($this->can($user, $permission)) {
            return;
        }

        throw HttpException::forbidden(
            $message !== '' ? $message : 'You do not have permission to perform this action.'
        );
    }

    /**
     * Whether a permission code exists in the catalogue at all.
     *
     * A route guarded by an unknown code is a configuration error; the
     * middleware still denies it, and this method lets a console command or a
     * test report *why*.
     */
    public function isDefined(string $permission): bool
    {
        return $this->permissions->findByCode($permission) !== null;
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function roleCode(?array $user): ?string
    {
        if (!is_array($user)) {
            return null;
        }

        $role = strtolower(trim((string) ($user['role_code'] ?? '')));

        return $role === '' ? null : $role;
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function isAdministrator(?array $user): bool
    {
        return $this->roleCode($user) === Scope::ADMIN_ROLE;
    }

    /**
     * Drop the cached grants (console commands, tests, role changes).
     */
    public function flush(): void
    {
        $this->grants = [];
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array<string, true>
     */
    private function grantsFor(int $roleId): array
    {
        if (!isset($this->grants[$roleId])) {
            $grants = [];

            foreach ($this->permissions->codesForRole($roleId) as $code) {
                $grants[strtolower($code)] = true;
            }

            $this->grants[$roleId] = $grants;
        }

        return $this->grants[$roleId];
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function roleId(?array $user): ?int
    {
        if (!is_array($user)) {
            return null;
        }

        $roleId = (int) ($user['role_id'] ?? 0);

        return $roleId > 0 ? $roleId : null;
    }

    /**
     * A user row is usable only when it names a role and is active.
     *
     * @param array<string, mixed>|null $user
     */
    private function isUsable(?array $user): bool
    {
        if (!is_array($user) || $this->roleId($user) === null) {
            return false;
        }

        return (int) ($user['is_active'] ?? 0) === 1;
    }
}
