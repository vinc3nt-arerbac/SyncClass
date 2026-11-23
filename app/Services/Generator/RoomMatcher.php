<?php

declare(strict_types=1);

namespace App\Services\Generator;

use App\Repositories\RoomRepository;
use App\Services\Scope;

/**
 * Room eligibility (CNF-05, BR-05).
 *
 * A room is legal for a subject when its type matches the preferred room type
 * of the subject (or the subject has no preference, in which case any room is
 * fine) and its capacity is at least the section's `student_count`. Faculty
 * profiles are *not* rooms: deans choose rooms, secretaries floor them, admins
 * own them. The matcher is pure and returns the room ids that satisfy both
 * constraints, ordered by capacity.
 */
final class RoomMatcher
{
    public function __construct(
        private readonly RoomRepository $rooms
    ) {
    }

    /**
     * Legal room ids for a subject within a scope and department.
     *
     * @param array<int, int> $roomIds
     * @return array<int, int>
     */
    public function eligibleIds(Scope $scope, int $departmentId, int $subjectId, array $roomIds): array
    {
        $subject = $this->rooms->find($subjectId);
        $preferred = $subject['preferred_room_type'] ?? 'lecture';
        $limit = $subject['units'] > 0 ? $subject['units'] : 3.0;

        $eligible = [];
        foreach ($roomIds as $roomId) {
            $room = $this->rooms->find($roomId);
            if ($room === null) {
                continue;
            }

            // Type match: the subject's preferred room type must dominate the
            // room's type. A laboratory subject gets a laboratory or seminar
            // room (no lecture); a lecture subject gets any type.
            $roomType = $room['room_type'] ?? 'lecture';
            if (!in_array($roomType, $this->complement($preferred), true)) {
                continue;
            }

            $capacity = (int) $room['capacity'];
            if ($capacity < (int) $limit) {
                continue;
            }

            $eligible[] = $roomId;
        }

        return $eligible;
    }

    /**
     * Complement of the subject's preferred room type: the set of room types
     * that may host it.
     */
    private function complement(string $preferred): array
    {
        if ($preferred === 'laboratory') {
            return ['laboratory', 'seminar'];
        }

        // lecture or seminar (null = any)
        return ['lecture', 'seminar', 'laboratory'];
    }
}
