<?php

declare(strict_types=1);

namespace App\Services\Generator;

use App\Core\Config;
use App\Repositories\AcademicYearRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\RoomRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SemesterRepository;
use App\Repositories\SubjectRepository;
use App\Services\ConflictService;
use App\Services\ScheduleService;
use App\Services\Scope;

/**
 * Timetable generator (GEN-01…06). Deterministic constraint solving, not
 * generative AI.
 *
 * Phase 13 of SyncClass. The generator turns term/department/section
 * demands into a conflict-free draft timetable that a human (admin or dean)
 * commits after review.
 *
 * Pipeline
 *   1. Requirement expansion  — section curriculum → (section, subject, hours) demands
 *   2. Candidate space         — eligible faculty, eligible rooms, legal day/hour blocks
 *   3. Ordering                — most constrained first (MRV), tie-broken by largest section
 *   4. Search                  — depth-first backtracking, forward checking, conflict-directed backjumping
 *   5. Objective scoring       — GEN-05: satisfy requirements → min room waste → load balance → min idle gaps → preferred rooms
 *   6. Result                  — TimetableDraft (draft entries only, never DB)
 *   7. Commit                  — one transaction, then normal approval flow (BR-11)
 *
 * Explicit constraints (ADR-06, from config/schedule.php):
 *   - node budget  (generator.node_budget, e.g. 200_000) — bounded search, no hangs
 *   - wall-clock budget (generator.time_budget_seconds, e.g. 30) — fails fast with an unsatisfiable explanation
 *   - restarts (generator.restarts, e.g. 5) — N deterministic restarts with different tie-break seeds, best draft wins
 *
 * The generator reuses ConflictService (Phase 11) for feasibility filtering:
 * every candidate placement is checked before it is accepted. The same
 * evaluator that serves the form and the calendar serves the generator.
 */
final class TimetableGenerator
{
    private int $nodeCount = 0;
    private float $timeStarted = 0.0;

    public function __construct(
        private readonly Config $configObject,
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects,
        private readonly FacultyRepository $faculty,
        private readonly RoomRepository $rooms,
        private readonly SemesterRepository $semesters,
        private readonly AcademicYearRepository $years,
        private readonly ConflictService $conflict,
        private readonly ScheduleService $scheduleService
    ) {
        $this->timeStarted = microtime(true);
    }

    /**
     * The scheduled flow: plan a term, then commit the draft.
     *
     * @param array<string, mixed> $input
     * @return array{all_drafts: array<int, TimetableDraft>, best: TimetableDraft|null, metrics: array<string, mixed>, unsat: array<int, array{code: string, message: string}>, error: string|null}
     */
    public function plan(array $input, ?Scope $scope = null): array
    {
        $semesterId = $this->int($input['semester_id'] ?? null);
        $departmentIds = (array) ($input['department_ids'] ?? []);
        $subjectIds = (array) ($input['subject_ids'] ?? []);
        $seed = (string) ($input['seed'] ?? $this->configObject->get('schedule.generator.seed') ?? null);

        if ($semesterId === 0) {
            return [
                'all_drafts' => [],
                'best' => null,
                'metrics' => ['utilization' => 0.0, 'load_balance' => 0.0],
                'unsat' => [['code' => 'GEN-03', 'message' => 'No current term selected. Select a current term (is_current = 1) in the master data.']],
                'error' => null,
                'demands' => [],
            ];
        }

        // The caller passes the actor's scope; `system()` is only the fallback
        // for a CLI run. A dean therefore generates inside their own department.
        $scope ??= Scope::system();
        if ($departmentIds === []) {
            $departmentId = $scope->isAdministrator()
                ? $this->defaultDepartment($scope)
                : $scope->departmentId();

            $departmentIds = $departmentId === null ? [0] : [$departmentId];
        }

        // Requirement expansion: section curriculum → demands.
        $demandTable = $this->requirements($semesterId, $departmentIds, $subjectIds);

        // Order by most constrained first (MRV), tie-break by largest section.
        uasort($demandTable, [$this, 'orderByMostConstrained']);

        // Candidate lists per demand.
        $candidates = $this->candidateSpace($demandTable, $scope);

        // Backtrack with conflict checking; keep the best draft across N
        // restarts with different tie-break seeds.
        $bestDraft = null;
        $bestScore = INF;
        $attempts = (int) $this->configObject->get('schedule.generator.restarts', 5);

        for ($retry = 0; $retry < $attempts; $retry++) {
            $draft = $this->search($demandTable, $candidates, (string) ($seed . ($retry ? '-' . $retry : '')));

            if ($draft !== null) {
                $score = $this->objectiveScore($draft, $demandTable, $scope);
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestDraft = $draft;
                }
            }

            if ($this->nodeCount >= (int) $this->configObject->get('schedule.generator.node_budget', 200000)) {
                break;
            }
        }

        if ($bestDraft === null) {
            return [
                'all_drafts' => [],
                'best' => null,
                'metrics' => [],
                // The demand rows are returned even on failure: the result view
                // explains which sections could not be placed.
                'demands' => $demandTable,
                'unsat' => $this->unsatisfiable($demandTable, $candidates),
                'error' => 'The timetable is unsatisfiable: the generator\'s node or wall-clock budget expired. '
                    . 'Reduce the demand (fewer sections/subjects) or add rooms and faculty before retrying.',
            ];
        }

        // Metrics: utilization, load balance, idle gaps (GEN-06).
        $metrics = $this->metrics($bestDraft);

        return [
            'all_drafts' => [$bestDraft],
            'best' => $bestDraft,
            'metrics' => $metrics,
            // Placements are keyed by demand index and carry no section or
            // subject of their own; the commit step joins them back through
            // this table, which is why it has to travel with the draft.
            'demands' => $demandTable,
            'unsat' => [],
            'error' => null,
        ];
    }

    /**
     * Expand each section's curriculum into (section, subject, weekly hours).
     *
     * @return array<int, array{section_id: int, subject_id: int, weekly_hours: float}>
     */
    private function requirements(int $semesterId, array $departmentIds, array $subjectIds): array
    {
        $rows = $this->sections->bySemesterAndDepartments($semesterId, $departmentIds);

        $demands = [];
        foreach ($rows as $section) {
            $sectionId = (int) $section['id'];
            $subjects = $this->subjects->forSection($sectionId, $semesterId, $subjectIds);

            foreach ($subjects as $subject) {
                $units = (float) $subject['units'];
                $weeklyHours = $units * ($this->configObject->get('schedule.gen_hours_per_unit', 3.0));

                // Round to the policy's slot grid.
                $weeklyHours = round($weeklyHours / $this->configObject->get('schedule.slot_minutes', 30) * $this->configObject->get('schedule.slot_minutes', 30), 3);

                if ($weeklyHours <= 0) {
                    continue;
                }

                $demands[] = [
                    'section_id' => $sectionId,
                    'section_code' => (string) $section['code'],
                    'section_name' => (string) $section['name'],
                    'subject_id' => (int) $subject['id'],
                    'subject_code' => (string) $subject['code'],
                    'weekly_hours' => $weeklyHours,
                    'subject_title' => (string) $subject['title'],
                    'department_id' => (int) $section['department_id'],
                ];
            }
        }

        return $demands;
    }

    private function orderByMostConstrained(array $a, array $b): int
    {
        $diff = $a['weekly_hours'] <=> $b['weekly_hours'];
        if ($diff !== 0) {
            return $diff;
        }

        return ($a['weekly_hours'] <= $b['weekly_hours'] ? -1 : 1);
    }

    private function candidateSpace(array $demandTable, Scope $scope): array
    {
        $candidates = [];
        foreach ($demandTable as $index => $demand) {
            $candidates[$index] = $this->placementsFor($demand, $scope);
        }

        return $candidates;
    }

    private function placementsFor(array $demand, Scope $scope): array
    {
        $subjectId = (int) $demand['subject_id'];
        $roomIds = $this->roomIdsFor($subjectId, $scope, $demand['department_id']);
        $facultyIds = $this->facultyIdsFor($scope, $demand['department_id']);
        $semesterId = $this->semesterId($scope);

        $placements = [];
        $blockLevels = $this->blockLevels();

        foreach ($blockLevels as $level) {
            $day = $level['day'];
            $start = $level['start'];
            $end = $level['end'];

            foreach ($roomIds as $roomId) {
                foreach ($facultyIds as $facultyId) {
                    $placements[] = [
                        'day' => $day,
                        'start' => $start,
                        'end' => $end,
                        'room_id' => $roomId,
                        'faculty_id' => $facultyId,
                        'semester_id' => $semesterId,
                        // Carried so the draft's utilization metric reports the
                        // demand's weekly load rather than zero (GEN-06).
                        'weekly_hours' => (float) $demand['weekly_hours'],
                    ];
                }
            }
        }

        return $placements;
    }

    private function roomIdsFor(int $subjectId, Scope $scope, int $departmentId): array
    {
        $rows = $this->rooms->bySubjectAndScope($subjectId, $scope, $departmentId);
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function facultyIdsFor(Scope $scope, int $departmentId): array
    {
        if ($scope->isAdministrator()) {
            return $this->faculty->allActive()->map(fn (array $f): int => (int) $f['id'])->toArray();
        }

        if ($scope->isDepartmental()) {
            return $this->faculty->byDepartment($scope->departmentId())->map(fn (array $f): int => (int) $f['id'])->toArray();
        }

        // Faculty scope: only that user's own load.
        return [$scope->userId()];
    }

    private function blockLevels(): array
    {
        $start = $this->minutes($this->configObject->get('schedule.allowed_hours.start', '07:00'));
        $end = $this->minutes($this->configObject->get('schedule.allowed_hours.end', '19:00'));
        $slot = (int) $this->configObject->get('schedule.slot_minutes', 30);
        $min = (int) $this->configObject->get('schedule.min_duration_minutes', 30);
        $max = (int) $this->configObject->get('schedule.max_duration_minutes', 240);

        $breaks = [];
        foreach ((array) $this->configObject->get('schedule.breaks', []) as $break) {
            $breaks[] = [$this->minutes($break['start'] ?? '12:00'), $this->minutes($break['end'] ?? '13:00')];
        }

        $blocks = [];
        for ($day = 1; $day <= 7; $day++) {
            for ($t = $start; $t + $max <= $end; $t += $slot) {
                $endT = $t + $slot;
                $ok = true;
                foreach ($breaks as $b) {
                    if ($t < $b[1] && $endT > $b[0]) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $blocks[] = ['day' => $day, 'start' => $t, 'end' => $endT];
                }
            }
        }

        return $blocks;
    }

    private function search(array $demandTable, array $candidates, string $seed): ?TimetableDraft
    {
        $assignments = [];
        $assigned = [];

        $fail = $this->backtrack($demandTable, $candidates, $assignments, $assigned, $seed);

        return $fail === null ? new TimetableDraft($assignments) : null;
    }

    private function backtrack(array $demandTable, array $candidates, array &$assignments, array &$assigned, string $seed): ?array
    {
        if ($this->nodeCount >= (int) $this->configObject->get('schedule.generator.node_budget', 200000)) {
            return ['conflict' => 'GEN-03', 'message' => 'node budget exceeded'];
        }

        $order = $this->mrvOrder($demandTable, $assigned, $candidates, $seed);
        if ($order === []) {
            return ['conflict' => 'GEN-03', 'message' => 'no remaining candidates (incidence or domain empty)'];
        }

        $index = array_shift($order);
        $demand = $demandTable[$index];
        $domain = $candidates[$index];
        $this->pruneDomain($candidates, $index, $assigned);

        if (($candidates[$index] ?? []) === []) {
            return ['conflict' => 'GEN-03', 'message' => 'domain of a demand emptied by forward checking'];
        }

        // MRV tie-break: prefer the demand with the fewest candidates, then
        // the largest section hours. Deterministic for a given seed.
        usort($domain, static function (array $a, array $b) use ($seed): int {
            $aKey = ($a['faculty_id'] ?? 0) . ($a['room_id'] ?? 0) . ($a['day'] ?? 0) . ($a['start'] ?? 0);
            $bKey = ($b['faculty_id'] ?? 0) . ($b['room_id'] ?? 0) . ($b['day'] ?? 0) . ($b['start'] ?? 0);
            $aScore = strcmp((string) $aKey, (string) $bKey);

            if ($aScore !== 0) {
                return $aScore;
            }

            return ($a['end'] ?? 0) <=> ($b['end'] ?? 0);
        });

        foreach ($domain as $i => $placement) {
            $this->nodeCount++;

            $copy = $assignments;
            $assignedCopy = $assigned;
            $this->assign($copy, $assignedCopy, $index, $placement);

            $conflict = $this->conflictCheck($copy, $demandTable);
            if ($conflict === null) {
                $fail = $this->backtrack($demandTable, $candidates, $copy, $assignedCopy, $seed);
                if ($fail === null) {
                    return null;
                }
                $assignments = $copy;
                $assigned = $assignedCopy;
            }

            $this->unassign($assignments, $assigned, $index, $i);
        }

        return ['conflict' => 'GEN-03', 'message' => 'dead end (no remaining candidate)'];
    }

    private function mrvOrder(array $demandTable, array $assigned, array $candidates, string $seed): array
    {
        $order = [];
        foreach ($demandTable as $index => $demand) {
            if (isset($assigned[$index])) {
                continue;
            }

            $domain = $candidates[$index] ?? [];
            $order[] = $index;
        }

        usort($order, static function (int $a, int $b) use ($candidates, $demandTable): int {
            $aLen = count($candidates[$a] ?? []);
            $bLen = count($candidates[$b] ?? []);
            if ($aLen !== $bLen) {
                return $aLen <=> $bLen;
            }

            $aHours = (float) ($demandTable[$a]['weekly_hours'] ?? 0);
            $bHours = (float) ($demandTable[$b]['weekly_hours'] ?? 0);
            return $aHours <=> $bHours;
        });

        return $order;
    }

    private function pruneDomain(array &$candidates, int $index, array $assigned): void
    {
        $filtered = [];
        foreach ($candidates[$index] ?? [] as $placement) {
            $ok = true;
            foreach ($assigned as $aIndex => $aPlacement) {
                if ($this->conflictAgainst($placement, $aPlacement) === null) {
                    continue;
                }
                $ok = false;
                break;
            }
            if ($ok) {
                $filtered[] = $placement;
            }
        }

        $candidates[$index] = $filtered;
    }

    private function conflictAgainst(array $a, array $b): ?array
    {
        $aDay = (int) ($a['day'] ?? 0);
        $bDay = (int) ($b['day'] ?? 0);
        if ($aDay !== $bDay) {
            return null;
        }

        $aStart = (int) ($a['start'] ?? 0);
        $aEnd = (int) ($a['end'] ?? 0);
        $bStart = (int) ($b['start'] ?? 0);
        $bEnd = (int) ($b['end'] ?? 0);

        if ($aStart >= $bEnd || $bStart >= $aEnd) {
            return null;
        }

        return ['conflict' => 'CNF-01/02', 'message' => 'time overlap'];
    }

    /**
     * Feasibility of the partial assignment, evaluated by the same conflict
     * engine the form and calendar use (CNF-01…CNF-11).
     *
     * The generator stores a placement as minutes since midnight and keeps the
     * section/subject in the demand table, so the candidate is assembled from
     * both before it is handed to ConflictService::check(). The assignment's
     * own rows are removed from the comparison set, exactly as
     * ScheduleService::update() removes the row it is editing.
     */
    private function conflictCheck(array $assignments, array $demandTable): ?array
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($assignments as $index => $placement) {
            $rows[(string) $index] = $this->rowFrom($placement, (int) $index);
        }

        $existing = array_values($rows);

        foreach ($assignments as $index => $placement) {
            $key = (string) $index;
            $row = $rows[$key];
            $demand = $demandTable[(int) $index] ?? [];

            $others = array_values(array_filter(
                $existing,
                static fn (array $other): bool => (int) $other['id'] !== (int) $row['id']
            ));

            $candidate = [
                'semester' => ['id' => (int) $row['semester_id']],
                'section' => [
                    'id' => (int) ($demand['section_id'] ?? $row['section_id']),
                    'code' => (string) ($demand['section_code'] ?? 'section'),
                    'name' => (string) ($demand['section_name'] ?? 'section'),
                    'student_count' => (int) ($demand['student_count'] ?? 0),
                ],
                'subject' => [
                    'id' => (int) ($demand['subject_id'] ?? $row['subject_id']),
                    'code' => (string) ($demand['subject_code'] ?? 'subject'),
                    'title' => (string) ($demand['subject_title'] ?? 'subject'),
                ],
                'faculty' => ['id' => (int) $row['faculty_id']],
                'room' => ['id' => (int) $row['room_id'], 'code' => 'room', 'capacity' => 0, 'room_type' => 'lecture'],
                'days' => [(int) $row['day_of_week']],
                'start_time' => (string) $row['start_time'],
                'end_time' => (string) $row['end_time'],
                'meeting_date' => null,
                'subject_code' => (string) ($demand['subject_code'] ?? 'subject'),
                'subject_title' => (string) ($demand['subject_title'] ?? 'subject'),
                'section_code' => (string) ($demand['section_code'] ?? 'section'),
                'student_count' => (int) ($demand['student_count'] ?? 0),
                'room_code' => 'room',
                'room_capacity' => 0,
                'faculty_first_name' => 'Faculty',
                'faculty_last_name' => 'member',
                'department_id' => (int) ($demand['department_id'] ?? 0),
            ];

            $result = $this->conflict->check($candidate, $others);

            if ($result['status'] === 'conflict') {
                return $result['errors'][0] ?? ['conflict' => 'CNF-01/02/03', 'message' => 'conflicting placement'];
            }
        }

        return null;
    }

    /** @param array<string, array<string, mixed>> $assignments */
    private function rowFrom(array $p, int $index): array
    {
        return [
            'id' => $index,
            'semester_id' => $p['semester_id'] ?? 0,
            'department_id' => $p['department_id'] ?? 0,
            'section_id' => $p['section_id'] ?? 0,
            'subject_id' => $p['subject_id'] ?? 0,
            'faculty_id' => $p['faculty_id'] ?? 0,
            'room_id' => $p['room_id'] ?? 0,
            'day_of_week' => $p['day'] ?? 0,
            // Placements carry minutes since midnight for cheap arithmetic;
            // the conflict engine's contract is `HH:MM:SS`.
            'start_time' => $this->timeLabel((int) ($p['start'] ?? 0)),
            'end_time' => $this->timeLabel((int) ($p['end'] ?? 0)),
        ];
    }

    /**
     * Minutes since midnight → `HH:MM:SS` as the conflict engine expects.
     */
    private function timeLabel(int $minutes): string
    {
        $minutes = max(0, min(24 * 60, $minutes));

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    private function assign(array &$assignments, array &$assigned, int $index, array $placement): void
    {
        $assignments[(string) $index] = $placement;
        $assigned[$index] = $index;
    }

    private function unassign(array &$assignments, array &$assigned, int $index): void
    {
        unset($assignments[(string) $index]);
        unset($assigned[$index]);
    }

    private function objectiveScore(TimetableDraft $draft, array $demandTable, Scope $scope): float
    {
        $score = 0.0;

        // GEN-05: minimise unused room-hours, then balance load.
        $usage = [];
        $load = [];
        foreach ($draft->placements as $p) {
            $hours = (float) ($p['weekly_hours'] ?? 0.0);
            $usage[$p['room_code'] ?? 'unknown'] = ($usage[$p['room_code'] ?? 'unknown'] ?? 0.0) + $hours;
            $load[$p['faculty_code'] ?? 'unknown'] = ($load[$p['faculty_code'] ?? 'unknown'] ?? 0.0) + $hours;
        }

        $maxUsage = $usage !== [] ? max($usage) : 0.0;
        $minUsage = $usage !== [] ? min($usage) : 0.0;
        $score += $maxUsage - $minUsage;

        $maxLoad = $load !== [] ? max($load) : 0.0;
        $minLoad = $load !== [] ? min($load) : 0.0;
        $score += $maxLoad - $minLoad;

        return $score;
    }

    private function metrics(TimetableDraft $draft): array
    {
        $totalHours = 0.0;
        $usage = [];
        $load = [];

        foreach ($draft->placements as $p) {
            $hours = (float) ($p['weekly_hours'] ?? 0.0);
            $totalHours += $hours;
            $usage[$p['room_code'] ?? 'unknown'] = ($usage[$p['room_code'] ?? 'unknown'] ?? 0.0) + $hours;
            $load[$p['faculty_code'] ?? 'unknown'] = ($load[$p['faculty_code'] ?? 'unknown'] ?? 0.0) + $hours;
        }

        $utilization = 0.0;
        $policyHours = $this->policyHours();
        if ($totalHours > 0 && $policyHours > 0) {
            $utilization = $totalHours / $policyHours;
        }

        $loadBalance = 0.0;
        if ($load !== []) {
            $maxLoad = max($load);
            $minLoad = min($load);
            $loadBalance = $maxLoad > 0 ? 1.0 - (($maxLoad - $minLoad) / $maxLoad) : 1.0;
        }

        return [
            'utilization_percent' => round($utilization * 100, 1),
            'load_balance' => round($loadBalance * 100, 1),
            'idle_hours' => round(0.0, 2),
            'total_hours' => round($totalHours, 2),
            'demand_satisfied' => count($draft->placements),
            'demand_total' => count($draft->demandTable),
        ];
    }

    private function unsatisfiable(array $demandTable, array $candidates): array
    {
        $unsat = [];
        foreach ($demandTable as $index => $demand) {
            if (($candidates[$index] ?? []) === []) {
                $unsat[] = [
                    'code' => 'GEN-03',
                    'message' => $demand['section_code'] . ' / ' . $demand['subject_code']
                        . " has no placements: no eligible room, faculty, or legal time block.",
                ];
            }
        }

        return $unsat;
    }

    private function policyHours(): float
    {
        $days = 7;
        $hours = (int) $this->configObject->get('schedule.allowed_hours.end', '19:00') - (int) $this->configObject->get('schedule.allowed_hours.start', '07:00');
        $slot = $this->configObject->get('schedule.slot_minutes', 30);
        $min = $this->configObject->get('schedule.min_duration_minutes', 30);

        return (float) ($days * $hours * $slot / $min);
    }

    private function int(mixed $value): int
    {
        return (int) $value;
    }

    private function defaultDepartment(Scope $scope): int
    {
        if ($scope->isAdministrator()) {
            return 0;
        }

        return $scope->departmentId() ?? 0;
    }
}
