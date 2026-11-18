<?php

/**
 * People directory (Phase 8).
 *
 * Everything dynamic is escaped through $e(). The page states the scope it was
 * rendered with so a reviewer can see why a dean's list is shorter than the
 * administrator's — the filter is server-side, not a hidden UI condition.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var App\Services\Scope $access_scope
 * @var array<int, array<string, mixed>> $users
 * @var array<int, array<string, mixed>> $roles
 * @var array{search: string, role_code: string} $filters
 * @var int $total
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var bool $can_edit
 * @var bool $can_create
 */

$query = static function (array $overrides) use ($filters): string {
    $values = array_filter(array_replace($filters, $overrides), static fn (mixed $value): bool => $value !== '' && $value !== 0 && $value !== null);

    return $values === [] ? '' : '?' . http_build_query($values);
};
?>
<section class="hero">
    <p class="eyebrow">Phase 8 · Role-based access</p>
    <h1>People</h1>
    <p class="lede">
        You are signed in as <strong><?= $e($access_scope->roleCode()) ?></strong>, so this list is filtered by
        <strong><?= $e($access_scope->label()) ?></strong>. The filter is applied in SQL by
        <code>UserRepository::paginateForScope()</code>, not by hiding rows in the template.
    </p>
    <ul class="badges">
        <li><?= $e($total) ?> account<?= $total === 1 ? '' : 's' ?> in scope</li>
        <li>Permission required: <code>users.view</code></li>
        <li><?= $can_edit ? 'Editing granted' : 'Read-only' ?></li>
    </ul>
</section>

<section class="card">
    <header class="card-head">
        <h2>Directory</h2>
        <?php if ($can_create): ?>
            <span class="tag">users.create granted</span>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('admin.users.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Name, email or username">
        </label>
        <label>
            <span>Role</span>
            <select name="role">
                <option value="">All roles</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= $e($role['code']) ?>" <?= $filters['role_code'] === (string) $role['code'] ? 'selected' : '' ?>>
                        <?= $e($role['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button">Filter</button>
        <a class="button button-ghost" href="<?= $e($route('admin.users.index')) ?>">Reset</a>
    </form>

    <?php if ($users === []): ?>
        <p class="hint">No accounts match this filter inside your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Department</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last sign-in</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td><?= $e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                        <td><code><?= $e($row['email']) ?></code></td>
                        <td><?= $e($row['role_name']) ?></td>
                        <td><?= $e($row['department_name'] ?? '—') ?></td>
                        <td>
                            <?php if ((int) $row['is_active'] === 1): ?>
                                <span class="tag tag-ok">Active</span>
                            <?php else: ?>
                                <span class="tag tag-muted">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($row['last_login_at'] ?? 'Never') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav class="pager" aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a class="button button-ghost" href="<?= $e($route('admin.users.index') . $query(['page' => $page - 1])) ?>">Previous</a>
            <?php endif; ?>
            <span>Page <?= $e($page) ?> of <?= $e($last_page) ?> · <?= $e($per_page) ?> per page</span>
            <?php if ($page < $last_page): ?>
                <a class="button button-ghost" href="<?= $e($route('admin.users.index') . $query(['page' => $page + 1])) ?>">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
