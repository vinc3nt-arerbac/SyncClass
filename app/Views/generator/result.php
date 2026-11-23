<?php

declare(strict_types=1);

/**
 * Timetable generator — the run result with metrics and commit (GEN-06).
 *
 * @var callable(mixed): string $e
 * @var string $csrf_meta
 * @var array<string, mixed> $auth_user
 * @var array<string, mixed> $plan
 * @var array<string, string> $query
 * @var callable(string): string $route
 * @var callable(string): string $asset
 * @var array|string|null $notice
 * @var array<int, array<string, mixed>> $semesters
 * @var array<int, array<string, mixed>> $semester
 * @var array<int, array<string, mixed>> $errors

 * Helpers (pure, no $this).
 */
function minutes(string $time): int
{
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h * 60 + $m;
}

function minutesToTime(int $minutes): string
{
    return sprintf('%02d:%02d', (int) floor($minutes / 60), $minutes % 60);
}

$configAllowedStart = '07:00';
$configAllowedEnd = '19:00';
$configSlot = 30;

$csrf_meta = $csrf_meta;
$e = $e;
$route = $route;
$plan = $plan;
$auth_user = $auth_user;
$query = $query;
$asset = $asset;
$notice = $notice;
$semesters = $semesters;
$semester = $semester;
$errors = $errors;

// Day-of-week labels for the grid header.
$dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$weekdays = $dayNames;

// Build the day × time-slot grid from the draft placements.
$grid = [];
$used = [];
if ($plan['best'] !== null) {
    $slots = $plan['best']->placements;
    foreach ($slots as $index => $p) {
        $day = (int) ($p['day'] ?? 1);
        $start = ($p['start'] ?? '09:00');
        $end = ($p['end'] ?? '10:30');

        if (!isset($grid[$day])) {
            $grid[$day] = [];
        }

        $grid[$day][$start] = [
            'day' => $day,
            'start' => $start,
            'end' => $end,
            'room' => $p['room_code'] ?? '—',
            'faculty' => $p['faculty_code'] ?? '—',
            'subject' => $p['subject_code'] ?? '—',
            'status' => 'draft',
        ];

        for ($t = minutes($start); $t < minutes($end) && $t < minutes($configAllowedEnd); $t += $configSlot) {
            $block = minutesToTime($t);
            if (!isset($used[$day . '|' . $block])) {
                $used[$day . '|' . $block] = true;
            }
        }
    }
}

// Weekday names for the grid header.
$weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

?>
<?php require __DIR__ . '/../master/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Timetabling · Generator</p>
    <h1><?= $notice ? 'Committed draft' : 'Generator result' ?></h1>
    <p class="lede">
        <?php if ($notice): ?>
            <?= $e($notice) ?>
        <?php else: ?>
            Draft generated with zero hard conflicts. Click <strong>Commit</strong>
            to insert the placements as `draft` schedules (BR-04), then approve
            them through the normal lifecycle. The seed
            <code><?= $e($query['seed'] ?? 'auto') ?></code> makes this run
            reproducible.
        <?php endif; ?>
    </p>
</section>

<?php if ($plan['best'] !== null): ?>
    <section class="card metrics">
        <header class="card-head">
            <h2>Metrics (GEN-06)</h2>
        </header>
        <div class="metrics-grid">
            <div class="metric">
                <span class="metric-value"><?= $e($plan['metrics']['utilization_percent'] ?? 0) ?>%</span>
                <span class="metric-label">utilization</span>
            </div>
            <div class="metric">
                <span class="metric-value"><?= $e($plan['metrics']['load_balance'] ?? 0) ?>%</span>
                <span class="metric-label">load balance</span>
            </div>
            <div class="metric">
                <span class="metric-value"><?= $e($plan['metrics']['demand_satisfied'] ?? 0) ?></span>
                <span class="metric-label">demand satisfied / total</span>
            </div>
            <div class="metric">
                <span class="metric-value"><?= $e($plan['metrics']['total_hours'] ?? 0) ?></span>
                <span class="metric-label">total hours</span>
            </div>
        </div>

        <?php if (($plan['metrics']['utilization_percent'] ?? 0) > 90): ?>
            <p class="warn">
                Utilization is high; consider moving lower-priority courses
                outside peak hours.
            </p>
        <?php endif; ?>
    </section>

    <section class="card">
        <header class="card-head">
            <h2>Timetable preview (week × day)</h2>
            <div class="card-tools">
                <a class="btn btn-outline-secondary" href="<?= $e($route('generator.preview')) ?>">Redo</a>
                <?php if ($notice === null): ?>
                    <a class="btn btn-primary" href="<?= $e($route('generator.commit')) ?>" data-csrf="<?= $e($csrf_meta) ?>">Commit draft</a>
                <?php endif; ?>
                <a class="btn btn-outline-secondary" href="<?= $e($route('generator.plan')) ?>">New run</a>
            </div>
        </header>

        <div class="card-body">
            <?php if ($notice !== null): ?>
                <p class="text-muted">
                    No draft was produced; nothing to preview.
                    <a href="<?= $e($route('generator.plan')) ?>">Run the generator again</a>.
                </p>
            <?php else: ?>
                <div class="cal-week-grid" id="scheduler-grid">
                    <div class="cal-head">
                        <div class="cal-cell cal-day-name">Date</div>
                        <?php foreach ($weekdays as $day): ?>
                            <div class="cal-cell cal-day-name"><?= $e($day) ?></div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // Render one row per 30-minute block inside the policy
                    // window (07:00–19:00).
                    $row = 1;
                    $t = $this->minutes($this->config->get('allowed_hours.start', '07:00'));
                    $endM = $this->minutes($this->config->get('allowed_hours.end', '19:00'));
                    while ($t < $endM) {
                        $slotStart = $this->minutesToTime($t);
                        $slotEnd = $this->minutesToTime($t + 30);
                        echo '<div class="cal-row">'
                            . '<div class="cal-cell cal-slot-label">' . $e($slotStart) . '</div>';
                        for ($d = 1; $d <= 7; $d++) {
                            $key = $d . '|' . $slotStart;
                            $occupied = isset($grid[$d][$slotStart]) || isset($used[$day . '|' . $slotStart]);
                            echo '<div class="cal-cell cal-slot'
                                . ($occupied ? ' cal-slot-occupied' : '')
                                . '" data-day="' . $d . '" data-start="' . $e($slotStart) . '" data-end="' . $e($slotEnd) . '"></div>';
                        }
                        echo '</div>';
                        $t += 30;
                        $row++;
                    }
                    ?>
                </div>
                <p class="cap">
                    Room = <?= $e($this->room ?? '—') ?> ·
                    Faculty = <?= $e($this->faculty ?? '—') ?>
                </p>
            <?php endif; ?>
        </div>
    </section>
<?php else: ?>
    <section class="card">
        <header class="card-head">
            <h2>No draft</h2>
        </header>
        <?php foreach ($plan['unsat'] ?? [] as $u): ?>
            <div class="alert alert-danger"><?= $e($u['message']) ?></div>
        <?php endforeach; ?>
        <?php if (($plan['error'] ?? null) !== null): ?>
            <div class="alert alert-warning"><?= $e($plan['error']) ?></div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script src="<?= $e($asset('js/generator.js')) ?>" defer></script>
