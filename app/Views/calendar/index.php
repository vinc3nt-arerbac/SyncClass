<?php

declare(strict_types=1);

/**
 * Calendar page shell (CAL-01).
 *
 * The page is the controller's first-class output: the month grid, the week/
 * day pivot, the range-scoped JSON feed and all of the filter controls live
 * in one file so the controller stays a pure renderer. The피혁 can switch
 * between month/week/day/timetable with a single query string parameter; the
 * controller passes exactly the data each pane needs.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(string): bool $can
 * @var string $title
 * @var array<string, mixed> $auth_user
 * @var string $csrf_meta
 * @var array<string, mixed> $current_semester
 * @var array<string, mixed> $active_semester
 * @var array<int, array<string, mixed>> $years
 * @var array<int, array<string, mixed>> $semesters
 * @var array<int, array<string, mixed>> $faculty
 * @var array<int, array<string, mixed>> $rooms
 * @var array<int, array<string, string>> $weekdays
 * @var array<int, array<string, string>> $months
 * @var int $year
 * @var int $month
 * @var string|null $date
 * @var array<int, string> $weekday_names
 * @var array<string, string> $query
 * @var callable(string): string $asset
 */

$csrfMeta = $csrf_meta;
$route = $route;
$e = $e;
?>

<?php require __DIR__ . '/../master/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Scheduling · Calendar</p>
    <h1>Calendar</h1>
    <p class="lede">
        Drag any meeting and drop it on a different day or time. The calendar
        re-checks every move against the conflict engine and reverts it if it
        would clash. Filters below narrow the term without changing what the
        engine sees.
    </p>

    <?php if ($current_semester !== null): ?>
        <nav class="term-nav" aria-label="Term">
            <a class="term-link" href="<?= $e($route('calendar.index')) ?>">
                <?= $e($current_semester['code'] . ' · ' . $current_semester['name']) ?>
            </a>
        </nav>
    <?php endif; ?>
</section>

<section class="card">
    <header class="card-head">
        <div class="card-tools">
            <div class="month-picker">
                <?php foreach ($months as $index => $item): ?>
                    <?php if ($item['disabled']): continue; endif; ?>
                    <a
                        class="btn <?= $index === 0 ? 'btn-outline-secondary' : 'btn-outline-primary' ?>"
                        href="<?= $e($route('calendar.month', ['year' => $item['year'], 'month' => $item['month']])) ?>"
                        <?= $index === 0 ? 'aria-disabled="true"' : '' ?>
                    >
                        <?= $e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="date-label">
                <?= $e($year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT)) ?>
            </div>

            <div class="view-switcher">
                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($route('calendar.index')) ?>">
                    Month
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($route('calendar.index')) ?>?view=week">
                    Week
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($route('calendar.index')) ?>?view=day">
                    Day
                </a>
                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($route('calendar.index')) ?>?view=timetable">
                    Timetable
                </a>
            </div>
        </div>

        <?php if ($current_semester !== null): ?>
            <form class="filters" method="get" action="<?= $e($route('calendar.index')) ?>">
                <div class="form-floating">
                    <select
                        class="form-select"
                        name="semester_id"
                        id="semester-filter"
                        aria-label="Filter by semester"
                    >
                        <option value="">All terms</option>
                        <?php foreach ($semesters as $s): ?>
                            <option value="<?= $e($s['id']) ?>" <?= (int) (($query['semester_id'] ?? '') === (string) $s['id']) ? 'selected' : '' ?>>
                                <?= $e($s['code'] . ' · ' . $s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>Filter by term</label>
                </div>

                <div class="form-floating">
                    <select
                        class="form-select"
                        name="department_id"
                        id="department-filter"
                        aria-label="Filter by department"
                    >
                        <option value="">All departments</option>
                        <?php foreach ($current_semester['departments'] ?? [] as $dept): ?>
                            <option value="<?= $e($dept['id']) ?>" <?= (int) (($query['department_id'] ?? '') === (string) $dept['id']) ? 'selected' : '' ?>>
                                <?= $e($dept['code'] . ' · ' . $dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>Filter by department</label>
                </div>

                <button class="btn btn-primary" type="submit">Filter</button>
                <?php if (!empty(array_filter($query, static fn ($v) => $v !== ''))): ?>
                    <a class="btn btn-outline-secondary" href="<?= $e($route('calendar.index')) ?>">Reset</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </header>

    <div class="card-body">
        <?php if ($current_semester === null): ?>
            <p class="text-muted">No active term is set; the calendar is empty until a term exists.</p>
        <?php else: ?>
            <?php
            $view = $query['view'] ?? 'month';
            if ($view === 'week'): include __DIR__ . '/week.php'; endif;
            if ($view === 'day'): include __DIR__ . '/day.php'; endif;
            if ($view === 'timetable'): include __DIR__ . '/timetable.php'; endif;
            if ($view === 'month' || $view === ''): include __DIR__ . '/month.php'; endif;
            ?>
        <?php endif; ?>
    </div>
</section>

<script src="<?= $e($asset('js/calendar.js')) ?>" defer></script>
