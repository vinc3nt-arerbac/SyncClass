<?php

/**
 * Departments list (MD-01, MD-08).
 *
 * The list is already filtered by the caller's scope in SQL; this template
 * only renders what it was given. Column headers that support sorting link to
 * the same page with `sort`/`direction`, which the repository validates against
 * a whitelist.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $departments
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

$pageUrl = static fn (int $page): string => $route('master.departments.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));

$sortUrl = static function (string $key) use ($filters, $query, $route): string {
    $direction = $filters['sort'] === $key && strtolower($filters['direction']) === 'asc' ? 'desc' : 'asc';

    return $route('master.departments.index') . '?' . http_build_query(array_merge($query, ['sort' => $key, 'direction' => $direction]));
};
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Departments</p>
    <h1>Departments</h1>
    <p class="lede">
        The root of every scope in SyncClass. A dean or secretary sees only their own department here;
        the administrator sees all of them. Departments are archived, never deleted.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>All departments</h2>
        <?php if ($can_create): ?>
            <a class="button" href="<?= $e($route('master.departments.create')) ?>">New department</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.departments.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Code, name or short name">
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
        <a class="button button-ghost" href="<?= $e($route('master.departments.index')) ?>">Reset</a>
    </form>

    <?php if ($departments === []): ?>
        <p class="hint">No departments match this filter inside your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col"><a href="<?= $e($sortUrl('code')) ?>">Code</a></th>
                    <th scope="col"><a href="<?= $e($sortUrl('name')) ?>">Name</a></th>
                    <th scope="col">Short name</th>
                    <th scope="col">Dean</th>
                    <th scope="col">Contact</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $department): ?>
                    <tr>
                        <td><code><?= $e($department['code']) ?></code></td>
                        <td>
                            <span class="swatch" style="background: <?= $e($department['color_hex']) ?>" aria-hidden="true"></span>
                            <?= $e($department['name']) ?>
                        </td>
                        <td><?= $e($department['short_name'] ?? '—') ?></td>
                        <td><?= $e(($department['dean_name'] ?? '') !== '' ? $department['dean_name'] : '—') ?></td>
                        <td class="muted">
                            <?= $e($department['email'] ?? '—') ?>
                            <?php if (($department['phone'] ?? '') !== ''): ?>
                                <br><span class="muted"><?= $e($department['phone']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $department['is_active'] === 1): ?>
                                <span class="tag tag-ok">Active</span>
                            <?php else: ?>
                                <span class="tag tag-muted">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_edit): ?>
                                <a href="<?= $e($route('master.departments.edit', ['id' => $department['id']])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($can_archive): ?>
                                <form class="inline-form" method="post" action="<?= $e($route('master.departments.toggle', ['id' => $department['id']])) ?>">
                                    <?= $csrf_field() ?>
                                    <button type="submit" class="link-button">
                                        <?= (int) $department['is_active'] === 1 ? 'Archive' : 'Restore' ?>
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
