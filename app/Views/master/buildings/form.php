<?php

/**
 * Building create/edit form (MD-02).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $building
 * @var bool $can_edit
 */

$editing = $building !== null;
$action = $editing
    ? $route('master.buildings.update', ['id' => $building['id']])
    : $route('master.buildings.store');
$value = static fn (string $field, string $default = ''): string => (string) ($building[$field] ?? $default);
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Buildings</p>
    <h1><?= $e($editing ? 'Edit ' . $building['code'] : 'New building') ?></h1>
    <p class="lede">Codes are unique across the campus and appear in every room label.</p>
</section>

<section class="card">
    <header class="card-head">
        <h2>Building details</h2>
        <?php if (!$can_edit): ?><span class="tag">Read-only</span><?php endif; ?>
    </header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Code</span>
                <input name="code" value="<?= $e($value('code')) ?>" maxlength="20" required placeholder="MB"
                       <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Name</span>
                <input name="name" value="<?= $e($value('name')) ?>" maxlength="120" required placeholder="Main Building"
                       <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Campus</span>
                <input name="campus" value="<?= $e($value('campus')) ?>" maxlength="120" placeholder="Sta. Ana Campus"
                       <?= $can_edit ? '' : 'disabled' ?>>
            </label>
            <label class="field">
                <span>Address</span>
                <input name="address" value="<?= $e($value('address')) ?>" maxlength="255" placeholder="123 Mabini Street"
                       <?= $can_edit ? '' : 'disabled' ?>>
            </label>
        </div>

        <div class="form-actions">
            <?php if ($can_edit): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create building' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.buildings.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
