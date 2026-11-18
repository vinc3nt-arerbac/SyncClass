<?php

declare(strict_types=1);

namespace App\Controllers\MasterData;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Repositories\SectionRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;

/**
 * Sections (MD-04).
 *
 * A section is a cohort with a year level and a student count; the count is
 * what the conflict engine compares against room capacity later. Scope and
 * write rules mirror subjects: department-scoped reads, a scoped department on
 * write, archive instead of delete.
 */
final class SectionController extends MasterDataController
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        MasterDataService $masterData,
        AuthorizationService $authorization,
        AuditRepository $audit,
        private readonly SectionRepository $sections
    ) {
        parent::__construct($request, $view, $router, $config, $masterData, $authorization, $audit);
    }

    public function index(Request $request): Response
    {
        $scope = $this->scope($request);
        $filters = [
            'search' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'department_id' => $request->query('department'),
            'year_level' => $request->query('year'),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];
        $pagination = $this->pagination($request);
        $result = $this->sections->paginate($scope, $filters, $pagination['per_page'], $pagination['offset']);
        $user = $this->actor($request);

        return $this->view('master/sections/index', [
            'title' => 'Sections',
            'sections' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'departments' => $this->departmentOptions($scope),
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'last_page' => $this->lastPage($result['total'], $pagination['per_page']),
            'notice' => $this->notice($request),
            'can_create' => $this->authorization->can($user, 'sections.create'),
            'can_edit' => $this->authorization->can($user, 'sections.update'),
            'can_archive' => $this->authorization->can($user, 'sections.delete'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $scope = $this->scope($request);
        $data = $this->validateSection($request);
        $data['department_id'] = $this->guardDepartment($scope, (int) $data['department_id']);
        $this->guardUnique($this->sections->codeExists($data['code']), 'code', 'That section code is already in use.');

        $id = $this->sections->create($data);
        $this->auditChange($request, 'master.section.created', 'section', $id, 'Created section ' . $data['code']);

        return $this->saved('master.sections.index', 'created');
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->load($request, (int) $id));
    }

    public function update(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);
        $scope = $this->scope($request);
        $data = $this->validateSection($request);
        $data['department_id'] = $this->guardDepartment($scope, (int) $data['department_id']);
        $this->guardUnique(
            $this->sections->codeExists($data['code'], (int) $record['id']),
            'code',
            'That section code is already in use.'
        );

        $this->sections->update((int) $record['id'], $data);
        $this->auditChange($request, 'master.section.updated', 'section', (int) $record['id'], 'Updated section ' . $data['code']);

        return $this->saved('master.sections.index', 'updated');
    }

    public function toggle(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);
        $active = (int) $record['is_active'] === 1;

        $this->sections->setActive((int) $record['id'], !$active);
        $this->auditChange(
            $request,
            $active ? 'master.section.archived' : 'master.section.restored',
            'section',
            (int) $record['id'],
            ($active ? 'Archived' : 'Restored') . ' section ' . $record['code']
        );

        return $this->saved('master.sections.index', $active ? 'archived' : 'restored');
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function form(Request $request, ?array $record): Response
    {
        return $this->view('master/sections/form', [
            'title' => $record === null ? 'New section' : 'Edit ' . $record['code'],
            'section' => $record,
            'departments' => $this->departmentOptions($this->scope($request)),
            'can_edit' => $this->authorization->can($this->actor($request), $record === null ? 'sections.create' : 'sections.update'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(Request $request, int $id): array
    {
        return $this->requireRecord(
            $this->sections->findInScope($this->scope($request), $id),
            'That section does not exist.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSection(Request $request): array
    {
        return $this->validate(
            [
                'code' => 'required|alpha_dash|max:30',
                'name' => 'string|max:120',
                'program' => 'string|max:120',
                'year_level' => 'required|integer|min:1|max:6',
                'department_id' => 'required|integer|min:1',
                'student_count' => 'required|integer|min:1|max:2000',
            ],
            [
                'code' => 'Section code',
                'name' => 'Section name',
                'program' => 'Program',
                'year_level' => 'Year level',
                'department_id' => 'Department',
                'student_count' => 'Student count',
            ]
        );
    }
}
