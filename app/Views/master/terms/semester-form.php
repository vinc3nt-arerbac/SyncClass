<?php

/**
 * Semester create/edit form (MD-06).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $semester
 * @var array<int, array{id: int, code: string}> $years
 * @var array<int, string> $statuses
 * @var bool $can_manage
 */

$editing = $semester !== null;
$action = $editing ? $route('master.semesters.update', ['id' => $semester['id']]) : $route('master.semesters.store');
$value = static fn (string $field, string $default = ''): string => (string) ($semester[$field] ?? $default);
$disabled = $can_manage ? '' : 'disabled';
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Semesters</p>
    <h1><?= $e($editing ? 'Edit ' . $semester['code'] : 'New semester') ?></h1>
    <p class="lede">
        A semester code is unique across the institution. Term numbers 1, 2 and 3 are unique within their
        academic year.
    </p>
</section>

<section class="card">
    <header class="card-head"><h2>Semester details</h2></header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Academic year</span>
                <select name="academic_year_id" required <?= $disabled ?>>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= $e($year['id']) ?>" <?= (string) $value('academic_year_id') === (string) $year['id'] ? 'selected' : '' ?>>
                            <?= $e($year['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Term number</span>
                <select name="term_no" required <?= $disabled ?>>
                    <?php foreach ([1, 2, 3] as $term): ?>
                        <option value="<?= $e($term) ?>" <?= $value('term_no', '1') === (string) $term ? 'selected' : '' ?>>
                            <?= $e($term) ?><?= $term === 3 ? ' (summer)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Name</span>
                <input name="name" value="<?= $e($value('name')) ?>" maxlength="40" required placeholder="First Semester" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Code</span>
                <input name="code" value="<?= $e($value('code')) ?>" maxlength="30" required placeholder="2026-2027-1ST" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Start date</span>
                <input type="date" name="start_date" value="<?= $e($value('start_date')) ?>" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>End date</span>
                <input type="date" name="end_date" value="<?= $e($value('end_date')) ?>" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Status</span>
                <select name="status" required <?= $disabled ?>>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= $e($status) ?>" <?= $value('status', 'planned') === $status ? 'selected' : '' ?>>
                            <?= $e(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field-check">
                <input type="checkbox" name="is_current" value="1" <?= $editing && (int) $semester['is_current'] === 1 ? 'checked' : '' ?> <?= $disabled ?>>
                <span>Make this the current semester of its year</span>
            </label>
        </div>

        <div class="form-actions">
            <?php if ($can_manage): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create semester' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.semesters.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
