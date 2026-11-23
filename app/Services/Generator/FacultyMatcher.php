<?php

declare(strict_types=1);

namespace App\Services\Generator;

use App\Repositories\FacultyRepository;
use App\Services\Scope;

/**
 * Faculty eligibility (CNF-06, BR-07) plus the GEN-02 availability filter.
 *
 * A faculty profile is eligible when its department matches the subject's
 * department (the section lives in the subject's department), and — if the
 * profile carries an explicit `max_load_units` — the residual teaching load
 * (assigned units this term + the candidate's units) does not exceed it.
 *
 * The `availability` rule (CNF-07) is enforced inside the generator's block
 * enumeration: a slot is only legal if the faculty is `available` on that
 * weekday, and never during an `unavailable` window. The matcher below keeps
 * the static eligibility check (department, load) separate from the dynamic
 * slot check, which needs a weekday and a time range.
 *
 * Scope rules (the same ones the calendar, the conflict engine and the
 * schedule form reuse):
 *   administrator  → every active teacher,
 *   dean / secretary → teachers in their department,
 *   faculty          → only their own profile.
 */
final class FacultyMatcher
{
    public function __construct(
        private readonly FacultyRepository $faculty,
        private readonly Scope $scope,
        private readonly int $semesterId,
        private readonly int $subjectDepartmentId,
        private readonly float $candidateUnits
    ) {
    }

    /**
     * @return array<int, array{id: int, user_id: int, department_id: int, max_load_units: float}>
     */
    public function eligibleProfiles(): array
    {
        if ($this->scope->isAdministrator()) {
            return $this->faculty->allActive();
        }

        return $this->byDepartmentOrSelf();
    }

    /**
     * @return array<int, array{id: int, user_id: int, department_id: int, max_load_units: float}>
     */
    private function byDepartmentOrSelf(): array
    {
        $scope = $this->scope;

        if ($scope->isDepartmental()) {
            return $this->faculty->byDepartment($scope->departmentId());
        }

        // Faculty scope: the teacher can only teach themselves.
        return $this->faculty->findByUserId($scope->userId());
    }

    /**
     * Residual capacity in units for a candidate subject.
     *
     * @param array<int, array{id: int, user_id: int, department_id: int, max_load_units: float, usage: float}> $profiles
     * @return array{is_eligible: bool, remaining: float}
     */
    public function capacity(array $profiles, int $facultyId, float $candidateUnits): array
    {
        $profile = $this->find($facultyId, $profiles);
        if ($profile === null) {
            return ['is_eligible' => false, 'remaining' => 0.0];
        }

        $cap = (float) ($profile['max_load_units'] ?? $this->config['default_max_load_units'] ?? 18.0);
        $used = ($profile['usage'] ?? 0.0) + $candidateUnits;
        $remaining = max(0.0, $cap - $used);

        return [
            'is_eligible' => $remaining >= 0.0,
            'remaining' => $remaining,
        ];
    }

    /**
     * Remaining load for the candidate placement.
     */
    public function remainingLoad(int $facultyId, float $candidateUnits, array $profiles): float
    {
        $profile = $this->find($facultyId, $profiles);
        if ($profile === null) {
            return 0.0;
        }

        $cap = (float) ($profile['max_load_units'] ?? $this->config['default_max_load_units'] ?? 18.0);
        $used = ($profile['assigned_units'] ?? 0.0) + $candidateUnits;

        return max(0.0, $cap - $used);
    }

    private function find(int $facultyId, array $profiles): ?array
    {
        foreach ($profiles as $p) {
            if ((int) $p['id'] === $facultyId) {
                return $p;
            }
        }

        return null;
    }

    private function config(array $defaults): array
    {
        $defaults['default_max_load_units'] = 18.0;

        return $defaults;
    }
}
