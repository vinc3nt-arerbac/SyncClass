<?php

/**
 * Dashboard (Phase 16, DASH-01…DASH-03).
 *
 * Server-rendered widgets use the numbers the service already scoped; the four
 * charts are the only client-side data. They read their series from the JSON
 * endpoint named in `data-dashboard-endpoint` (DASH-03: no dataset is written
 * into this template). Every printed value is escaped through $e().
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
 * @var string $chart_endpoint
 * @var string $csrf_meta
 */

$termId = isset($context['semester']['id']) ? (int) $context['semester']['id'] : 0;
$isFaculty = $audience === 'faculty';
?>

<section class="hero dashboard-hero">
    <p class="eyebrow">Dashboard · <?= $e($term_name) ?></p>
    <h1>Welcome back, <?= $e($auth_user['first_name'] ?? 'there') ?>.</h1>
    <p class="lede">
        This is your working day at a glance: what is scheduled now, what comes next, and where the
        timetable is under strain. Every number is read from the live schedule inside your scope.
    </p>
    <ul class="badges">
        <li>Scope: <?= $e($scope_label) ?></li>
        <li>Today: <?= $e($today['label']) ?></li>
        <?php if ($term_dates !== null): ?>
            <li>Term: <?= $e($term_dates) ?></li>
        <?php endif; ?>
        <li><?= $e(count($context['semesters'])) ?> term<?= count($context['semesters']) === 1 ? '' : 's' ?> available</li>
    </ul>
</section>

<section class="stat-grid" aria-label="Key figures">
    <?php foreach ($stats as $stat): ?>
        <article class="stat" data-widget="<?= $e($stat['key']) ?>">
            <span class="stat-label"><?= $e($stat['label']) ?></span>
            <strong class="stat-value"><?= $e($stat['value']) ?></strong>
            <span class="stat-hint"><?= $e($stat['hint']) ?></span>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($context['semesters'] !== []): ?>
    <section class="card">
        <header class="card-head">
            <h2>Selected term</h2>
            <span class="tag"><?= $e($scope_label) ?></span>
        </header>
        <form class="filters" method="get" action="<?= $e($route('home')) ?>">
            <div class="form-floating">
                <select class="form-select" name="semester" id="dashboard-semester" aria-label="Select term">
                    <?php foreach ($context['semesters'] as $term): ?>
                        <option value="<?= $e($term['id']) ?>" <?= (int) $term['id'] === $termId ? 'selected' : '' ?>>
                            <?= $e($term['code'] . ' · ' . $term['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="dashboard-semester">Term</label>
            </div>
            <button class="btn btn-primary" type="submit">Apply</button>
            <?php if ($termId > 0): ?>
                <a class="btn btn-outline-secondary" href="<?= $e($route('reports.index')) ?>?semester=<?= $e($termId) ?>">
                    Open reports for this term
                </a>
            <?php endif; ?>
        </form>
    </section>
<?php endif; ?>

<div class="dashboard-grid">
    <section class="card">
        <header class="card-head">
            <h2>Today · <?= $e($today['label']) ?></h2>
            <span class="tag"><?= $e($today['count']) ?> class<?= $today['count'] === 1 ? '' : 'es' ?> · <?= $e($today['hours']) ?> h</span>
        </header>

        <?php if ($today['count'] === 0): ?>
            <p class="empty">
                <?php if (!$today['is_teaching_day']): ?>
                    It is a weekend — no weekday meetings are scheduled.
                <?php else: ?>
                    Nothing is scheduled for today in your scope.
                <?php endif; ?>
            </p>
        <?php else: ?>
            <ol class="session-list">
                <?php foreach ($today['classes'] as $class): ?>
                    <li class="session" style="border-left-color: <?= $e($class['color_hex'] !== '' ? $class['color_hex'] : '#8c1d40') ?>">
                        <span class="session-time"><?= $e($class['start_time']) ?><small><?= $e($class['end_time']) ?></small></span>
                        <span class="session-body">
                            <strong><?= $e($class['subject_code']) ?></strong>
                            <?= $e($class['subject_title']) ?>
                            <small>
                                <?= $e($class['section_code']) ?> · <?= $e($class['room_code']) ?>
                                <?php if (!$isFaculty): ?> · <?= $e($class['faculty_name']) ?><?php endif; ?>
                                · <?= $e($class['duration']) ?> h
                            </small>
                        </span>
                        <span class="tag"><?= $e($class['department_code']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="card">
        <header class="card-head">
            <h2>Upcoming sessions</h2>
            <span class="tag">Next 7 days</span>
        </header>

        <?php if ($upcoming === []): ?>
            <p class="empty">No further meetings in the next seven days.</p>
        <?php else: ?>
            <ol class="session-list">
                <?php foreach ($upcoming as $item): ?>
                    <li class="session" style="border-left-color: <?= $e($item['color_hex'] !== '' ? $item['color_hex'] : '#c9a227') ?>">
                        <span class="session-time"><?= $e($item['start_time']) ?><small><?= $e($item['day_label']) ?></small></span>
                        <span class="session-body">
                            <strong><?= $e($item['subject_code']) ?></strong>
                            <?= $e($item['subject_title']) ?>
                            <small>
                                <?= $e($item['section_code']) ?> · <?= $e($item['room_code']) ?>
                                <?php if (!$isFaculty): ?> · <?= $e($item['faculty_name']) ?><?php endif; ?>
                            </small>
                        </span>
                        <span class="tag"><?= $e($item['department_code']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>

<div class="dashboard-grid">
    <section class="card">
        <header class="card-head">
            <h2>Room utilization</h2>
            <span class="tag"><?= $e($utilization['overall']) ?>% of bookable time</span>
        </header>
        <p class="muted">
            <?= $e($utilization['booked_hours']) ?> booked of <?= $e($utilization['available_hours']) ?> available hours
            across <?= $e($utilization['rooms']) ?> room<?= $utilization['rooms'] === 1 ? '' : 's' ?>.
        </p>
        <?php if ($utilization['top'] === []): ?>
            <p class="empty">No room bookings in this term.</p>
        <?php else: ?>
            <ul class="meter-list">
                <?php foreach ($utilization['top'] as $room): ?>
                    <li>
                        <span class="meter-label">
                            <?= $e(trim(($room['building_code'] ?? '') . ' ' . ($room['room_code'] ?? ''))) ?>
                            <strong><?= $e($room['utilization']) ?>%</strong>
                        </span>
                        <span class="meter" aria-hidden="true">
                            <span class="meter-fill band-<?= $e($room['band']) ?>" style="width: <?= $e(min(100, (float) $room['utilization'])) ?>%"></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($can('reports.view')): ?>
            <p class="hint"><a href="<?= $e($route('reports.rooms', $termId > 0 ? ['semester' => $termId] : [])) ?>">Room utilization report</a></p>
        <?php endif; ?>
    </section>

    <section class="card">
        <header class="card-head">
            <h2><?= $isFaculty ? 'Your workload' : 'Faculty workload' ?></h2>
            <span class="tag"><?= $e($workload['totals']['units'] ?? 0) ?> units</span>
        </header>
        <?php if ($workload['rows'] === []): ?>
            <p class="empty">No teaching load in this term.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Teacher</th>
                        <th scope="col">Units</th>
                        <th scope="col">Hours</th>
                        <th scope="col">Cap</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($workload['rows'] as $row): ?>
                        <tr>
                            <td>
                                <?= $e(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?>
                                <small class="muted"><?= $e($row['department_code'] ?? '') ?></small>
                            </td>
                            <td><?= $e($row['units']) ?></td>
                            <td><?= $e($row['hours']) ?></td>
                            <td>
                                <span class="status band-<?= $e($row['band']) ?>"><?= $e($row['cap_share']) ?>%</span>
                                <small class="muted">of <?= $e($row['max_load_units']) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($can('reports.view')): ?>
            <p class="hint"><a href="<?= $e($route('reports.faculty-load', $termId > 0 ? ['semester' => $termId] : [])) ?>">Faculty load report</a></p>
        <?php endif; ?>
    </section>
</div>

<div class="dashboard-grid">
    <section class="card">
        <header class="card-head">
            <h2>Active conflicts</h2>
            <span class="tag"><?= $e($conflicts['total']) ?> logged</span>
        </header>
        <ul class="count-list">
            <li><strong><?= $e($conflicts['open']) ?></strong><span>Open</span></li>
            <li><strong><?= $e($conflicts['resolved']) ?></strong><span>Resolved</span></li>
            <li><strong><?= $e($conflicts['ignored']) ?></strong><span>Ignored</span></li>
        </ul>
        <p class="hint">
            <?= $conflicts['open'] === 0 ? 'No open conflict needs attention.' : 'Open conflicts still need a decision.' ?>
            <?php if ($can('reports.view')): ?>
                <a href="<?= $e($route('reports.conflicts', $termId > 0 ? ['semester' => $termId] : [])) ?>">Conflict report</a>
            <?php endif; ?>
        </p>
    </section>

    <section class="card">
        <header class="card-head">
            <h2><?= $audience === 'institution' ? 'Department statistics' : 'Your department' ?></h2>
            <span class="tag"><?= $e(count($departments)) ?> row<?= count($departments) === 1 ? '' : 's' ?></span>
        </header>
        <?php if ($departments === []): ?>
            <p class="empty">No department has meetings in this term.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Department</th>
                        <th scope="col">Meetings</th>
                        <th scope="col">Teachers</th>
                        <th scope="col">Rooms</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($departments as $department): ?>
                        <tr>
                            <td>
                                <span class="dept-dot" style="background: <?= $e($department['color_hex'] ?: '#8c1d40') ?>" aria-hidden="true"></span>
                                <?= $e($department['department_code']) ?>
                                <small class="muted"><?= $e($department['department_name']) ?></small>
                            </td>
                            <td><?= $e($department['meetings']) ?></td>
                            <td><?= $e($department['teachers']) ?></td>
                            <td><?= $e($department['rooms']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($can('reports.view')): ?>
            <p class="hint"><a href="<?= $e($route('reports.departments', $termId > 0 ? ['semester' => $termId] : [])) ?>">Department schedule report</a></p>
        <?php endif; ?>
    </section>
</div>

<section class="card dashboard-charts" data-dashboard-endpoint="<?= $e($chart_endpoint) ?>">
    <header class="card-head">
        <h2>Analytics</h2>
        <span class="tag">Chart.js · live data</span>
    </header>
    <p class="muted">
        The series below are fetched from <code><?= $e($chart_endpoint) ?></code> and plotted in the
        browser. No dataset is written into this page, and the endpoint applies the same scope as the
        widgets above.
    </p>

    <div class="chart-grid">
        <figure class="chart-card">
            <figcaption>Meetings by department</figcaption>
            <canvas id="chart-departments" data-chart-key="departments" role="img" aria-label="Bar chart of meetings by department"></canvas>
        </figure>
        <figure class="chart-card">
            <figcaption>Teaching load against cap</figcaption>
            <canvas id="chart-workload" data-chart-key="workload" role="img" aria-label="Bar chart of teacher units against their load cap"></canvas>
        </figure>
        <figure class="chart-card">
            <figcaption>Room utilization</figcaption>
            <canvas id="chart-utilization" data-chart-key="utilization" role="img" aria-label="Bar chart of room utilization percentages"></canvas>
        </figure>
        <figure class="chart-card">
            <figcaption>Conflicts by status</figcaption>
            <canvas id="chart-conflicts" data-chart-key="conflicts" role="img" aria-label="Doughnut chart of conflicts by status"></canvas>
        </figure>
    </div>

    <p class="hint" data-dashboard-status role="status">Loading charts…</p>
</section>

<?php // Vendored locally: the CSP is script-src 'self', so a CDN build would be blocked. ?>
<script src="<?= $e($asset('js/vendor/chart.umd.min.js')) ?>" defer></script>
<script src="<?= $e($asset('js/dashboard.js')) ?>" defer></script>
