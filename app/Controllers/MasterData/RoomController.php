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
use App\Repositories\RoomRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;

/**
 * Rooms (MD-02, CNF-05).
 *
 * The room code is unique per building, so the form asks for both and the
 * duplicate check includes the building. Type and capacity are the fields the
 * conflict engine will read later; they are validated strictly now so the
 * scheduler never sees a zero-capacity laboratory.
 */
final class RoomController extends MasterDataController
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        MasterDataService $masterData,
        AuthorizationService $authorization,
        AuditRepository $audit,
        private readonly RoomRepository $rooms,
        private readonly BuildingRepository $buildings
    ) {
        parent::__construct($request, $view, $router, $config, $masterData, $authorization, $audit);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'building_id' => $request->query('building'),
            'room_type' => (string) $request->query('type', ''),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];
        $pagination = $this->pagination($request);
        $result = $this->rooms->paginate($filters, $pagination['per_page'], $pagination['offset']);
        $user = $this->actor($request);

        return $this->view('master/rooms/index', [
            'title' => 'Rooms',
            'rooms' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'buildings' => $this->buildings->options(false),
            'room_types' => RoomRepository::TYPES,
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
        $data = $this->validateRoom($request);
        $this->guardUnique(
            $this->rooms->codeExistsInBuilding((int) $data['building_id'], $data['code']),
            'code',
            'That room code already exists in the selected building.'
        );

        $id = $this->rooms->create($data);
        $this->auditChange($request, 'master.room.created', 'room', $id, 'Created room ' . $data['code']);

        return $this->saved('master.rooms.index', 'created');
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->requireRecord($this->rooms->find((int) $id), 'That room does not exist.'));
    }

    public function update(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->rooms->find((int) $id), 'That room does not exist.');
        $data = $this->validateRoom($request);
        $this->guardUnique(
            $this->rooms->codeExistsInBuilding((int) $data['building_id'], $data['code'], (int) $record['id']),
            'code',
            'That room code already exists in the selected building.'
        );

        $this->rooms->update((int) $record['id'], $data);
        $this->auditChange($request, 'master.room.updated', 'room', (int) $record['id'], 'Updated room ' . $data['code']);

        return $this->saved('master.rooms.index', 'updated');
    }

    public function toggle(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->rooms->find((int) $id), 'That room does not exist.');
        $active = (int) $record['is_active'] === 1;

        $this->rooms->setActive((int) $record['id'], !$active);
        $this->auditChange(
            $request,
            $active ? 'master.room.archived' : 'master.room.restored',
            'room',
            (int) $record['id'],
            ($active ? 'Archived' : 'Restored') . ' room ' . $record['code']
        );

        return $this->saved('master.rooms.index', $active ? 'archived' : 'restored');
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function form(Request $request, ?array $record): Response
    {
        return $this->view('master/rooms/form', [
            'title' => $record === null ? 'New room' : 'Edit ' . $record['code'],
            'room' => $record,
            'buildings' => $this->buildings->options(false),
            'room_types' => RoomRepository::TYPES,
            'can_edit' => $this->authorization->can($this->actor($request), $record === null ? 'rooms.create' : 'rooms.update'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRoom(Request $request): array
    {
        return $this->validate(
            [
                'building_id' => 'required|integer|min:1',
                'code' => 'required|alpha_dash|max:20',
                'name' => 'string|max:120',
                'floor' => 'integer|min:-3|max:60',
                'room_type' => 'required|in:' . implode(',', RoomRepository::TYPES),
                'capacity' => 'required|integer|min:1|max:1000',
                'facilities' => 'string|max:500',
            ],
            [
                'building_id' => 'Building',
                'code' => 'Room code',
                'name' => 'Room name',
                'floor' => 'Floor',
                'room_type' => 'Room type',
                'capacity' => 'Capacity',
                'facilities' => 'Facilities',
            ]
        );
    }
}
