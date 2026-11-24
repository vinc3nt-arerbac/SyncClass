<?php

/**
 * Conflict report (RPT-04).
 *
 * Reads the persisted log in `schedule_conflicts`. The table is written by the
 * conflict engine when a saved meeting violates a rule; a term with no rows is
 * a clean term, not an error, so the empty state says so explicitly.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var array{semesters: array<int, array<string, mixed>>, semester: array<string, mixed>|null, term: array{start: string, end: string}|null} $context
 * @var array<int, array<string, mixed>> $rows
 * @var array{open: int, resolved: int, ignored: int, errors: int, warnings: int} $counts
 * @var string $status
 * @var string $term_name
 * @var string $scope_label
 * @var array<string, mixed>|null $semester
 */

$semester = $context['semester'] ?? null;
$selectedId = $semester === null ? 0 : (int) $semester['id'];
$days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

$filterUrl = static fn (string $statusFilter): string => $route('reports.conflicts', array_filter([
    'semester' => $selectedId > 0 ? $selectedId : null,
    'status' => $statusFilter === '' ? null : $statusFilter,
]));
?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Reports · RPT-04</p>
    <h1>Conflict report</h1>
    <p class="lede">
        The conflict log for <strong><?= $e($term_name) ?></strong>. Rows are written when a saved meeting breaks a
        rule — room, teacher, section, duplicate subject, capacity, room type, load or availability.
    </p>
    <ul class="badges">
        <li>Scope: <?= $e($scope_label) ?></li>
        <li><?= $e($counts['open']) ?> open</li>
        <li><?= $e($counts['resolved']) ?> resolved</li>
        <li><?= $e($counts['ignored']) ?> ignored</li>
    </ul>
</section>

<section class="card">
    <header class="card-head">
        <h2>Logged conflicts</h2>
        <button type="button" class="button button-ghost" onclick="window.print()">Print / save as PDF</button>
    </header>

    <form class="filters no-print" method="get" action="<?= $e($route('reports.conflicts')) ?>">
        <label>
            <span>Semester</span>
            <select name="semester">
                <?php foreach ($context['semesters'] as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= (int) $option['id'] === $selectedId ? 'selected' : '' ?>>
                        <?= $e($option['code']) ?> — <?= $e($option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Status</span>
            <select name="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>Every status</option>
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="resolved" <?= $status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                <option value="ignored" <?= $status === 'ignored' ? 'selected' : '' ?>>Ignored</option>
            </select>
        </label>
        <button type="submit" class="button">Show</button>
        <a class="button button-ghost" href="<?= $e($filterUrl('')) ?>">Reset</a>
    </form>

    <?php if ($rows === []): ?>
        <p class="hint">
            No conflict is logged for this term and filter. A clean term has no rows — the conflict engine records a
            violation only when a saved meeting breaks a rule.
        </p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Severity</th>
                    <th scope="col">Status</th>
                    <th scope="col">Rule</th>
                    <th scope="col">Meeting</th>
                    <th scope="col">When</th>
                    <th scope="col">Message</th>
                    <th scope="col">Detected</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><span class="tag band-<?= $row['severity'] === 'warning' ? 'under' : 'over' ?>"><?= $e(ucfirst((string) $row['severity'])) ?></span></td>
                        <td><span class="tag"><?= $e(ucfirst((string) $row['status'])) ?></span></td>
                        <td>
                            <code><?= $e($row['rule_code']) ?></code>
                            <br><span class="muted"><?= $e(str_replace('_', ' ', (string) $row['conflict_type'])) ?></span>
                        </td>
                        <td>
                            <strong><?= $e($row['subject_code']) ?></strong> · <?= $e($row['section_code']) ?>
                            <br><span class="muted">
                                <?= $e($row['faculty_last_name'] . ', ' . $row['faculty_first_name']) ?> ·
                                <?= $e($row['building_code'] . ' ' . $row['room_code']) ?> ·
                                <?= $e($row['department_code']) ?>
                            </span>
                        </td>
                        <td>
                            <?= $e($days[(int) $row['day_of_week']] ?? '#') ?>
                            <?= $e($row['start_time']) ?>–<?= $e($row['end_time']) ?>
                        </td>
                        <td><?= $e($row['message']) ?></td>
                        <td><?= $e(substr((string) $row['detected_at'], 0, 16)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">
            <?= $e($counts['errors']) ?> error-level and <?= $e($counts['warnings']) ?> warning-level rows in this view.
            The list is capped at the 500 most recent rows.
        </p>
    <?php endif; ?>
</section>
