<?php

declare(strict_types=1);

namespace App\Services\Generator;

use App\Core\Config;
use App\Services\ConflictService;
use App\Services\ScheduleService;

/**
 * In-session draft timetable (GEN-04, BR-04).
 *
 * The generator keeps its partial assignment in memory; nothing is written to
 * the database until the administrator or dean commits the draft. The session
 * is the single source of truth during a run: on success it hands the placements
 * to `ScheduleService::create()` (which runs the full conflict check and writes
 * them as `draft` rows), and it is discarded on failure — the user gets an
 * `unsat` explanation, not a half-built timetable.
 *
 * Brings together the pieces the generator needs:
 *   - conflict engine (Phase 11) for the feasibility filter
 *   - schedule service (Phase 12) for the single validated write path
 *   - the policy windows from the config (allowed hours, breaks, load caps)
 */
final class DraftSession
{
    public function __construct(
        private readonly Config $config,
        private readonly ConflictService $conflict,
        private readonly ScheduleService $scheduleService
    ) {
    }

    /** @var array<string, array<string, mixed>> placement keyed by id */
    public array $placements = [];

    /** @var array<int, array<string, mixed>> demand rows being satisfied */
    public array $demandTable = [];

    /** Backtrack bookkeeping: demand index → parent assignee (what we revert on failure) */
    public array $assignmentStack = [];

    /** Node budget counter (ADR-06) */
    public int $nodeCount = 0;

    /** Wall-clock timer (ADR-06) */
    public float $timeStarted = 0.0;

    /** Current running time in seconds */
    public float $elapsed(): float
    {
        return microtime(true) - $this->timeStarted;
    }

    /** Whether the node budget has been exhausted. */
    public function overBudget(int $budget): bool
    {
        return $this->nodeCount >= $budget;
    }

    /** Whether the wall-clock budget has been exhausted. */
    public function overTimeLimit(float $seconds): bool
    {
        return $this->elapsed() > $seconds;
    }

    /**
     * Add one placement to the draft and record the demand it satisfies.
     */
    public function add(int $demandIndex, array $placement): void
    {
        $this->placements[(string) $demandIndex] = $placement;
        $this->assignmentStack[] = $demandIndex;
    }

    /**
     * Remove the most recent placement (backtrack).
     */
    public function backtrack(): ?int
    {
        if ($this->assignmentStack === []) {
            return null;
        }

        $index = array_pop($this->assignmentStack);
        unset($this->placements[$index]);

        return $index;
    }

    /**
     * The set of placement ids currently committed to the draft.
     *
     * @return array<int, int>
     */
    public function assignedIds(): array
    {
        $ids = [];
        foreach ($this->placements as $index => $p) {
            $ids[(int) $index] = (int) $index;
        }

        return $ids;
    }

    /**
     * Copy the session for backtracking (so a branch can fail while the
     * parent keeps its state).
     */
    public function copy(): self
    {
        $copy = new self(
            $this->config,
            $this->conflict,
            $this->scheduleService
        );
        $copy->placements = $this->placements;
        $copy->demandTable = $this->demandTable;
        $copy->assignmentStack = $this->assignmentStack;
        $copy->nodeCount = $this->nodeCount;
        $copy->timeStarted = $this->timeStarted;

        return $copy;
    }
}
