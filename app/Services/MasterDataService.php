<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\DepartmentRepository;
use App\Repositories\TermRepository;

/**
 * Cross-module rules for the master-data screens (Phase 9).
 *
 * The repositories own SQL and the controllers own HTTP. This service owns the
 * three rules that are not specific to one table but are security- or
 * integrity-critical:
 *
 *   1. **Department membership.** A dean may only create a subject in their own
 *      department; the controller must not trust a posted `department_id`.
 *      `assertDepartment()` turns a mismatch into 403 and returns the clean id.
 *   2. **One current year, one current semester per year.** Enforced with a
 *      transaction that clears the previous row before setting the new one.
 *   3. **Uniqueness as a field error.** A duplicate code is a 422 on the form,
 *      never a database exception page.
 *
 * Keeping these here means every module applies the same rule the same way —
 * the alternative is six slightly different copies of "can this user touch this
 * department?".
 */
final class MasterDataService
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly TermRepository $terms
    ) {
    }

    /**
     * Departments the user may choose in a form or filter.
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function departmentOptions(Scope $scope): array
    {
        return $this->departments->options($scope);
    }

    /**
     * Validate that a department id is inside the caller's scope.
     *
     * @throws HttpException 403 when the id belongs to another department.
     */
    public function assertDepartment(Scope $scope, ?int $departmentId): int
    {
        if ($departmentId === null || $departmentId <= 0) {
            throw HttpException::unprocessable(['department_id' => 'Choose a department.']);
        }

        if (!$scope->coversDepartment($departmentId)) {
            throw HttpException::forbidden('You can only manage records in your own department.');
        }

        return $departmentId;
    }

    /**
     * A duplicate code is a validation failure, not a 500.
     */
    public function assertUnique(bool $exists, string $field, string $message): void
    {
        if ($exists) {
            throw HttpException::unprocessable([$field => $message]);
        }
    }

    /**
     * Mark an academic year as the current one (clearing the previous).
     *
     * @throws HttpException 404 when the row does not exist.
     */
    public function setCurrentYear(int $yearId): void
    {
        if ($this->terms->findYear($yearId) === null) {
            throw HttpException::notFound('That academic year does not exist.');
        }

        $this->terms->setCurrentYear($yearId);
    }

    /**
     * Mark a semester as current within its academic year.
     *
     * @throws HttpException 404 when the row does not exist.
     */
    public function setCurrentSemester(int $semesterId): void
    {
        if ($this->terms->findSemester($semesterId) === null) {
            throw HttpException::notFound('That semester does not exist.');
        }

        $this->terms->setCurrentSemester($semesterId);
    }
}
