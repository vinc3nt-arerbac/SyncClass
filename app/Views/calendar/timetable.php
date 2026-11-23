<?php

declare(strict_types=1);

/** @var callable(mixed): string $e */
/** @var callable(string, array<string, mixed>): string $route */
/** @var array $faculty */
/** @var array $rooms */
/** @var array $query */
/** @var callable(string): string $asset */

$csrfMeta = $csrf_meta;
$e = $e;
$route = $route;
$faculty = $faculty;
$rooms = $rooms;
$query = $query;
$today = $today ?? null;

// Timetable grid: rows are rooms, columns are 30-minute slots across the
// term's day window. A cell is painted when the repository range feed
// (already scoped by role and department) contains an entry whose
// `day_of_week` + start/end covers the slot. The range feed is the single
// source of truth — no second query.
?>
<div class="cal-timetable">
    <div class="cal-t-head">
        <div class="cal-t-cell cal-t-room">Room</div>
        <?php for ($h = 0; $h < 48; $h++): ?>
            <div class="cal-t-cell cal-t-slot"><?= $e(sprintf('%02d:%02d', $h >> 1, ($h & 1) ? 30 : 0)) ?></div>
        <?php endfor; ?>
    </div>
    <?php foreach ($rooms as $room): ?>
        <div class="cal-t-row">
            <div class="cal-t-cell cal-t-room"><?= $e($room['code']) ?></div>
            <?php for ($h = 0; $h < 48; $h++): ?>
                <div class="cal-t-cell cal-t-slot" data-room="<?= $e($room['id']) ?>" data-slot="<?= $h ?>"></div>
            <?php endfor; ?>
        </div>
    <?php endforeach; ?>
</div>
