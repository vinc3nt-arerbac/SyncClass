<?php

/**
 * Rooms list (MD-02, MD-08).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $rooms
 * @var array{search: string, status: string, building_id: mixed, room_type: string, sort: string, direction: string} $filters
 * @var array<int, array{id: int, code: string, name: string}> $buildings
 * @var array<int, string> $room_types
 * @var int $total
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var string|null $notice
 * @var bool $can_create
 * @var bool $can_edit
 * @var bool $can_archive
 */

$query = array_filter([
    'q' => $filters['search'],
    'status' => $filters['status'],
    'building' => $filters['building_id'],
    'type' => $filters['room_type'],
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
]);

$pageUrl = static fn (int $page): string => $route('master.rooms.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Rooms</p>
    <h1>Rooms</h1>
    <p class="lede">
        Type and capacity are scheduling facts, not decoration: the conflict engine refuses a laboratory
        subject in a lecture room and a section larger than its room.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>All rooms</h2>
        <?php if ($can_create): ?>
            <a class="button" href="<?= $e($route('master.rooms.create')) ?>">New room</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.rooms.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Room or building">
        </label>
        <label>
            <span>Building</span>
            <select name="building">
                <option value="">All buildings</option>
                <?php foreach ($buildings as $building): ?>
                    <option value="<?= $e($building['id']) ?>" <?= (string) $filters['building_id'] === (string) $building['id'] ? 'selected' : '' ?>>
                        <?= $e($building['code']) ?> · <?= $e($building['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Type</span>
            <select name="type">
                <option value="">All types</option>
                <?php foreach ($room_types as $type): ?>
                    <option value="<?= $e($type) ?>" <?= $filters['room_type'] === $type ? 'selected' : '' ?>>
                        <?= $e(ucfirst($type)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Status</span>
            <select name="status">
                <option value="">All</option>
                <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Archived</option>
            </select>
        </label>
        <button type="submit" class="button">Filter</button>
        <a class="button button-ghost" href="<?= $e($route('master.rooms.index')) ?>">Reset</a>
    </form>

    <?php if ($rooms === []): ?>
        <p class="hint">No rooms match this filter.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Building</th>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Floor</th>
                    <th scope="col">Type</th>
                    <th scope="col">Capacity</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td><code><?= $e($room['building_code']) ?></code></td>
                        <td><code><?= $e($room['code']) ?></code></td>
                        <td><?= $e($room['name'] ?? '—') ?></td>
                        <td><?= $e($room['floor'] ?? '—') ?></td>
                        <td><span class="tag"><?= $e(ucfirst($room['room_type'])) ?></span></td>
                        <td><?= $e($room['capacity']) ?></td>
                        <td>
                            <?php if ((int) $room['is_active'] === 1): ?>
                                <span class="tag tag-ok">Active</span>
                            <?php else: ?>
                                <span class="tag tag-muted">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_edit): ?>
                                <a href="<?= $e($route('master.rooms.edit', ['id' => $room['id']])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($can_archive): ?>
                                <form class="inline-form" method="post" action="<?= $e($route('master.rooms.toggle', ['id' => $room['id']])) ?>">
                                    <?= $csrf_field() ?>
                                    <button type="submit" class="link-button">
                                        <?= (int) $room['is_active'] === 1 ? 'Archive' : 'Restore' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php require __DIR__ . '/../_pager.php'; ?>
    <?php endif; ?>
</section>
