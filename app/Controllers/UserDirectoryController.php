<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuthorizationService;
use App\Services\Scope;

/**
 * Read-only staff directory (Phase 8).
 *
 * This page exists to make RBAC visible before Phase 9 builds real user
 * management: it is guarded by `permission:users.view` at the route, and every
 * query it makes goes through UserRepository's scope clause. The result is the
 * three-shaped behaviour the security model promises:
 *
 *   administrator → everyone
 *   dean/secretary → their department
 *   faculty → themselves
 *
 * The controller never filters by `department_id` from the session itself; it
 * builds a Scope and hands it to the repository, so there is one implementation
 * of the rule rather than one per page.
 */
final class UserDirectoryController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly AuthorizationService $authorization
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    public function index(Request $request): Response
    {
        $user = $request->attribute('auth_user');

        if (!is_array($user)) {
            // Unreachable while the route carries 'auth'; kept explicit so the
            // types below are honest and the page fails closed if it changes.
            $this->abort(401, 'Sign in to continue.');
        }

        $scope = Scope::forUser($user);

        $search = trim((string) $request->query('q', ''));
        $roleCode = strtolower(trim((string) $request->query('role', '')));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(5, min(100, (int) $this->config->get('app.pagination.per_page', 25)));

        $filters = ['search' => $search, 'role_code' => $roleCode];
        $total = $this->users->countForScope($scope, $filters);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        // Note: not keyed `scope` — View::evaluate() has a `$scope` parameter of
        // its own, and extract(EXTR_SKIP) would leave the template with that
        // array instead of this object.
        return $this->view('admin/users/index', [
            'title' => 'People',
            'access_scope' => $scope,
            'users' => $this->users->paginateForScope($scope, $filters, $perPage, ($page - 1) * $perPage),
            'roles' => $this->roles->all(),
            'filters' => ['search' => $search, 'role_code' => $roleCode],
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'can_edit' => $this->authorization->can($user, 'users.update'),
            'can_create' => $this->authorization->can($user, 'users.create'),
        ]);
    }
}
