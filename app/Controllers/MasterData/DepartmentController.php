<?php

declare(strict_types=1);

namespace App\Controllers\MasterData;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Repositories\DepartmentRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;
use App\Services\Scope;

/**
 * Departments (MD-01).
 *
 * The administrator maintains them; a dean or secretary sees only their own
 * department (read-only for the secretary, update for the dean). That is why
 * `index` is scoped and the `edit` route loads the row through the same scope:
 * a dean editing department 2 gets 404, not a form.
 *
 * Codes are stored upper-case and unique; duplicates come back as a 422 on the
 * `code` field. Departments are archived, never deleted — the screen explains
 * the dependencies when the row is archived.
 */
final class DepartmentController extends MasterDataController
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        MasterDataService $masterData,
        AuthorizationService $authorization,
        AuditRepository $audit,
        private readonly DepartmentRepository $departments
    ) {
        parent::__construct($request, $view, $router, $config, $masterData, $authorization, $audit);
    }

    public function index(Request $request): Response
    {
        $scope = $this->scope($request);
        $filters = [
            'search' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];
        $pagination = $this->pagination($request);
        $result = $this->departments->paginate($scope, $filters, $pagination['per_page'], $pagination['offset']);

        return $this->view('master/departments/index', [
            'title' => 'Departments',
            'departments' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'last_page' => $this->lastPage($result['total'], $pagination['per_page']),
            'notice' => $this->notice($request),
            'can_create' => $this->authorization->can($this->actor($request), 'departments.create'),
            'can_edit' => $this->authorization->can($this->actor($request), 'departments.update'),
            'can_archive' => $this->authorization->can($this->actor($request), 'departments.delete'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateDepartment($request);
        $this->guardUnique($this->departments->codeExists($data['code']), 'code', 'That department code is already in use.');

        $id = $this->departments->create($data);
        $this->auditChange($request, 'master.department.created', 'department', $id, 'Created department ' . $data['code']);

        return $this->saved('master.departments.index', 'created');
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->load($request, (int) $id));
    }

    public function update(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);
        $data = $this->validateDepartment($request);
        $this->guardUnique(
            $this->departments->codeExists($data['code'], (int) $record['id']),
            'code',
            'That department code is already in use.'
        );

        $this->departments->update((int) $record['id'], $data);
        $this->auditChange($request, 'master.department.updated', 'department', (int) $record['id'], 'Updated department ' . $data['code']);

        return $this->saved('master.departments.index', 'updated');
    }

    public function toggle(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);
        $active = (int) $record['is_active'] === 1;

        $this->departments->setActive((int) $record['id'], !$active);
        $this->auditChange(
            $request,
            $active ? 'master.department.archived' : 'master.department.restored',
            'department',
            (int) $record['id'],
            ($active ? 'Archived' : 'Restored') . ' department ' . $record['code']
        );

        return $this->saved('master.departments.index', $active ? 'archived' : 'restored');
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function form(Request $request, ?array $record): Response
    {
        $scope = $this->scope($request);

        return $this->view('master/departments/form', [
            'title' => $record === null ? 'New department' : 'Edit ' . $record['code'],
            'department' => $record,
            'scope' => $scope,
            'can_edit' => $this->authorization->can($this->actor($request), $record === null ? 'departments.create' : 'departments.update'),
            'dependencies' => $record === null ? null : $this->departments->dependencies((int) $record['id']),
        ]);
    }

    /**
     * Load a department inside the caller's scope, or 404.
     *
     * @return array<string, mixed>
     */
    private function load(Request $request, int $id): array
    {
        $scope = $this->scope($request);
        $record = $this->requireRecord($this->departments->find($id), 'That department does not exist.');

        if (!$scope->coversDepartment((int) $record['id'])) {
            $this->abort(404, 'That department does not exist.');
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDepartment(Request $request): array
    {
        return $this->validate(
            [
                'code' => 'required|alpha_dash|max:20',
                'name' => 'required|string|max:150',
                'short_name' => 'string|max:50',
                'email' => 'email|max:150',
                'phone' => 'string|max:30',
                'color_hex' => 'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            [
                'code' => 'Department code',
                'name' => 'Department name',
                'short_name' => 'Short name',
                'email' => 'Department email',
                'phone' => 'Telephone',
                'color_hex' => 'Calendar colour',
            ],
            [
                'color_hex.regex' => 'Use a six-digit hex colour such as #8C1D40.',
            ]
        );
    }
}
