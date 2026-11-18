<?php

/**
 * Academic years list (MD-06).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $years
 * @var array{search: string, sort: string, direction: string} $filters
 * @var int $total
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var string|null $notice
 * @var bool $can_manage
 */

$query = array_filter(['q' => $filters['search'], 'sort' => $filters['sort'], 'direction' => $filters['direction']]);
$pageUrl = static fn (int $page): string => $route('master.years.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Academic years</p>
    <h1>Academic years</h1>
    <p class="lede">The container for semesters. Exactly one year is current; setting a new one clears the previous.</p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>Years</h2>
        <?php if ($can_manage): ?>
            <a class="button" href="<?= $e($route('master.years.create')) ?>">New academic year</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.years.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="2026-2027">
        </label>
        <button type="submit" class="button">Filter</button>
        <a class="button button-ghost" href="<?= $e($route('master.years.index')) ?>">Reset</a>
    </form>

    <?php if ($years === []): ?>
        <p class="hint">No academic years yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Year</th>
                    <th scope="col">Start</th>
                    <th scope="col">End</th>
                    <th scope="col">Semesters</th>
                    <th scope="col">Current</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($years as $year): ?>
                    <tr>
                        <td><code><?= $e($year['code']) ?></code></td>
                        <td><?= $e($year['start_date']) ?></td>
                        <td><?= $e($year['end_date']) ?></td>
                        <td><?= $e($year['semester_count']) ?></td>
                        <td>
                            <?php if ((int) $year['is_current'] === 1): ?>
                                <span class="tag tag-ok">Current</span>
                            <?php else: ?>
                                <span class="tag tag-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_manage): ?>
                                <a href="<?= $e($route('master.years.edit', ['id' => $year['id']])) ?>">Edit</a>
                                <?php if ((int) $year['is_current'] !== 1): ?>
                                    <form class="inline-form" method="post" action="<?= $e($route('master.years.current', ['id' => $year['id']])) ?>">
                                        <?= $csrf_field() ?>
                                        <button type="submit" class="link-button">Make current</button>
                                    </form>
                                <?php endif; ?>
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
