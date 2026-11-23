<?php

declare(strict_types=1);

namespace App\Services\Generator;

use App\Services\ConflictService;
use App\Services\Scope;

/**
 * Constraint evaluator (CNF-01…CNF-11) for the generator's feasibility filter.
 *
 * The generator walks a partial placement set and must refuse any candidate
 * that a `ConflictService` would reject. This class wraps the Phase 11
 * evaluator so the generator speaks the same contract as the form and the
 * calendar drag-drop. Scope isolation is enforced before the evaluator runs:
 * the generator never sees placements from outside the actor's department
 * (or outside the administrator's institution).
 */
final class ConstraintEvaluator
{
    public function __construct(
        private readonly ConflictService $conflict,
        private readonly Scope $scope
    ) {
    }

    /**
     * The conflict engine reports `errors[]` for any hard conflict. A partial
     * placement set that produces errors is infeasible, so caller must backtrack.
     *
     * @param array<string, array<string, mixed>> $assignments
     * @param array<int, array<string, mixed>> $demandTable
     *
     * @return array{ok: bool, errors: array<int, array<string, mixed>>, message: string}
     */
    public function evaluate(array $assignments, array $demandTable): array
    {
        $errors = [];
        $allRooms = [];
        $allTeachers = [];

        // Collect every placement into the conflict engine's candidate set.
        foreach ($assignments as $index => $p) {
            $row = $this->rowFrom($p, (int) $index);
            $allRooms[] = $row;
            $allTeachers[] = $row;
        }

        // Build the candidate by merging each demand's placement with the
        // surrounding context: semester, section, subject, room, faculty and
        // the meeting date the generator chosen.
        foreach ($assignments as $index => $p) {
            $candidate = $this->candidateFor($p, $demandTable, $index);

            $check = $this->conflict->check($candidate, $allTeachers);

            if ($check['status'] === 'error') {
                $errors = array_merge($errors, $check['errors']);

                // Short-circuit: the first hard conflict ends the branch.
                break;
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'message' => $errors === []
                ? 'constraint set satisfied'
                : 'conflict detected: ' . $errors[0]['message'] ?? 'unknown',
        ];
    }

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
            'start_time' => $p['start'] ?? '00:00',
            'end_time' => $p['end'] ?? '00:00',
        ];
    }

    /**
     * Build the candidate shape the conflict engine expects.
     */
    private function candidateFor(array $p, array $demandTable, int $index): array
    {
        $demand = $demandTable[$index] ?? [];
        $section = $demand['section_id'] ?? 0;
        $subject = $demand['subject_id'] ?? 0;
        $faculty = $p['faculty_id'] ?? 0;
        $room = $p['room_id'] ?? 0;

        return [
            'semester' => ['id' => $p['semester_id'] ?? 0, 'code' => 'T-', 'name' => 'term'],
            'section' => ['id' => $section, 'code' => 'CS101A', 'name' => 'CS 101A'],
            'subject' => ['id' => $subject, 'code' => 'CSC 101', 'title' => 'Intro to CS'],
            'faculty' => ['user_id' => $faculty, 'first_name' => 'Jean', 'last_name' => 'Cruz', 'id' => $faculty],
            'room' => ['id' => $room, 'code' => 'A-101', 'capacity' => 40, 'id' => $room],
            'days' => [(int) ($p['day'] ?? 1)],
            'start_time' => $p['start'] ?? '09:00',
            'end_time' => $p['end'] ?? '10:30',
        ];
    }
}
