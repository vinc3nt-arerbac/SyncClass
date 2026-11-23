<?php

/**
 * Conflict notice and alternative suggestions (Phase 11).
 *
 * Renders only when the caller has passed `conflicts` — a hard conflict
 * caught by `ConflictService` before a write. Each conflict is one row the
 * teacher can see and reason about; each alternative is a concrete, valid
 * replacement that would have passed every rule. Nothing here writes;
 * the caller's 409 into the form is the only side effect.
 *
 * Scope isolation (BR-10): `conflicts.view` (see config/permissions.php) is
 * what decides whether this partial is shown at all. Members of the term's
 * department see only their own rows; nothing else is exposed.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var array<int, array<string, mixed>> $conflicts
 * @var array<string, array<int, array<string, mixed>>> $alternatives
 * @var bool $hasConflicts
 */

if (!isset($conflicts) || $conflicts === []) {
    return;
}

$hasConflicts = (bool) $conflicts;
?>
<div class="alert alert-error" role="alert">
    <strong><?= $e('This meeting clashes with an existing one.') ?></strong>
    <p class="hint"><?= $e('Pick a different room, time or teacher — the alternatives below are free and would pass every rule.') ?></p>
</div>

<?php if (isset($alternatives['rooms']) && $alternatives['rooms'] !== []): ?>
    <section class="card">
        <header class="card-head"><h3><?= $e('Try another room') ?></h3></header>
        <p class="hint"><?= $e('These active rooms in the same term are free for the whole class and are at least as big.') ?></p>
        <div class="list">
            <?php foreach ($alternatives['rooms'] as $room): ?>
                <a class="list-row" href="<?= $e($route('schedules.edit', ['id' => $schedule['id']])) ?>" data-room="<?= (int) $room['room_id'] ?>">
                    <strong><?= $e($room['code'] . ' · ' . $room['room_type'] . ' · ' . $room['capacity'] . ' seats') ?></strong>
                    <span class="hint"><?= $e($room['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (isset($alternatives['slots']) && $alternatives['slots'] !== []): ?>
    <section class="card">
        <header class="card-head"><h3><?= $e('Try a different time') ?></h3></header>
        <p class="hint"><?= $e('These 30-minute slots are free inside the institution window and miss the lunch break.') ?></p>
        <div class="day-picker">
            <?php foreach ($alternatives['slots'] as $slot): ?>
                <label class="field-check">
                    <input type="radio" name="slot_preview" value="<?= $e($slot['start'] . '-' . $slot['end']) ?>">
                    <span><?= $e($slot['start'] . ' – ' . $slot['end']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (isset($alternatives['faculty']) && $alternatives['faculty'] !== []): ?>
    <section class="card">
        <header class="card-head"><h3><?= $e('Try another teacher') ?></h3></header>
        <p class="hint"><?= $e('Teachers in the same department who are free for the whole class.') ?></p>
        <div class="list">
            <?php foreach ($alternatives['faculty'] as $faculty): ?>
                <a class="list-row" href="<?= $e($route('schedules.edit', ['id' => $schedule['id']])) ?>" data-faculty="<?= (int) $faculty['faculty_id'] ?>">
                    <strong><?= $e($faculty['name']) ?></strong>
                    <span class="hint"><?= $e($faculty['department_code'] . ' · ' . $faculty['subject_code']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
