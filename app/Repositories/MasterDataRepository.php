<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Scope;

/**
 * Shared query plumbing for master-data repositories (Phase 9).
 *
 * The six master-data modules differ in columns, not in behaviour: every list
 * needs the same four moves — a scope clause, a search clause, a whitelisted
 * ORDER BY and a count for pagination. Duplicating that glue seven times is
 * how list screens drift apart, so it lives here once.
 *
 * Deliberate limits:
 *
 *   - **Sorting is a whitelist.** A `sort` query parameter is only ever turned
 *     into SQL after it matches a key the repository declares, so the column
 *     name can never come from the client. The direction is normalised to
 *     ASC/DESC for the same reason.
 *   - **Search is bound, never interpolated.** Each searched column gets its
 *     own placeholder (native MySQL prepares reject a repeated named
 *     parameter), and `!` is the LIKE escape character so the same SQL behaves
 *     identically on MySQL and on the SQLite test database.
 *   - **Pagination numbers are integers built in PHP**, bounded by the caller,
 *     so LIMIT/OFFSET stay literal numbers that need no binding.
 */
abstract class MasterDataRepository
{
    public function __construct(protected readonly Database $database)
    {
    }

    /**
     * Escape a user search term for a LIKE with `ESCAPE '!'`.
     */
    protected function likeTerm(string $search): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
    }

    /**
     * Build `AND (col1 LIKE :prefix_0 ESCAPE '!' OR …)`.
     *
     * @param array<int, string>    $columns  Fully-qualified column names.
     * @param array<string, mixed>  $bindings Extended with one entry per column.
     */
    protected function searchClause(?string $search, array $columns, array &$bindings, string $prefix = 'q'): string
    {
        $search = trim((string) $search);

        if ($search === '' || $columns === []) {
            return '';
        }

        $term = $this->likeTerm($search);
        $parts = [];

        foreach (array_values($columns) as $index => $column) {
            $placeholder = $prefix . '_' . $index;
            $parts[] = $column . ' LIKE :' . $placeholder . " ESCAPE '!'";
            $bindings[$placeholder] = $term;
        }

        return ' AND (' . implode(' OR ', $parts) . ')';
    }

    /**
     * Whitelisted ORDER BY.
     *
     * @param array<string, string> $allowed Map of public sort key => SQL expression.
     */
    protected function orderBy(array $allowed, ?string $sort, ?string $direction, string $default): string
    {
        $key = (string) $sort;

        if ($key === '' || !array_key_exists($key, $allowed)) {
            $expression = $default;
        } else {
            $expression = $allowed[$key];
        }

        $dir = strtolower((string) $direction) === 'desc' ? 'DESC' : 'ASC';

        return ' ORDER BY ' . $expression . ' ' . $dir;
    }

    /**
     * `AND col = 1` / `AND col = 0` / '' — an integer literal, never a binding,
     * because the value comes from a boolean, not from the request.
     */
    protected function activeClause(?bool $active, string $column = 'is_active'): string
    {
        if ($active === null) {
            return '';
        }

        return ' AND ' . $column . ' = ' . ($active ? '1' : '0');
    }

    /**
     * Run a count query and the matching page query with one binding set.
     *
     * @param array<string, mixed> $bindings
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    protected function fetchPage(string $selectSql, string $countSql, array $bindings, int $limit, int $offset): array
    {
        $total = (int) $this->database->scalar($countSql, $bindings);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $rows = $this->database->select(
            $selectSql . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $bindings
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Coerce a filter value to a positive id or null, so a malformed query
     * string becomes "no filter" instead of an error or an injection attempt.
     */
    protected function idOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * Scope clause for any table with a `department_id` column.
     *
     * Administrator → no restriction. Everyone else → their department, with
     * a missing department matching nothing (an incomplete profile must not
     * silently widen to the whole institution). Faculties are handled the same
     * way here because their *catalogue* access is department-shaped; the
     * faculty profile itself uses `user_id` instead (see FacultyRepository).
     *
     * @param array<string, mixed> $bindings
     */
    protected function departmentScope(Scope $scope, string $alias, array &$bindings): string
    {
        if ($scope->isAdministrator()) {
            return '1 = 1';
        }

        $bindings['scope_department_id'] = $scope->departmentId() ?? 0;

        return $alias . '.department_id = :scope_department_id';
    }

    /**
     * `true` / `false` / null from a status filter (`active`, `inactive`).
     */
    protected function statusOrNull(mixed $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            'active' => true,
            'inactive', 'archived' => false,
            default => null,
        };
    }
}
