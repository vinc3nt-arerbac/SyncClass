<?php

declare(strict_types=1);

namespace App\Controllers\MasterData;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Repositories\BuildingRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;

/**
 * Buildings (MD-02).
 *
 * Institution-wide reference data with no department column, so there is no
 * scope: the route permissions are the whole rule (administrator maintains,
 * everyone with `rooms.view` reads). Archived, never deleted — rooms point at
 * buildings and schedules will point at rooms.
 */
final class BuildingController extends MasterDataController
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        MasterDataService $masterData,
        AuthorizationService $authorization,
        AuditRepository $audit,
        private readonly BuildingRepository $buildings
    ) {
        parent::__construct($request, $view, $router, $config, $masterData, $authorization, $audit);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];
        $pagination = $this->pagination($request);
        $result = $this->buildings->paginate($filters, $pagination['per_page'], $pagination['offset']);
        $user = $this->actor($request);

        return $this->view('master/buildings/index', [
            'title' => 'Buildings',
            'buildings' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'last_page' => $this->lastPage($result['total'], $pagination['per_page']),
            'notice' => $this->notice($request),
            'can_create' => $this->authorization->can($user, 'rooms.create'),
            'can_edit' => $this->authorization->can($user, 'rooms.update'),
            'can_archive' => $this->authorization->can($user, 'rooms.delete'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateBuilding($request);
        $this->guardUnique($this->buildings->codeExists($data['code']), 'code', 'That building code is already in use.');

        $id = $this->buildings->create($data);
        $this->auditChange($request, 'master.building.created', 'building', $id, 'Created building ' . $data['code']);

        return $this->saved('master.buildings.index', 'created');
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->requireRecord($this->buildings->find((int) $id), 'That building does not exist.'));
    }

    public function update(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->buildings->find((int) $id), 'That building does not exist.');
        $data = $this->validateBuilding($request);
        $this->guardUnique(
            $this->buildings->codeExists($data['code'], (int) $record['id']),
            'code',
            'That building code is already in use.'
        );

        $this->buildings->update((int) $record['id'], $data);
        $this->auditChange($request, 'master.building.updated', 'building', (int) $record['id'], 'Updated building ' . $data['code']);

        return $this->saved('master.buildings.index', 'updated');
    }

    public function toggle(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->buildings->find((int) $id), 'That building does not exist.');
        $active = (int) $record['is_active'] === 1;

        $this->buildings->setActive((int) $record['id'], !$active);
        $this->auditChange(
            $request,
            $active ? 'master.building.archived' : 'master.building.restored',
            'building',
            (int) $record['id'],
            ($active ? 'Archived' : 'Restored') . ' building ' . $record['code']
        );

        return $this->saved('master.buildings.index', $active ? 'archived' : 'restored');
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function form(Request $request, ?array $record): Response
    {
        return $this->view('master/buildings/form', [
            'title' => $record === null ? 'New building' : 'Edit ' . $record['code'],
            'building' => $record,
            'can_edit' => $this->authorization->can($this->actor($request), $record === null ? 'rooms.create' : 'rooms.update'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBuilding(Request $request): array
    {
        return $this->validate(
            [
                'code' => 'required|alpha_dash|max:20',
                'name' => 'required|string|max:120',
                'campus' => 'string|max:120',
                'address' => 'string|max:255',
            ],
            ['code' => 'Building code', 'name' => 'Building name', 'campus' => 'Campus', 'address' => 'Address']
        );
    }
}
