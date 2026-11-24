<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\AnalyticsRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Dashboard service (Phase 16, DASH-01…DASH-03).
 *
 * The repository answers "what rows exist"; this service decides what the
 * dashboard says: which widgets a role sees, how today's classes and the next
 * seven days are expanded from the recurring timetable, and which series the
 * charts plot.
 *
 * Role awareness (DASH-02) is implemented as an *audience*, resolved once from
 * the signed-in user's Scope:
 *
 *   institution  → administrator: institution-wide counts and workload
 *   department   → dean / secretary: the same widgets, department-scoped
 *   faculty      → their own day, their own meetings, their own rooms
 *
 * Scope itself is enforced in SQL by AnalyticsRepository and by the reused
 * ReportService read models; this class never widens what those return. It also
 * never reads request input: the controller parses `?semester=` to an int.
 *
 * Chart data (DASH-03) is returned as plain label/data series from `charts()`;
 * the view only carries an endpoint URL, never a baked-in dataset.
 */
final class DashboardService
{
    /** How many upcoming meetings the agenda lists. */
    private const UPCOMING_LIMIT = 8;

    /** How many rows the workload table and chart show. */
    private const WORKLOAD_LIMIT = 8;

    /** How many rooms the utilization list and chart show. */
    private const ROOM_LIMIT = 8;

    /** Days in the look-ahead agenda window (today included). */
    private const AGENDA_DAYS = 7;

    /** DASH colours keep the maroon/gold identity even when a department has no colour. */
    private const FALLBACK_COLORS = ['#8c1d40', '#c9a227', '#6d1531', '#1f7a4d', '#8a6100', '#4b3a2f'];

    public function __construct(
        private readonly AnalyticsRepository $analytics,
        private readonly ReportService $reports,
        private readonly Config $config
    ) {
    }

    /**
     * Everything the dashboard page renders, already scoped to the actor.
     *
     * `$today` is injectable so the test suite can pin the clock to a known
     * date instead of depending on the day the suite runs.
     *
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>
     */
    public function overview(array $authUser, ?int $semesterId = null, ?DateTimeImmutable $today = null): array
    {
        $scope = Scope::forUser($authUser);
        $context = $this->reports->context($authUser, $semesterId);
        $semester = $context['semester'];
        $semesterId = $semester === null ? null : (int) $semester['id'];
        $today ??= new DateTimeImmutable('now', $this->timezone());

        $audience = $this->audience($scope);
        $counts = $this->analytics->counts($scope, $semesterId);
        $todayClasses = $semesterId === null
            ? []
            : $this->classesFor($this->analytics->classesOn(
                $scope,
                $semesterId,
                (int) $today->format('N'),
                $today->format('Y-m-d')
            ));

        $upcoming = $semesterId === null
            ? []
            : $this->upcoming($scope, $semesterId, $today);

        $conflicts = $semesterId === null
            ? ['open' => 0, 'resolved' => 0, 'ignored' => 0, 'total' => 0]
            : $this->analytics->conflictCounts($scope, $semesterId);

        $workload = $semesterId === null
            ? ['rows' => [], 'totals' => ['teachers' => 0, 'units' => 0.0, 'hours' => 0.0, 'meetings' => 0, 'over' => 0, 'under' => 0]]
            : $this->reports->facultyLoad($authUser, $semesterId);

        $utilization = $semesterId === null
            ? ['rows' => [], 'totals' => ['rooms' => 0, 'booked_hours' => 0.0, 'available_hours' => 0.0, 'utilization' => 0.0, 'meetings' => 0]]
            : $this->reports->roomUtilization($authUser, $semesterId);

        $roomRows = $this->topRooms($utilization['rows']);

        return [
            'context' => $context,
            'term_name' => $this->termName($semester),
            'term_dates' => $this->termDates($semester),
            'scope_label' => $this->scopeLabel($authUser),
            'audience' => $audience,
            'today' => [
                'date' => $today->format('Y-m-d'),
                'label' => $today->format('l, j F Y'),
                'is_teaching_day' => (int) $today->format('N') <= 5,
                'classes' => $todayClasses,
                'count' => count($todayClasses),
                'hours' => round($this->hours($todayClasses), 1),
            ],
            'upcoming' => $upcoming,
            'departments' => $semesterId === null
                ? []
                : $this->analytics->departmentStatistics($scope, $semesterId),
            'stats' => $this->stats($audience, $counts, $todayClasses, $workload['totals']),
            'conflicts' => $conflicts,
            'utilization' => [
                'overall' => (float) ($utilization['totals']['utilization'] ?? 0.0),
                'rooms' => (int) ($utilization['totals']['rooms'] ?? 0),
                'booked_hours' => (float) ($utilization['totals']['booked_hours'] ?? 0.0),
                'available_hours' => (float) ($utilization['totals']['available_hours'] ?? 0.0),
                'top' => $roomRows,
            ],
            'workload' => [
                'rows' => array_slice($workload['rows'], 0, self::WORKLOAD_LIMIT),
                'totals' => $workload['totals'],
            ],
        ];
    }

    /**
     * The chart payload served by `GET /api/dashboard` (DASH-03).
     *
     * Four charts, all derived from scoped queries: meetings per department,
     * units against the contracted cap per teacher, utilization per room, and
     * the conflict log by status. When no term is selected every series is
     * empty and the page renders its empty state instead of a fake chart.
     *
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>
     */
    public function charts(array $authUser, ?int $semesterId = null): array
    {
        $scope = Scope::forUser($authUser);
        $context = $this->reports->context($authUser, $semesterId);
        $semester = $context['semester'];
        $semesterId = $semester === null ? null : (int) $semester['id'];

        $payload = [
            'term' => $semester === null ? null : [
                'id' => (int) $semester['id'],
                'code' => (string) $semester['code'],
                'name' => (string) $semester['name'],
            ],
            'scope' => $this->scopeLabel($authUser),
            'generated_at' => (new DateTimeImmutable('now', $this->timezone()))->format(DATE_ATOM),
            'charts' => [
                'departments' => [
                    'type' => 'bar',
                    'title' => 'Meetings by department',
                    'labels' => [],
                    'datasets' => [
                        ['label' => 'Meetings', 'data' => [], 'backgroundColor' => []],
                    ],
                ],
                'workload' => [
                    'type' => 'bar',
                    'title' => 'Teaching load (units against cap)',
                    'labels' => [],
                    'datasets' => [
                        ['label' => 'Units', 'data' => [], 'backgroundColor' => '#8c1d40'],
                        ['label' => 'Cap', 'data' => [], 'backgroundColor' => '#c9a227'],
                    ],
                ],
                'utilization' => [
                    'type' => 'bar',
                    'title' => 'Room utilization (%)',
                    'labels' => [],
                    'datasets' => [
                        // Per-room colours are appended below; starting from a
                        // string here made PHP assign to a string offset.
                        ['label' => 'Utilization %', 'data' => [], 'backgroundColor' => []],
                    ],
                ],
                'conflicts' => [
                    'type' => 'doughnut',
                    'title' => 'Conflicts by status',
                    'labels' => ['Open', 'Resolved', 'Ignored'],
                    'datasets' => [
                        [
                            'label' => 'Conflicts',
                            'data' => [0, 0, 0],
                            'backgroundColor' => ['#8c1d40', '#1f7a4d', '#6b5b4b'],
                        ],
                    ],
                ],
            ],
        ];

        if ($semesterId === null) {
            return $payload;
        }

        $departments = $this->analytics->departmentStatistics($scope, $semesterId);
        $utilization = $this->reports->roomUtilization($authUser, $semesterId);
        $workload = $this->reports->facultyLoad($authUser, $semesterId);
        $conflicts = $this->analytics->conflictCounts($scope, $semesterId);

        foreach ($departments as $index => $department) {
            $payload['charts']['departments']['labels'][] = (string) $department['department_code'];
            $payload['charts']['departments']['datasets'][0]['data'][] = (int) $department['meetings'];
            $payload['charts']['departments']['datasets'][0]['backgroundColor'][] = $this->color(
                (string) ($department['color_hex'] ?? ''),
                $index
            );
        }

        foreach (array_slice($workload['rows'], 0, self::WORKLOAD_LIMIT) as $row) {
            $payload['charts']['workload']['labels'][] = trim(
                (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
            );
            $payload['charts']['workload']['datasets'][0]['data'][] = (float) ($row['units'] ?? 0.0);
            $payload['charts']['workload']['datasets'][1]['data'][] = (float) ($row['max_load_units'] ?? 0.0);
        }

        foreach ($this->topRooms($utilization['rows']) as $index => $room) {
            $payload['charts']['utilization']['labels'][] = trim(
                (string) ($room['building_code'] ?? '') . ' ' . (string) ($room['room_code'] ?? '')
            );
            $payload['charts']['utilization']['datasets'][0]['data'][] = (float) ($room['utilization'] ?? 0.0);
            $payload['charts']['utilization']['datasets'][0]['backgroundColor'][$index] = $this->color('', $index);
        }

        $payload['charts']['conflicts']['datasets'][0]['data'] = [
            $conflicts['open'],
            $conflicts['resolved'],
            $conflicts['ignored'],
        ];

        return $payload;
    }

    /**
     * The audience keyword the view switches on (DASH-02).
     */
    public function audience(Scope $scope): string
    {
        if ($scope->isAdministrator()) {
            return 'institution';
        }

        return $scope->isSelfOnly() ? 'faculty' : 'department';
    }

    /**
     * The widget row, in the order the page prints it.
     *
     * An administrator and a dean count people, catalogue and rooms; a teacher
     * starts from their own day, because "3 teachers" is meaningless to someone
     * whose scope is themselves.
     *
     * @param array{teachers: int, subjects: int, rooms: int, sections: int} $counts
     * @param array<int, array<string, mixed>> $todayClasses
     * @param array<string, mixed> $totals
     * @return array<int, array{key: string, label: string, value: int, hint: string}>
     */
    private function stats(string $audience, array $counts, array $todayClasses, array $totals): array
    {
        if ($audience === 'faculty') {
            return [
                [
                    'key' => 'today',
                    'label' => 'My classes today',
                    'value' => count($todayClasses),
                    'hint' => 'Meetings on your timetable for today.',
                ],
                [
                    'key' => 'meetings',
                    'label' => 'My weekly meetings',
                    'value' => (int) ($totals['meetings'] ?? 0),
                    'hint' => 'Every meeting in the selected term.',
                ],
                [
                    'key' => 'subjects',
                    'label' => 'My subjects',
                    'value' => $counts['subjects'],
                    'hint' => 'Subjects you teach this term.',
                ],
                [
                    'key' => 'rooms',
                    'label' => 'My rooms',
                    'value' => $counts['rooms'],
                    'hint' => 'Rooms your classes use this term.',
                ],
            ];
        }

        $scopeWord = $audience === 'department' ? 'Department' : 'Institution';

        return [
            [
                'key' => 'teachers',
                'label' => $audience === 'department' ? 'Department teachers' : 'Total teachers',
                'value' => $counts['teachers'],
                'hint' => 'Active faculty profiles in scope.',
            ],
            [
                'key' => 'subjects',
                'label' => $audience === 'department' ? 'Department subjects' : 'Total subjects',
                'value' => $counts['subjects'],
                'hint' => $scopeWord . ' catalogue, active rows only.',
            ],
            [
                'key' => 'rooms',
                'label' => $audience === 'department' ? 'Rooms in use' : 'Total rooms',
                'value' => $counts['rooms'],
                'hint' => $audience === 'department'
                    ? 'Rooms your department books this term.'
                    : 'Active rooms in the institution.',
            ],
            [
                'key' => 'today',
                'label' => "Today's classes",
                'value' => count($todayClasses),
                'hint' => 'Meetings scheduled for today.',
            ],
        ];
    }

    /**
     * Expand the next seven days of the timetable into an agenda.
     *
     * A weekly row occurs on the date whose weekday matches; a one-off occurs
     * on its `meeting_date`. Today's earlier meetings are already over, so they
     * are filtered out; a meeting that has just started is still listed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upcoming(Scope $scope, int $semesterId, DateTimeImmutable $today): array
    {
        $end = $today->modify('+' . (self::AGENDA_DAYS - 1) . ' days');
        $weekdays = [];

        for ($offset = 0; $offset < self::AGENDA_DAYS; $offset++) {
            $weekdays[] = (int) $today->modify('+' . $offset . ' day')->format('N');
        }

        $rows = $this->analytics->meetingsBetween(
            $scope,
            $semesterId,
            $weekdays,
            $today->format('Y-m-d'),
            $end->format('Y-m-d')
        );

        $now = $today->format('H:i:s');
        $items = [];

        for ($offset = 0; $offset < self::AGENDA_DAYS; $offset++) {
            $date = $today->modify('+' . $offset . ' day');
            $key = $date->format('Y-m-d');
            $weekday = (int) $date->format('N');

            foreach ($rows as $row) {
                $isWeekly = (string) $row['recurrence'] === 'weekly';
                $occurs = $isWeekly
                    ? (int) $row['day_of_week'] === $weekday
                    : substr((string) ($row['meeting_date'] ?? ''), 0, 10) === $key;

                if (!$occurs) {
                    continue;
                }

                // Today's finished meetings are history, not agenda.
                if ($offset === 0 && substr((string) $row['start_time'], 0, 8) <= $now) {
                    continue;
                }

                $items[] = [
                    'date' => $key,
                    'day_label' => $offset === 0 ? 'Today' : ($offset === 1 ? 'Tomorrow' : $date->format('D j M')),
                    'start_time' => substr((string) $row['start_time'], 0, 5),
                    'end_time' => substr((string) $row['end_time'], 0, 5),
                    'subject_code' => (string) $row['subject_code'],
                    'subject_title' => (string) $row['subject_title'],
                    'section_code' => (string) $row['section_code'],
                    'room_code' => trim((string) $row['building_code'] . ' ' . (string) $row['room_code']),
                    'faculty_name' => trim(
                        (string) $row['faculty_first_name'] . ' ' . (string) $row['faculty_last_name']
                    ),
                    'department_code' => (string) $row['department_code'],
                    'color_hex' => (string) ($row['color_hex'] ?? ''),
                    'is_today' => $offset === 0,
                ];
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => [$a['date'], $a['start_time'], $a['subject_code']]
                <=> [$b['date'], $b['start_time'], $b['subject_code']]
        );

        return array_slice($items, 0, self::UPCOMING_LIMIT);
    }

    /**
     * Normalise one `classesOn()` row for the view.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function classesFor(array $rows): array
    {
        $classes = [];

        foreach ($rows as $row) {
            $start = substr((string) $row['start_time'], 0, 5);
            $end = substr((string) $row['end_time'], 0, 5);

            $classes[] = [
                'id' => (int) $row['id'],
                'start_time' => $start,
                'end_time' => $end,
                'duration' => $this->duration($start, $end),
                'subject_code' => (string) $row['subject_code'],
                'subject_title' => (string) $row['subject_title'],
                'section_code' => (string) $row['section_code'],
                'room_code' => trim((string) $row['building_code'] . ' ' . (string) $row['room_code']),
                'faculty_name' => trim(
                    (string) $row['faculty_first_name'] . ' ' . (string) $row['faculty_last_name']
                ),
                'department_code' => (string) $row['department_code'],
                'color_hex' => (string) ($row['color_hex'] ?? ''),
                'recurrence' => (string) $row['recurrence'],
            ];
        }

        return $classes;
    }

    /**
     * The utilization rows the list and chart show: highest first, capped.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function topRooms(array $rows): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => (float) ($b['utilization'] ?? 0) <=> (float) ($a['utilization'] ?? 0)
        );

        return array_slice($rows, 0, self::ROOM_LIMIT);
    }

    /**
     * Sum the duration of the formatted class rows.
     *
     * @param array<int, array<string, mixed>> $classes
     */
    private function hours(array $classes): float
    {
        $hours = 0.0;

        foreach ($classes as $class) {
            $hours += (float) ($class['duration'] ?? 0.0);
        }

        return $hours;
    }

    /**
     * Decimal hours between two `H:i` strings (0 when the pair is invalid).
     */
    private function duration(string $start, string $end): float
    {
        $startMinutes = $this->minutes($start);
        $endMinutes = $this->minutes($end);

        if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
            return 0.0;
        }

        return round(($endMinutes - $startMinutes) / 60.0, 2);
    }

    /**
     * `H:i` (or `H:i:s`) to minutes since midnight.
     */
    private function minutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    /**
     * @param array<string, mixed>|null $semester
     */
    private function termName(?array $semester): string
    {
        return $semester === null
            ? 'No term selected'
            : (string) $semester['code'] . ' — ' . (string) $semester['name'];
    }

    /**
     * @param array<string, mixed>|null $semester
     */
    private function termDates(?array $semester): ?string
    {
        if ($semester === null) {
            return null;
        }

        return substr((string) $semester['start_date'], 0, 10)
            . ' → ' . substr((string) $semester['end_date'], 0, 10);
    }

    /**
     * A short, non-sensitive description of what the dashboard covers.
     *
     * @param array<string, mixed> $authUser
     */
    private function scopeLabel(array $authUser): string
    {
        $scope = Scope::forUser($authUser);

        if ($scope->isAdministrator()) {
            return 'Institution-wide';
        }

        if ($scope->isSelfOnly()) {
            return 'Your own classes only';
        }

        return trim((string) ($authUser['department_name'] ?? '')) !== ''
            ? 'Department — ' . (string) $authUser['department_name']
            : 'Your department only';
    }

    /**
     * A safe CSS colour: a six-digit department colour, else the palette.
     */
    private function color(string $candidate, int $index): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) === 1) {
            return $candidate;
        }

        return self::FALLBACK_COLORS[$index % count(self::FALLBACK_COLORS)];
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone((string) $this->config->get('app.timezone', 'UTC'));
    }
}
