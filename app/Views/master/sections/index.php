<?php

/**
 * Sections list (MD-04, MD-08).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $sections
 * @var array{search: string, status: string, department_id: mixed, year_level: mixed, sort: string, direction: string} $filters
 * @var array<int, array{id: int, code: string, name: string}> $departments
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
    'department' => $filters['department_id'],
    'year' => $filters['year_level'],
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
]);

$pageUrl = static fn (int $page): string => $route('master.sections.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Sections</p>
    <h1>Sections</h1>
    <p class="lede">
        Cohorts the timetable is published for. The student count is compared with room capacity by the
        conflict engine, so it is required for every section.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>All sections</h2>
        <?php if ($can_create): ?>
            <a class="button" href="<?= $e($route('master.sections.create')) ?>">New section</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.sections.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Code, name or program">
        </label>
        <label>
            <span>Department</span>
            <select name="department">
                <option value="">All departments</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= $e($department['id']) ?>" <?= (string) $filters['department_id'] === (string) $department['id'] ? 'selected' : '' ?>>
                        <?= $e($department['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Year level</span>
            <select name="year">
                <option value="">All years</option>
                <?php foreach (range(1, 6) as $year): ?>
                    <option value="<?= $e($year) ?>" <?= (string) $filters['year_level'] === (string) $year ? 'selected' : '' ?>>
                        Year <?= $e($year) ?>
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
        <a class="button button-ghost" href="<?= $e($route('master.sections.index')) ?>">Reset</a>
    </form>

    <?php if ($sections === []): ?>
        <p class="hint">No sections match this filter inside your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Program</th>
                    <th scope="col">Year</th>
                    <th scope="col">Department</th>
                    <th scope="col">Students</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sections as $section): ?>
                    <tr>
                        <td><code><?= $e($section['code']) ?></code></td>
                        <td>
                            <?= $e($section['program'] ?? '—') ?>
                            <?php if (($section['name'] ?? '') !== ''): ?>
                                <br><span class="muted"><?= $e($section['name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($section['year_level']) ?></td>
                        <td><span class="tag"><?= $e($section['department_code']) ?></span></td>
                        <td><?= $e($section['student_count']) ?></td>
                        <td>
                            <?php if ((int) $section['is_active'] === 1): ?>
                                <span class="tag tag-ok">Active</span>
                            <?php else: ?>
                                <span class="tag tag-muted">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_edit): ?>
                                <a href="<?= $e($route('master.sections.edit', ['id' => $section['id']])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($can_archive): ?>
                                <form class="inline-form" method="post" action="<?= $e($route('master.sections.toggle', ['id' => $section['id']])) ?>">
                                    <?= $csrf_field() ?>
                                    <button type="submit" class="link-button">
                                        <?= (int) $section['is_active'] === 1 ? 'Archive' : 'Restore' ?>
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
