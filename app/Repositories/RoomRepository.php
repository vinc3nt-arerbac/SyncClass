<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Scope;

/**
 * Rooms (MD-02, CNF-05).
 *
 * A room belongs to a building and carries the type and capacity the conflict
 * engine will use later (capacity mismatch, laboratory-in-lecture-room). The
 * unique key is `(building_id, code)`, so the same code may exist in two
 * buildings — this repository checks uniqueness with the building in the
 * equation rather than globally.
 *
 * Archived, never deleted (MD-07): schedules will reference rooms.
 */
final class RoomRepository extends MasterDataRepository
{
    public const TYPES = ['lecture', 'laboratory', 'seminar'];

    private const SELECT = <<<'SQL'
        SELECT r.id, r.building_id, r.code, r.name, r.floor, r.room_type, r.capacity,
               r.facilities, r.is_active, r.created_at,
               b.code AS building_code, b.name AS building_name
          FROM rooms r
          INNER JOIN buildings b ON b.id = r.building_id
        SQL;

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE r.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * @param array{search?: string, status?: string, building_id?: int|null, room_type?: string, sort?: string, direction?: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $bindings = [];
        $where = ' WHERE 1 = 1';
        $where .= $this->searchClause($filters['search'] ?? null, ['r.code', 'r.name', 'b.code', 'b.name'], $bindings);
        $where .= $this->activeClause($this->statusOrNull($filters['status'] ?? null), 'r.is_active');

        $buildingId = $this->idOrNull($filters['building_id'] ?? null);

        if ($buildingId !== null) {
            $where .= ' AND r.building_id = :building_id';
            $bindings['building_id'] = $buildingId;
        }

        $type = strtolower(trim((string) ($filters['room_type'] ?? '')));

        if (in_array($type, self::TYPES, true)) {
            $where .= ' AND r.room_type = :room_type';
            $bindings['room_type'] = $type;
        }

        $order = $this->orderBy(
            ['code' => 'r.code', 'building' => 'b.code', 'capacity' => 'r.capacity', 'type' => 'r.room_type'],
            $filters['sort'] ?? null,
            $filters['direction'] ?? null,
            'b.code, r.code'
        );

        return $this->fetchPage(
            self::SELECT . $where . $order,
            'SELECT COUNT(*) FROM rooms r INNER JOIN buildings b ON b.id = r.building_id' . $where,
            $bindings,
            $limit,
            $offset
        );
    }

    /**
     * Active rooms for the schedule form.
     *
     * @return array<int, array{id: int, label: string, room_type: string, capacity: int}>
     */
    public function options(): array
    {
        $rows = $this->database->select(
            'SELECT r.id, r.code, r.room_type, r.capacity, b.code AS building_code
               FROM rooms r
               INNER JOIN buildings b ON b.id = r.building_id
              WHERE r.is_active = 1 AND b.is_active = 1
              ORDER BY b.code, r.code'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => $row['building_code'] . ' ' . $row['code'] . ' · ' . ucfirst((string) $row['room_type']) . ' · ' . $row['capacity'] . ' seats',
            'room_type' => (string) $row['room_type'],
            'capacity' => (int) $row['capacity'],
        ], $rows);
    }

    public function codeExistsInBuilding(int $buildingId, string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM rooms WHERE building_id = :building_id AND code = :code';
        $bindings = ['building_id' => $buildingId, 'code' => strtoupper(trim($code))];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @param array{code: string, name?: string|null, building_id: int, floor?: int|null, room_type: string, capacity: int, facilities?: string|null} $data
     */
    public function create(array $data): int
    {
        return $this->database->insert('rooms', $this->payload($data) + ['is_active' => 1]);
    }

    /**
     * @param array{code: string, name?: string|null, building_id: int, floor?: int|null, room_type: string, capacity: int, facilities?: string|null} $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('rooms', $this->payload($data), 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('rooms', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    /**
     * @param array{code: string, name?: string|null, building_id: int, floor?: int|null, room_type: string, capacity: int, facilities?: string|null} $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'building_id' => (int) $data['building_id'],
            'code' => strtoupper(trim($data['code'])),
            'name' => $this->nullable($data['name'] ?? null),
            'floor' => isset($data['floor']) && $data['floor'] !== '' ? (int) $data['floor'] : null,
            'room_type' => in_array((string) $data['room_type'], self::TYPES, true) ? (string) $data['room_type'] : 'lecture',
            'capacity' => max(1, (int) $data['capacity']),
            'facilities' => $this->nullable($data['facilities'] ?? null),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Active rooms for a filter dropdown.
     *
     * Rooms are shared institutional space: they belong to a building, not to a
     * department, so every signed-in actor sees the same list. Department
     * scoping applies to the schedules booked into a room, not to the room.
     *
     * @param array<string, mixed> $authUser
     * @return array<int, array<string, mixed>>
     */
    public function optionsFor(array $authUser = []): array
    {
        return $this->database->select(
            'SELECT r.id, r.code, r.name, r.room_type, r.capacity, b.code AS building_code'
            . ' FROM rooms r INNER JOIN buildings b ON b.id = r.building_id'
            . ' WHERE r.is_active = 1 ORDER BY b.code, r.code'
        );
    }

    /**
     * Rooms that can host a subject, for the generator's candidate space.
     *
     * The subject's preferred room type wins; otherwise the subject type decides
     * (a laboratory or mixed subject needs a laboratory, everything else a
     * lecture room). Capacity is returned as well so the caller can prefer a
     * room that fits the section.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bySubjectAndScope(int $subjectId, Scope $scope, int $departmentId): array
    {
        $subject = $this->database->selectOne(
            'SELECT subject_type, preferred_room_type FROM subjects WHERE id = :id LIMIT 1',
            ['id' => $subjectId]
        );

        $type = (string) ($subject['preferred_room_type'] ?? '');

        if ($type === '') {
            $type = in_array((string) ($subject['subject_type'] ?? 'lecture'), ['laboratory', 'mixed'], true)
                ? 'laboratory'
                : 'lecture';
        }

        return $this->database->select(
            'SELECT r.id, r.code, r.room_type, r.capacity, b.code AS building_code'
            . ' FROM rooms r INNER JOIN buildings b ON b.id = r.building_id'
            . ' WHERE r.is_active = 1 AND r.room_type = :type'
            . ' ORDER BY r.capacity DESC, r.id',
            ['type' => $type]
        );
    }
}
