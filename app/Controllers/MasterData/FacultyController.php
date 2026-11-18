<?php

declare(strict_types=1);

namespace App\Controllers\MasterData;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Repositories\AvailabilityRepository;
use App\Repositories\FacultyRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;

/**
 * Faculty profiles and availability (MD-05).
 *
 * Two resources share one controller because they share one authorisation
 * boundary: a profile. Availability routes therefore take the *profile* id and
 * load it through `findInScope()` — a faculty member may edit their own
 * windows, a dean the windows of their department, and nobody can post a
 * hidden `faculty_id` to touch someone else's.
 *
 * Only user accounts whose role is `faculty` and that do not already have a
 * profile can be chosen when creating one; the repository supplies that list,
 * scoped, and the controller re-checks it rather than trusting the form.
 */
final class FacultyController extends MasterDataController
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        MasterDataService $masterData,
        AuthorizationService $authorization,
        AuditRepository $audit,
        private readonly FacultyRepository $faculty,
        private readonly AvailabilityRepository $availability
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
            'employment_type' => (string) $request->query('employment', ''),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];
        $pagination = $this->pagination($request);
        $result = $this->faculty->paginate($scope, $filters, $pagination['per_page'], $pagination['offset']);
        $user = $this->actor($request);

        return $this->view('master/faculty/index', [
            'title' => 'Faculty',
            'faculty' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'departments' => $this->departmentOptions($scope),
            'employment_types' => FacultyRepository::EMPLOYMENT_TYPES,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'last_page' => $this->lastPage($result['total'], $pagination['per_page']),
            'notice' => $this->notice($request),
            'can_create' => $this->authorization->can($user, 'faculty.create'),
            'can_edit' => $this->authorization->can($user, 'faculty.update'),
            'can_archive' => $this->authorization->can($user, 'faculty.delete'),
            'can_availability' => $this->authorization->can($user, 'availability.update'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $scope = $this->scope($request);
        $data = $this->validateProfile($request);
        $data['department_id'] = $this->guardDepartment($scope, (int) $data['department_id']);

        $eligible = array_column($this->faculty->availableUsers($scope), 'id');

        if (!in_array((int) $data['faculty_id'], $eligible, true)) {
            throw HttpException::unprocessable(['faculty_id' => 'Choose an active faculty account without a profile.']);
        }

        $id = $this->faculty->create($data);
        $this->auditChange($request, 'master.faculty.created', 'faculty_profile', $id, 'Created faculty profile #' . $id);

        return $this->saved('master.faculty.index', 'created');
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->load($request, (int) $id));
    }

    public function update(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);
        $scope = $this->scope($request);
        $data = $this->validateProfile($request, false);
        $data['department_id'] = $this->guardDepartment($scope, (int) $data['department_id']);

        $this->faculty->update((int) $record['id'], $data);
        $this->auditChange($request, 'master.faculty.updated', 'faculty_profile', (int) $record['id'], 'Updated faculty profile #' . $record['id']);

        return $this->saved('master.faculty.index', 'updated');
    }

    public function toggle(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);
        $active = (int) $record['is_active'] === 1;

        $this->faculty->setActive((int) $record['id'], !$active);
        $this->auditChange(
            $request,
            $active ? 'master.faculty.archived' : 'master.faculty.restored',
            'faculty_profile',
            (int) $record['id'],
            ($active ? 'Archived' : 'Restored') . ' faculty profile #' . $record['id']
        );

        return $this->saved('master.faculty.index', $active ? 'archived' : 'restored');
    }

    /**
     * Availability windows for one profile.
     */
    public function availability(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);

        return $this->view('master/faculty/availability', [
            'title' => 'Availability · ' . $record['first_name'] . ' ' . $record['last_name'],
            'profile' => $record,
            'windows' => $this->availability->forFaculty((int) $record['id']),
            'types' => AvailabilityRepository::TYPES,
            'notice' => $this->notice($request),
        ]);
    }

    public function storeAvailability(Request $request, string $id): Response
    {
        $record = $this->load($request, (int) $id);
        $data = $this->validate(
            [
                'day_of_week' => 'required|integer|min:1|max:7',
                'start_time' => 'required|time',
                'end_time' => 'required|time|after:start_time',
                'availability_type' => 'required|in:' . implode(',', AvailabilityRepository::TYPES),
                'note' => 'string|max:255',
            ],
            [
                'day_of_week' => 'Weekday',
                'start_time' => 'Start time',
                'end_time' => 'End time',
                'availability_type' => 'Window type',
                'note' => 'Note',
            ]
        );

        $start = $this->normaliseTime($data['start_time']);
        $end = $this->normaliseTime($data['end_time']);

        if ($this->availability->overlaps((int) $record['id'], (int) $data['day_of_week'], $start, $end)) {
            throw HttpException::unprocessable(['start_time' => 'That window overlaps an existing window on the same day.']);
        }

        $windowId = $this->availability->create((int) $record['id'], [
            'day_of_week' => $data['day_of_week'],
            'start_time' => $start,
            'end_time' => $end,
            'availability_type' => $data['availability_type'],
            'note' => $data['note'] ?? null,
        ]);

        $this->auditChange(
            $request,
            'master.availability.created',
            'faculty_availability',
            $windowId,
            'Added availability window to profile #' . $record['id'],
            ['window_id' => $windowId, 'profile_id' => (int) $record['id']]
        );

        return $this->saved('master.faculty.availability', 'window-added', ['id' => $record['id']]);
    }

    public function destroyAvailability(Request $request, string $id, string $windowId): Response
    {
        $record = $this->load($request, (int) $id);
        $window = $this->requireRecord($this->availability->find((int) $windowId), 'That availability window does not exist.');

        if ((int) $window['faculty_id'] !== (int) $record['id']) {
            $this->abort(404, 'That availability window does not exist.');
        }

        $this->availability->delete((int) $window['id']);
        $this->auditChange(
            $request,
            'master.availability.deleted',
            'faculty_availability',
            (int) $window['id'],
            'Removed availability window from profile #' . $record['id']
        );

        return $this->saved('master.faculty.availability', 'window-removed', ['id' => $record['id']]);
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function form(Request $request, ?array $record): Response
    {
        $scope = $this->scope($request);
        $user = $this->actor($request);

        return $this->view('master/faculty/form', [
            'title' => $record === null ? 'New faculty profile' : 'Edit ' . $record['first_name'] . ' ' . $record['last_name'],
            'profile' => $record,
            'departments' => $this->departmentOptions($scope),
            'employment_types' => FacultyRepository::EMPLOYMENT_TYPES,
            'candidates' => $record === null ? $this->faculty->availableUsers($scope) : [],
            'can_edit' => $this->authorization->can($user, $record === null ? 'faculty.create' : 'faculty.update'),
            'can_availability' => $this->authorization->can($user, 'availability.update'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(Request $request, int $id): array
    {
        return $this->requireRecord(
            $this->faculty->findInScope($this->scope($request), $id),
            'That faculty profile does not exist.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProfile(Request $request, bool $creating = true): array
    {
        $rules = [
            'department_id' => 'required|integer|min:1',
            'employment_type' => 'required|in:' . implode(',', FacultyRepository::EMPLOYMENT_TYPES),
            'max_load_units' => 'required|numeric|min:1|max:40',
            'specialization' => 'string|max:180',
            'office' => 'string|max:80',
        ];

        if ($creating) {
            $rules = ['faculty_id' => 'required|integer|min:1'] + $rules;
        }

        return $this->validate(
            $rules,
            [
                'faculty_id' => 'Faculty account',
                'department_id' => 'Department',
                'employment_type' => 'Employment type',
                'max_load_units' => 'Maximum load (units)',
                'specialization' => 'Specialisation',
                'office' => 'Office',
            ]
        );
    }

    /**
     * The time rule accepts `H:i`; the TIME column wants `H:i:s`.
     */
    private function normaliseTime(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
