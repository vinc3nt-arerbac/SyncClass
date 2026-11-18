<?php

/**
 * Section create/edit form (MD-04).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $section
 * @var array<int, array{id: int, code: string, name: string}> $departments
 * @var bool $can_edit
 */

$editing = $section !== null;
$action = $editing
    ? $route('master.sections.update', ['id' => $section['id']])
    : $route('master.sections.store');

$value = static fn (string $field, string $default = ''): string => (string) ($section[$field] ?? $default);
$disabled = $can_edit ? '' : 'disabled';
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Sections</p>
    <h1><?= $e($editing ? 'Edit ' . $section['code'] : 'New section') ?></h1>
    <p class="lede">A section code is unique across the institution, so it can be used in exports without a department prefix.</p>
</section>

<section class="card">
    <header class="card-head"><h2>Section details</h2></header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Code</span>
                <input name="code" value="<?= $e($value('code')) ?>" maxlength="30" required placeholder="BSIT-2A" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Name</span>
                <input name="name" value="<?= $e($value('name')) ?>" maxlength="120" placeholder="BSIT second year, block A" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Program</span>
                <input name="program" value="<?= $e($value('program')) ?>" maxlength="120" placeholder="BS Information Technology" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Year level</span>
                <select name="year_level" required <?= $disabled ?>>
                    <?php foreach (range(1, 6) as $year): ?>
                        <option value="<?= $e($year) ?>" <?= $value('year_level', '1') === (string) $year ? 'selected' : '' ?>>
                            Year <?= $e($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
                <span>Student count</span>
                <input type="number" name="student_count" value="<?= $e($value('student_count', '40')) ?>" min="1" max="2000" required <?= $disabled ?>>
            </label>
        </div>

        <div class="form-actions">
            <?php if ($can_edit): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create section' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.sections.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
