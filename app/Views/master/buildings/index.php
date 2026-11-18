<?php

/**
 * Buildings list (MD-02, MD-08).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $buildings
 * @var array{search: string, status: string, sort: string, direction: string} $filters
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
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
]);

$pageUrl = static fn (int $page): string => $route('master.buildings.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Buildings</p>
    <h1>Buildings</h1>
    <p class="lede">
        Physical locations on campus. Rooms belong to a building, and the scheduler uses the building code
        to group and filter timetables.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>All buildings</h2>
        <?php if ($can_create): ?>
            <a class="button" href="<?= $e($route('master.buildings.create')) ?>">New building</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.buildings.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Code, name or campus">
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
        <a class="button button-ghost" href="<?= $e($route('master.buildings.index')) ?>">Reset</a>
    </form>

    <?php if ($buildings === []): ?>
        <p class="hint">No buildings match this filter.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Campus</th>
                    <th scope="col">Address</th>
                    <th scope="col">Rooms</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($buildings as $building): ?>
                    <tr>
                        <td><code><?= $e($building['code']) ?></code></td>
                        <td><?= $e($building['name']) ?></td>
                        <td><?= $e($building['campus'] ?? '—') ?></td>
                        <td class="muted"><?= $e($building['address'] ?? '—') ?></td>
                        <td><?= $e($building['room_count']) ?></td>
                        <td>
                            <?php if ((int) $building['is_active'] === 1): ?>
                                <span class="tag tag-ok">Active</span>
                            <?php else: ?>
                                <span class="tag tag-muted">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_edit): ?>
                                <a href="<?= $e($route('master.buildings.edit', ['id' => $building['id']])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($can_archive): ?>
                                <form class="inline-form" method="post" action="<?= $e($route('master.buildings.toggle', ['id' => $building['id']])) ?>">
                                    <?= $csrf_field() ?>
                                    <button type="submit" class="link-button">
                                        <?= (int) $building['is_active'] === 1 ? 'Archive' : 'Restore' ?>
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
