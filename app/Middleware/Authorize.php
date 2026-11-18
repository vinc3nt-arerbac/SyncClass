<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AuditRepository;
use App\Services\AuthorizationService;

/**
 * Route-level authorisation (Phase 8, deny by default).
 *
 * Routes declare rules through parameterised aliases:
 *
 *     ['auth', 'permission:users.view']
 *     ['auth', 'permission:schedules.create,schedules.update']   // any-of
 *     ['auth', 'role:administrator,dean']                        // any-of
 *
 * The Kernel parses those aliases into the `route_rules` request attribute and
 * places this middleware at the position the alias occupied (so `auth` always
 * runs first and `auth_user` is available here).
 *
 * Three failure modes, all 403:
 *
 *   1. the route declared no rule but still runs this middleware — a
 *      misconfiguration must lock the page, not open it;
 *   2. the user's role is not in `role:`;
 *   3. the user holds none of the `permission:` codes.
 *
 * The 403 is audited (`authz.denied`) with the route, the required rules and
 * the actor, which is what makes privilege probing visible after the fact.
 *
 * 403 rather than 404 here: the route itself is not a secret, only the action
 * is. Object-level checks (a schedule that belongs to another department) use
 * 404, because there the *existence* of the row must stay hidden — see
 * UserRepository::findByIdInScope().
 */
final class Authorize implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditRepository $audit
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        /** @var array{permissions?: array<int, string>, roles?: array<int, string>} $rules */
        $rules = (array) $request->attribute('route_rules', []);
        $permissions = array_values((array) ($rules['permissions'] ?? []));
        $roles = array_values((array) ($rules['roles'] ?? []));
        $user = $request->attribute('auth_user');

        if ($permissions === [] && $roles === []) {
            $this->deny($request, is_array($user) ? $user : null, $permissions, $roles, 'No authorisation rule was declared for this route.');
        }

        if (!is_array($user)) {
            // The route declared a rule but not 'auth': authentication is a
            // precondition of authorisation, so this is answered as 401.
            throw HttpException::unauthorized('Sign in to continue.');
        }

        if ($roles !== [] && !in_array($this->authorization->roleCode($user), $roles, true)) {
            $this->deny($request, $user, $permissions, $roles, 'The role does not satisfy this route.');
        }

        if ($permissions !== [] && !$this->authorization->canAny($user, $permissions)) {
            $this->deny($request, $user, $permissions, $roles, 'None of the required permissions is granted.');
        }

        return $next($request);
    }

    /**
     * Record the refusal and stop the request.
     *
     * @param array<string, mixed>|null $user
     * @param array<int, string>        $permissions
     * @param array<int, string>        $roles
     * @throws HttpException 403
     */
    private function deny(Request $request, ?array $user, array $permissions, array $roles, string $reason): never
    {
        $this->audit->record(
            'authz.denied',
            $user !== null ? (int) ($user['id'] ?? 0) ?: null : null,
            null,
            null,
            $reason,
            [
                'route' => (string) $request->attribute('route_name', $request->path()),
                'path' => $request->path(),
                'method' => $request->method(),
                'required_permissions' => $permissions,
                'required_roles' => $roles,
                'role' => $user !== null ? (string) ($user['role_code'] ?? '') : null,
            ],
            $request->ip(),
            $request->userAgent()
        );

        throw HttpException::forbidden('You do not have permission to perform this action.');
    }
}
