<?php

/**
 * Subject create/edit form (MD-03).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $subject
 * @var array<int, array{id: int, code: string, name: string}> $departments
 * @var array<int, string> $subject_types
 * @var array<int, string> $room_types
 * @var bool $can_edit
 */

$editing = $subject !== null;
$action = $editing
    ? $route('master.subjects.update', ['id' => $subject['id']])
    : $route('master.subjects.store');

$value = static fn (string $field, string $default = ''): string => (string) ($subject[$field] ?? $default);
$disabled = $can_edit ? '' : 'disabled';
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Subjects</p>
    <h1><?= $e($editing ? 'Edit ' . $subject['code'] : 'New subject') ?></h1>
    <p class="lede">
        Contact hours are the sum of lecture and laboratory hours and are calculated by the database — they
        are shown here for convenience but never posted.
    </p>
</section>

<section class="card">
    <header class="card-head"><h2>Subject details</h2></header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Code</span>
                <input name="code" value="<?= $e($value('code')) ?>" maxlength="30" required placeholder="IT-201" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Title</span>
                <input name="title" value="<?= $e($value('title')) ?>" maxlength="180" required
                       placeholder="Data Structures and Algorithms" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Department</span>
                <select name="department_id" required <?= $disabled ?>>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= $e($department['id']) ?>" <?= (string) $value('department_id') === (string) $department['id'] ? 'selected' : '' ?>>
                            <?= $e($department['code']) ?> · <?= $e($department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Units</span>
                <input type="number" step="0.5" name="units" value="<?= $e($value('units', '3')) ?>" min="0" max="12" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Lecture hours / week</span>
                <input type="number" step="0.5" name="lecture_hours" value="<?= $e($value('lecture_hours', '3')) ?>" min="0" max="12" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Laboratory hours / week</span>
                <input type="number" step="0.5" name="lab_hours" value="<?= $e($value('lab_hours', '0')) ?>" min="0" max="12" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Subject type</span>
                <select name="subject_type" required <?= $disabled ?>>
                    <?php foreach ($subject_types as $type): ?>
                        <option value="<?= $e($type) ?>" <?= $value('subject_type', 'lecture') === $type ? 'selected' : '' ?>>
                            <?= $e(ucfirst($type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Preferred room type</span>
                <select name="preferred_room_type" <?= $disabled ?>>
                    <option value="">No preference</option>
                    <?php foreach ($room_types as $type): ?>
                        <option value="<?= $e($type) ?>" <?= $value('preferred_room_type') === $type ? 'selected' : '' ?>>
                            <?= $e(ucfirst($type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1">
                <span>Description</span>
                <textarea name="description" maxlength="2000" <?= $disabled ?>><?= $e($value('description')) ?></textarea>
            </label>
        </div>

        <?php if ($editing): ?>
            <p class="hint">Contact hours (generated): <code><?= $e($subject['contact_hours']) ?></code></p>
        <?php endif; ?>

        <div class="form-actions">
            <?php if ($can_edit): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create subject' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.subjects.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
