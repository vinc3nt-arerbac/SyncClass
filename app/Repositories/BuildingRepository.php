<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Buildings (MD-02).
 *
 * Buildings are institution-wide reference data: a dean may look at them, but
 * only the administrator maintains them (the RBAC matrix grants deans
 * `rooms.view` and nothing more). There is no department column, so no scope
 * clause — the permission on the route is the whole rule.
 *
 * Archived, never deleted (MD-07): rooms and, later, schedules reference them.
 */
final class BuildingRepository extends MasterDataRepository
{
    private const SELECT = <<<'SQL'
        SELECT b.id, b.code, b.name, b.campus, b.address, b.is_active, b.created_at,
               (SELECT COUNT(*) FROM rooms r WHERE r.building_id = b.id) AS room_count
          FROM buildings b
        SQL;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE b.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @param array{search?: string, status?: string, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE 1 = 1';
        $where .= $this->searchClause($filters['search'] ?? null, ['b.code', 'b.name', 'b.campus'], $bindings);
        $where .= $this->activeClause($this->statusOrNull($filters['status'] ?? null));

        $order = $this->orderBy(
            ['code' => 'b.code', 'name' => 'b.name', 'rooms' => 'room_count'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            'b.code'
        );

        return $this->fetchPage(
            self::SELECT . $where . $order,
            'SELECT COUNT(*) FROM buildings b' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function options(bool $activeOnly = true): array
    {
        $rows = $this->database->select(
            'SELECT id, code, name FROM buildings' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY code'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM buildings WHERE code = :code';
        $bindings = ['code' => strtoupper(trim($code))];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @param array{code: string, name: string, campus?: string|null, address?: string|null} $data
     */
    public function create(array $data): int
    {
        return $this->database->insert('buildings', $this->payload($data) + ['is_active' => 1]);
    }

    /**
     * @param array{code: string, name: string, campus?: string|null, address?: string|null} $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('buildings', $this->payload($data), 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('buildings', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    /**
     * @param array{code: string, name: string, campus?: string|null, address?: string|null} $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'campus' => $this->nullable($data['campus'] ?? null),
            'address' => $this->nullable($data['address'] ?? null),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
