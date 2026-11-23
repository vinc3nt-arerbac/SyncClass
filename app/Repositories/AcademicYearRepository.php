<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Academic-year read model for scheduling filters.
 *
 * Writing years is TermRepository's job (Phase 9). The calendar only needs the
 * list for its year filter, so this class exposes one query and nothing else.
 */
final class AcademicYearRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Years for a filter dropdown.
     *
     * `['active' => true]` keeps the current year plus any year that has not
     * finished yet — a closed past year would only produce empty calendars.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function all(array $filters = []): array
    {
        $sql = 'SELECT y.id, y.code, y.start_date, y.end_date, y.is_current,
                       (SELECT COUNT(*) FROM semesters s WHERE s.academic_year_id = y.id) AS semester_count
                  FROM academic_years y';

        $bindings = [];

        if (($filters['active'] ?? false) === true) {
            $sql .= ' WHERE y.is_current = 1 OR y.end_date >= :today';
            $bindings['today'] = date('Y-m-d');
        }

        $sql .= ' ORDER BY y.start_date DESC, y.id DESC';

        return $this->database->select($sql, $bindings);
    }
}
