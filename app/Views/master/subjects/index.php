<?php

/**
 * Subjects list (MD-03, MD-08).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $subjects
 * @var array{search: string, status: string, department_id: mixed, subject_type: string, sort: string, direction: string} $filters
 * @var array<int, array{id: int, code: string, name: string}> $departments
 * @var array<int, string> $subject_types
 * @var int $total
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var string|null $notice
 * @var bool $can_create
 * @var bool $can_edit
 * @var bool $can_delete
 */

$query = array_filter([
    'q' => $filters['search'],
    'status' => $filters['status'],
    'department' => $filters['department_id'],
    'type' => $filters['subject_type'],
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
]);

$pageUrl = static fn (int $page): string => $route('master.subjects.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Subjects</p>
    <h1>Subjects</h1>
    <p class="lede">
        The catalogue the timetable is built from. Units and hours drive faculty load and room matching;
        the subject type tells the conflict engine whether a laboratory room is required.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>Catalogue</h2>
        <?php if ($can_create): ?>
            <a class="button" href="<?= $e($route('master.subjects.create')) ?>">New subject</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.subjects.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Code or title">
        </label>
        <label>
            <span>Department</span>
            <select name="department">
                <option value="">All departments</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= $e($department['id']) ?>" <?= (string) $filters['department_id'] === (string) $department['id'] ? 'selected' : '' ?>>
                        <?= $e($department['code']) ?> · <?= $e($department['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Type</span>
            <select name="type">
                <option value="">All types</option>
                <?php foreach ($subject_types as $type): ?>
                    <option value="<?= $e($type) ?>" <?= $filters['subject_type'] === $type ? 'selected' : '' ?>>
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
        <a class="button button-ghost" href="<?= $e($route('master.subjects.index')) ?>">Reset</a>
    </form>

    <?php if ($subjects === []): ?>
        <p class="hint">No subjects match this filter inside your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Title</th>
                    <th scope="col">Department</th>
                    <th scope="col">Units</th>
                    <th scope="col">Hours</th>
                    <th scope="col">Type</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><code><?= $e($subject['code']) ?></code></td>
                        <td><?= $e($subject['title']) ?></td>
                        <td><span class="tag"><?= $e($subject['department_code']) ?></span></td>
                        <td><?= $e(number_format((float) $subject['units'], 1)) ?></td>
                        <td class="muted">
                            <?= $e(number_format((float) $subject['lecture_hours'], 1)) ?> lec +
                            <?= $e(number_format((float) $subject['lab_hours'], 1)) ?> lab
                        </td>
                        <td><?= $e(ucfirst($subject['subject_type'])) ?></td>
                        <td>
                            <?php if ((int) $subject['is_active'] === 1): ?>
                                <span class="tag tag-ok">Active</span>
                            <?php else: ?>
                                <span class="tag tag-muted">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_edit): ?>
                                <a class="button button-small button-gold" href="<?= $e($route('master.subjects.edit', ['id' => $subject['id']])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                                <form class="inline-form"
                                      method="post"
                                      action="<?= $e($route('master.subjects.toggle', ['id' => $subject['id']])) ?>"
                                      onsubmit="return confirm('Delete <?= $e($subject['code']) ?>? The subject will be archived and hidden from the active catalogue.');">
                                    <?= $csrf_field() ?>
                                    <?php if ((int) $subject['is_active'] === 1): ?>
                                        <button type="submit" class="button button-small">Delete</button>
                                    <?php else: ?>
                                        <button type="submit" class="button button-small button-secondary">Restore</button>
                                    <?php endif; ?>
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
