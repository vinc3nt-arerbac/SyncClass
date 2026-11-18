<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Faculty availability windows (MD-05, CNF-07).
 *
 * Rows are half-open time intervals per weekday. `unavailable` is a carve-out
 * inside a wider available range (available 08:00–17:00, unavailable 12:00–13:00),
 * which is why the type is stored per row rather than modelled as one range.
 *
 * The repository never decides *who* may edit a window: the controller loads
 * the faculty profile through FacultyRepository::findInScope() first, then
 * calls this repository with that profile id. Ownership is therefore checked
 * once, against the profile, and cannot be bypassed by posting another
 * faculty_id.
 */
final class AvailabilityRepository extends MasterDataRepository
{
    public const TYPES = ['available', 'preferred', 'unavailable'];

    private const SELECT = <<<'SQL'
        SELECT a.id, a.faculty_id, a.day_of_week, a.start_time, a.end_time,
               a.availability_type, a.note, a.created_at
          FROM faculty_availability a
        SQL;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forFaculty(int $facultyId): array
    {
        return $this->database->select(
            self::SELECT . ' WHERE a.faculty_id = :faculty_id ORDER BY a.day_of_week, a.start_time',
            ['faculty_id' => $facultyId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->selectOne(self::SELECT . ' WHERE a.id = :id LIMIT 1', ['id' => $id]);
    }

    public function countForFaculty(int $facultyId): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM faculty_availability WHERE faculty_id = :faculty_id',
            ['faculty_id' => $facultyId]
        );
    }

    /**
     * True when the new window overlaps an existing one on the same weekday.
     *
     * Half-open intervals: 08:00–12:00 and 12:00–13:00 do not overlap, which
     * is what a timetable expects. Stored as `start < new_end AND end > new_start`.
     */
    public function overlaps(int $facultyId, int $dayOfWeek, string $startTime, string $endTime, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM faculty_availability
                 WHERE faculty_id = :faculty_id
                   AND day_of_week = :day_of_week
                   AND start_time < :end_time
                   AND end_time > :start_time';
        $bindings = [
            'faculty_id' => $facultyId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return (int) $this->database->scalar($sql, $bindings) > 0;
    }

    /**
     * @param array{day_of_week: int, start_time: string, end_time: string, availability_type: string, note?: string|null} $data
     */
    public function create(int $facultyId, array $data): int
    {
        return $this->database->insert('faculty_availability', [
            'faculty_id' => $facultyId,
            'day_of_week' => max(1, min(7, (int) $data['day_of_week'])),
            'start_time' => $this->time($data['start_time']),
            'end_time' => $this->time($data['end_time']),
            'availability_type' => in_array((string) $data['availability_type'], self::TYPES, true)
                ? (string) $data['availability_type']
                : 'available',
            'note' => $this->nullable($data['note'] ?? null),
        ]);
    }

    public function delete(int $id): void
    {
        $this->database->delete('faculty_availability', 'id = :id', ['id' => $id]);
    }

    /**
     * Accept `H:i` (the time input) and normalise to `H:i:s` for the TIME column.
     */
    private function time(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value . ':00';
        }

        return $value;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
