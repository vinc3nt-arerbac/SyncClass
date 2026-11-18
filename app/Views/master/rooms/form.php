<?php

/**
 * Room create/edit form (MD-02).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $room
 * @var array<int, array{id: int, code: string, name: string}> $buildings
 * @var array<int, string> $room_types
 * @var bool $can_edit
 */

$editing = $room !== null;
$action = $editing
    ? $route('master.rooms.update', ['id' => $room['id']])
    : $route('master.rooms.store');

$value = static fn (string $field, string $default = ''): string => (string) ($room[$field] ?? $default);
$disabled = $can_edit ? '' : 'disabled';
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Rooms</p>
    <h1><?= $e($editing ? 'Edit ' . $room['building_code'] . ' ' . $room['code'] : 'New room') ?></h1>
    <p class="lede">Room codes are unique inside a building, so <code>MB 204</code> and <code>AB 204</code> can coexist.</p>
</section>

<section class="card">
    <header class="card-head"><h2>Room details</h2></header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <label class="field">
                <span>Building</span>
                <select name="building_id" required <?= $disabled ?>>
                    <?php foreach ($buildings as $building): ?>
                        <option value="<?= $e($building['id']) ?>" <?= (string) $value('building_id') === (string) $building['id'] ? 'selected' : '' ?>>
                            <?= $e($building['code']) ?> · <?= $e($building['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Code</span>
                <input name="code" value="<?= $e($value('code')) ?>" maxlength="20" required placeholder="204" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Name</span>
                <input name="name" value="<?= $e($value('name')) ?>" maxlength="120" placeholder="Computer Laboratory 2" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Floor</span>
                <input type="number" name="floor" value="<?= $e($value('floor')) ?>" min="-3" max="60" placeholder="2" <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Type</span>
                <select name="room_type" required <?= $disabled ?>>
                    <?php foreach ($room_types as $type): ?>
                        <option value="<?= $e($type) ?>" <?= $value('room_type', 'lecture') === $type ? 'selected' : '' ?>>
                            <?= $e(ucfirst($type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Capacity</span>
                <input type="number" name="capacity" value="<?= $e($value('capacity', '30')) ?>" min="1" max="1000" required <?= $disabled ?>>
            </label>
            <label class="field">
                <span>Facilities</span>
                <input name="facilities" value="<?= $e($value('facilities')) ?>" maxlength="500"
                       placeholder="Projector, air-conditioning, 40 workstations" <?= $disabled ?>>
            </label>
        </div>

        <div class="form-actions">
            <?php if ($can_edit): ?>
                <button type="submit" class="button"><?= $editing ? 'Save changes' : 'Create room' ?></button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.rooms.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
