<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use App\Services\ConflictService;
use App\Repositories\FacultyRepository;
use App\Repositories\RoomRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\TermRepository;
use DateTimeImmutable;

/**
 * The one validated save path for schedule entries (SCH-02, SCH-03, SCH-05).
 *
 * Every write — the add-class form today, the calendar drag in Phase 12 and the
 * generator commit in Phase 13 — must pass through this service. That is what
 * keeps the rules in one place:
 *
 *   1. **Relations are checked, not trusted.** The posted section, subject,
 *      faculty profile and room must exist, be active, and belong to the same
 *      department as the section. The department of the entry is *derived* from
 *      the section; the client never supplies it.
 *   2. **The actor's scope is checked again.** A dean may only write inside
 *      their department, even if they hold `schedules.update`.
 *   3. **Policy is enforced**: half-open time range, minimum/maximum duration,
 *      institution hours and break windows from config/schedule.php.
 *   4. **Recurrence is normalised to rows.** `days[]` becomes one row per day
 *      (SCH-04); a one-off meeting derives its weekday from `meeting_date`.
 *   5. **Lifecycle is a directed graph.** Only the transitions in TRANSITIONS
 *      are accepted, approval and cancellation need their own permissions, and
 *      editing an approved entry sends it back to `pending` for re-approval.
 *
 * Phase 11 adds the conflict engine to `create()`/`update()` before the insert.
 * `ConflictService` owns every overlap rule (room, faculty, section, subject,
 * department, one-off date) and the alternative suggestions. The service asks
 * `ScheduleRepository::activeInSemester()` for the term's other meetings, throws
 * a 422 with `conflicts[]` on a hit, and hands the validated facts to the
 * repository.
 */
final class ScheduleService
{
    /**
     * Allowed lifecycle moves (SCH-05). `cancelled` is terminal.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        'draft' => ['pending', 'cancelled'],
        'pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['published', 'cancelled'],
        'published' => ['cancelled'],
        'rejected' => ['draft', 'cancelled'],
        'cancelled' => [],
    ];

    /** Statuses an entry may be edited from (approved/published reopen to pending). */
    private const EDITABLE = ['draft', 'pending', 'rejected'];

    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly SectionRepository $sections,
        private readonly SubjectRepository $subjects,
        private readonly FacultyRepository $faculty,
        private readonly RoomRepository $rooms,
        private readonly TermRepository $terms,
        private readonly AuthorizationService $authorization,
        private readonly Config $config,
        private readonly ConflictService $conflict,
        // Optional so the standalone suites (and any CLI backfill) can build
        // the service without an inbox; the HTTP container autowires it.
        private readonly ?NotificationService $notifications = null
    ) {
    }

    /**
     * Create one row per selected weekday.
     *
     * @param array<string, mixed> $data Validated form input.
     * @param array<string, mixed> $actor The signed-in user row.
     * @return array<int, int> Inserted schedule ids, in day order.
     */
    public function create(Scope $scope, array $data, array $actor): array
    {
        $resolved = $this->resolve($scope, $data);
        $status = in_array((string) ($data['status'] ?? 'draft'), ['draft', 'pending'], true)
            ? (string) $data['status']
            : 'draft';

        // Phase 11: evaluate every overlap rule *before* a row is written, so
        // a half-open clash is a 409 with the specific conflicts and the
        // alternative rooms the caller can offer the teacher. The repository
        // supplies the term's active meetings (migration 006 covers this);
        // nothing else has to change in the engine.
        $existing = $this->schedules->activeInSemester((int) $resolved['semester']['id']);
        $result = $this->conflict->check($resolved, $existing);

        if ($result['status'] === 'conflict') {
            throw HttpException::unprocessable(
                ['conflicts' => $result['errors']],
                'This meeting clashes with an existing one'
            );
        }

        $ids = [];

        foreach ($resolved['days'] as $day) {
            $ids[] = $this->schedules->create([
                'semester_id' => $resolved['semester']['id'],
                'department_id' => $resolved['section']['department_id'],
                'section_id' => $resolved['section']['id'],
                'subject_id' => $resolved['subject']['id'],
                'faculty_id' => $resolved['faculty']['id'],
                'room_id' => $resolved['room']['id'],
                'day_of_week' => $day,
                'start_time' => $resolved['start_time'],
                'end_time' => $resolved['end_time'],
                'meeting_date' => $resolved['meeting_date'],
                'recurrence' => $resolved['recurrence'],
                'status' => $status,
                'notes' => $resolved['notes'],
                'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
            ]);
        }

        return $ids;
    }

    /**
     * Update one meeting.
     *
     * Approved or published entries may only be changed by an approver, and the
     * edit reopens them as `pending` so the change is reviewed again rather
     * than silently mutating a published timetable.
     *
     * @param array<string, mixed> $schedule Row loaded through findInScope().
     * @param array<string, mixed> $data     Validated form input.
     * @param array<string, mixed> $actor
     */
    public function update(Scope $scope, array $schedule, array $data, array $actor): void
    {
        $status = (string) $schedule['status'];
        $reopen = false;

        if (!in_array($status, self::EDITABLE, true)) {
            $this->authorization->authorize(
                $actor,
                'schedules.approve',
                'This entry is ' . $status . '; only an approver can change it.'
            );

            $reopen = true;
        }

        $resolved = $this->resolve($scope, $data);

        // Phase 11: re-check the term against the *new* facts. An edit can
        // create a clash with the rows it is now moving past (the draft it is
        // replacing, the room it is now sharing) even though the old row was
        // clean, so the check runs with the resolved facts and the active
        // rows in the term, same as create().
        $existing = array_values(array_filter(
            $this->schedules->activeInSemester((int) $resolved['semester']['id']),
            // The row being edited is not its own conflict. Without this every
            // edit that kept or extended the old window clashed with the row
            // it was replacing (a 14:00–15:00 meeting could not become
            // 14:00–16:00 because it overlapped itself).
            static fn (array $meeting): bool => (int) $meeting['id'] !== (int) $schedule['id']
        ));
        $result = $this->conflict->check($resolved, $existing);

        if ($result['status'] === 'conflict') {
            throw HttpException::unprocessable(
                ['conflicts' => $result['errors']],
                'This meeting clashes with an existing one'
            );
        }

        $this->schedules->update((int) $schedule['id'], [
            'semester_id' => $resolved['semester']['id'],
            'department_id' => $resolved['section']['department_id'],
            'section_id' => $resolved['section']['id'],
            'subject_id' => $resolved['subject']['id'],
            'faculty_id' => $resolved['faculty']['id'],
            'room_id' => $resolved['room']['id'],
            'day_of_week' => $resolved['days'][0],
            'start_time' => $resolved['start_time'],
            'end_time' => $resolved['end_time'],
            'meeting_date' => $resolved['meeting_date'],
            'recurrence' => $resolved['recurrence'],
            'notes' => $resolved['notes'],
            'actor_id' => (int) ($actor['id'] ?? 0) ?: null,
        ]);

        if ($reopen) {
            $this->schedules->applyStatus((int) $schedule['id'], 'pending', [
                'approved_by' => null,
                'approved_at' => null,
                'published_at' => null,
            ]);
        }
    }

    /**
     * Move an entry through the lifecycle.
     *
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $actor
     */
    public function transition(Scope $scope, array $schedule, string $target, array $actor, ?string $reason = null): void
    {
        $current = (string) $schedule['status'];
        $target = strtolower(trim($target));

        if (!in_array($target, ScheduleRepository::STATUSES, true)) {
            throw HttpException::unprocessable(['status' => 'Unknown status.']);
        }

        if (!in_array($target, self::TRANSITIONS[$current] ?? [], true)) {
            throw HttpException::unprocessable([
                'status' => sprintf('A %s entry cannot move to %s.', $current, $target),
            ]);
        }

        // Approving or publishing is a privileged act; cancelling is a delete.
        if (in_array($target, ['approved', 'published'], true)) {
            $this->authorization->authorize($actor, 'schedules.approve', 'You cannot approve this timetable.');
        }

        if ($target === 'cancelled') {
            $this->authorization->authorize($actor, 'schedules.delete', 'You cannot cancel this entry.');
        }

        if ($target === 'pending' || $target === 'rejected' || $target === 'draft') {
            $this->authorization->authorize($actor, 'schedules.update', 'You cannot change this entry.');
        }

        $actorId = (int) ($actor['id'] ?? 0) ?: null;
        $now = date('Y-m-d H:i:s');
        $fields = ['updated_by' => $actorId];

        $fields += match ($target) {
            'approved' => ['approved_by' => $actorId, 'approved_at' => $now],
            'published' => ['published_at' => $now],
            'cancelled' => [
                'cancelled_at' => $now,
                'cancellation_reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            ],
            default => [],
        };

        $this->schedules->applyStatus((int) $schedule['id'], $target, $fields);

        // UAT-2: an approval must reach the teacher who has to deliver the
        // class. The inbox row is written after the status change commits so a
        // notification is never sent for a transition that failed.
        if ($target === 'approved') {
            $this->notifyFacultyOfApproval($schedule);
        }
    }

    /**
     * Write the faculty member's in-app notification for an approved entry.
     *
     * @param array<string, mixed> $schedule
     */
    private function notifyFacultyOfApproval(array $schedule): void
    {
        if ($this->notifications === null) {
            return;
        }

        $faculty = $this->faculty->find((int) $schedule['faculty_id']);
        $recipient = (int) ($faculty['user_id'] ?? 0);

        if ($recipient <= 0) {
            return;
        }

        $this->notifications->notify(
            $recipient,
            'schedule.approved',
            'Timetable approved',
            sprintf(
                '%s \u00b7 %s for %s was approved.',
                (string) ($schedule['subject_code'] ?? 'Class'),
                (string) ($schedule['section_code'] ?? 'section'),
                (string) ($schedule['semester_code'] ?? 'the term')
            ),
            'in_app',
            null
        );
    }

    /**
     * Transitions the actor may actually perform, for the detail page buttons.
     *
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $actor
     * @return array<int, string>
     */
    public function allowedTransitions(array $schedule, array $actor): array
    {
        $allowed = [];

        foreach (self::TRANSITIONS[(string) $schedule['status']] ?? [] as $target) {
            $permission = match (true) {
                in_array($target, ['approved', 'published'], true) => 'schedules.approve',
                $target === 'cancelled' => 'schedules.delete',
                default => 'schedules.update',
            };

            if ($this->authorization->can($actor, $permission)) {
                $allowed[] = $target;
            }
        }

        return $allowed;
    }

    /**
     * Validate every relation and policy rule, returning the normalised facts.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     semester: array<string, mixed>,
     *     section: array<string, mixed>,
     *     subject: array<string, mixed>,
     *     faculty: array<string, mixed>,
     *     room: array<string, mixed>,
     *     days: array<int, int>,
     *     start_time: string,
     *     end_time: string,
     *     meeting_date: string|null,
     *     recurrence: string,
     *     notes: string|null
     * }
     */
    /**
     * Resolve one form payload into the facts the write path and the conflict
     * engine both use (existing relations, normalised times, weekdays).
     *
     * Public because the conflict-preview endpoint evaluates a candidate with
     * the same resolver as create()/update(): one definition of "what this form
     * means", so the preview can never disagree with the save.
     */
    public function resolve(Scope $scope, array $data): array
    {
        $semester = $this->terms->findSemester((int) ($data['semester_id'] ?? 0));

        if ($semester === null) {
            throw HttpException::unprocessable(['semester_id' => 'Choose an existing semester.']);
        }

        if ((string) $semester['status'] === 'closed') {
            throw HttpException::unprocessable(['semester_id' => 'That semester is closed; schedules cannot be added to it.']);
        }

        $section = $this->sections->find((int) ($data['section_id'] ?? 0));

        if ($section === null || (int) $section['is_active'] !== 1) {
            throw HttpException::unprocessable(['section_id' => 'Choose an active section.']);
        }

        if (!$scope->coversDepartment((int) $section['department_id'])) {
            throw HttpException::forbidden('You can only schedule classes in your own department.');
        }

        $subject = $this->subjects->find((int) ($data['subject_id'] ?? 0));

        if ($subject === null || (int) $subject['is_active'] !== 1) {
            throw HttpException::unprocessable(['subject_id' => 'Choose an active subject.']);
        }

        if ((int) $subject['department_id'] !== (int) $section['department_id']) {
            throw HttpException::unprocessable([
                'subject_id' => 'That subject belongs to another department than the section.',
            ]);
        }

        $faculty = $this->faculty->find((int) ($data['faculty_id'] ?? 0));

        if ($faculty === null || (int) $faculty['is_active'] !== 1) {
            throw HttpException::unprocessable(['faculty_id' => 'Choose an active faculty profile.']);
        }

        if ((int) $faculty['department_id'] !== (int) $section['department_id']) {
            throw HttpException::unprocessable([
                'faculty_id' => 'That teacher belongs to another department than the section.',
            ]);
        }

        $room = $this->rooms->find((int) ($data['room_id'] ?? 0));

        if ($room === null || (int) $room['is_active'] !== 1) {
            throw HttpException::unprocessable(['room_id' => 'Choose an active room.']);
        }

        [$start, $end] = $this->validateTimes(
            (string) ($data['start_time'] ?? ''),
            (string) ($data['end_time'] ?? '')
        );

        $recurrence = in_array((string) ($data['recurrence'] ?? 'weekly'), ScheduleRepository::RECURRENCES, true)
            ? (string) $data['recurrence']
            : 'weekly';

        [$days, $meetingDate] = $this->resolveDays($recurrence, $data);

        return [
            'semester' => $semester,
            'section' => $section,
            'subject' => $subject,
            'faculty' => $faculty,
            'room' => $room,
            'days' => $days,
            'start_time' => $start,
            'end_time' => $end,
            'meeting_date' => $meetingDate,
            'recurrence' => $recurrence,
            'notes' => $this->notes($data['notes'] ?? null),

            // ConflictService reasons about a candidate the same way it reasons
            // about a stored meeting row: it labels the room, teacher, section
            // and subject from flat columns. The resolved relations above stay
            // available for the rules that need whole records (semester status,
            // section department), so both shapes are published here instead of
            // making the service guess which one it was handed.
            'subject_code' => (string) $subject['code'],
            'subject_title' => (string) $subject['title'],
            'section_code' => (string) $section['code'],
            'student_count' => (int) $section['student_count'],
            'room_code' => (string) $room['code'],
            'room_capacity' => (int) $room['capacity'],
            'room_type' => (string) $room['room_type'],
            'faculty_first_name' => (string) $faculty['first_name'],
            'faculty_last_name' => (string) $faculty['last_name'],
            'department_id' => (int) $section['department_id'],
        ];
    }

    /**
     * Time policy: ordered, long enough, inside institution hours, outside breaks.
     *
     * @return array{0: string, 1: string} `H:i:s` start and end.
     */
    private function validateTimes(string $start, string $end): array
    {
        $start = $this->normaliseTime($start);
        $end = $this->normaliseTime($end);

        $startMinutes = $this->minutes($start);
        $endMinutes = $this->minutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            throw HttpException::unprocessable(['start_time' => 'Enter valid start and end times.']);
        }

        if ($endMinutes <= $startMinutes) {
            throw HttpException::unprocessable(['end_time' => 'The end time must be after the start time.']);
        }

        $duration = $endMinutes - $startMinutes;
        $minDuration = (int) $this->config->get('schedule.min_duration_minutes', 30);
        $maxDuration = (int) $this->config->get('schedule.max_duration_minutes', 240);

        if ($duration < $minDuration) {
            throw HttpException::unprocessable(['end_time' => sprintf('A class must last at least %d minutes.', $minDuration)]);
        }

        if ($duration > $maxDuration) {
            throw HttpException::unprocessable(['end_time' => sprintf('A class cannot last longer than %d minutes.', $maxDuration)]);
        }

        $allowedStart = $this->minutes($this->normaliseTime((string) $this->config->get('schedule.allowed_hours.start', '07:00')));
        $allowedEnd = $this->minutes($this->normaliseTime((string) $this->config->get('schedule.allowed_hours.end', '19:00')));

        if ($allowedStart !== null && $startMinutes < $allowedStart) {
            throw HttpException::unprocessable(['start_time' => sprintf(
                'Classes may not start before %s.',
                substr((string) $this->config->get('schedule.allowed_hours.start', '07:00'), 0, 5)
            )]);
        }

        if ($allowedEnd !== null && $endMinutes > $allowedEnd) {
            throw HttpException::unprocessable(['end_time' => sprintf(
                'Classes must end by %s.',
                substr((string) $this->config->get('schedule.allowed_hours.end', '19:00'), 0, 5)
            )]);
        }

        foreach ((array) $this->config->get('schedule.breaks', []) as $break) {
            $breakStart = $this->minutes($this->normaliseTime((string) ($break['start'] ?? '')));
            $breakEnd = $this->minutes($this->normaliseTime((string) ($break['end'] ?? '')));

            if ($breakStart === null || $breakEnd === null) {
                continue;
            }

            if ($startMinutes < $breakEnd && $endMinutes > $breakStart) {
                throw HttpException::unprocessable(['start_time' => sprintf(
                    'That time overlaps the %s (%s–%s).',
                    (string) ($break['label'] ?? 'break'),
                    substr((string) $break['start'], 0, 5),
                    substr((string) $break['end'], 0, 5)
                )]);
            }
        }

        return [$start, $end];
    }

    /**
     * Weekly entries need one or more weekdays; a one-off derives its weekday
     * from the meeting date (SCH-04).
     *
     * @param array<string, mixed> $data
     * @return array{0: array<int, int>, 1: string|null}
     */
    private function resolveDays(string $recurrence, array $data): array
    {
        if ($recurrence === 'one_off') {
            $date = trim((string) ($data['meeting_date'] ?? ''));
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw HttpException::unprocessable(['meeting_date' => 'A one-off class needs a valid meeting date.']);
            }

            return [[(int) $parsed->format('N')], $date];
        }

        $days = array_values(array_unique(array_map(
            static fn (mixed $day): int => (int) $day,
            (array) ($data['days'] ?? [])
        )));
        $days = array_values(array_filter($days, static fn (int $day): bool => $day >= 1 && $day <= 7));
        sort($days);

        if ($days === []) {
            throw HttpException::unprocessable(['days' => 'Choose at least one weekday.']);
        }

        return [$days, null];
    }

    private function notes(mixed $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : mb_substr($notes, 0, 500);
    }

    /**
     * Accept `H:i` or `H:i:s` and return `H:i:s`.
     */
    private function normaliseTime(string $time): string
    {
        $time = trim($time);

        return preg_match('/^\d{2}:\d{2}$/', $time) === 1 ? $time . ':00' : $time;
    }

    private function minutes(string $time): ?int
    {
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $time, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    }
}
