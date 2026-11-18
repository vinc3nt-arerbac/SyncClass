<?php

/**
 * Academic year create/edit form (MD-06).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $year
 * @var bool $can_manage
 */

$editing = $year !== null;
$action = $editing ? $route('master.years.update', ['id' => $year['id']]) : $route('master.years.store');
$value = static fn (string $field, string $default = ''): string => (string) ($year[$field] ?? $default);
$current = $editing && (int) $year['is_current'] === 1;
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Academic years</p>
    <h1><?= $e($editing ? 'Edit ' . $year['code'] : 'New academic year') ?></h1>
    <p class="lede">Use the <code>YYYY-YYYY</code> form, for example <code>2026-2027</code>.</p>
</section>

<section class="card">
    <header class="card-head"><h2>Year details</h2></header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Code</span>
                <input name="code" value="<?= $e($value('code')) ?>" maxlength="20" required placeholder="2026-2027" <?= $can_manage ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Start date</span>
                <input type="date" name="start_date" value="<?= $e($value('start_date')) ?>" required <?= $can_manage ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>End date</span>
                <input type="date" name="end_date" value="<?= $e($value('end_date')) ?>" required <?= $can_manage ? '' : 'disabled' ?>>
            </label>
            <label class="field-check">
                <input type="checkbox" name="is_current" value="1" <?= $current ? 'checked' : '' ?> <?= $can_manage ? '' : 'disabled' ?>>
                <span>Make this the current academic year</span>
            </label>
        </div>

        <div class="form-actions">
            <?php if ($can_manage): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create year' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.years.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
