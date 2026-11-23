<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Read model for semesters used by the calendar and the generator.
 *
 * The CRUD side of terms lives in TermRepository (Phase 9: create, edit, set
 * current). This class is deliberately narrower: it only answers the three
 * questions the scheduling surfaces ask — which terms may I pick, which one is
 * current, and what does one term look like — so those screens do not import
 * the whole master-data surface.
 *
 * Every term is institution-wide; "visibility" here is about *ordering and
 * availability for selection* (current first, then open terms, never closed
 * ones unless asked), not department scoping. Department scoping is applied to
 * the schedule rows themselves by ScheduleRepository.
 */
final class SemesterRepository
{
    private const SELECT = <<<'SQL'
        SELECT s.id, s.academic_year_id, s.term_no, s.name, s.code,
               s.start_date, s.end_date, s.status, s.is_current,
               y.code AS academic_year_code
          FROM semesters s
          INNER JOIN academic_years y ON y.id = s.academic_year_id
        SQL;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Terms the actor may choose from, current first.
     *
     * @param array<string, mixed> $authUser
     * @return array<int, array<string, mixed>>
     */
    public function visibleFor(array $authUser, ?int $currentId = null): array
    {
        $rows = $this->database->select(
            self::SELECT . ' ORDER BY s.is_current DESC, s.start_date DESC, s.term_no DESC'
        );

        if ($currentId !== null) {
            usort($rows, static function (array $a, array $b) use ($currentId): int {
                $rank = static fn (array $row): int => (int) $row['id'] === $currentId ? 0 : 1;

                return $rank($a) <=> $rank($b);
            });
        }

        return $rows;
    }

    /**
     * Open terms for a picker: the current one plus any active/planned term.
     * Closed terms are history and are not offered for new scheduling.
     *
     * @param array<string, mixed> $authUser
     * @return array<int, array<string, mixed>>
     */
    public function currentOrActiveFor(array $authUser): array
    {
        return $this->database->select(
            self::SELECT . " WHERE s.is_current = 1 OR s.status IN ('active', 'planned')"
            . ' ORDER BY s.is_current DESC, s.start_date DESC, s.term_no DESC'
        );
    }

    /** @return array<string, mixed>|null */
    public function findSemester(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE s.id = :id LIMIT 1', ['id' => $id]);
    }
}
