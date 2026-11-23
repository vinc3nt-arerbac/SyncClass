<?php

declare(strict_types=1);

/**
 * Generator excerpt fragment for the calendar drag-drop, plus the preview
 * endpoint. The phase-13 generator is a deterministic solver, not a
 * generative-AI backend: `GET /api/generator/preview` returns a draft in the
 * same envelope as every other API page.
 */
?>

<?php if (!empty($plan['best'] ?? null)): ?>
    <div class="card metrics">
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
                <span class="metric-value"><?= $e($plan['metrics']['demand_satisfied'] ?? 0) ?> / <?= $e($plan['metrics']['demand_total'] ?? 0) ?></span>
                <span class="metric-label">demand satisfied</span>
            </div>
            <div class="metric">
                <span class="metric-value"><?= $e($plan['metrics']['total_hours'] ?? 0) ?></span>
                <span class="metric-label">total hours</span>
            </div>
        </div>
    </div>

    <section class="card">
        <header class="card-head">
            <h2>Draft timetable (week × day)</h2>
        </header>
        <div class="card-body">
            <div class="cal-week-grid">
                <div class="cal-head">
                    <div class="cal-cell cal-day-name">Day</div>
                    <?php foreach (range(1, 7) as $d): ?>
                        <div class="cal-cell cal-day-name"><?= $e($weekdays[$d - 1] ?? $d) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($grid ?: [] as $row): ?>
                    <div class="cal-row">
                        <div class="cal-cell cal-slot-label"><?= $e($row['label']) ?></div>
                        <?php foreach (range(1, 7) as $d): ?>
                            <div class="cal-cell cal-slot"></div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php else: ?>
    <div class="card">
        <p class="text-muted">No draft was produced.</p>
    </div>
<?php endif; ?>
