<?php

/**
 * Weekly board and semester timetable (RPT-03 / RPT-05).
 *
 * One query feeds both shapes: `$grid` is the week board (lane per start/end
 * window, column per weekday) and `$rows` is the full semester listing. The
 * teacher filter turns the board into a teacher's own schedule (RPT-05); the
 * section filter turns it into a class timetable.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var array{semesters: array<int, array<string, mixed>>, semester: array<string, mixed>|null, term: array{start: string, end: string}|null} $context
 * @var array<int, array<string, mixed>> $rows
 * @var array<string, array<int, array<int, array<string, mixed>>>> $grid
 * @var array<int, string> $days
 * @var array{faculty_id: int, department_id: int, section_id: int} $filters
 * @var int $meetings
 * @var float $total_hours
 * @var string $term_name
 * @var string $scope_label
 * @var array<int, array<string, mixed>> $faculty_options
 * @var array<int, array{id: int, code: string, name: string}> $department_options
 * @var array<int, array<string, mixed>> $section_options
 * @var array<string, mixed>|null $semester
 */

$semester = $context['semester'] ?? null;
$selectedId = $semester === null ? 0 : (int) $semester['id'];

// Where the "weekly" board and the "semester" listing anchor their filters.
$baseQuery = array_filter([
    'semester' => $selectedId > 0 ? $selectedId : null,
    'faculty' => $filters['faculty_id'] > 0 ? $filters['faculty_id'] : null,
    'department' => $filters['department_id'] > 0 ? $filters['department_id'] : null,
    'section' => $filters['section_id'] > 0 ? $filters['section_id'] : null,
]);

$facultyName = static function (array $row): string {
    return trim((string) $row['faculty_last_name'] . ', ' . (string) $row['faculty_first_name']);
};
?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Reports · RPT-03 / RPT-05</p>
    <h1>Weekly &amp; semester timetable</h1>
    <p class="lede">
        The timetable for <strong><?= $e($term_name) ?></strong>, printed from the live schedule.
        Filter to one teacher for their own schedule, or to one section for a class timetable.
    </p>
    <ul class="badges">
        <li>Scope: <?= $e($scope_label) ?></li>
        <li><?= $e($meetings) ?> meeting<?= $meetings === 1 ? '' : 's' ?></li>
        <li><?= $e($total_hours) ?> scheduled hours per week</li>
    </ul>
</section>

<section class="card">
    <header class="card-head">
        <h2>Filters</h2>
        <button type="button" class="button button-ghost" onclick="window.print()">Print / save as PDF</button>
    </header>

    <form class="filters no-print" method="get" action="<?= $e($route('reports.timetable')) ?>">
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
            <span>Teacher</span>
            <select name="faculty">
                <option value="">Every teacher in scope</option>
                <?php foreach ($faculty_options as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= $filters['faculty_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= $e($option['last_name'] . ', ' . $option['first_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Department</span>
            <select name="department">
                <option value="">Every department in scope</option>
                <?php foreach ($department_options as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= $filters['department_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= $e($option['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Section</span>
            <select name="section">
                <option value="">Every section</option>
                <?php foreach ($section_options as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= $filters['section_id'] === (int) $option['id'] ? 'selected' : '' ?>>
                        <?= $e($option['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button">Show</button>
        <a class="button button-ghost" href="<?= $e($route('reports.timetable', $selectedId > 0 ? ['semester' => $selectedId] : [])) ?>">Reset</a>
    </form>
</section>

<section class="card">
    <header class="card-head">
        <h2>Week board</h2>
        <span class="muted">Monday–Saturday · times are the meeting windows</span>
    </header>

    <?php if ($grid === []): ?>
        <p class="hint">No non-cancelled meeting matches this filter in the selected term.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="timetable">
                <thead>
                <tr>
                    <th scope="col">Time</th>
                    <?php foreach ($days as $number => $label): ?>
                        <th scope="col"><?= $e($label) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($grid as $lane => $byDay): ?>
                    <tr>
                        <th scope="row" class="lane"><?= $e($lane) ?></th>
                        <?php foreach (array_keys($days) as $dayNumber): ?>
                            <td>
                                <?php foreach (($byDay[(int) $dayNumber] ?? []) as $entry): ?>
                                    <div class="slot">
                                        <strong><?= $e($entry['subject_code']) ?></strong>
                                        <span class="muted"><?= $e($entry['section_code']) ?></span>
                                        <span><?= $e($facultyName($entry)) ?></span>
                                        <span class="muted"><?= $e($entry['building_code'] . ' ' . $entry['room_code']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <header class="card-head">
        <h2>Semester timetable</h2>
        <span class="muted"><?= $e($meetings) ?> meeting<?= $meetings === 1 ? '' : 's' ?></span>
    </header>

    <?php if ($rows === []): ?>
        <p class="hint">Nothing to list for this filter.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Day</th>
                    <th scope="col">Time</th>
                    <th scope="col">Subject</th>
                    <th scope="col">Section</th>
                    <th scope="col">Teacher</th>
                    <th scope="col">Room</th>
                    <th scope="col">Dept</th>
                    <th scope="col">Status</th>
                    <th scope="col">Dates</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $e($days[(int) $row['day_of_week']] ?? ('Day ' . $row['day_of_week'])) ?></td>
                        <td><?= $e($row['start_time']) ?>–<?= $e($row['end_time']) ?></td>
                        <td>
                            <code><?= $e($row['subject_code']) ?></code>
                            <br><span class="muted"><?= $e($row['subject_title']) ?></span>
                        </td>
                        <td><?= $e($row['section_code']) ?></td>
                        <td><?= $e($facultyName($row)) ?></td>
                        <td><?= $e($row['building_code'] . ' ' . $row['room_code']) ?></td>
                        <td><span class="tag"><?= $e($row['department_code']) ?></span></td>
                        <td><?= $e(ucfirst((string) $row['status'])) ?></td>
                        <td><?= $row['recurrence'] === 'one_off' ? $e(substr((string) $row['meeting_date'], 0, 10)) : 'Weekly' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="hint">
            One row is one meeting. A weekly class that meets twice a week appears twice, which is why the count above
            is higher than the number of subjects.
        </p>
    <?php endif; ?>
</section>
