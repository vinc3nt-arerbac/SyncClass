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

    /** ISO weekday → English label, for the weekly timetable columns. */
    private const DAY_LABELS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /** Cards a planning-board column renders before folding the rest into "+N". */
    private const BOARD_CARDS = 4;

    /** Unscheduled catalogue subjects the backlog column reads. */
    private const BACKLOG_LIMIT = 24;

    /** Room fill at or above this share is shown as a tight room. */
    private const TIGHT_FILL = 95.0;

    /** Conflict items the watch panel lists; the counters keep the real totals. */
    private const ALERT_LIMIT = 5;

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
        $weekRows = $semesterId === null ? [] : $this->analytics->timetable($scope, $semesterId);
        $alerts = $this->alerts($weekRows);
        $backlog = $semesterId === null
            ? []
            : $this->analytics->unscheduledSubjects($scope, $semesterId, self::BACKLOG_LIMIT);

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
            'week' => $this->week($weekRows, $today, $alerts['flags']),
            'alerts' => $alerts,
            'occupancy' => $this->occupancy($weekRows),
            'buildings' => $this->buildings($utilization['buildings'] ?? []),
            'board' => $this->board($weekRows, $backlog),
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
     * Room-focused utilisation read model for the advanced analytics view:
     * per-room bookings, occupancy and the building rollup, scoped to the actor.
     *
     * @return array<string, mixed>
     */
    public function roomUtilization(array $authUser, ?int $semesterId): array
    {
        return $this->reports->roomUtilization($authUser, $semesterId);
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
     * The weekly timetable: day columns, time bands and one cell per band.
     *
     * Bands are the distinct start/end pairs the term actually uses, so the grid
     * never invents half-hour rows nobody teaches in. Every session carries the
     * `flag` the alert pass gave it, which the view turns into a red or amber
     * outline — the conflict indicator on the timeline itself.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $flags
     * @return array<string, mixed>
     */
    private function week(array $rows, DateTimeImmutable $today, array $flags): array
    {
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        $present = [];

        foreach ($rows as $row) {
            $day = (int) $row['day_of_week'];

            if (isset(self::DAY_LABELS[$day])) {
                $present[$day] = true;
            }
        }

        // Monday to Friday are the teaching week even when a term is empty: an
        // empty grid with its columns is more legible than no grid at all.
        for ($day = 1; $day <= 5; $day++) {
            $present[$day] = true;
        }

        ksort($present);
        $days = [];

        foreach (array_keys($present) as $day) {
            $date = $monday->modify('+' . ($day - 1) . ' days');
            $days[$day] = [
                'index' => $day,
                'short' => substr(self::DAY_LABELS[$day], 0, 3),
                'label' => self::DAY_LABELS[$day],
                'date' => $date->format('Y-m-d'),
                'date_label' => $date->format('j M'),
                'is_today' => $date->format('Y-m-d') === $today->format('Y-m-d'),
                'count' => 0,
                'alerts' => 0,
            ];
        }

        $bands = [];
        $cells = [];

        foreach ($rows as $row) {
            $day = (int) $row['day_of_week'];

            if (!isset($days[$day])) {
                continue;
            }

            $start = substr((string) $row['start_time'], 0, 5);
            $end = substr((string) $row['end_time'], 0, 5);
            $key = $start . '|' . $end;
            $session = $this->session($row, $flags);

            $bands[$key] = ['key' => $key, 'start' => $start, 'end' => $end];
            $cells[$day][$key][] = $session;
            $days[$day]['count']++;

            if ($session['flag'] !== null) {
                $days[$day]['alerts']++;
            }
        }

        uasort(
            $bands,
            static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]
        );

        foreach ($cells as $day => $byBand) {
            foreach ($byBand as $key => $list) {
                usort(
                    $list,
                    static fn (array $a, array $b): int => [$a['start'], $a['code']] <=> [$b['start'], $b['code']]
                );
                $cells[$day][$key] = $list;
            }
        }

        $peak = null;

        foreach ($days as $day) {
            if ($peak === null || $day['count'] > $peak['count']) {
                $peak = ['label' => $day['label'], 'short' => $day['short'], 'count' => $day['count']];
            }
        }

        return [
            'label' => $monday->format('j M') . ' – ' . $monday->modify('+4 days')->format('j M Y'),
            'monday' => $monday->format('Y-m-d'),
            'days' => array_values($days),
            'bands' => array_values($bands),
            'cells' => $cells,
            'total' => count($rows),
            'peak' => $peak,
        ];
    }

    /**
     * One timetable card, already shaped for the grid.
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $flags
     * @return array<string, mixed>
     */
    private function session(array $row, array $flags): array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'code' => (string) $row['subject_code'],
            'title' => (string) $row['subject_title'],
            'section' => (string) $row['section_code'],
            'room' => (string) $row['room_code'],
            'building' => (string) $row['building_code'],
            'faculty' => trim((string) $row['faculty_first_name'] . ' ' . (string) $row['faculty_last_name']),
            'faculty_last' => (string) $row['faculty_last_name'],
            'department' => (string) $row['department_code'],
            'color' => $this->color((string) ($row['color_hex'] ?? ''), 0),
            'start' => substr((string) $row['start_time'], 0, 5),
            'end' => substr((string) $row['end_time'], 0, 5),
            'fill' => $this->fill($row),
            'flag' => $flags[$id] ?? null,
            'status' => (string) $row['status'],
        ];
    }

    /**
     * Seats taken as a whole-revealed percentage, or null for a room with no
     * capacity on record.
     *
     * @param array<string, mixed> $row
     */
    private function fill(array $row): ?int
    {
        $capacity = (int) ($row['capacity'] ?? 0);

        if ($capacity <= 0) {
            return null;
        }

        return (int) round(((int) ($row['student_count'] ?? 0)) / $capacity * 100);
    }

    /**
     * Clash and capacity checks over the whole term timetable.
     *
     * Three findings, all computed from the live rows rather than read from the
     * conflict log: two meetings in one room at one time, one teacher in two
     * rooms at one time, and a section larger than its room. The `flags` map is
     * returned so the timetable can outline exactly the cards that are involved.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function alerts(array $rows): array
    {
        $items = [];
        $flags = [];
        $counts = ['room_overlap' => 0, 'faculty_overlap' => 0, 'over_capacity' => 0, 'tight' => 0];

        foreach ([
            ['room', 'room_id', 'room_overlap', 'Room double-booked'],
            ['faculty', 'faculty_id', 'faculty_overlap', 'Teacher double-booked'],
        ] as [$kind, $column, $bucket, $label]) {
            $groups = [];

            foreach ($rows as $row) {
                $groups[(int) $row['day_of_week'] . '|' . (int) $row[$column]][] = $row;
            }

            foreach ($groups as $group) {
                usort(
                    $group,
                    static fn (array $a, array $b): int => (string) $a['start_time'] <=> (string) $b['start_time']
                );

                $previous = null;
                $previousEnd = null;

                foreach ($group as $row) {
                    $start = $this->minutes((string) $row['start_time']);
                    $end = $this->minutes((string) $row['end_time']);

                    if ($start === null || $end === null) {
                        continue;
                    }

                    if ($previous !== null && $previousEnd !== null && $start < $previousEnd) {
                        $counts[$bucket]++;
                        $flags[(int) $previous['id']] = 'danger';
                        $flags[(int) $row['id']] = 'danger';
                        $items[] = [
                            'type' => $bucket,
                            'severity' => 'danger',
                            'title' => $kind === 'room'
                                ? $label . ' · ' . $row['building_code'] . ' ' . $row['room_code']
                                : $label . ' · ' . trim((string) $row['faculty_first_name'] . ' ' . (string) $row['faculty_last_name']),
                            'detail' => $previous['subject_code'] . ' (' . $previous['section_code'] . ') overlaps '
                                . $row['subject_code'] . ' (' . $row['section_code'] . ')',
                            'when' => $this->dayShort((int) $row['day_of_week']) . ' '
                                . substr((string) $row['start_time'], 0, 5) . '–' . substr((string) $row['end_time'], 0, 5),
                            'ids' => [(int) $previous['id'], (int) $row['id']],
                        ];
                    }

                    if ($previousEnd === null || $end > $previousEnd) {
                        $previous = $row;
                        $previousEnd = $end;
                    }
                }
            }
        }

        foreach ($rows as $row) {
            $capacity = (int) ($row['capacity'] ?? 0);
            $students = (int) ($row['student_count'] ?? 0);

            if ($capacity <= 0) {
                continue;
            }

            $share = $students / $capacity * 100.0;

            if ($students > $capacity) {
                $counts['over_capacity']++;
                $flags[(int) $row['id']] = 'danger';
                $items[] = [
                    'type' => 'over_capacity',
                    'severity' => 'danger',
                    'title' => 'Over capacity · ' . $row['building_code'] . ' ' . $row['room_code'],
                    'detail' => $row['subject_code'] . ' · ' . $row['section_code'] . ' needs ' . $students
                        . ' seats, the room holds ' . $capacity,
                    'when' => $this->dayShort((int) $row['day_of_week']) . ' '
                        . substr((string) $row['start_time'], 0, 5) . '–' . substr((string) $row['end_time'], 0, 5),
                    'ids' => [(int) $row['id']],
                ];
            } elseif ($share >= self::TIGHT_FILL) {
                $counts['tight']++;
                $flags[(int) $row['id']] = 'warning';
                $items[] = [
                    'type' => 'tight',
                    'severity' => 'warning',
                    'title' => 'Room nearly full · ' . $row['building_code'] . ' ' . $row['room_code'],
                    'detail' => $row['subject_code'] . ' · ' . $row['section_code'] . ' fills ' . (int) round($share)
                        . '% of the seats',
                    'when' => $this->dayShort((int) $row['day_of_week']) . ' '
                        . substr((string) $row['start_time'], 0, 5) . '–' . substr((string) $row['end_time'], 0, 5),
                    'ids' => [(int) $row['id']],
                ];
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => [$a['severity'] === 'danger' ? 0 : 1, $a['when']]
                <=> [$b['severity'] === 'danger' ? 0 : 1, $b['when']]
        );

        $danger = $counts['room_overlap'] + $counts['faculty_overlap'] + $counts['over_capacity'];

        return [
            'items' => array_slice($items, 0, self::ALERT_LIMIT),
            'counts' => $counts,
            'danger' => $danger,
            'warning' => $counts['tight'],
            'total' => $danger + $counts['tight'],
            'flags' => $flags,
            'checks' => [
                ['label' => 'Room double-bookings', 'count' => $counts['room_overlap']],
                ['label' => 'Teacher double-bookings', 'count' => $counts['faculty_overlap']],
                ['label' => 'Sessions over room capacity', 'count' => $counts['over_capacity']],
                ['label' => 'Rooms at 95% of seats or more', 'count' => $counts['tight']],
            ],
        ];
    }

    /**
     * Seat occupancy across the term: students seated against seats available.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{percent: float, students: int, seats: int, sessions: int}
     */
    private function occupancy(array $rows): array
    {
        $students = 0;
        $seats = 0;

        foreach ($rows as $row) {
            $students += (int) ($row['student_count'] ?? 0);
            $seats += (int) ($row['capacity'] ?? 0);
        }

        return [
            'percent' => $seats > 0 ? round($students / $seats * 100.0, 1) : 0.0,
            'students' => $students,
            'seats' => $seats,
            'sessions' => count($rows),
        ];
    }

    /**
     * The per-building utilisation rollup, trimmed to what the panel prints.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildings(array $rows): array
    {
        $buildings = [];

        foreach ($rows as $row) {
            $buildings[] = [
                'code' => (string) $row['building_code'],
                'name' => (string) $row['building_name'],
                'rooms' => (int) $row['rooms'],
                'meetings' => (int) $row['meetings'],
                'booked_hours' => (float) $row['booked_hours'],
                'available_hours' => (float) $row['available_hours'],
                'utilization' => (float) $row['utilization'],
                'band' => (float) $row['utilization'] >= 75.0 ? 'high' : ((float) $row['utilization'] < 40.0 ? 'low' : 'balanced'),
            ];
        }

        return $buildings;
    }

    /**
     * The planning board: four workflow columns built from the schedule rows.
     *
     * The board is not a second source of truth. "Needs a slot" is the real
     * catalogue backlog (active subjects with no meeting), the middle columns are
     * the scheduling statuses the timetable already carries, and "Published" is
     * the last step of the same workflow. That keeps the board honest for the
     * selected term instead of showing invented tasks.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $backlog
     * @return array<string, mixed>
     */
    private function board(array $rows, array $backlog): array
    {
        $groups = ['draft' => [], 'approved' => [], 'published' => []];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $groups[$status === 'published' ? 'published' : ($status === 'approved' ? 'approved' : 'draft')][] = $row;
        }

        foreach ($groups as $key => $list) {
            usort(
                $list,
                static fn (array $a, array $b): int => [(int) $a['day_of_week'], (string) $a['start_time']]
                    <=> [(int) $b['day_of_week'], (string) $b['start_time']]
            );
            $groups[$key] = $list;
        }

        $backlogCards = [];

        foreach ($backlog as $subject) {
            $backlogCards[] = [
                'code' => (string) $subject['code'],
                'title' => (string) $subject['title'],
                'meta' => (string) $subject['department_code'] . ' · ' . $this->units($subject['units']) . ' units',
                'color' => $this->color((string) ($subject['color_hex'] ?? ''), 0),
                'badge' => null,
            ];
        }

        $columns = [[
            'key' => 'to_schedule',
            'label' => 'Needs a slot',
            'tone' => 'amber',
            'count' => count($backlogCards),
            'cards' => array_slice($backlogCards, 0, self::BOARD_CARDS),
            'more' => max(0, count($backlogCards) - self::BOARD_CARDS),
            'empty' => 'Every active subject already has a meeting this term.',
        ]];

        foreach ([
            'draft' => ['label' => 'In review', 'tone' => 'violet', 'empty' => 'No draft or pending meeting is waiting for review.'],
            'approved' => ['label' => 'Approved', 'tone' => 'green', 'empty' => 'Nothing is approved for this term yet.'],
            'published' => ['label' => 'Published', 'tone' => 'blue', 'empty' => 'Approved meetings appear here once published.'],
        ] as $key => $meta) {
            $cards = [];

            foreach (array_slice($groups[$key], 0, self::BOARD_CARDS) as $row) {
                $cards[] = [
                    'code' => (string) $row['subject_code'],
                    'title' => (string) $row['section_code'] . ' · ' . $row['room_code'] . ' ' . $row['building_code'],
                    'meta' => $this->dayShort((int) $row['day_of_week']) . ' '
                        . substr((string) $row['start_time'], 0, 5) . ' · ' . $row['faculty_last_name']
                        . ' · ' . $this->units($row['units']) . 'u',
                    'color' => $this->color((string) ($row['color_hex'] ?? ''), 0),
                    'badge' => $this->fill($row) !== null ? $this->fill($row) . '% full' : null,
                ];
            }

            $columns[] = [
                'key' => $key,
                'label' => $meta['label'],
                'tone' => $meta['tone'],
                'count' => count($groups[$key]),
                'cards' => $cards,
                'more' => max(0, count($groups[$key]) - self::BOARD_CARDS),
                'empty' => $meta['empty'],
            ];
        }

        return ['columns' => $columns, 'total' => count($rows), 'backlog' => count($backlog)];
    }

    /**
     * A credit count without the trailing `.0`.
     */
    private function units(mixed $units): string
    {
        return rtrim(rtrim(number_format((float) $units, 1, '.', ''), '0'), '.');
    }

    /**
     * Mon, Tue … for inline metadata.
     */
    private function dayShort(int $day): string
    {
        return isset(self::DAY_LABELS[$day]) ? substr(self::DAY_LABELS[$day], 0, 3) : '—';
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
