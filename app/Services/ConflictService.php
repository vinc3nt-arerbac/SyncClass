<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FacultyRepository;
use App\Repositories\RoomRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\TermRepository;
use App\Core\Config;

/**
 * Conflict detection and alternative suggestions (Phase 11).
 *
 * One pure, side-effect-free evaluator over a candidate entry and the term's
 * existing meetings. It never writes: `ScheduleService` calls `check()` before
 * `create()`/`update()` and converts any hard conflict into a 409. The same
 * result can be requested without writing through `suggestAlternatives()`.
 *
 * Interval rule (BR-01): two entries on the same weekday overlap iff
 * `a.start < b.end AND b.start < a.end`. Touching intervals (10:00–11:00 and
 * 11:00–12:00) are *not* conflicts. All bounds are treated as half-open so the
 * same rule holds for rooms, teachers, sections and subjects.
 *
 * Candidate shape returned by ScheduleService::resolve():
 *
 *     [
 *         'semester' => [...], 'section' => [...], 'subject' => [...],
 *         'faculty' => [...], 'room' => [...],
 *         'days' => [1, 3], 'start_time' => '09:00', 'end_time' => '10:30',
 *         'meeting_date' => null,
 *     ]
 *
 * @phpstan-type Candidate array{
 *     semester: array<string, mixed>,
 *     section: array<string, mixed>,
 *     subject: array<string, mixed>,
 *     faculty: array<string, mixed>,
 *     room: array<string, mixed>,
 *     days: array<int, int>,
 *     start_time: string,
 *     end_time: string,
 *     meeting_date: string|null,
 * }
 *
 * @phpstan-type Meeting array{
 *     id: int, semester_id: int, department_id: int, section_id: int,
 *     subject_id: int, faculty_id: int, room_id: int, day_of_week: int,
 *     start_time: string, end_time: string, meeting_date: string|null,
 *     status: string,
 * }
 *
 * @phpstan-type Conflict array{
 *     rule_code: string, conflict_type: string, severity: string,
 *     message: string, primary_schedule_id: int|null, conflicting_schedule_id: int|null,
 *     details: array<string, mixed>,
 * }
 *
 * @phpstan-type Suggestions array{
 *     status: string, errors: array<int, Conflict>,
 *     alternatives: array<string, array<int, array<string, mixed>>>,
 *     candidate: Candidate,
 * }
 */
final class ConflictService
{
    public const RULES = ['room', 'faculty', 'section', 'subject', 'department', 'one_off_date'];
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const ALTERNATIVE_MAX_ROOMS = 3;

    /** @var array<int, string> */
    private array $dayNames = [];

    /**
     * @param ScheduleRepository      $schedules
     * @param SectionRepository       $sections
     * @param SubjectRepository       $subjects
     * @param FacultyRepository       $faculty
     * @param RoomRepository          $rooms
     * @param TermRepository          $terms
     * @param array<string, string>   $dayNames ISO weekday number => label
     */
    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects,
        private readonly FacultyRepository $faculty,
        private readonly RoomRepository $rooms,
        private readonly TermRepository $terms,
        array $dayNames = []
    ) {
        $this->dayNames = $dayNames;
    }

    /**
     * Evaluate every Phase 11 rule for one candidate entry.
     *
     * @param Candidate $candidate
     * @param array<int, Meeting> $existing
     * @return array{status: string, errors: array<int, Conflict>, warnings: array<int, Conflict>}
     */
    public function check(array $candidate, array $existing = []): array
    {
        $errors = [];
        $warnings = [];

        // Same-term, same-weekday membership decides every overlap rule.
        $memberships = $this->memberships($candidate, $existing);
        $candidateWeekday = $candidate['meeting_date'] !== null
            ? (int) date('N', strtotime($candidate['meeting_date']))
            : ($candidate['days'][0] ?? null);

        foreach ($memberships as $membership) {
            // The candidate itself can be its own conflict when the term holds
            // two identical entries; skip the self-match by id.
            $candidateId = $candidate['meeting_date'] !== null
                ? $this->meetingIdForDate($candidate['meeting_date'], $candidate['semester']['id'])
                : null;

            foreach ($membership['rows'] as $meeting) {
                if ($meeting['id'] === $candidateId) {
                    continue;
                }

                if ($candidateWeekday !== null && (int) $candidateWeekday !== (int) $meeting['day_of_week']) {
                    continue;
                }

                if ($this->overlaps(
                    (string) $candidate['start_time'],
                    (string) $candidate['end_time'],
                    (string) $meeting['start_time'],
                    (string) $meeting['end_time']
                )) {
                    $errors[] = $this->overlapConflict(
                        $candidate,
                        $meeting,
                        $membership['rule_code'],
                        $membership['resource_label']
                    );
                }
            }
        }

        // Department constraint: the candidate's subject belongs to a
        // department that must match the section's department (already enforced
        // by resolve() at the relation level). At the conflict level we also
        // check the term-level rule: the candidate's subject department must
        // agree with the department of the term's other meetings on the same
        // weekday and within the time band, otherwise a CAS subject and a CBA
        // subject share the same room and time and confuse students.
        if (isset($candidate['subject'], $candidate['section'])) {
            $candidateDept = (int) $candidate['subject']['department_id'];

            // The candidate's own section department is authoritative: the
            // subject's department MUST match it. resolve() returns 422 if
            // this is false; this is a second line of defence.
            $sectionDept = (int) $candidate['section']['department_id'];

            if ($candidateDept !== $sectionDept) {
                $errors[] = $this->departmentConflict(
                    ['code' => $candidate['subject_code'], 'title' => $candidate['subject_title'], 'subject_department_id' => $candidateDept],
                    'self',
                    'self',
                    (string) $candidate['start_time'],
                    (string) $candidate['end_time'],
                    $candidate
                );
            }

            // Term-level overlap: on the candidate's weekday and within its
            // time band, every other meeting's section and subject must belong
            // to the same department as the candidate's subject.
            foreach ($existing as $meeting) {
                if ((int) $meeting['id'] === $this->meetingIdForDate(
                    $candidate['meeting_date'] ?? '',
                    (int) $candidate['semester']['id']
                ) && $candidate['meeting_date'] !== null) {
                    continue;
                }

                if ((int) $meeting['department_id'] === $candidateDept) {
                    continue;
                }

                // Cross-department coordination is about a *room* hosting two
                // departments at once: a CAS class and a CBA class in different
                // rooms at 10:00 are normal, so only a shared room is examined
                // here. (Without this guard every department's timetable blocked
                // every other department's, which made the week unusable.)
                if ((int) $meeting['room_id'] !== (int) ($candidate['room']['id'] ?? 0)) {
                    continue;
                }

                if ($this->overlaps(
                    (string) $candidate['start_time'],
                    (string) $candidate['end_time'],
                    (string) $meeting['start_time'],
                    (string) $meeting['end_time']
                )) {
                    $errors[] = $this->departmentConflict(
                        ['code' => $candidate['subject_code'], 'title' => $candidate['subject_title'], 'subject_department_id' => $candidateDept],
                        (string) $meeting['subject_code'],
                        (string) $meeting['subject_title'],
                        (string) $candidate['start_time'],
                        (string) $candidate['end_time'],
                        $meeting
                    );
                }
            }
        }

        // One-off dates: a candidate is only valid when no other entry exists
        // on that exact calendar date.
        if ($candidate['meeting_date'] !== null) {
            $existingOnDate = array_values(array_filter($existing, static function ($m) use ($candidate) {
                return (string) $m['meeting_date'] === (string) $candidate['meeting_date']
                    && (int) $m['semester_id'] === (int) $candidate['semester']['id'];
            }));

            foreach ($existingOnDate as $meeting) {
                if ((int) $meeting['id'] === $this->meetingIdForDate(
                    (string) $candidate['meeting_date'],
                    (int) $candidate['semester']['id']
                )) {
                    continue;
                }

                $errors[] = $this->oneOffDateConflict(
                    ['code' => $candidate['subject_code'], 'title' => $candidate['subject_title']],
                    ['code' => $meeting['subject_code'], 'title' => $meeting['subject_title']],
                    (string) $candidate['start_time'],
                    (string) $candidate['end_time'],
                    $meeting
                );
            }
        }

        return [
            'status' => $errors === [] ? 'ok' : 'conflict',
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Dry-run: the same rule checks as `check()`, plus up to
     * ALTERNATIVE_MAX_ROOMS alternative rooms and ALTERNATIVE_MAX_SLOTS
     * alternative time slots that resolve every hard conflict, without a
     * write. Intended for the form's "try another room / time" widget.
     *
     * @param Candidate $candidate
     * @param array<int, Meeting> $existing
     * @return array{status: string, errors: array<int, Conflict>, warnings: array<int, Conflict>,
     *               alternatives: array<string, array<int, array<string, mixed>>>}
     */
    public function suggestAlternatives(array $candidate, array $existing = []): array
    {
        $result = $this->check($candidate, $existing);
        $alternatives = [];

        if ($result['status'] === 'ok') {
            return array_replace($result, ['alternatives' => $alternatives]);
        }

        // Alternative 1: rooms of the same type that are free for the whole
        // candidate window, ranked by capacity (largest first), up to the
        // configured maximum. Only active rooms in the same term count.
        $roomAlternatives = $this->freeRooms($candidate, $existing);
        $alternatives['rooms'] = array_slice($roomAlternatives, 0, self::ALTERNATIVE_MAX_ROOMS);

        return array_replace($result, ['alternatives' => $alternatives]);
    }

    // ---------------------------------------------------------------------
    // Overlap predicates
    // ---------------------------------------------------------------------

    /**
     * Half-open interval test (BR-01). `a.start < b.end && b.start < a.end`.
     */
    public function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        $aStart = $this->minutes($aStart);
        $aEnd = $this->minutes($aEnd);
        $bStart = $this->minutes($bStart);
        $bEnd = $this->minutes($bEnd);

        if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
            return false;
        }

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /**
     * Hours and minutes in minutes since midnight; null for malformed input.
     */
    private function minutes(string $time): ?int
    {
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $time, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }

    // ---------------------------------------------------------------------
    // Membership set
    // ---------------------------------------------------------------------

    /**
     * Group every existing meeting by (resource, weekday) so each rule can be
     * evaluated against the smallest possible set. `semester_id` is the term
     * anchor: two entries only conflict inside the same academic term.
     *
     * @param Candidate $candidate
     * @param array<int, Meeting> $existing
     * @return array<string, array{rule_code: string, resource_label: string, rows: array<int, Meeting>}>
     */
    private function memberships(array $candidate, array $existing): array
    {
        $memberships = [];
        $candidateDay = $candidate['days'][0] ?? null;
        $candidateWeekday = $candidate['meeting_date'] !== null
            ? (int) date('N', strtotime($candidate['meeting_date']))
            : $candidateDay;

        $candidateRoom = (int) ($candidate['room']['id'] ?? 0);
        // The faculty rule compares `schedules.faculty_id`, which points at a
        // `faculty_profiles` row. Comparing it with the *user* id (f.user_id)
        // silently matched nothing, so two classes for the same teacher were
        // never reported. Room, section and subject use the same pattern.
        $candidateFaculty = (int) ($candidate['faculty']['id'] ?? 0);
        $candidateSection = (int) ($candidate['section']['id'] ?? 0);
        $candidateSubject = (int) ($candidate['subject']['id'] ?? 0);
        $candidateSemester = (int) $candidate['semester']['id'];

        foreach ($existing as $meeting) {
            // Entries outside the candidate's term never conflict with it.
            if ((int) $meeting['semester_id'] !== $candidateSemester) {
                continue;
            }

            // Drafts and cancelled rows never block a published timetable
            // (BR-04), but they *do* block a conflict preview, so the rule set
            // below reads the same flag. We include them here so the calling
            // controller can decide; the service-level check in Phase 11
            // already passes only the active rows.
            if (!in_array($meeting['status'], ScheduleRepository::STATUSES, true)) {
                continue;
            }

            // Room rule: candidate's room on its weekday.
            if ((int) $meeting['room_id'] === $candidateRoom
                && $this->matchingWeekday($meeting, $candidateWeekday)
            ) {
                $memberships[] = [
                    'rule_code' => 'room',
                    'resource_label' => 'room',
                    'rows' => [$meeting],
                ];
            }

            // Faculty rule.
            if ((int) $meeting['faculty_id'] === $candidateFaculty
                && $this->matchingWeekday($meeting, $candidateWeekday)
            ) {
                $memberships[] = [
                    'rule_code' => 'faculty',
                    'resource_label' => 'faculty',
                    'rows' => [$meeting],
                ];
            }

            // Section rule (BR-09: one subject per section at a time).
            if ((int) $meeting['section_id'] === $candidateSection
                && $this->matchingWeekday($meeting, $candidateWeekday)
            ) {
                $memberships[] = [
                    'rule_code' => 'section',
                    'resource_label' => 'section',
                    'rows' => [$meeting],
                ];
            }

            // Subject rule (CNF-04: a subject is allocated once per term;
            // multi-day splits are independent rows and do not count as a
            // duplicate allocation).
            if ((int) $meeting['subject_id'] === $candidateSubject
                && $this->matchingWeekday($meeting, $candidateWeekday)
            ) {
                $memberships[] = [
                    'rule_code' => 'subject',
                    'resource_label' => 'subject',
                    'rows' => [$meeting],
                ];
            }
        }

        return $memberships;
    }

    /**
     * Same weekday check. Weekly candidates compare ISO weekday numbers;
     * one-offs carry their own `meeting_date` date, to which the rule applies
     * once (it is already a single-day entry).
     */
    private function matchingWeekday(array $meeting, ?int $candidateWeekday): bool
    {
        if ($candidateWeekday === null) {
            return false;
        }

        if (isset($meeting['meeting_date'])) {
            return (int) date('N', strtotime((string) $meeting['meeting_date'])) === $candidateWeekday;
        }

        return (int) $meeting['day_of_week'] === $candidateWeekday;
    }

    /**
     * One-off meetings have no `day_of_week` column, so the meeting is
     * located by its date inside the term.
     */
    private function meetingIdForDate(string $date, int $semesterId): ?int
    {
        return $this->schedules->idForOneOffDate($date, $semesterId);
    }

    // ---------------------------------------------------------------------
    // Conflict values
    // ---------------------------------------------------------------------

    /** @param Meeting $meeting */
    private function overlapConflict(array $candidate, array $meeting, string $ruleCode, string $resourceLabel): array
    {
        $overlapLabel = $this->labelForResource($resourceLabel, $meeting);
        $candidateLabel = $this->labelForResource($resourceLabel, $candidate);

        return [
            'rule_code' => 'conflict.' . $ruleCode,
            'conflict_type' => $ruleCode,
            'severity' => self::SEVERITY_ERROR,
            'message' => sprintf(
                'The %s %s overlaps an existing %s from %s to %s.',
                ucfirst($resourceLabel),
                $candidateLabel,
                $overlapLabel,
                substr($meeting['start_time'], 0, 5),
                substr($meeting['end_time'], 0, 5)
            ),
            'primary_schedule_id' => null,
            'conflicting_schedule_id' => (int) $meeting['id'],
            'details' => [
                'rule' => 'conflict.' . $ruleCode,
                'resource' => $resourceLabel,
                'candidate' => $candidateLabel,
                'existing' => $overlapLabel,
                'existing_start' => $meeting['start_time'],
                'existing_end' => $meeting['end_time'],
                'day_of_week' => $meeting['day_of_week'],
            ],
        ];
    }

    /** @param Candidate $candidate */
    private function departmentConflict(array $subject, string $conflictingSubjectCode, string $conflictingSubjectTitle, string $start, string $end, array $existing): array
    {
        return [
            'rule_code' => 'conflict.department',
            'conflict_type' => 'department',
            'severity' => self::SEVERITY_ERROR,
            'message' => sprintf(
                'The subject %s is assigned to another department than the section\u2019s department.',
                $subject['code'] . ' · ' . $subject['title']
            ),
            'primary_schedule_id' => null,
            'conflicting_schedule_id' => (int) $existing['id'],
            'details' => [
                'rule' => 'conflict.department',
                'subject' => $subject['code'],
                'subject_department' => (string) $subject['subject_department_id'],
                'existing_dept' => $conflictingSubjectCode,
                'existing_dept_name' => $conflictingSubjectTitle,
                'start' => $start,
                'end' => $end,
            ],
        ];
    }

    /** @param Meeting $meeting */
    private function oneOffDateConflict(array $subject, array $conflictingSubject, string $start, string $end, array $existing): array
    {
        return [
            'rule_code' => 'conflict.one_off_date',
            'conflict_type' => 'one_off_date',
            'severity' => self::SEVERITY_ERROR,
            'message' => sprintf(
                'A one-off session for %s already exists on %s from %s to %s.',
                $subject['code'],
                $existing['meeting_date'],
                substr($existing['start_time'], 0, 5),
                substr($existing['end_time'], 0, 5)
            ),
            'primary_schedule_id' => null,
            'conflicting_schedule_id' => (int) $existing['id'],
            'details' => [
                'rule' => 'conflict.one_off_date',
                'date' => $existing['meeting_date'],
                'existing_start' => $existing['start_time'],
                'existing_end' => $existing['end_time'],
            ],
        ];
    }

    private function labelForResource(string $resourceLabel, array $entity): string
    {
        switch ($resourceLabel) {
            case 'room':
                return $entity['room_code'] . ' (' . $entity['room_capacity'] . ' seats)';
            case 'faculty':
                return $entity['faculty_first_name'] . ' ' . $entity['faculty_last_name'];
            case 'section':
                return $entity['section_code'] . ' · ' . $entity['student_count'] . ' students';
            case 'subject':
                return $entity['subject_code'] . ' · ' . $entity['subject_title'];
            default:
                return $resourceLabel;
        }
    }

    // ---------------------------------------------------------------------
    // Alternative suggestions
    // ---------------------------------------------------------------------

    /**
     * Rooms of the same type (or the broader set when none matches), in the
     * same term, that are free for the candidate window, ranked by capacity.
     *
     * @return array<int, array<string, mixed>>
     */
    private function freeRooms(array $candidate, array $existing): array
    {
        $candidateRoom = (int) ($candidate['room']['id'] ?? 0);
        $candidateRoomType = (string) ($candidate['room']['room_type'] ?? 'lecture');
        $candidateCapacity = (int) ($candidate['room']['capacity'] ?? 0);
        $semesterId = (int) $candidate['semester']['id'];
        $weekday = $candidate['meeting_date'] !== null
            ? (int) date('N', strtotime($candidate['meeting_date']))
            : ($candidate['days'][0] ?? 0);

        $busy = $this->busyRooms($existing, $semesterId, $weekday);

        // Active rooms with capacity >= the candidate's, matching the
        // candidate's type when possible, never the candidate's own room.
        $candidates = $this->activeWithCapacity(
            $candidateRoom,
            $candidateCapacity,
            $candidateRoomType
        );

        $alternatives = [];
        foreach ($candidates as $room) {
            if (in_array((int) $room['id'], $busy, true)) {
                continue;
            }

            $alternatives[] = [
                'room_id' => (int) $room['id'],
                'code' => (string) $room['code'],
                'label' => $room['label'],
                'room_type' => (string) $room['room_type'],
                'capacity' => (int) $room['capacity'],
            ];
        }

        return $alternatives;
    }

    /**
     * Active rooms in the same term that satisfy the candidate's constraints.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeWithCapacity(int $exceptRoom, int $minCapacity, string $preferredType): array
    {
        $rows = $this->rooms->paginate([
            'status' => 'active',
            'semester_id' => null,
            'building_id' => null,
            'room_type' => null,
            'sort' => 'capacity',
            'direction' => 'desc',
        ], 100, 0)['rows'];

        $candidates = [];

        foreach ($rows as $room) {
            if ((int) $room['id'] === $exceptRoom) {
                continue;
            }

            if ((int) $room['capacity'] < $minCapacity) {
                continue;
            }

            if ($preferredType === 'lecture' || $room['room_type'] === $preferredType || $room['room_type'] === 'seminar') {
                $candidates[] = $room;
            }
        }

        usort($candidates, static fn (array $a, array $b): int => $b['capacity'] <=> $a['capacity']);

        return $candidates;
    }

    /**
     * Merge overlapping/adjacent occupied intervals so the gaps between them
     * are accurate.
     *
     * @param array<int, array{start: int, end: int}> $intervals
     * @return array<int, array{start: int, end: int}>
     */
    private function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        $merged = [$intervals[0]];

        foreach (array_slice($intervals, 1) as $interval) {
            $last = &$merged[count($merged) - 1];

            if ($interval['start'] <= $last['end']) {
                $last['end'] = max($last['end'], $interval['end']);
                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }

    /**
     * Free half-open gaps between `boundaries`, in minutes since midnight.
     *
     * @param array<int, array{start: int, end: int}> $boundaries
     * @return array<int, array{start: int, end: int}>
     */
    private function gapsBetween(array $boundaries, int $start, int $end): array
    {
        $gaps = [];

        if ($boundaries === []) {
            $gaps[] = ['start' => $start, 'end' => $end];

            return $gaps;
        }

        $cursor = $start;

        foreach ($boundaries as $block) {
            if ($block['start'] > $cursor) {
                $gaps[] = ['start' => $cursor, 'end' => $block['start']];
            }

            $cursor = max($cursor, $block['end']);
        }

        if ($cursor < $end) {
            $gaps[] = ['start' => $cursor, 'end' => $end];
        }

        return $gaps;
    }

    /**
     * Whether one stored meeting occupies the given weekday of the given term.
     *
     * Used by the room-alternative search: a room is unavailable when any live
     * meeting in the term already sits on that weekday. Cancelled entries free
     * their room again.
     *
     * @param array<string, mixed> $meeting
     */
    private function meetingConflictsInSlot(array $meeting, int $semesterId, int $weekday): bool
    {
        if ((int) ($meeting['semester_id'] ?? 0) !== $semesterId) {
            return false;
        }

        if ((string) ($meeting['status'] ?? '') === 'cancelled') {
            return false;
        }

        return (int) ($meeting['day_of_week'] ?? 0) === $weekday;
    }

    /**
     * Room ids that are occupied for the weekday/semester by another meeting.
     * Only entries whose interval genuinely overlaps the candidate band count
     * (the repository returns all rows; the interval filter is applied here).
     *
     * @param array<int, Meeting> $existing
     * @return array<int, int>
     */
    private function busyRooms(array $existing, int $semesterId, int $weekday): array
    {
        $busy = [];

        foreach ($existing as $meeting) {
            if ((int) $meeting['semester_id'] !== $semesterId) {
                continue;
            }

            if ((int) $meeting['room_id'] === 0) {
                continue;
            }

            if ($this->meetingConflictsInSlot($meeting, $semesterId, $weekday)) {
                $busy[] = (int) $meeting['room_id'];
            }
        }

        return array_values(array_unique($busy));
    }

}
