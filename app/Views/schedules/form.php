<?php

/**
 * Add-class / edit-meeting form (SCH-01…SCH-04).
 *
 * Weekly classes use the weekday checkboxes and produce one row per day; a
 * one-off meeting uses the date and derives its weekday server-side. The
 * department is not a field: it comes from the section, so it cannot be
 * spoofed.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $schedule
 * @var array<int, array{id: int, code: string, name: string, status: string, year_code: string}> $semesters
 * @var array<int, array{id: int, code: string, name: string}> $sections
 * @var array<int, array{id: int, code: string, title: string}> $subjects
 * @var array<int, array{id: int, name: string, department_id: int}> $faculty
 * @var array<int, array{id: int, label: string}> $rooms
 * @var array<int, string> $days
 * @var array<int, string> $recurrences
 * @var int|null $default_semester
 * @var bool $can_edit
 */

$editing = $schedule !== null;
$action = $editing ? $route('schedules.update', ['id' => $schedule['id']]) : $route('schedules.store');
$value = static fn (string $field, string $default = ''): string => (string) ($schedule[$field] ?? $default);
$selectedDays = $editing ? [(int) $schedule['day_of_week']] : [];
$shortTime = static fn (string $time): string => substr($time, 0, 5);
$disabled = $can_edit ? '' : 'disabled';
?>
<?php require __DIR__ . '/../master/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Scheduling · <?= $e($editing ? 'Edit meeting' : 'Add class') ?></p>
    <h1><?= $e($editing ? $schedule['subject_code'] . ' · ' . $schedule['section_code'] : 'Add class') ?></h1>
    <p class="lede">
        Pick the section first: the department, the subject list and the teacher list must belong to it.
        Saving a weekly class creates one row per selected weekday.
    </p>
</section>

<section class="card">
    <header class="card-head">
        <h2>Meeting details</h2>
        <?php if ($editing): ?>
            <span class="tag status-<?= $e($schedule['status']) ?>"><?= $e(ucfirst($schedule['status'])) ?></span>
        <?php endif; ?>
    </header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Semester</span>
                <select name="semester_id" required <?= $disabled ?>>
                    <?php foreach ($semesters as $semester): ?>
                        <option value="<?= $e($semester['id']) ?>"
                            <?= (string) $value('semester_id', (string) $default_semester) === (string) $semester['id'] ? 'selected' : '' ?>>
                            <?= $e($semester['year_code'] . ' · ' . $semester['name'] . ' (' . $semester['status'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Section</span>
                <select name="section_id" required <?= $disabled ?>>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= $e($section['id']) ?>" <?= (string) $value('section_id') === (string) $section['id'] ? 'selected' : '' ?>>
                            <?= $e($section['code'] . ' · ' . $section['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Subject</span>
                <select name="subject_id" required <?= $disabled ?>>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= $e($subject['id']) ?>" <?= (string) $value('subject_id') === (string) $subject['id'] ? 'selected' : '' ?>>
                            <?= $e($subject['code'] . ' · ' . $subject['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Teacher</span>
                <select name="faculty_id" required <?= $disabled ?>>
                    <?php foreach ($faculty as $profile): ?>
                        <option value="<?= $e($profile['id']) ?>" <?= (string) $value('faculty_id') === (string) $profile['id'] ? 'selected' : '' ?>>
                            <?= $e($profile['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Room</span>
                <select name="room_id" required <?= $disabled ?>>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?= $e($room['id']) ?>" <?= (string) $value('room_id') === (string) $room['id'] ? 'selected' : '' ?>>
                            <?= $e($room['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Recurrence</span>
                <select name="recurrence" required <?= $disabled ?>>
                    <?php foreach ($recurrences as $recurrence): ?>
                        <option value="<?= $e($recurrence) ?>" <?= $value('recurrence', 'weekly') === $recurrence ? 'selected' : '' ?>>
                            <?= $e($recurrence === 'weekly' ? 'Weekly (repeat)' : 'One-off meeting') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Start time</span>
                <input type="time" name="start_time" value="<?= $e($editing ? $shortTime((string) $schedule['start_time']) : '08:00') ?>" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>End time</span>
                <input type="time" name="end_time" value="<?= $e($editing ? $shortTime((string) $schedule['end_time']) : '09:30') ?>" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>One-off date (only for one-off meetings)</span>
                <input type="date" name="meeting_date" value="<?= $e($value('meeting_date')) ?>" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Initial status</span>
                <select name="status" <?= $editing ? 'disabled' : '' ?>>
                    <option value="draft" <?= $value('status', 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="pending" <?= $value('status', 'draft') === 'pending' ? 'selected' : '' ?>>Submit for approval</option>
                </select>
            </label>
        </div>

        <fieldset class="fieldset">
            <legend>Weekdays (weekly classes)</legend>
            <div class="day-picker">
                <?php foreach ($days as $number => $label): ?>
                    <label class="field-check">
                        <input type="checkbox" name="days[]" value="<?= $e($number) ?>"
                            <?= in_array((int) $number, $selectedDays, true) ? 'checked' : '' ?> <?= $disabled ?>>
                        <span><?= $e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($editing): ?>
                <p class="hint">Editing this row changes this one meeting; other meetings of the same class are separate rows.</p>
            <?php endif; ?>
        </fieldset>

        <label class="field" style="margin-top: 1rem">
            <span>Notes</span>
            <textarea name="notes" maxlength="500" <?= $disabled ?>><?= $e($value('notes')) ?></textarea>
        </label>

        <div class="form-actions">
            <?php if ($can_edit): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create meeting(s)' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('schedules.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
