<?php

declare(strict_types=1);

/** @var callable(mixed): string $e */
/** @var callable(string, array<string, mixed>): string $route */
/** @var array<int, array<string, string>> $weekdays */
/** @var array<int, array<string, string>> $months */
/** @var int $year */
/** @var int $month */
/** @var string|null $date */
/** @var array<string, string> $query */
/** @var callable(string): string $asset */

$csrfMeta = $csrf_meta;
$e = $e;
$route = $route;
$year = (int) $year;
$month = (int) $month;

// Build the first-of-month DateTimeImmutable for the calendar's arithmetic.
$monthStart = (new DateTimeImmutable())->setDate($year, $month, 1);
$monthName = $monthStart->format('F Y');

// Weekday of the 1st (0 = Sunday, 6 = Saturday).
$firstWeekday = (int) $monthStart->format('w');
// Weekday of the last day of the month (calendar renders Monday-first).
$lastDay = (int) $monthStart->format('t');
$lastWeekday = (int) $monthStart->format('N') + $lastDay - 1;
$lastWeekday %= 7;
if ($lastWeekday === 0) {
    $lastWeekday = 7;
}

// Days of the week, Monday-first (ISO 8601). config/schedule.php supplies the
// label per day code; the weekday strip already ordered them this way.
$dayNames = [];
foreach ($weekdays as $row) {
    $dayNames[$row['code']] = $row['label'];
}

// Cell content: array of normalised entry rows that share the (week, day)
// the chip pane will paint. The repository's range feed already filtered to
// the term, so this is just re-grouping, never a second query.
$cells = [];
for ($d = 1; $d <= $lastDay; $d++) {
    $cells[$d] = [];
}

// Group entries by calendar date (weekly entries repeat on every day of the
// week, one-off entries carry a meeting_date; filter by that date's week).
// The repository already confined the feed to one term, so no further join is
// needed here.
$feed = $query['events'] ?? [];
foreach ($feed as $entry) {
    $entryDate = $entry['date'] ?? null;
    if ($entryDate === null) {
        continue;
    }

    if (!isset($cells[$entryDate])) {
        $cells[$entryDate] = [];
    }

    $cells[$entryDate][] = $entry;
}

// Build the week rows the template hands the grid tiles. We render each week
// as a `<div class="cal-grid">` with seven `<div class="cal-cell">` named
// after the ISO day number, carrying the `data-date` for the drag-and-drop
// target tiles.
?>
<div class="cal-month-wrap">
    <div class="cal-grid">
        <div class="cal-header">
            <?php foreach ($dayNames as $code => $label): ?>
                <div class="cal-day-name"><?= $e($label) ?></div>
            <?php endforeach; ?>
        </div>

        <?php
        $week = [];
        $row = 1;
        for ($d = 1; $d <= $lastDay; $d++) {
            $cellDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $isToday = $cellDate === $today ?? false;
            $hasEvents = $cells[$d] !== [];

            $week[] = sprintf(
                '<div class="cal-cell%s" data-date="%s" data-entry-count="%d">
                    <span class="cal-day">%d</span>
                    <div class="cal-dots">%s</div>
                </div>',
                $isToday ? ' cal-today' : '',
                $e($cellDate),
                count($cells[$d]),
                $d,
                $hasEvents ? $this->renderDots($cells[$d], $e) : ''
            );

            // A full week = 7 cells, then close the row.
            if (count($week) === 7 || $d === $lastDay) {
                // Pad the tail of the month grid with empty cells so the
                // table always has complete weeks.
                while (count($week) < 7) {
                    $week[] = '<div class="cal-cell cal-empty"></div>';
                }

                echo '<div class="cal-week">' . implode('', $week) . '</div>';
                $week = [];
            }
        }
        ?>
    </div>

    <p class="cal-legend">
        <?= $e($monthName) ?>
    </p>
</div>

<?php
/**
 * Render the department-color dots for one day's entries. Each dot is a
 * 12 × 12 rounded square that the drag-and-drop stub uses as the touch
 * target; FullCalendar replaces it with its own event tile at runtime.
 */
function renderDots(array $entries, callable $e): string
{
    $dots = [];
    foreach ($entries as $entry) {
        $color = $entry['department_color'] ?? '#8c1d40';
        $dots[] = sprintf(
            '<span class="cal-dot" style="background:%s" title="%s"></span>',
            $e($color),
            $e($entry['teacher'] ?? '')
        );
    }

    return implode("\n", $dots);
}
