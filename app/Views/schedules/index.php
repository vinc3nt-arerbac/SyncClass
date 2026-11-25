<?php

/**
 * Schedule list (SCH-01, SCH-04, SCH-07).
 *
 * One row is one meeting. The view states the scope it was rendered with
 * implicitly through the department column: a faculty member only ever sees
 * their own classes, a dean only their department, because the repository
 * filtered before this template ran.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<int, array<string, mixed>> $schedules
 * @var array{search: string, semester_id: mixed, status: string, department_id: mixed, day_of_week: mixed, sort: string, direction: string} $filters
 * @var array<int, array{id: int, code: string, name: string}> $departments
 * @var array<int, array{id: int, code: string, name: string, status: string, year_code: string}> $semesters
 * @var array<int, string> $statuses
 * @var array<int, string> $days
 * @var array<string, mixed>|null $current_semester
 * @var int $total
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var string|null $notice
 * @var bool $can_create
 */

$query = array_filter([
    'q' => $filters['search'],
    'semester' => $filters['semester_id'],
    'status' => $filters['status'],
    'department' => $filters['department_id'],
    'day' => $filters['day_of_week'],
    'sort' => $filters['sort'],
    'direction' => $filters['direction'],
]);

$pageUrl = static fn (int $page): string => $route('schedules.index') . '?' . http_build_query(array_merge($query, ['page' => $page]));
$time = static fn (string $value): string => substr($value, 0, 5);
?>
<?php require __DIR__ . '/../master/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Scheduling · Timetable</p>
    <h1>Schedules</h1>
    <p class="lede">
        Every meeting is its own row, which is what lets the calendar, the conflict engine and the
        &ldquo;today&rsquo;s classes&rdquo; widget work from one shape. Weekly classes are created one row per
        selected weekday.
    </p>
    <ul class="badges">
        <li><?= $e($total) ?> meeting<?= $total === 1 ? '' : 's' ?></li>
        <?php if ($current_semester !== null): ?>
            <li>Current term: <?= $e($current_semester['code']) ?></li>
        <?php endif; ?>
        <li>Conflict checks arrive in the next phase</li>
    </ul>
</section>

<?php require __DIR__ . '/../master/_notice.php'; ?>

<section class="card">
    <header class="card-head">
        <h2>All meetings</h2>
        <?php if ($can_create): ?>
            <a class="button" href="<?= $e($route('schedules.create')) ?>">Add class</a>
        <?php endif; ?>
    </header>

    <form class="filters" method="get" action="<?= $e($route('schedules.index')) ?>">
        <label>
            <span>Search</span>
            <input type="search" name="q" value="<?= $e($filters['search']) ?>" placeholder="Subject, section, room or teacher">
        </label>
        <label>
            <span>Semester</span>
            <select name="semester">
                <option value="">All semesters</option>
                <?php foreach ($semesters as $semester): ?>
                    <option value="<?= $e($semester['id']) ?>" <?= (string) $filters['semester_id'] === (string) $semester['id'] ? 'selected' : '' ?>>
                        <?= $e($semester['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Day</span>
            <select name="day">
                <option value="">Every day</option>
                <?php foreach ($days as $number => $label): ?>
                    <option value="<?= $e($number) ?>" <?= (string) $filters['day_of_week'] === (string) $number ? 'selected' : '' ?>>
                        <?= $e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Status</span>
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= $e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                        <?= $e(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button">Filter</button>
        <a class="button button-ghost" href="<?= $e($route('schedules.index')) ?>">Reset</a>
    </form>

    <?php if ($schedules === []): ?>
        <p class="hint">No meetings match this filter inside your scope.</p>
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
                    <th scope="col">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($schedules as $schedule): ?>
                    <tr>
                        <td><?= $e($days[(int) $schedule['day_of_week']] ?? $schedule['day_of_week']) ?></td>
                        <td><?= $e($time((string) $schedule['start_time'])) ?>–<?= $e($time((string) $schedule['end_time'])) ?></td>
                        <td>
                            <code><?= $e($schedule['subject_code']) ?></code>
                            <br><span class="muted"><?= $e($schedule['subject_title']) ?></span>
                        </td>
                        <td><?= $e($schedule['section_code']) ?></td>
                        <td><?= $e($schedule['faculty_first_name'] . ' ' . $schedule['faculty_last_name']) ?></td>
                        <td><?= $e($schedule['building_code'] . ' ' . $schedule['room_code']) ?></td>
                        <td><span class="tag"><?= $e($schedule['department_code']) ?></span></td>
                        <td><span class="tag status-<?= $e($schedule['status']) ?>"><?= $e(ucfirst($schedule['status'])) ?></span></td>
                        <td class="row-actions table-actions">
                            <a href="<?= $e($route('schedules.show', ['id' => $schedule['id']])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php require __DIR__ . '/../master/_pager.php'; ?>
    <?php endif; ?>
</section>
