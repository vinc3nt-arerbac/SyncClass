<?php

/**
 * Faculty load report (RPT-01).
 *
 * Units are credited once per subject/section allocation (ReportService), so a
 * subject taught on Monday and Wednesday shows 3 units and two meetings — not
 * 6 units. Contact hours are the sum of the meeting windows.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var array{semesters: array<int, array<string, mixed>>, semester: array<string, mixed>|null, term: array{start: string, end: string}|null} $context
 * @var array<int, array<string, mixed>> $rows
 * @var array{teachers: int, units: float, hours: float, meetings: int, over: int, under: int} $totals
 * @var string $term_name
 * @var string $scope_label
 * @var array<string, mixed>|null $semester
 */

$semester = $context['semester'] ?? null;
$selectedId = $semester === null ? 0 : (int) $semester['id'];
?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Reports · RPT-01</p>
    <h1>Faculty load</h1>
    <p class="lede">
        Teaching load for <strong><?= $e($term_name) ?></strong>. A teacher is <em>over</em> when credited units exceed
        the contracted cap on their profile and <em>under</em> below 60 % of it; the band is advice, not a block.
    </p>
    <ul class="badges">
        <li>Scope: <?= $e($scope_label) ?></li>
        <li><?= $e($totals['teachers']) ?> teacher<?= $totals['teachers'] === 1 ? '' : 's' ?></li>
        <li><?= $e($totals['units']) ?> units · <?= $e($totals['hours']) ?> contact hours</li>
        <li><?= $e($totals['over']) ?> over cap · <?= $e($totals['under']) ?> under-loaded</li>
    </ul>
</section>

<section class="card">
    <header class="card-head">
        <h2>Load per teacher</h2>
        <button type="button" class="button button-ghost" onclick="window.print()">Print / save as PDF</button>
    </header>

    <form class="filters no-print" method="get" action="<?= $e($route('reports.faculty-load')) ?>">
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
        <button type="submit" class="button">Show</button>
    </form>

    <?php if ($rows === []): ?>
        <p class="hint">No active faculty profile is visible in this term and scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Teacher</th>
                    <th scope="col">Department</th>
                    <th scope="col">Type</th>
                    <th scope="col">Cap (units)</th>
                    <th scope="col">Units</th>
                    <th scope="col">Of cap</th>
                    <th scope="col">Contact h/week</th>
                    <th scope="col">Meetings</th>
                    <th scope="col">Subjects</th>
                    <th scope="col">Sections</th>
                    <th scope="col">Band</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= $e($row['last_name'] . ', ' . $row['first_name']) ?></strong>
                            <br><span class="muted"><?= $e($row['email']) ?></span>
                        </td>
                        <td><span class="tag"><?= $e($row['department_code']) ?></span></td>
                        <td><?= $e(ucwords(str_replace('_', ' ', (string) $row['employment_type']))) ?></td>
                        <td><?= $e($row['max_load_units']) ?></td>
                        <td><?= $e($row['units']) ?></td>
                        <td><?= $e($row['cap_share']) ?>%</td>
                        <td><?= $e($row['hours']) ?></td>
                        <td><?= $e($row['meetings']) ?></td>
                        <td><?= $e($row['subjects']) ?></td>
                        <td><?= $e($row['sections']) ?></td>
                        <td><span class="tag band-<?= $e($row['band']) ?>"><?= $e(ucfirst((string) $row['band'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th scope="row" colspan="2">Totals</th>
                    <td colspan="2"><?= $e($totals['teachers']) ?> teachers</td>
                    <td><?= $e($totals['units']) ?></td>
                    <td colspan="2"><?= $e($totals['hours']) ?> h</td>
                    <td><?= $e($totals['meetings']) ?></td>
                    <td colspan="3"></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <p class="hint">
            Units count each subject/section allocation once, however many weekdays it meets. Contact hours are weekly
            teaching hours: every meeting in the week is summed, which is the figure a load cap is expressed against.
            Cancelled meetings are excluded from both.
        </p>
    <?php endif; ?>
</section>
