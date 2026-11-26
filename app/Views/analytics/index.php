<?php
/**
 * Advanced Analytics — Room availability timetable.
 *
 * Weekly grid (day × time band) showing which rooms are VACANT at each slot,
 * labelled by building. This is the room-availability counterpart to the
 * day/teacher-centric command-centre dashboard.
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
 * @var array $today
 * @var array $upcoming
 * @var array $stats
 * @var array $conflicts
 * @var array $utilization
 * @var array $workload
 * @var array $departments
 * @var array $week
 * @var array $alerts
 * @var array $occupancy
 * @var array $buildings
 * @var array $board
 * @var array $rooms
 * @var string $chart_endpoint
 * @var string $csrf_meta
 */

$termId = isset($context['semester']['id']) ? (int) $context['semester']['id'] : 0;
$termCode = isset($context['semester']['code']) ? (string) $context['semester']['code'] : 'No term';

// Full active room catalogue grouped by building (used to compute vacancies).
$allRoomsByBuilding = [];
foreach ($rooms['rows'] ?? [] as $row) {
    $allRoomsByBuilding[(string) $row['building_code']][(string) $row['room_code']] = (string) $row['room_name'];
}
ksort($allRoomsByBuilding);
foreach ($allRoomsByBuilding as $bc => &$rs) {
    ksort($rs);
}
unset($rs);

// For each day × band, derive the set of vacant rooms (labelled by building).
$vacantBySlot = [];
$totalRooms = 0;


foreach ($allRoomsByBuilding as $rs) {
    $totalRooms += count($rs);
}
foreach ($week['days'] as $day) {
    foreach ($week['bands'] as $band) {
        $booked = [];
        foreach ($week['cells'][$day['index']][$band['key']] ?? [] as $session) {
            $booked[(string) $session['building'] . '|' . (string) $session['room']] = true;
        }
        $vacant = [];
        foreach ($allRoomsByBuilding as $bCode => $rs) {
            foreach ($rs as $rCode => $rName) {
                if (!isset($booked[$bCode . '|' . $rCode])) {
                    $vacant[] = ['building' => $bCode, 'room' => $rCode, 'name' => $rName];
                }
            }
        }
        $vacantBySlot[$day['index']][$band['key']] = $vacant;
    }
}
?>

<div class="dash">
    <header class="dash-head">
        <div class="dash-head-main">
            <p class="dash-eyebrow">
                <span class="dash-pulse" aria-hidden="true"></span>
                Advanced analytics · Room availability · <?= $e($term_name) ?>
            </p>
            <h1>Weekly vacant-room timetable</h1>
            <p class="dash-sub">
                Each cell lists the rooms that are <strong>free</strong> (not booked)
                at that day and time, labelled by building. Occupied slots are shown
                as a count; click through for the full schedule.
            </p>
            <ul class="dash-badges">
                <li>Scope: <?= $e($scope_label) ?></li>
                <li>Term <?= $e($termCode) ?></li>
                <li><?= $e($totalRooms) ?> rooms tracked</li>
                <?php if ($term_dates !== null): ?>
                    <li><?= $e($term_dates) ?></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="dash-head-side">
            <?php if ($context['semesters'] !== []): ?>
                <form class="dash-term" method="get" action="<?= $e($route('analytics.index')) ?>">
                    <label for="analytics-semester">Term</label>
                    <select class="dash-select" name="semester" id="analytics-semester">
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
                <a class="dash-btn" href="<?= $e($route('home')) ?>">Overview</a>
                <?php if ($can('schedules.view')): ?>
                    <a class="dash-btn" href="<?= $e($route('calendar.index')) ?>">Calendar</a>
                <?php endif; ?>
                <?php if ($can('reports.view')): ?>
                    <a class="dash-btn" href="<?= $e($route('reports.rooms')) ?>">Room report</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <section class="stat-grid dash-stats" aria-label="Key figures">
        <article class="dash-stat" data-widget="rooms">
            <span class="dash-stat-body">
                <span class="dash-stat-label">Rooms tracked</span>
                <strong class="dash-stat-value"><?= $e($totalRooms) ?></strong>
                <span class="dash-stat-hint">Active rooms in scope.</span>
            </span>
        </article>
        <article class="dash-stat" data-widget="buildings">
            <span class="dash-stat-body">
                <span class="dash-stat-label">Buildings</span>
                <strong class="dash-stat-value"><?= $e(count($allRoomsByBuilding)) ?></strong>
                <span class="dash-stat-hint">Buildings with rooms.</span>
            </span>
        </article>
        <article class="dash-stat" data-widget="utilization">
            <span class="dash-stat-body">
                <span class="dash-stat-label">Room utilization</span>
                <strong class="dash-stat-value"><?= $e($utilization['overall']) ?>%</strong>
                <span class="dash-stat-hint"><?= $e(number_format($utilization['booked_hours'])) ?> h booked.</span>
            </span>
        </article>
        <article class="dash-stat" data-widget="occupancy">
            <span class="dash-stat-body">
                <span class="dash-stat-label">Seat occupancy</span>
                <strong class="dash-stat-value"><?= $e($occupancy['percent']) ?>%</strong>
                <span class="dash-stat-hint"><?= $e($occupancy['students']) ?> / <?= $e($occupancy['seats']) ?> seats.</span>
            </span>
        </article>
        <article class="dash-stat" data-widget="conflicts">
            <span class="dash-stat-body">
                <span class="dash-stat-label">Timetable flags</span>
                <strong class="dash-stat-value"><?= $e($alerts['total']) ?></strong>
                <span class="dash-stat-hint"><?= $alerts['total'] === 0 ? 'No clash or capacity warning.' : $e($alerts['danger']) . ' blocking · ' . $e($alerts['warning']) . ' warnings' ?></span>
            </span>
        </article>
        <article class="dash-stat" data-widget="sessions">
            <span class="dash-stat-body">
                <span class="dash-stat-label">Sessions</span>
                <strong class="dash-stat-value"><?= $e($occupancy['sessions']) ?></strong>
                <span class="dash-stat-hint">Meetings this term.</span>
            </span>
        </article>
    </section>

    <?php if ($totalRooms === 0): ?>
        <section class="dash-panel">
            <p class="dash-empty">No active rooms found in scope for this term.</p>
        </section>
    <?php else: ?>
        <section class="dash-panel" aria-label="Vacant room timetable">
            <header class="dash-panel-head">
                <div>
                    <p class="dash-kicker">Availability grid</p>
                    <h2>Vacant rooms by day and time</h2>
                </div>
            </header>

            <?php if ($departments !== []): ?>
                <ul class="dash-dept-legend" aria-label="Department colours">
                    <?php foreach ($departments as $dept): ?>
                        <li>
                            <span class="dash-dept-swatch" style="background: <?= $e($dept['color_hex'] !== '' ? $dept['color_hex'] : '#8c1d40') ?>" aria-hidden="true"></span>
                            <span><?= $e($dept['department_name'] ?? $dept['name'] ?? ($dept['department_code'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="dash-table-wrap">
                <table class="dash-vacant-table">
                    <thead>
                        <tr>
                            <th class="dash-vacant-timecol">Time</th>
                            <?php foreach ($week['days'] as $day): ?>
                                <th><?= $e($day['short']) ?><br><span class="dash-muted"><?= $e($day['name']) ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($week['bands'] as $band): ?>
                            <tr>
                                <th class="dash-vacant-timecol">
                                    <?= $e($band['label']) ?><br>
                                    <span class="dash-muted"><?= $e($band['start']) ?>–<?= $e($band['end']) ?></span>
                                </th>
                                <?php foreach ($week['days'] as $day): ?>
                                    <?php
                                        $vacant = $vacantBySlot[$day['index']][$band['key']] ?? [];
                                        $occupiedCount = count($week['cells'][$day['index']][$band['key']] ?? []);
                                    ?>
                                    <td>
                                        <div class="dash-vacant-cell">
                                            <div class="dash-vacant-count">
                                                <?= $e(count($vacant)) ?> free · <?= $e($occupiedCount) ?> booked
                                            </div>
                                            <ul class="dash-vacant-list">
                                                <?php foreach ($vacant as $v): ?>
                                                    <li class="dash-vacant-room">
                                                        <span class="dash-vacant-bldg"><?= $e($v['building']) ?></span>
                                                        <span class="dash-vacant-roomcode"><?= $e($v['room']) ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                                <?php if ($vacant === []): ?>
                                                    <li class="dash-muted">No free rooms</li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<script src="<?= $e($asset('js/vendor/chart.umd.min.js')) ?>" defer></script>
<script src="<?= $e($asset('js/dashboard.js')) ?>" defer></script>
