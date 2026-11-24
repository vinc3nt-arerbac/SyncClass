<?php

/**
 * Department schedule report (RPT-03).
 *
 * One row per department with meetings that are not cancelled in the term.
 * `rooms` counts distinct rooms the department used, which is the number a
 * dean compares against the rooms the department actually owns.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var array{semesters: array<int, array<string, mixed>>, semester: array<string, mixed>|null, term: array{start: string, end: string}|null} $context
 * @var array<int, array<string, mixed>> $rows
 * @var array{departments: int, meetings: int, hours: float, subjects: int, sections: int, faculty: int, rooms: int} $totals
 * @var array<int, array{id: int, code: string, name: string}> $department_options
 * @var int $department_filter
 * @var string $term_name
 * @var string $scope_label
 * @var array<string, mixed>|null $semester
 */

$semester = $context['semester'] ?? null;
$selectedId = $semester === null ? 0 : (int) $semester['id'];
?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Reports · RPT-03</p>
    <h1>Department schedule</h1>
    <p class="lede">
        Timetable volume for <strong><?= $e($term_name) ?></strong>. Counts are distinct: a teacher who teaches four
        sections counts once as a teacher and four times as sections.
    </p>
    <ul class="badges">
        <li>Scope: <?= $e($scope_label) ?></li>
        <li><?= $e($totals['departments']) ?> department<?= $totals['departments'] === 1 ? '' : 's' ?></li>
        <li><?= $e($totals['meetings']) ?> meetings · <?= $e($totals['hours']) ?> hours</li>
    </ul>
</section>

<section class="card">
    <header class="card-head">
        <h2>Volume per department</h2>
        <button type="button" class="button button-ghost" onclick="window.print()">Print / save as PDF</button>
    </header>

    <form class="filters no-print" method="get" action="<?= $e($route('reports.departments')) ?>">
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
            <span>Department</span>
            <select name="department">
                <option value="">Every department in scope</option>
                <?php foreach ($department_options as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= $department_filter === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= $e($option['code']) ?> — <?= $e($option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button">Show</button>
        <a class="button button-ghost" href="<?= $e($route('reports.departments', $selectedId > 0 ? ['semester' => $selectedId] : [])) ?>">Reset</a>
    </form>

    <?php if ($rows === []): ?>
        <p class="hint">No non-cancelled meeting exists for this term inside your scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Department</th>
                    <th scope="col">Meetings</th>
                    <th scope="col">Weekly hours</th>
                    <th scope="col">Subjects</th>
                    <th scope="col">Sections</th>
                    <th scope="col">Teachers</th>
                    <th scope="col">Rooms used</th>
                    <th scope="col">Avg minutes/meeting</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= $e($row['department_code']) ?></strong>
                            <br><span class="muted"><?= $e($row['department_name']) ?></span>
                        </td>
                        <td><?= $e($row['meetings']) ?></td>
                        <td><?= $e($row['hours']) ?></td>
                        <td><?= $e($row['subjects']) ?></td>
                        <td><?= $e($row['sections']) ?></td>
                        <td><?= $e($row['faculty']) ?></td>
                        <td><?= $e($row['rooms']) ?></td>
                        <td><?= $e((int) $row['meetings'] > 0 ? round(((float) $row['hours'] * 60) / (int) $row['meetings']) : 0) ?> min</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th scope="row"><?= $e($totals['departments']) ?> departments</th>
                    <td><?= $e($totals['meetings']) ?></td>
                    <td><?= $e($totals['hours']) ?></td>
                    <td><?= $e($totals['subjects']) ?></td>
                    <td><?= $e($totals['sections']) ?></td>
                    <td><?= $e($totals['faculty']) ?></td>
                    <td><?= $e($totals['rooms']) ?></td>
                    <td></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <p class="hint">
            The totals row sums the per-department counts, so a teacher shared by two departments appears in both
            totals — the column is "teachers per department", not a headcount of the institution.
        </p>
    <?php endif; ?>
</section>
