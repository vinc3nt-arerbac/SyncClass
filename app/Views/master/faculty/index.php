<?php

/**
 * Faculty profiles list (MD-05, MD-08).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $faculty
 * @var array{search: string, status: string, department_id: mixed, employment_type: string, sort: string, direction: string} $filters
 * @var array<int, array{id: int, code: string, name: string}> $departments
 * @var array<int, string> $employment_types
 * @var int $total
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var string|null $notice
 * @var bool $can_create
 * @var bool $can_edit
 * @var bool $can_archive
 * @var bool $can_availability
 */

$query = array_filter([
    'q' => $filters['search'],
    'status' => $filters['status'],
    'department' => $filters['department_id'],
    'employment' => $filters['employment_type'],
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
]);

$pageUrl = static fn (int $page): string => $route('master.faculty.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));

$employmentLabel = static fn (string $type): string => ucwords(str_replace('_', ' ', $type));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Faculty</p>
    <h1>Faculty</h1>
    <p class="lede">
        A profile links a faculty account to a department and records the teaching load the scheduler must
        respect. Faculty see only their own profile here; deans see their department.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>Profiles</h2>
        <?php if ($can_create): ?>
            <a class="button" href="<?= $e($route('master.faculty.create')) ?>">New profile</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('master.faculty.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Name, email or specialisation">
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
            <span>Employment</span>
            <select name="employment">
                <option value="">All types</option>
                <?php foreach ($employment_types as $type): ?>
                    <option value="<?= $e($type) ?>" <?= $filters['employment_type'] === $type ? 'selected' : '' ?>>
                        <?= $e($employmentLabel($type)) ?>
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
        <a class="button button-ghost" href="<?= $e($route('master.faculty.index')) ?>">Reset</a>
    </form>

    <?php if ($faculty === []): ?>
        <p class="hint">No faculty profiles match this filter inside your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Department</th>
                    <th scope="col">Employment</th>
                    <th scope="col">Max load</th>
                    <th scope="col">Specialisation</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($faculty as $profile): ?>
                    <tr>
                        <td><?= $e($profile['first_name'] . ' ' . $profile['last_name']) ?></td>
                        <td><code><?= $e($profile['email']) ?></code></td>
                        <td><span class="tag"><?= $e($profile['department_code']) ?></span></td>
                        <td><?= $e($employmentLabel((string) $profile['employment_type'])) ?></td>
                        <td><?= $e(number_format((float) $profile['max_load_units'], 1)) ?> u</td>
                        <td class="muted"><?= $e($profile['specialization'] ?? '—') ?></td>
                        <td>
                            <?php if ((int) $profile['is_active'] === 1): ?>
                                <span class="tag tag-ok">Active</span>
                            <?php else: ?>
                                <span class="tag tag-muted">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions table-actions">
                            <?php if ($can_edit): ?>
                                <a href="<?= $e($route('master.faculty.edit', ['id' => $profile['id']])) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if ($can_availability): ?>
                                <a href="<?= $e($route('master.faculty.availability', ['id' => $profile['id']])) ?>">Availability</a>
                            <?php endif; ?>
                            <?php if ($can_archive): ?>
                                <form class="inline-form" method="post" action="<?= $e($route('master.faculty.toggle', ['id' => $profile['id']])) ?>">
                                    <?= $csrf_field() ?>
                                    <button type="submit" class="link-button">
                                        <?= (int) $profile['is_active'] === 1 ? 'Archive' : 'Restore' ?>
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
