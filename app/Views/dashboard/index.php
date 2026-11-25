<?php

/**
 * Dashboard — scheduling command centre (Phase 16, redesigned in Phase 18).
 *
 * A weekly timetable is the centrepiece; around it sit the health checks that
 * make a timetable trustworthy (room and teacher clashes, seat capacity), the
 * utilisation read-outs, and a planning board of the work still left in the term.
 *
 * Every value on the page comes from the scoped read models in
 * DashboardService/AnalyticsRepository, so a dean sees their college and a
 * teacher sees their own classes without the template knowing about roles. The
 * only client-side data is the Chart.js panel, which reads the JSON endpoint
 * named in `data-dashboard-endpoint` (DASH-03: no dataset in the template).
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(string): bool $can
 * @var callable(string): string $asset
 * @var array<string, mixed> $auth_user
 * @var array<string, mixed> $context
 * @var string $term_name
 * @var string|null $term_dates
 * @var string $scope_label
 * @var string $audience
 * @var array{date: string, label: string, is_teaching_day: bool, classes: array<int, array<string, mixed>>, count: int, hours: float} $today
 * @var array<int, array<string, mixed>> $upcoming
 * @var array<int, array{key: string, label: string, value: int, hint: string}> $stats
 * @var array{open: int, resolved: int, ignored: int, total: int} $conflicts
 * @var array{overall: float, rooms: int, booked_hours: float, available_hours: float, top: array<int, array<string, mixed>>} $utilization
 * @var array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>} $workload
 * @var array<int, array<string, mixed>> $departments
 * @var array<string, mixed> $week
 * @var array<string, mixed> $alerts
 * @var array{percent: float, students: int, seats: int, sessions: int} $occupancy
 * @var array<int, array<string, mixed>> $buildings
 * @var array<string, mixed> $board
 * @var string $chart_endpoint
 * @var string $csrf_meta
 */

$termId = isset($context['semester']['id']) ? (int) $context['semester']['id'] : 0;
$termCode = isset($context['semester']['code']) ? (string) $context['semester']['code'] : 'No term';
$isFaculty = $audience === 'faculty';

/** Small line icons keep the stat row scannable without an icon font. */
$icons = [
    'teachers' => '<path d="M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1"/><circle cx="9.5" cy="7" r="3.2"/><path d="M17 8.2a3 3 0 0 1 0 5.6M21 19v-1a4 4 0 0 0-3-3.8"/>',
    'subjects' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H19v15.5H6.5A2.5 2.5 0 0 0 4 21z"/><path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H19"/>',
    'rooms' => '<path d="M4 20V6.5L12 3l8 3.5V20"/><path d="M9 20v-5h6v5"/><path d="M9.5 9.5h1.5M13 9.5h1.5"/>',
    'today' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 1.8"/>',
    'occupancy' => '<path d="M4 20v-9l8-4 8 4v9"/><path d="M9 20v-4h6v4"/><path d="M9 10.5h6"/>',
    'conflicts' => '<path d="M12 4 3.5 19h17z"/><path d="M12 9.5v4M12 16.4v.1"/>',
];

/** The widget row: the service's four role-aware cards plus two health cards. */
$cards = $stats;

$cards[] = [
    'key' => 'occupancy',
    'label' => 'Seat occupancy',
    'value' => $occupancy['percent'] . '%',
    'hint' => $occupancy['students'] . ' students seated of ' . $occupancy['seats'] . ' seats',
];

$cards[] = [
    'key' => 'conflicts',
    'label' => 'Timetable flags',
    'value' => $alerts['total'],
    'hint' => $alerts['total'] === 0
        ? 'No clash or capacity warning in this term.'
        : $alerts['danger'] . ' blocking · ' . $alerts['warning'] . ' capacity warnings',
];
?>

<div class="dash">

    <header class="dash-head">
        <div class="dash-head-main">
            <p class="dash-eyebrow">
                <span class="dash-pulse" aria-hidden="true"></span>
                Command centre · <?= $e($term_name) ?>
            </p>
            <h1>Welcome back, <?= $e($auth_user['first_name'] ?? 'there') ?>.</h1>
            <p class="dash-sub">
                <?= $e($today['label']) ?> — <?= $e($today['count']) ?>
                <?= $today['count'] === 1 ? 'class' : 'classes' ?> on your timetable today across
                <?= $e($week['total']) ?> weekly sessions. Dates, rooms and clashes below are read live
                from the schedule.
            </p>
            <ul class="dash-badges">
                <li>Scope: <?= $e($scope_label) ?></li>
                <li>Term <?= $e($termCode) ?></li>
                <?php if ($term_dates !== null): ?>
                    <li><?= $e($term_dates) ?></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="dash-head-side">
            <?php if ($context['semesters'] !== []): ?>
                <form class="dash-term" method="get" action="<?= $e($route('home')) ?>">
                    <label for="dashboard-semester">Term</label>
                    <select class="dash-select" name="semester" id="dashboard-semester">
                        <?php foreach ($context['semesters'] as $term): ?>
                            <option value="<?= $e($term['id']) ?>" <?= (int) $term['id'] === $termId ? 'selected' : '' ?>>
                                <?= $e($term['code'] . ' · ' . $term['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="dash-btn dash-btn-quiet" type="submit">Switch</button>
                </form>
            <?php endif; ?>

            <div class="dash-actions">
                <?php if ($can('schedules.create')): ?>
                    <a class="dash-btn dash-btn-primary" href="<?= $e($route('schedules.create')) ?>">
                        <span aria-hidden="true">+</span> Add class
                    </a>
                <?php endif; ?>
                <?php if ($can('schedules.view')): ?>
                    <a class="dash-btn" href="<?= $e($route('calendar.index')) ?>">Calendar</a>
                <?php endif; ?>
                <?php if ($can('reports.view')): ?>
                    <a class="dash-btn" href="<?= $e($route('reports.index')) ?>">Reports</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <section class="stat-grid dash-stats" aria-label="Key figures">
        <?php foreach ($cards as $card): ?>
            <article class="dash-stat" data-widget="<?= $e($card['key']) ?>">
                <span class="dash-stat-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round"><?= $icons[$card['key']] ?? $icons['today'] ?></svg>
                </span>
                <span class="dash-stat-body">
                    <span class="dash-stat-label"><?= $e($card['label']) ?></span>
                    <strong class="dash-stat-value"><?= $e($card['value']) ?></strong>
                    <span class="dash-stat-hint"><?= $e($card['hint']) ?></span>
                </span>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="dash-split">

        <div class="dash-col-main">

            <section class="dash-panel dash-week" aria-labelledby="dash-week-title">
                <header class="dash-panel-head">
                    <div>
                        <p class="dash-kicker">Weekly timetable</p>
                        <h2 id="dash-week-title"><?= $e($week['label']) ?></h2>
                    </div>
                    <div class="dash-panel-tools">
                        <span class="dash-chip"><?= $e($week['total']) ?> sessions</span>
                        <?php if ($week['peak'] !== null && $week['peak']['count'] > 0): ?>
                            <span class="dash-chip is-quiet">Busiest <?= $e($week['peak']['short']) ?> · <?= $e($week['peak']['count']) ?></span>
                        <?php endif; ?>
                        <?php if ($can('schedules.view')): ?>
                            <a class="dash-link" href="<?= $e($route('calendar.index')) ?>">Open calendar</a>
                        <?php endif; ?>
                    </div>
                </header>

                <?php if ($departments !== []): ?>
                    <ul class="dash-legend" aria-label="Department colours">
                        <?php foreach ($departments as $department): ?>
                            <li>
                                <span class="dash-dot" style="--dot: <?= $e($department['color_hex'] !== '' ? $department['color_hex'] : '#8c1d40') ?>" aria-hidden="true"></span>
                                <strong><?= $e($department['department_code']) ?></strong>
                                <span class="dash-legend-name"><?= $e($department['department_name']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="dash-week-scroll">
                    <div class="dash-week-grid" role="table" aria-label="Weekly timetable, colour-coded by department" style="--days: <?= (int) count($week['days']) ?>">
                        <div class="dash-week-row dash-week-head" role="row">
                            <div class="dash-week-time" role="columnheader"><span>Time</span></div>
                            <?php foreach ($week['days'] as $day): ?>
                                <div class="dash-week-day<?= $day['is_today'] ? ' is-today' : '' ?>" role="columnheader">
                                    <span class="dash-week-dow"><?= $e($day['short']) ?></span>
                                    <strong><?= $e($day['date_label']) ?></strong>
                                    <small><?= $e($day['count']) ?> sessions</small>
                                    <?php if ((int) $day['alerts'] > 0): ?>
                                        <span class="dash-week-alert" title="<?= $e($day['alerts']) ?> flags on this day"><?= $e($day['alerts']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($week['bands'] === []): ?>
                            <p class="dash-empty">
                                No meeting is scheduled in this term yet, so there is nothing to draw in the grid.
                                <?php if ($can('schedules.create')): ?>
                                    <a href="<?= $e($route('schedules.create')) ?>">Add the first class</a> to start the week.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <?php foreach ($week['bands'] as $band): ?>
                            <div class="dash-week-row" role="row">
                                <div class="dash-week-time" role="rowheader">
                                    <strong><?= $e($band['start']) ?></strong>
                                    <small><?= $e($band['end']) ?></small>
                                </div>
                                <?php foreach ($week['days'] as $day): ?>
                                    <div class="dash-week-cell" role="cell">
                                        <?php foreach ($week['cells'][$day['index']][$band['key']] ?? [] as $session): ?>
                                            <a class="dash-session<?= $session['flag'] !== null ? ' is-' . $e($session['flag']) : '' ?>"
                                               style="--dept: <?= $e($session['color']) ?>"
                                               href="<?= $e($route('schedules.show', ['id' => $session['id']])) ?>"
                                               aria-label="<?= $e($session['code'] . ' ' . $session['title'] . ', ' . $session['section'] . ', ' . $session['room'] . ' ' . $session['building'] . ', ' . $session['faculty'] . ', ' . $session['start'] . ' to ' . $session['end']) ?>">
                                                <span class="dash-session-top">
                                                    <span class="dash-session-code"><?= $e($session['code']) ?></span>
                                                    <?php if ($session['flag'] === 'danger'): ?>
                                                        <span class="dash-session-flag" title="Clash or capacity problem">!</span>
                                                    <?php elseif ($session['flag'] === 'warning'): ?>
                                                        <span class="dash-session-flag is-warning" title="Room nearly full">!</span>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="dash-session-meta"><?= $e($session['room']) ?> · <?= $e($session['building']) ?></span>
                                                <span class="dash-session-meta"><?= $e($session['section']) ?> · <?= $e($session['faculty_last']) ?></span>
                                                <?php if ($session['fill'] !== null && $session['fill'] >= 95): ?>
                                                    <span class="dash-session-fill"><?= $e($session['fill']) ?>% full</span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="dash-panel dash-board" aria-labelledby="dash-board-title">
                <header class="dash-panel-head">
                    <div>
                        <p class="dash-kicker">Planning board</p>
                        <h2 id="dash-board-title">Term pipeline</h2>
                    </div>
                    <div class="dash-panel-tools">
                        <span class="dash-chip"><?= $e($board['total']) ?> scheduled</span>
                        <?php if ($can('schedules.view')): ?>
                            <a class="dash-link" href="<?= $e($route('schedules.index')) ?>">All classes</a>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="dash-board-cols">
                    <?php foreach ($board['columns'] as $column): ?>
                        <article class="dash-board-col is-<?= $e($column['tone']) ?>">
                            <header class="dash-board-col-head">
                                <span class="dash-board-dot" aria-hidden="true"></span>
                                <h3><?= $e($column['label']) ?></h3>
                                <span class="dash-board-count"><?= $e($column['count']) ?></span>
                            </header>

                            <div class="dash-board-cards">
                                <?php if ($column['cards'] === []): ?>
                                    <p class="dash-board-empty"><?= $e($column['empty']) ?></p>
                                <?php endif; ?>

                                <?php foreach ($column['cards'] as $card): ?>
                                    <article class="dash-task" style="--dept: <?= $e($card['color']) ?>">
                                        <span class="dash-task-top">
                                            <span class="dash-task-code"><?= $e($card['code']) ?></span>
                                            <?php if ($card['badge'] !== null): ?>
                                                <span class="dash-task-badge"><?= $e($card['badge']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <strong class="dash-task-title"><?= $e($card['title']) ?></strong>
                                        <span class="dash-task-meta"><?= $e($card['meta']) ?></span>
                                    </article>
                                <?php endforeach; ?>

                                <?php if ((int) $column['more'] > 0): ?>
                                    <span class="dash-board-more">+ <?= $e($column['more']) ?> more</span>
                                <?php endif; ?>
                            </div>

                            <footer class="dash-board-foot">
                                <?php if ($column['key'] === 'to_schedule' && $can('subjects.view')): ?>
                                    <a href="<?= $e($route('master.subjects.index')) ?>">Open catalogue</a>
                                <?php elseif ($column['key'] !== 'to_schedule' && $can('schedules.view')): ?>
                                    <a href="<?= $e($route('schedules.index')) ?>">Open schedule</a>
                                <?php endif; ?>
                            </footer>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <aside class="dash-col-side" aria-label="Timetable health and utilisation">

            <section class="dash-panel dash-health<?= $alerts['total'] > 0 ? ' has-flags' : ' is-clear' ?>" aria-labelledby="dash-health-title">
                <header class="dash-panel-head">
                    <div>
                        <p class="dash-kicker">Conflict watch</p>
                        <h2 id="dash-health-title">Timetable health</h2>
                    </div>
                    <span class="dash-health-pill<?= $alerts['danger'] > 0 ? ' is-danger' : ($alerts['warning'] > 0 ? ' is-warning' : '') ?>">
                        <?= $alerts['total'] === 0 ? 'All clear' : $e($alerts['danger']) . ' blocking' ?>
                    </span>
                </header>

                <ul class="dash-checks">
                    <?php foreach ($alerts['checks'] as $check): ?>
                        <li class="<?= (int) $check['count'] > 0 ? 'is-issue' : 'is-clear' ?>">
                            <span class="dash-check-mark" aria-hidden="true"><?= (int) $check['count'] > 0 ? '!' : '✓' ?></span>
                            <span class="dash-check-label"><?= $e($check['label']) ?></span>
                            <strong><?= $e($check['count']) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($alerts['items'] !== []): ?>
                    <ul class="dash-flag-list">
                        <?php foreach ($alerts['items'] as $flag): ?>
                            <li class="dash-flag is-<?= $e($flag['severity']) ?>">
                                <span class="dash-flag-time"><?= $e($flag['when']) ?></span>
                                <strong><?= $e($flag['title']) ?></strong>
                                <span><?= $e($flag['detail']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="dash-health-note">
                        Every meeting in this term has its own room and teacher, and no section is larger than
                        its room. The weekly grid would show a red outline the moment a clash appeared.
                    </p>
                <?php endif; ?>

                <?php if ($can('reports.view')): ?>
                    <p class="dash-panel-foot">
                        <a class="dash-link" href="<?= $e($route('reports.conflicts', $termId > 0 ? ['semester' => $termId] : [])) ?>">
                            Conflict report
                        </a>
                        <span class="dash-muted"><?= $e($conflicts['open']) ?> open in the conflict log</span>
                    </p>
                <?php endif; ?>
            </section>

            <section class="dash-panel dash-util" aria-labelledby="dash-util-title">
                <header class="dash-panel-head">
                    <div>
                        <p class="dash-kicker">Utilisation</p>
                        <h2 id="dash-util-title">Room occupancy</h2>
                    </div>
                    <span class="dash-chip is-quiet"><?= $e($utilization['rooms']) ?> rooms</span>
                </header>

                <div class="dash-gauge-row">
                    <div class="dash-gauge" style="--value: <?= $e(min(100, max(0, $utilization['overall']))) ?>">
                        <span class="dash-gauge-face">
                            <strong><?= $e($utilization['overall']) ?>%</strong>
                            <small>bookable time</small>
                        </span>
                    </div>
                    <dl class="dash-facts">
                        <div>
                            <dt>Booked</dt>
                            <dd><?= $e(number_format($utilization['booked_hours'])) ?> h</dd>
                        </div>
                        <div>
                            <dt>Seat fill</dt>
                            <dd><?= $e($occupancy['percent']) ?>%</dd>
                        </div>
                        <div>
                            <dt>Meetings</dt>
                            <dd><?= $e($occupancy['sessions']) ?></dd>
                        </div>
                    </dl>
                </div>

                <?php if ($buildings !== []): ?>
                    <ul class="dash-bars" aria-label="Utilisation by building">
                        <?php foreach ($buildings as $building): ?>
                            <li>
                                <span class="dash-bar-label">
                                    <strong><?= $e($building['code']) ?></strong>
                                    <span><?= $e($building['name']) ?></span>
                                </span>
                                <span class="dash-bar-track" aria-hidden="true">
                                    <span class="dash-bar-fill is-<?= $e($building['band']) ?>" style="width: <?= $e(min(100, max(0, $building['utilization']))) ?>%"></span>
                                </span>
                                <span class="dash-bar-value"><?= $e($building['utilization']) ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="dash-chart" data-dashboard-endpoint="<?= $e($chart_endpoint) ?>">
                    <p class="dash-chart-title">Meetings by department</p>
                    <div class="dash-chart-canvas">
                        <canvas id="chart-departments" data-chart-key="departments" role="img"
                                aria-label="Bar chart of meetings per department in the selected term"></canvas>
                    </div>
                    <p class="dash-chart-status hint" data-dashboard-status role="status">Loading chart…</p>
                </div>
            </section>

            <section class="dash-panel dash-today" aria-labelledby="dash-today-title">
                <header class="dash-panel-head">
                    <div>
                        <p class="dash-kicker">Today</p>
                        <h2 id="dash-today-title"><?= $e($today['label']) ?></h2>
                    </div>
                    <span class="dash-chip"><?= $e($today['count']) ?> · <?= $e($today['hours']) ?> h</span>
                </header>

                <?php if ($today['classes'] === []): ?>
                    <p class="dash-empty is-inline">
                        <?= $today['is_teaching_day']
                            ? 'Nothing is scheduled for today in your scope.'
                            : 'It is a weekend — no weekday meetings are scheduled.' ?>
                    </p>
                <?php else: ?>
                    <ol class="dash-agenda">
                        <?php foreach ($today['classes'] as $class): ?>
                            <li class="dash-agenda-item" style="--dept: <?= $e($class['color_hex'] !== '' ? $class['color_hex'] : '#8c1d40') ?>">
                                <span class="dash-agenda-time"><?= $e($class['start_time']) ?><small><?= $e($class['end_time']) ?></small></span>
                                <span class="dash-agenda-body">
                                    <strong><?= $e($class['subject_code']) ?></strong>
                                    <span><?= $e($class['subject_title']) ?></span>
                                    <small><?= $e($class['room_code']) ?><?php if (!$isFaculty): ?> · <?= $e($class['faculty_name']) ?><?php endif; ?></small>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <?php if ($upcoming !== []): ?>
                    <p class="dash-next">
                        <span class="dash-next-label">Next up</span>
                        <strong><?= $e($upcoming[0]['subject_code']) ?></strong>
                        <?= $e($upcoming[0]['day_label']) ?> · <?= $e($upcoming[0]['start_time']) ?>
                        · <?= $e($upcoming[0]['room_code']) ?>
                    </p>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>

<?php // Vendored locally: the CSP is script-src 'self', so a CDN build would be blocked. ?>
<script src="<?= $e($asset('js/vendor/chart.umd.min.js')) ?>" defer></script>
<script src="<?= $e($asset('js/dashboard.js')) ?>" defer></script>
