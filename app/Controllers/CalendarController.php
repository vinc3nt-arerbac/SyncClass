<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AcademicYearRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\RoomRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SemesterRepository;
use App\Repositories\SubjectRepository;
use App\Services\CalendarService;
use App\Services\Scope;

/**
 * Calendar page (CAL-01) and its month/year drill-down (CAL-05).
 *
 * Two entry points share one renderer:
 *   GET /calendar            → the current month view (label: calendar.index)
 *   GET /calendar/{year}/{month} → a fixed month view (label: calendar.month)
 *
 * The rest of the calendar surface is the JSON API: /api/calendar/events
 * returns FullCalendar's `events` array for a date range, filtered server-side
 * and permission-scoped. Every JSON payload is wrapped { ok: true, data }
 * (or { ok: false, error }).
 */
final class CalendarController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly ScheduleRepository $schedules,
        private readonly SemesterRepository $semesters,
        private readonly AcademicYearRepository $years,
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects,
        private readonly FacultyRepository $faculty,
        private readonly RoomRepository $rooms,
        private readonly DepartmentRepository $departments,
        private readonly CalendarService $calendarService
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    /**
     * Month view (label: calendar.index).
     *
     * @param array<string, string|null> $query
     */
    public function index(Request $request, string $year = null, int $month = null): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'schedules.view');

        $today = new \DateTimeImmutable('today', new \DateTimeZone($this->config->get('app.timezone', 'UTC')));
        $year = $this->intParam($request->query('year'), (string) $today->format('Y'));
        $month = $this->intParam($request->query('month'), (string) $today->format('n'));
        $selectedDate = $this->dateParam($request->query('date'));

        $currentSemester = $this->currentSemester($authUser);
        $years = $this->years->all(['active' => true]);
        $semesters = $this->semesters->visibleFor($authUser, $currentSemester === null ? null : $currentSemester['id']);
        $activeSemester = $this->semesters->currentOrActiveFor($authUser);
        $faculty = $this->faculty->optionsFor($authUser);
        $rooms = $this->rooms->optionsFor($authUser);

        // Build the Calendar's full-month header: weekdays for the current
        // week, the month label, and the nav that moves ≥/≤ by one month.
        $weekdays = $this->weekdayRows($currentSemester);
        $months = $this->monthRows($year, $month);

        $weekdayNames = $this->weekdayNames();
        $month = (int) $month;
        $year = (int) $year;

        return $this->view('calendar/index', [
            'title' => 'Calendar',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
            'current_semester' => $currentSemester,
            'active_semester' => $activeSemester,
            'years' => $years,
            'semesters' => $semesters,
            'faculty' => $faculty,
            'rooms' => $rooms,
            'weekdays' => $weekdays,
            'months' => $months,
            'year' => $year,
            'month' => $month,
            'date' => $selectedDate ? $selectedDate->format('Y-m-d') : null,
            'weekday_names' => $weekdayNames,
            'query' => $request->query(),
        ]) ->withHeader('cache-control', 'no-store');
    }

    /**
     * FullCalendar range feed (CAL-05).
     *
     * Returns every non-cancelled entry whose term overlaps the requested
     * `start`/`end` range. The endpoint is scope-aware: members of a
     * department see only their department's rows, faculty see only their
     * own classes, and everyone else sees the administrator's full term.
     *
     * @param array<string, string> $query
     */
    public function events(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'schedules.view');

        $start = $request->query('start');
        $end = $request->query('end');
        $semesterId = $this->intParam($request->query('semester_id'), null);
        $departmentId = $this->intParam($request->query('department_id'), null);

        if ($start === '' || $end === '') {
            return Response::json(['ok' => true, 'data' => ['events' => [], 'total' => 0]], 200)
                ->withHeader('Cache-Control', 'no-store');
        }

        $payload = $this->calendarService->events([
            'start' => $start,
            'end' => $end,
            'semester_id' => $semesterId ? (string) $semesterId : '',
            'department_id' => $departmentId ? (string) $departmentId : '',
        ], $authUser);

        return Response::json(['ok' => true, 'data' => $payload], 200)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Resolve and authorise the selected filter.
     */
    private function currentSemester(array $authUser): ?array
    {
        $semesters = $this->semesters->visibleFor($authUser, null);
        $active = array_filter($semesters, static fn (array $s): bool => $s['status'] === 'active');
        if ($active !== []) {
            return reset($active);
        }

        $planned = array_filter($semesters, static fn (array $s): bool => $s['status'] === 'planned');
        if ($planned !== []) {
            return reset($planned);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isoRange(null|string $start, null|string $end): array
    {
        if ($start === null || $end === null) {
            return ['', ''];
        }

        $start .= str_ends_with((string) $start, 'Z') ? '' : 'Z';
        $end .= str_ends_with((string) $end, 'Z') ? '' : 'Z';

        return [$start, $end];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function intParam(?string $value, ?string $fallback = null): int
    {
        $value = (string) ($value ?? $fallback ?? '');
        $value = trim($value);

        return $value === '' ? 0 : (int) $value;
    }

    /**
     * Resolve a `?date=YYYY-MM-DD` query parameter to a DateImmutable.
     */
    private function dateParam(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(
                $raw,
                new \DateTimeZone($this->config->get('app.timezone', 'UTC'))
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Month year navigation (CAL-05: the UI moves ±1 month, the API stays
     * range-scoped, never indexed by month).
     *
     * @return array<int, array<string, string>>
     */
    private function monthRows(int $year, int $month): array
    {
        $rows = [];

        for ($offset = -1; $offset <= 1; $offset++) {
            $target = (new \DateTimeImmutable())
                ->setDate((int) $year, (int) $month, 1)
                ->modify("+{$offset} months")
                ->format('Y-m');
            $rows[] = [
                'year' => (string) (new \DateTimeImmutable($target))->format('Y'),
                'month' => (string) (new \DateTimeImmutable($target))->format('n'),
                'label' => (new \DateTimeImmutable($target))->format('F Y'),
                'disabled' => false,
            ];
        }

        return $rows;
    }

    /**
     * Weekday row for the calendar control strip (Monday-first).
     *
     * @return array<int, array{code: string, label: string}>
     */
    private function weekdayRows(?array $currentSemester): array
    {
        $rows = [];
        foreach (array_keys($this->weekdayNames()) as $index => $code) {
            $label = $this->config->get('schedule.days.' . $code, (string) $code);
            $rows[] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        return $rows;
    }

    /**
     * ISO 8601 weekday names for the calendar table header.
     *
     * @return array<int, string>
     */
    private function weekdayNames(): array
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
    }
}
