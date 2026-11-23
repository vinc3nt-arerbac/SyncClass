<?php

declare(strict_types=1);

namespace App\Services\Generator;

/**
 * The in-memory draft timetable (GEN-04, BR-04).
 *
 * A `TimetableDraft` is the generator's output: a set of placements that
 * satisfies the hard conflict rules and the GEN-05 objective ordering. It is
 * never written to the database; the controller commits it by inserting all
 * placements through `ScheduleService`, which enforces the single validated
 * write path for the form, calendar drag and this draft.
 *
 * The draft is also used by the preview endpoint (`GET /generator/preview`)
 * to display the proposed timetable with its utilization and load-balance
 * metrics.
 */
final class TimetableDraft
{
    /** @var array<int, array<string, mixed>> placement keyed by demand index */
    public array $placements = [];

    /** @var array<int, array<string, mixed>> demand rows that the draft satisfied */
    public array $demandTable = [];

    /** The term the draft was generated for. */
    public ?int $semesterId = null;

    public function __construct(array $placements = [])
    {
        $this->placements = $placements;
    }

    public function add(int $demandIndex, array $placement): void
    {
        $this->placements[$demandIndex] = $placement;
    }

    public function remove(int $demandIndex): void
    {
        unset($this->placements[$demandIndex]);
    }

    public function has(int $demandIndex): bool
    {
        return isset($this->placements[$demandIndex]);
    }

    public function get(int $demandIndex): ?array
    {
        return $this->placements[$demandIndex] ?? null;
    }

    public function toArray(): array
    {
        $rows = [];
        foreach ($this->placements as $index => $p) {
            $rows[$index] = $p;
        }

        return [
            'placements' => $rows,
            'semester_id' => $this->semesterId,
            'count' => count($rows),
        ];
    }
}
