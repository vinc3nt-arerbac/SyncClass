<?php

/**
 * Reports hub (RPT-01…RPT-05).
 *
 * One card per report with the term it will render. The four numbers come from
 * ReportRepository::termSummary(), already scoped: an administrator sees the
 * institution, a dean their department, a teacher their own classes.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var array{semesters: array<int, array<string, mixed>>, semester: array<string, mixed>|null, term: array{start: string, end: string}|null} $context
 * @var array{meetings: int, teachers: int, rooms: int, open_conflicts: int} $summary
 * @var string $scope_label
 * @var array<string, mixed>|null $semester
 */

$cards = [
    [
        'route' => 'reports.faculty-load',
        'title' => 'Faculty load',
        'reference' => 'RPT-01',
        'body' => 'Units and contact hours per teacher against the contracted cap, with under- and over-loaded teachers flagged.',
    ],
    [
        'route' => 'reports.rooms',
        'title' => 'Room utilization',
        'reference' => 'RPT-02',
        'body' => 'Booked hours against the term\'s bookable hours, per room and per building, using the same policy window the scheduler obeys.',
    ],
    [
        'route' => 'reports.departments',
        'title' => 'Department schedule',
        'reference' => 'RPT-03',
        'body' => 'Meetings, hours, subjects, sections, teachers and rooms per department for the selected term.',
    ],
    [
        'route' => 'reports.conflicts',
        'title' => 'Conflict report',
        'reference' => 'RPT-04',
        'body' => 'The persisted conflict log: open, resolved and ignored, with the rule, severity and the affected meeting.',
    ],
    [
        'route' => 'reports.timetable',
        'title' => 'Weekly & semester timetable',
        'reference' => 'RPT-03 / RPT-05',
        'body' => 'A printable week board and the full semester listing. Filter to one teacher for their own schedule.',
    ],
];
?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Reports · Read-only</p>
    <h1>Reports</h1>
    <p class="lede">
        Every report is scoped to what you may see and rendered from the live timetable: no snapshot, no
        cached copy. Choose a term here, then open a report — the term carries over.
    </p>
    <ul class="badges">
        <li>Scope: <?= $e($scope_label) ?></li>
        <?php if ($semester !== null): ?>
            <li>Term: <?= $e($semester['code']) ?></li>
            <li><?= $e(substr((string) $semester['start_date'], 0, 10)) ?> → <?= $e(substr((string) $semester['end_date'], 0, 10)) ?></li>
        <?php else: ?>
            <li>No term available</li>
        <?php endif; ?>
    </ul>
</section>

<section class="card">
    <header class="card-head">
        <h2>Selected term</h2>
    </header>

    <?php if ($context['semesters'] === []): ?>
        <p class="hint">No academic term exists yet. Create one under Master data → Semesters first.</p>
    <?php else: ?>
        <form class="filters" method="get" action="<?= $e($route('reports.index')) ?>">
            <label>
                <span>Semester</span>
                <select name="semester">
                    <?php foreach ($context['semesters'] as $option): ?>
                        <option value="<?= $e($option['id']) ?>" <?= $semester !== null && (int) $semester['id'] === (int) $option['id'] ? 'selected' : '' ?>>
                            <?= $e($option['code']) ?> — <?= $e($option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button">Use this term</button>
        </form>

        <div class="facts">
            <div>
                <dt>Meetings</dt>
                <dd><?= $e($summary['meetings']) ?></dd>
            </div>
            <div>
                <dt>Teachers scheduled</dt>
                <dd><?= $e($summary['teachers']) ?></dd>
            </div>
            <div>
                <dt>Rooms in use</dt>
                <dd><?= $e($summary['rooms']) ?></dd>
            </div>
            <div>
                <dt>Open conflicts</dt>
                <dd><?= $e($summary['open_conflicts']) ?></dd>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="grid">
    <?php foreach ($cards as $card): ?>
        <article class="card">
            <header class="card-head">
                <h2><?= $e($card['title']) ?></h2>
                <span class="tag"><?= $e($card['reference']) ?></span>
            </header>
            <p class="muted"><?= $e($card['body']) ?></p>
            <p class="actions">
                <a class="button" href="<?= $e($route($card['route'], $semester !== null ? ['semester' => $semester['id']] : [])) ?>">
                    Open report
                </a>
            </p>
        </article>
    <?php endforeach; ?>
</section>
