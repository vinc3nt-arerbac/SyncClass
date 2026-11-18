<?php

/**
 * Department create/edit form (MD-01).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $department
 * @var App\Services\Scope $scope
 * @var bool $can_edit
 * @var array{users: int, subjects: int, sections: int, faculty: int}|null $dependencies
 */

$editing = $department !== null;
$action = $editing
    ? $route('master.departments.update', ['id' => $department['id']])
    : $route('master.departments.store');

$value = static fn (string $field, string $default = ''): string => (string) ($department[$field] ?? $default);
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Departments</p>
    <h1><?= $e($editing ? 'Edit ' . $department['code'] : 'New department') ?></h1>
    <p class="lede">
        The code is short, upper-case and unique (<code>CAS</code>, <code>CBA</code>). The colour is used by
        the calendar and reports to keep a department recognisable at a glance.
    </p>
</section>

<?php if ($editing && $dependencies !== null): ?>
    <section class="card">
        <header class="card-head"><h2>What depends on this department</h2></header>
        <ul class="badges">
            <li><?= $e($dependencies['users']) ?> users</li>
            <li><?= $e($dependencies['subjects']) ?> subjects</li>
            <li><?= $e($dependencies['sections']) ?> sections</li>
            <li><?= $e($dependencies['faculty']) ?> faculty profiles</li>
        </ul>
        <p class="hint">
            Because of these references a department is archived rather than deleted. Archiving keeps history
            intact and can be undone from the list.
        </p>
    </section>
<?php endif; ?>

<section class="card">
    <header class="card-head">
        <h2>Department details</h2>
        <?php if (!$can_edit): ?>
            <span class="tag">Read-only</span>
        <?php endif; ?>
    </header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Code</span>
                <input name="code" value="<?= $e($value('code')) ?>" maxlength="20" required
                       placeholder="CAS" <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Name</span>
                <input name="name" value="<?= $e($value('name')) ?>" maxlength="150" required
                       placeholder="College of Arts and Sciences" <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Short name</span>
                <input name="short_name" value="<?= $e($value('short_name')) ?>" maxlength="50"
                       placeholder="Arts &amp; Sciences" <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="<?= $e($value('email')) ?>" maxlength="150"
                       placeholder="cas@sac.edu.ph" <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Telephone</span>
                <input name="phone" value="<?= $e($value('phone')) ?>" maxlength="30"
                       placeholder="(02) 8123-4567" <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Calendar colour</span>
                <input type="color" name="color_hex" value="<?= $e($value('color_hex', '#8C1D40')) ?>"
                       <?= $can_edit ? '' : 'disabled' ?>>
            </label>
        </div>

        <div class="form-actions">
            <?php if ($can_edit): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create department' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.departments.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
