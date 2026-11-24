<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\ReportRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SemesterRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reports service (Phase 15, RPT-01…RPT-07).
 *
 * The repository answers "what is in the database"; this service answers "what
 * does the report say". It owns the three things a report adds on top of the
 * rows — the term context every report shares, the derived numbers
 * (percentages, totals, bands) and the weekly grid — so no view has to do
 * arithmetic and every report states its numbers the same way.
 *
 * Scope is resolved once, from the signed-in user, and handed to the
 * repository. Nothing here reads request input: the controller validates and
 * parses parameters, this service only receives ints and strings.
 */
final class ReportService
{
    /** Below this share of the cap a teacher is under-loaded (BR-07 advice). */
    private const UNDER_LOADED = 60.0;

    /** Above 100 % of the cap the teacher is over the contracted load. */
    private const OVER_LOADED = 100.0;

    /** A room under this share of its bookable time is under-used. */
    private const LOW_UTILIZATION = 40.0;

    /** A room above this share is close to saturated. */
    private const HIGH_UTILIZATION = 75.0;

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly SemesterRepository $semesters,
        private readonly ScheduleRepository $schedules,
        private readonly Config $config
    ) {
    }

    /**
     * The term every report renders with: the requested one when it is visible
     * to the actor, otherwise the current term, otherwise the newest visible.
     *
     * A requested term is only honoured when it appears in the actor's own
     * term list, so a hand-edited `?semester=` cannot read a term that the term
     * picker never offered (the repository would still scope the rows, but the
     * page would show an empty, confusing report).
     *
     * @param array<string, mixed> $authUser
     * @return array{semesters: array<int, array<string, mixed>>, semester: array<string, mixed>|null, term: array{start: string, end: string}|null}
     */
    public function context(array $authUser, ?int $semesterId): array
    {
        $currentId = $this->schedules->currentSemesterId($authUser);
        $semesters = $this->semesters->visibleFor($authUser, $currentId);

        $visible = array_map(static fn (array $term): int => (int) $term['id'], $semesters);

        $chosenId = $semesterId !== null && in_array($semesterId, $visible, true)
            ? $semesterId
            : ($currentId !== null && in_array($currentId, $visible, true) ? $currentId : null);

        if ($chosenId === null && $semesters !== []) {
            $chosenId = (int) $semesters[0]['id'];
        }

        $semester = $chosenId === null ? null : $this->semesters->findSemester($chosenId);

        return [
            'semesters' => $semesters,
            'semester' => $semester,
            'term' => $chosenId === null ? null : $this->schedules->termBox($chosenId),
        ];
    }

    /**
     * The reports hub: the four headline numbers of the selected term.
     *
     * @param array<string, mixed> $authUser
     * @return array{context: array<string, mixed>, summary: array{meetings: int, teachers: int, rooms: int, open_conflicts: int}}
     */
    public function overview(array $authUser, ?int $semesterId): array
    {
        $context = $this->context($authUser, $semesterId);

        return [
            'context' => $context,
            'summary' => $context['semester'] === null
                ? ['meetings' => 0, 'teachers' => 0, 'rooms' => 0, 'open_conflicts' => 0]
                : $this->reports->termSummary(Scope::forUser($authUser), (int) $context['semester']['id']),
        ];
    }

    /**
     * RPT-01 — faculty load.
     *
     * Each row carries the contracted cap, the units credited in the term, the
     * contact hours, and a band the view colours: under-loaded, balanced or
     * over the cap. The band is advisory (BR-07 warns, it does not block).
     *
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>
     */
    public function facultyLoad(array $authUser, ?int $semesterId): array
    {
        $context = $this->context($authUser, $semesterId);
        $term = $this->termName($context);
        $rows = [];

        if ($context['semester'] !== null) {
            $rows = $this->reports->facultyLoad(Scope::forUser($authUser), (int) $context['semester']['id']);
        }

        $totals = ['teachers' => count($rows), 'units' => 0.0, 'hours' => 0.0, 'meetings' => 0, 'over' => 0, 'under' => 0];

        foreach ($rows as $index => $row) {
            $units = (float) $row['units'];
            $cap = (float) $row['max_load_units'];
            $share = $cap > 0 ? $units / $cap * 100.0 : 0.0;

            $rows[$index]['units'] = round($units, 1);
            $rows[$index]['hours'] = round((float) $row['hours'], 1);
            $rows[$index]['cap_share'] = round($share, 1);
            $rows[$index]['band'] = $share > self::OVER_LOADED
                ? 'over'
                : ($share < self::UNDER_LOADED ? 'under' : 'balanced');

            $totals['units'] += $units;
            $totals['hours'] += (float) $row['hours'];
            $totals['meetings'] += (int) $row['meetings'];
            $totals['over'] += $rows[$index]['band'] === 'over' ? 1 : 0;
            $totals['under'] += $rows[$index]['band'] === 'under' ? 1 : 0;
        }

        $totals['units'] = round($totals['units'], 1);
        $totals['hours'] = round($totals['hours'], 1);

        return [
            'context' => $context,
            'term_name' => $term,
            'rows' => $rows,
            'totals' => $totals,
            'scope_label' => $this->scopeLabel($authUser),
        ];
    }

    /**
     * RPT-02 — room utilization.
     *
     * The denominator is the term's bookable time: every weekday (Mon–Fri)
     * between the term's start and end, multiplied by the policy window from
     * config/schedule.php, with the configured breaks subtracted. That is the
     * same window the scheduler is allowed to use, which is what makes the
     * percentage comparable between terms of different lengths.
     *
     * Booked hours are expanded across the term: a weekly meeting occupies its
     * window once per teaching week (term weekdays ÷ 5, rounded up), while a
     * one-off occupies it exactly once. Comparing a single week of bookings
     * with a whole term of capacity would report every room as ~1 % used.
     *
     * A room can be booked on a Saturday by an explicit entry; the booked
     * hours still count, so a Saturday-only room legitimately exceeds 100 % of
     * its weekday capacity — the view flags that instead of hiding it.
     *
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>
     */
    public function roomUtilization(array $authUser, ?int $semesterId): array
    {
        $context = $this->context($authUser, $semesterId);
        $available = $this->bookableMinutes($context['term']);
        $weeks = $available > 0 ? max(1, (int) ceil(($available / max(1, $this->dailyBookableMinutes())) / 5)) : 1;
        $rows = [];

        if ($context['semester'] !== null) {
            $rows = $this->reports->roomUtilization(Scope::forUser($authUser), (int) $context['semester']['id']);
        }

        $totals = ['rooms' => count($rows), 'booked_hours' => 0.0, 'available_hours' => 0.0, 'meetings' => 0, 'low' => 0, 'high' => 0];

        foreach ($rows as $index => $row) {
            // Weekly windows recur once per teaching week; one-offs once.
            $bookedHours = ((float) $row['weekly_hours'] * $weeks) + (float) $row['one_off_hours'];
            $share = $available > 0 ? ($bookedHours * 60.0) / $available * 100.0 : 0.0;

            $rows[$index]['booked_hours'] = round($bookedHours, 1);
            $rows[$index]['available_hours'] = round($available / 60.0, 1);
            $rows[$index]['utilization'] = round($share, 1);
            $rows[$index]['band'] = $share >= self::HIGH_UTILIZATION
                ? 'high'
                : ($share < self::LOW_UTILIZATION ? 'low' : 'balanced');

            $totals['booked_hours'] += $bookedHours;
            $totals['available_hours'] += $available / 60.0;
            $totals['meetings'] += (int) $row['meetings'];
            $totals['low'] += $rows[$index]['band'] === 'low' ? 1 : 0;
            $totals['high'] += $rows[$index]['band'] === 'high' ? 1 : 0;
        }

        $totalShare = $totals['available_hours'] > 0
            ? $totals['booked_hours'] / $totals['available_hours'] * 100.0
            : 0.0;

        $totals['booked_hours'] = round($totals['booked_hours'], 1);
        $totals['available_hours'] = round($totals['available_hours'], 1);
        $totals['utilization'] = round($totalShare, 1);

        return [
            'context' => $context,
            'term_name' => $this->termName($context),
            'rows' => $rows,
            'totals' => $totals,
            'buildings' => $this->buildingRollup($rows),
            'availability' => [
                'business_days' => $available > 0 ? intdiv($available, max(1, $this->dailyBookableMinutes())) : 0,
                'daily_hours' => round($this->dailyBookableMinutes() / 60.0, 1),
                'weeks' => $weeks,
            ],
            'scope_label' => $this->scopeLabel($authUser),
        ];
    }

    /**
     * RPT-03 — department schedule report.
     *
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>
     */
    public function departmentSchedule(array $authUser, ?int $semesterId, ?int $departmentId = null): array
    {
        $context = $this->context($authUser, $semesterId);
        $rows = [];

        if ($context['semester'] !== null) {
            $rows = $this->reports->departmentSchedule(
                Scope::forUser($authUser),
                (int) $context['semester']['id'],
                $departmentId
            );
        }

        $totals = ['departments' => count($rows), 'meetings' => 0, 'hours' => 0.0, 'subjects' => 0, 'sections' => 0, 'faculty' => 0, 'rooms' => 0];

        foreach ($rows as $index => $row) {
            $rows[$index]['hours'] = round((float) $row['hours'], 1);

            foreach (['meetings', 'subjects', 'sections', 'faculty', 'rooms'] as $column) {
                $totals[$column] += (int) $row[$column];
            }

            $totals['hours'] += $rows[$index]['hours'];
        }

        $totals['hours'] = round($totals['hours'], 1);

        return [
            'context' => $context,
            'term_name' => $this->termName($context),
            'rows' => $rows,
            'totals' => $totals,
            'colspan' => 8,
            'scope_label' => $this->scopeLabel($authUser),
        ];
    }

    /**
     * RPT-04 — conflict report.
     *
     * Counts are computed from the same rows the table prints, so the summary
     * and the list can never disagree. `$status` is whitelisted here because
     * the report offers the three values as filters.
     *
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>
     */
    public function conflicts(array $authUser, ?int $semesterId, string $status = ''): array
    {
        $status = in_array($status, ['open', 'resolved', 'ignored'], true) ? $status : '';
        $context = $this->context($authUser, $semesterId);
        $rows = [];

        if ($context['semester'] !== null) {
            $rows = $this->reports->conflicts(Scope::forUser($authUser), (int) $context['semester']['id'], $status);
        }

        $counts = ['open' => 0, 'resolved' => 0, 'ignored' => 0, 'errors' => 0, 'warnings' => 0];

        foreach ($rows as $index => $row) {
            $rows[$index]['start_time'] = substr((string) $row['start_time'], 0, 5);
            $rows[$index]['end_time'] = substr((string) $row['end_time'], 0, 5);

            if (isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']]++;
            }

            $counts[(string) $row['severity'] === 'warning' ? 'warnings' : 'errors']++;
        }

        return [
            'context' => $context,
            'term_name' => $this->termName($context),
            'rows' => $rows,
            'counts' => $counts,
            'status' => $status,
            'scope_label' => $this->scopeLabel($authUser),
        ];
    }

    /**
     * RPT-03/RPT-05 — weekly and semester timetable.
     *
     * Two shapes from one query: `rows` for the semester listing and `grid`
     * for the weekly board. The grid is keyed by start time and then by
     * weekday, so a 09:00 class on Monday and a 09:00 class on Wednesday share
     * a lane; a meeting that starts at a time nobody else uses gets its own.
     *
     * The board shows Monday–Saturday (the scheduling window permits Saturday);
     * Sunday rows are listed in the table below the board so they are visible
     * rather than silently dropped.
     *
     * @param array<string, mixed> $authUser
     * @param array{faculty_id?: int, department_id?: int, section_id?: int} $filters
     * @return array<string, mixed>
     */
    public function timetable(array $authUser, ?int $semesterId, array $filters = []): array
    {
        $context = $this->context($authUser, $semesterId);
        $rows = [];

        if ($context['semester'] !== null) {
            $rows = $this->reports->timetable(
                Scope::forUser($authUser),
                (int) $context['semester']['id'],
                $filters
            );
        }

        $days = $this->config->get('schedule.days', []);
        $lanes = [];
        $hours = 0.0;

        foreach ($rows as $index => $row) {
            $rows[$index]['start_time'] = substr((string) $row['start_time'], 0, 5);
            $rows[$index]['end_time'] = substr((string) $row['end_time'], 0, 5);

            $start = $this->minutes((string) $row['start_time']);
            $end = $this->minutes((string) $row['end_time']);

            if ($start !== null && $end !== null && $end > $start) {
                $hours += ($end - $start) / 60.0;
            }

            $day = (int) $row['day_of_week'];
            $lane = $rows[$index]['start_time'] . '–' . $rows[$index]['end_time'];

            $lanes[$lane][$day][] = $rows[$index];
        }

        ksort($lanes);

        return [
            'context' => $context,
            'term_name' => $this->termName($context),
            'rows' => $rows,
            'grid' => $lanes,
            'days' => array_slice($days, 0, 6, true),
            'total_hours' => round($hours, 1),
            'meetings' => count($rows),
            'filters' => $filters,
            'scope_label' => $this->scopeLabel($authUser),
        ];
    }

    /**
     * A short, non-sensitive description of what the report covers.
     *
     * @param array<string, mixed> $authUser
     */
    public function scopeLabel(array $authUser): string
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
     * Bookable minutes in one room for the whole term.
     *
     * Weekends are excluded because the default scheduling window only opens
     * Monday–Friday; breaks are subtracted only when they fall inside the
     * window, so a longer policy window cannot be double-counted.
     *
     * @param array{start: string, end: string}|null $term
     */
    private function bookableMinutes(?array $term): int
    {
        if ($term === null) {
            return 0;
        }

        return $this->businessDays((string) $term['start'], (string) $term['end'])
            * $this->dailyBookableMinutes();
    }

    /**
     * One weekday's usable minutes: the policy window minus any break inside it.
     */
    private function dailyBookableMinutes(): int
    {
        $window = $this->config->get('schedule.allowed_hours', ['start' => '07:00', 'end' => '19:00']);
        $start = $this->minutes((string) ($window['start'] ?? '07:00'));
        $end = $this->minutes((string) ($window['end'] ?? '19:00'));

        if ($start === null || $end === null || $end <= $start) {
            return 0;
        }

        $minutes = $end - $start;

        foreach ($this->config->get('schedule.breaks', []) as $break) {
            $breakStart = $this->minutes((string) ($break['start'] ?? ''));
            $breakEnd = $this->minutes((string) ($break['end'] ?? ''));

            if ($breakStart === null || $breakEnd === null || $breakEnd <= $breakStart) {
                continue;
            }

            $overlap = min($end, $breakEnd) - max($start, $breakStart);

            if ($overlap > 0) {
                $minutes -= $overlap;
            }
        }

        return max(0, $minutes);
    }

    /**
     * Weekdays (Mon–Fri) between two `Y-m-d` dates, inclusive on both ends.
     */
    private function businessDays(string $start, string $end): int
    {
        $timezone = new DateTimeZone((string) $this->config->get('app.timezone', 'UTC'));

        try {
            $cursor = new DateTimeImmutable($start, $timezone);
            $last = new DateTimeImmutable($end, $timezone);
        } catch (\Throwable) {
            return 0;
        }

        if ($last < $cursor) {
            return 0;
        }

        $days = 0;

        while ($cursor <= $last) {
            $weekday = (int) $cursor->format('N');

            if ($weekday <= 5) {
                $days++;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * Per-building totals for the room report's second table.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildingRollup(array $rows): array
    {
        $buildings = [];

        foreach ($rows as $row) {
            $code = (string) $row['building_code'];

            if (!isset($buildings[$code])) {
                $buildings[$code] = [
                    'building_code' => $code,
                    'building_name' => (string) $row['building_name'],
                    'rooms' => 0,
                    'meetings' => 0,
                    'booked_hours' => 0.0,
                    'available_hours' => 0.0,
                    'utilization' => 0.0,
                ];
            }

            $buildings[$code]['rooms']++;
            $buildings[$code]['meetings'] += (int) $row['meetings'];
            $buildings[$code]['booked_hours'] += (float) $row['booked_hours'];
            $buildings[$code]['available_hours'] += (float) $row['available_hours'];
        }

        foreach ($buildings as $code => $building) {
            $buildings[$code]['booked_hours'] = round($building['booked_hours'], 1);
            $buildings[$code]['available_hours'] = round($building['available_hours'], 1);
            $buildings[$code]['utilization'] = $building['available_hours'] > 0
                ? round($building['booked_hours'] / $building['available_hours'] * 100.0, 1)
                : 0.0;
        }

        usort(
            $buildings,
            static fn (array $a, array $b): int => $b['utilization'] <=> $a['utilization']
        );

        return $buildings;
    }

    /**
     * The term label every report prints in its header.
     *
     * @param array<string, mixed> $context
     */
    private function termName(array $context): string
    {
        $semester = $context['semester'] ?? null;

        if (!is_array($semester)) {
            return 'No term selected';
        }

        return (string) $semester['code'] . ' — ' . (string) $semester['name'];
    }

    /**
     * `H:i` (or `HH:MM:SS`) to minutes since midnight.
     */
    private function minutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }
}
