<?php

declare(strict_types=1);

/** @var callable(mixed): string $e */
/** @var callable(string, array<string, mixed>): string $route */
/** @var array $current_semester */
/** @var array $faculty */
/** @var array $rooms */
/** @var array $query */
/** @var callable(string): string $asset */

$csrfMeta = $csrf_meta;
$e = $e;
$route = $route;
$current_semester = $current_semester;
$faculty = $faculty;
$rooms = $rooms;
$query = $query;
$today = $today ?? null;
$asset = $asset;

// The day's calendar cells. This is a single horizontal strip of 24 × 30
// minute cells; the drag stub reuses the same data-date/entry shape as the
// week view so the day's rows can be copied into the timetableView without
// any re-derivation.
?>
<div class="cal-day-wrap">
    <div class="cal-day-grid">
        <div class="cal-day-head">
            <span class="cal-day-name"><?= $e('Today') ?></span>
            <span class="cal-day-date"><?= $e(date('l, M d')) ?></span>
        </div>
        <div class="cal-time-column">
            <?php for ($h = 0; $h < 24; $h++): ?>
                <div class="cal-time-slot">
                    <span class="cal-time-label"><?= $e(sprintf('%02d:00', $h)) ?></span>
                    <div class="cal-cell-small" data-date="<?= $e(date('Y-m-d')) ?>"></div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>
