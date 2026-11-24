<?php

declare(strict_types=1);

namespace App\Controllers\Scheduling;

use App\Core\Config;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\RoomRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\TermRepository;
use App\Services\AuthorizationService;
use App\Services\ConflictService;
use App\Services\ScheduleService;
use App\Services\Scope;

/**
 * Schedule entries (SCH-01…SCH-07).
 *
 * The controller is thin on purpose: it validates the shape of the form,
 * loads rows through the scope, calls ScheduleService (which owns every
 * scheduling rule) and audits the result. It never decides whether a room is
 * free or whether a transition is legal.
 *
 * Status changes are a separate POST because they are a separate permission:
 * a secretary builds the timetable (`schedules.update`) but only a dean or the
 * administrator approves or publishes it (`schedules.approve`). The service
 * checks that permission again for the specific target, so the button list on
 * the detail page and the server rule cannot drift.
 */
final class ScheduleController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly ScheduleRepository $schedules,
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects,
        private readonly FacultyRepository $faculty,
        private readonly RoomRepository $rooms,
        private readonly TermRepository $terms,
        private readonly ScheduleService $service,
        private readonly AuthorizationService $authorization,
        private readonly AuditRepository $audit,
        private readonly ConflictService $conflict
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    public function index(Request $request): Response
    {
        $scope = $this->scope($request);
        $filters = [
            'search' => (string) $request->query('q', ''),
            'semester_id' => $request->query('semester'),
            'status' => (string) $request->query('status', ''),
            'department_id' => $request->query('department'),
            'day_of_week' => $request->query('day'),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];

        $perPage = max(5, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->schedules->paginate($scope, $filters, $perPage, ($page - 1) * $perPage);
        $user = $this->actor($request);
        $term = $this->terms->currentSemester();

        return $this->view('schedules/index', [
            'title' => 'Schedules',
            'schedules' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'semesters' => $this->terms->semesterOptions(),
            'statuses' => ScheduleRepository::STATUSES,
            'days' => (array) $this->config->get('schedule.days', []),
            'current_semester' => $term,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            'notice' => $this->notice($request),
            'can_create' => $this->authorization->can($user, 'schedules.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    /**
     * Preview conflicts and alternative rooms for a candidate entry.
     *
     * Phase 11: `ConflictService::check()` never writes, so this endpoint is
     * safe to call from the form's "what if" widget. On a hard conflict it
     * returns 409 with `errors[]` and `alternatives[]` so the view can offer
     * the teacher a concrete fix without persisting anything.
     *
     * @param array<string, mixed> $data Validated form input.
     */
    public function conflictSuggestions(Request $request, string $id): Response
    {
        $schedule = $this->load($request, (int) $id);
        $scope = $this->scope($request);
        $actor = $this->actor($request);
        $data = $this->validateSchedule($request);

        // The conflict engine expects the *resolved* facts, not the form
        // payload: one row per selected weekday, the department derived from
        // the section, the recurrence normalised. The service already owns
        // that resolver for the same purpose inside create()/update().
        $resolved = $this->service->resolve($scope, $data);

        // All reads are scoped by the term, and every row here is active: the
        // candidate is compared against the term's real timetable — except the
        // entry being previewed, which must not clash with itself.
        $existing = array_values(array_filter(
            $this->schedules->activeInSemester((int) $resolved['semester']['id']),
            static fn (array $meeting): bool => (int) $meeting['id'] !== (int) $schedule['id']
        ));
        $result = $this->conflict->check($resolved, $existing);

        if ($result['status'] === 'ok') {
            return $this->json(['ok' => true]);
        }

        return $this->json([
            'ok' => false,
            'errors' => $result['errors'],
            'alternatives' => $this->conflict->suggestAlternatives($resolved, $existing)['alternatives'],
        ], 409);
    }

    public function store(Request $request): Response
    {
        $scope = $this->scope($request);
        $actor = $this->actor($request);
        $data = $this->validateSchedule($request);

        $ids = $this->service->create($scope, $data, $actor);

        $this->audit->record(
            'schedule.created',
            (int) $actor['id'] ?: null,
            'schedule',
            $ids[0],
            sprintf('Created %d meeting(s) for %s', count($ids), (string) $data['section_id']),
            ['schedule_ids' => $ids, 'semester_id' => (int) $data['semester_id']],
            $request->ip(),
            $request->userAgent()
        );

        return $this->redirectToList('created');
    }

    public function show(Request $request, string $id): Response
    {
        $schedule = $this->load($request, (int) $id);
        $actor = $this->actor($request);

        return $this->view('schedules/show', [
            'title' => $schedule['subject_code'] . ' · ' . $schedule['section_code'],
            'schedule' => $schedule,
            'days' => (array) $this->config->get('schedule.days', []),
            'transitions' => $this->service->allowedTransitions($schedule, $actor),
            'can_edit' => $this->authorization->can($actor, 'schedules.update'),
            'notice' => $this->notice($request),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->load($request, (int) $id));
    }

    public function update(Request $request, string $id): Response
    {
        $scope = $this->scope($request);
        $actor = $this->actor($request);
        $schedule = $this->load($request, (int) $id);
        $data = $this->validateSchedule($request);

        $this->service->update($scope, $schedule, $data, $actor);

        $this->audit->record(
            'schedule.updated',
            (int) $actor['id'] ?: null,
            'schedule',
            (int) $schedule['id'],
            'Updated schedule entry #' . $schedule['id'],
            ['status_before' => (string) $schedule['status']],
            $request->ip(),
            $request->userAgent()
        );

        return $this->redirectToList('updated');
    }

    /**
     * CAL-03 / UAT-6: apply a calendar drag or resize.
     *
     * The browser sends ISO-8601 `start`/`end` values for the dropped tile.
     * The endpoint converts them back into the form shape and calls the same
     * ScheduleService::update() the edit page uses, so a drag cannot bypass a
     * single scheduling rule. A clash is a 409 with `errors[]` and room
     * `alternatives[]`; the JavaScript reverts the tile on any non-2xx.
     *
     * The route is PATCH /api/schedules/{id} (see routes/web.php), which is
     * the URL assets/js/calendar.js has always called.
     */
    public function move(Request $request, string $id): Response
    {
        $scope = $this->scope($request);
        $actor = $this->actor($request);
        $schedule = $this->load($request, (int) $id);
        $payload = $request->json();

        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($payload['end'] ?? ''));
        } catch (\Exception) {
            return Response::json([
                'ok' => false,
                'message' => 'The drop did not carry a valid start and end.',
            ], 422);
        }

        $oneOff = (string) $schedule['recurrence'] === 'one_off';
        $data = [
            'semester_id' => (string) $schedule['semester_id'],
            'section_id' => (string) $schedule['section_id'],
            'subject_id' => (string) $schedule['subject_id'],
            'faculty_id' => (string) $schedule['faculty_id'],
            'room_id' => (string) $schedule['room_id'],
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'recurrence' => $oneOff ? 'one_off' : 'weekly',
            // A one-off moved to another day changes its date; a weekly entry
            // moves the single weekday it occupies.
            'meeting_date' => $oneOff ? $start->format('Y-m-d') : null,
            'days' => [$start->format('N')],
            'notes' => (string) ($schedule['notes'] ?? ''),
        ];

        try {
            $this->service->update($scope, $schedule, $data, $actor);
        } catch (HttpException $exception) {
            $errors = $exception->errors();
            $conflicts = $errors['conflicts'] ?? null;

            if (is_array($conflicts)) {
                // Offer the same room alternatives the form preview shows. The
                // row being moved is excluded so it cannot conflict with itself.
                $resolved = $this->service->resolve($scope, $data);
                $existing = array_values(array_filter(
                    $this->schedules->activeInSemester((int) $schedule['semester_id']),
                    static fn (array $meeting): bool => (int) $meeting['id'] !== (int) $schedule['id']
                ));

                return Response::json([
                    'ok' => false,
                    'message' => 'That slot clashes with an existing meeting.',
                    'errors' => $conflicts,
                    'alternatives' => $this->conflict->suggestAlternatives($resolved, $existing)['alternatives'] ?? [],
                ], 409);
            }

            return Response::json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'errors' => $errors,
            ], $exception->status());
        }

        $this->audit->record(
            'schedule.moved',
            (int) $actor['id'] ?: null,
            'schedule',
            (int) $schedule['id'],
            sprintf('Moved schedule #%d to %s %s–%s', $schedule['id'], $start->format('D'), $data['start_time'], $data['end_time']),
            ['day_of_week' => (int) $start->format('N'), 'start_time' => $data['start_time'], 'end_time' => $data['end_time']],
            $request->ip(),
            $request->userAgent()
        );

        return Response::json([
            'ok' => true,
            'data' => [
                'id' => (int) $schedule['id'],
                'day_of_week' => (int) $start->format('N'),
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
            ],
        ]);
    }

    /**
     * One endpoint for every lifecycle move; the service owns the graph.
     */
    public function status(Request $request, string $id): Response
    {
        $schedule = $this->load($request, (int) $id);
        $actor = $this->actor($request);
        $target = (string) $request->post('status', '');
        $reason = $request->post('reason');

        $this->service->transition($this->scope($request), $schedule, $target, $actor, is_string($reason) ? $reason : null);

        $this->audit->record(
            'schedule.status_changed',
            (int) $actor['id'] ?: null,
            'schedule',
            (int) $schedule['id'],
            sprintf('Schedule #%d: %s → %s', $schedule['id'], $schedule['status'], $target),
            ['from' => (string) $schedule['status'], 'to' => $target],
            $request->ip(),
            $request->userAgent()
        );

        return Response::redirect(
            $this->router->url('schedules.show', ['id' => $schedule['id']]) . '?saved=' . rawurlencode($target),
            303
        );
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    private function form(Request $request, ?array $schedule): Response
    {
        $scope = $this->scope($request);
        $user = $this->actor($request);
        $current = $this->terms->currentSemester();

        return $this->view('schedules/form', [
            'title' => $schedule === null ? 'Add class' : 'Edit ' . $schedule['subject_code'] . ' · ' . $schedule['section_code'],
            'schedule' => $schedule,
            'semesters' => $this->terms->semesterOptions(),
            'sections' => $this->sections->options($scope),
            'subjects' => $this->subjects->options($scope),
            'faculty' => $this->faculty->options($scope),
            'rooms' => $this->rooms->options(),
            'days' => (array) $this->config->get('schedule.days', []),
            'recurrences' => ScheduleRepository::RECURRENCES,
            'default_semester' => $schedule['semester_id'] ?? ($current['id'] ?? null),
            'can_edit' => $this->authorization->can($user, $schedule === null ? 'schedules.create' : 'schedules.update'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(Request $request, int $id): array
    {
        $schedule = $this->schedules->findInScope($this->scope($request), $id);

        if ($schedule === null) {
            $this->abort(404, 'That schedule entry does not exist.');
        }

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSchedule(Request $request): array
    {
        return $this->validate(
            [
                'semester_id' => 'required|integer|min:1',
                'section_id' => 'required|integer|min:1',
                'subject_id' => 'required|integer|min:1',
                'faculty_id' => 'required|integer|min:1',
                'room_id' => 'required|integer|min:1',
                'start_time' => 'required|time',
                'end_time' => 'required|time',
                'recurrence' => 'required|in:' . implode(',', ScheduleRepository::RECURRENCES),
                'meeting_date' => 'date',
                'days' => 'array',
                'notes' => 'string|max:500',
                'status' => 'in:draft,pending',
            ],
            [
                'semester_id' => 'Semester',
                'section_id' => 'Section',
                'subject_id' => 'Subject',
                'faculty_id' => 'Teacher',
                'room_id' => 'Room',
                'start_time' => 'Start time',
                'end_time' => 'End time',
                'recurrence' => 'Recurrence',
                'meeting_date' => 'Meeting date',
                'days' => 'Weekdays',
                'notes' => 'Notes',
                'status' => 'Initial status',
            ]
        );
    }

    private function redirectToList(string $status): Response
    {
        return Response::redirect($this->router->url('schedules.index') . '?saved=' . rawurlencode($status), 303);
    }

    private function scope(Request $request): Scope
    {
        $user = $request->attribute('auth_user');

        if (!is_array($user)) {
            $this->abort(401, 'Sign in to continue.');
        }

        return Scope::forUser($user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function actor(?Request $request = null): array
    {
        $request ??= $this->request;
        $user = $request->attribute('auth_user');

        if (!is_array($user)) {
            $this->abort(401, 'Sign in to continue.');
        }

        return $user;
    }

    /**
     * Whitelisted status flag from `?saved=…`.
     */
    private function notice(Request $request): ?string
    {
        $status = strtolower(trim((string) $request->query('saved', '')));

        return in_array($status, array_merge(['created', 'updated'], ScheduleRepository::STATUSES), true)
            ? $status
            : null;
    }
}
