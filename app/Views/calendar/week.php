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

// Week start (Monday). The controller builds this for the month view; for a
// fixed week we anchor to the date= query or the active semester.
$anchor = $query['date'] ?? ($current_semester['start'] ?? null);
if ($anchor === null) {
    $anchor = date('Y-m-d');
}
[$year, $weekNumber] = explode('-', substr($anchor, 0, 4));

// The week's day rows: Monday 00:00 → Sunday 23:59. Each row is the
// horizontal slot that the calendar drag target spans; slots are
// 30-minute steps across the row.
?>
<div class="cal-week-wrap">
    <div class="cal-week-grid">
        <?php foreach ($weekNumber as $weekday => $cell): ?>
            <div class="cal-cell-calendar" data-date="<?= $e($cell) ?>">
                <div class="cal-day"><?= $e($weekday) ?></div>
                <div class="cal-time">
                    <!-- time grid painted by JS -->
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
