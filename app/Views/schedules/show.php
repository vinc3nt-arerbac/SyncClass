<?php

/**
 * Schedule entry detail and lifecycle actions (SCH-05).
 *
 * Only transitions the service would accept are rendered; the buttons are a
 * convenience, and the POST is checked again on the server.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed> $schedule
 * @var array<int, string> $days
 * @var array<int, string> $transitions
 * @var bool $can_edit
 * @var string|null $notice
 */

$time = static fn (string $value): string => substr($value, 0, 5);
$labels = [
    'pending' => 'Submit for approval',
    'approved' => 'Approve',
    'published' => 'Publish',
    'rejected' => 'Reject',
    'draft' => 'Return to draft',
    'cancelled' => 'Cancel class',
];
?>
<?php require __DIR__ . '/../master/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Scheduling · <?= $e($schedule['semester_code']) ?></p>
    <h1><?= $e($schedule['subject_code']) ?> · <?= $e($schedule['section_code']) ?></h1>
    <p class="lede"><?= $e($schedule['subject_title']) ?></p>
    <ul class="badges">
        <li><span class="tag status-<?= $e($schedule['status']) ?>"><?= $e(ucfirst($schedule['status'])) ?></span></li>
        <li><?= $e($days[(int) $schedule['day_of_week']] ?? 'Day ' . $schedule['day_of_week']) ?></li>
        <li><?= $e($time((string) $schedule['start_time'])) ?>–<?= $e($time((string) $schedule['end_time'])) ?></li>
        <li><?= $e($schedule['building_code'] . ' ' . $schedule['room_code']) ?></li>
        <li><?= $e($schedule['department_code']) ?></li>
    </ul>
</section>

<?php require __DIR__ . '/../master/_notice.php'; ?>

<div class="grid">
    <section class="card">
        <header class="card-head"><h2>Meeting</h2></header>
        <dl class="facts">
            <div><dt>Teacher</dt><dd><?= $e($schedule['faculty_first_name'] . ' ' . $schedule['faculty_last_name']) ?></dd></div>
            <div><dt>Section</dt><dd><?= $e($schedule['section_code']) ?> · <?= $e($schedule['student_count']) ?> students</dd></div>
            <div><dt>Room</dt><dd><?= $e($schedule['building_code'] . ' ' . $schedule['room_code']) ?> · <?= $e(ucfirst($schedule['room_type'])) ?> · <?= $e($schedule['room_capacity']) ?> seats</dd></div>
            <div><dt>Recurrence</dt><dd><?= $e($schedule['recurrence'] === 'weekly' ? 'Weekly' : 'One-off') ?><?= $schedule['meeting_date'] !== null ? ' · ' . $e($schedule['meeting_date']) : '' ?></dd></div>
            <div><dt>Semester</dt><dd><?= $e($schedule['semester_code']) ?> · <?= $e($schedule['semester_name']) ?></dd></div>
            <div><dt>Notes</dt><dd><?= $e($schedule['notes'] ?? '—') ?></dd></div>
            <?php if ($schedule['approved_at'] !== null): ?>
                <div><dt>Approved</dt><dd><?= $e($schedule['approved_at']) ?></dd></div>
            <?php endif; ?>
            <?php if ($schedule['published_at'] !== null): ?>
                <div><dt>Published</dt><dd><?= $e($schedule['published_at']) ?></dd></div>
            <?php endif; ?>
            <?php if ($schedule['cancelled_at'] !== null): ?>
                <div><dt>Cancelled</dt><dd><?= $e($schedule['cancelled_at']) ?><?= $schedule['cancellation_reason'] !== null ? ' · ' . $e($schedule['cancellation_reason']) : '' ?></dd></div>
            <?php endif; ?>
        </dl>

        <div class="form-actions" style="margin-top: 1rem">
            <?php if ($can_edit): ?>
                <a class="button" href="<?= $e($route('schedules.edit', ['id' => $schedule['id']])) ?>">Edit meeting</a>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('schedules.index')) ?>">Back to schedules</a>
        </div>
    </section>

    <section class="card">
        <header class="card-head">
            <h2>Lifecycle</h2>
            <span class="tag"><?= $e(count($transitions)) ?> action(s)</span>
        </header>

        <?php if ($transitions === []): ?>
            <p class="hint">
                No further transitions are available to you. A cancelled entry is final; approving needs
                <code>schedules.approve</code>.
            </p>
        <?php else: ?>
            <div class="status-actions">
                <?php foreach ($transitions as $target): ?>
                    <form method="post" action="<?= $e($route('schedules.status', ['id' => $schedule['id']])) ?>" class="status-form">
                        <?= $csrf_field() ?>
                        <input type="hidden" name="status" value="<?= $e($target) ?>">
                        <?php if ($target === 'cancelled'): ?>
                            <label class="field">
                                <span>Reason (optional)</span>
                                <input name="reason" maxlength="255" placeholder="Room unavailable">
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="button <?= $target === 'cancelled' || $target === 'rejected' ? 'button-ghost' : '' ?>">
                            <?= $e($labels[$target] ?? ucfirst($target)) ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="hint">
            draft → pending → approved → published. A <code>rejected</code> entry can go back to draft; an
            approved or published entry keeps its history, and editing it reopens it as pending.
        </p>
    </section>
</div>
