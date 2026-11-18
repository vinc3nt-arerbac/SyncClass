<?php

/**
 * Semesters list (MD-06).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $semesters
 * @var array{search: string, academic_year_id: mixed, status: string, sort: string, direction: string} $filters
 * @var array<int, array{id: int, code: string}> $years
 * @var array<int, string> $statuses
 * @var int $total
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var string|null $notice
 * @var bool $can_manage
 */

$query = array_filter([
    'q' => $filters['search'],
    'year' => $filters['academic_year_id'],
    'status' => $filters['status'],
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
]);

$pageUrl = static fn (int $page): string => $route('master.semesters.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Semesters</p>
    <h1>Semesters</h1>
    <p class="lede">
        One current semester per academic year. A semester moves <code>planned → active → closed</code>;
        closed terms stay readable for reports.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>Terms</h2>
        <?php if ($can_manage): ?>
            <a class="button" href="<?= $e($route('master.semesters.create')) ?>">New semester</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.semesters.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Code or name">
        </label>
        <label>
            <span>Academic year</span>
            <select name="year">
                <option value="">All years</option>
                <?php foreach ($years as $year): ?>
                    <option value="<?= $e($year['id']) ?>" <?= (string) $filters['academic_year_id'] === (string) $year['id'] ? 'selected' : '' ?>>
                        <?= $e($year['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Status</span>
            <select name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= $e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                        <?= $e(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button">Filter</button>
        <a class="button button-ghost" href="<?= $e($route('master.semesters.index')) ?>">Reset</a>
    </form>

    <?php if ($semesters === []): ?>
        <p class="hint">No semesters match this filter.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Year</th>
                    <th scope="col">Term</th>
                    <th scope="col">Dates</th>
                    <th scope="col">Status</th>
                    <th scope="col">Current</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($semesters as $semester): ?>
                    <tr>
                        <td><code><?= $e($semester['code']) ?></code></td>
                        <td><?= $e($semester['name']) ?></td>
                        <td><?= $e($semester['year_code']) ?></td>
                        <td><?= $e($semester['term_no']) ?></td>
                        <td class="muted"><?= $e($semester['start_date']) ?> → <?= $e($semester['end_date']) ?></td>
                        <td><span class="tag"><?= $e(ucfirst($semester['status'])) ?></span></td>
                        <td>
                            <?php if ((int) $semester['is_current'] === 1): ?>
                                <span class="tag tag-ok">Current</span>
                            <?php else: ?>
                                <span class="tag tag-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_manage): ?>
                                <a href="<?= $e($route('master.semesters.edit', ['id' => $semester['id']])) ?>">Edit</a>
                                <?php if ((int) $semester['is_current'] !== 1): ?>
                                    <form class="inline-form" method="post" action="<?= $e($route('master.semesters.current', ['id' => $semester['id']])) ?>">
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
