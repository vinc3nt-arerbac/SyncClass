<?php

/**
 * Room utilization report (RPT-02).
 *
 * The denominator comes from the term itself: weekdays between the term's
 * start and end multiplied by the policy window in config/schedule.php minus
 * its breaks. That is why the available hours differ between terms.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var array{semesters: array<int, array<string, mixed>>, semester: array<string, mixed>|null, term: array{start: string, end: string}|null} $context
 * @var array<int, array<string, mixed>> $rows
 * @var array<int, array<string, mixed>> $buildings
 * @var array{rooms: int, booked_hours: float, available_hours: float, utilization: float, meetings: int, low: int, high: int} $totals
 * @var array{business_days: int, daily_hours: float, weeks: int} $availability
 * @var string $term_name
 * @var string $scope_label
 * @var array<string, mixed>|null $semester
 */

$semester = $context['semester'] ?? null;
$selectedId = $semester === null ? 0 : (int) $semester['id'];
?>
<?php require __DIR__ . '/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Reports · RPT-02</p>
    <h1>Room utilization</h1>
    <p class="lede">
        Booked hours against bookable hours for <strong><?= $e($term_name) ?></strong>.
        <?php if ($availability['business_days'] > 0): ?>
            <?= $e($availability['business_days']) ?> weekdays × <?= $e($availability['daily_hours']) ?> usable hours
            (policy window minus breaks) = the denominator for every room. Weekly meetings are counted once for
            each of the term's <?= $e($availability['weeks']) ?> teaching weeks; one-off meetings once.
        <?php else: ?>
            The selected term has no bookable weekdays, so utilization is 0 %.
        <?php endif; ?>
    </p>
    <ul class="badges">
        <li>Scope: <?= $e($scope_label) ?></li>
        <li>Overall: <?= $e($totals['utilization']) ?>% of bookable time</li>
        <li><?= $e($totals['booked_hours']) ?> booked · <?= $e($totals['available_hours']) ?> available hours</li>
        <li><?= $e($totals['low']) ?> low · <?= $e($totals['high']) ?> near capacity</li>
    </ul>
</section>

<section class="card">
    <header class="card-head">
        <h2>Per room</h2>
        <button type="button" class="button button-ghost" onclick="window.print()">Print / save as PDF</button>
    </header>

    <form class="filters no-print" method="get" action="<?= $e($route('reports.rooms')) ?>">
        <label>
            <span>Semester</span>
            <select name="semester">
                <?php foreach ($context['semesters'] as $option): ?>
                    <option value="<?= $e($option['id']) ?>" <?= (int) $option['id'] === $selectedId ? 'selected' : '' ?>>
                        <?= $e($option['code']) ?> — <?= $e($option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button">Show</button>
    </form>

    <?php if ($rows === []): ?>
        <p class="hint">No active room is visible for this term and scope.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Room</th>
                    <th scope="col">Building</th>
                    <th scope="col">Type</th>
                    <th scope="col">Capacity</th>
                    <th scope="col">Meetings</th>
                    <th scope="col">Booked h</th>
                    <th scope="col">Available h</th>
                    <th scope="col">Utilization</th>
                    <th scope="col">Band</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= $e($row['room_code']) ?></strong><?php if (($row['room_name'] ?? '') !== '' && $row['room_name'] !== null): ?><br><span class="muted"><?= $e($row['room_name']) ?></span><?php endif; ?></td>
                        <td><?= $e($row['building_code']) ?></td>
                        <td><?= $e(ucfirst((string) $row['room_type'])) ?></td>
                        <td><?= $e($row['capacity']) ?></td>
                        <td><?= $e($row['meetings']) ?></td>
                        <td><?= $e($row['booked_hours']) ?></td>
                        <td><?= $e($row['available_hours']) ?></td>
                        <td><?= $e($row['utilization']) ?>%</td>
                        <td><span class="tag band-<?= $e($row['band']) ?>"><?= $e(ucfirst((string) $row['band'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th scope="row" colspan="4"><?= $e($totals['rooms']) ?> rooms</th>
                    <td><?= $e($totals['meetings']) ?></td>
                    <td><?= $e($totals['booked_hours']) ?></td>
                    <td><?= $e($totals['available_hours']) ?></td>
                    <td colspan="2"><strong><?= $e($totals['utilization']) ?>%</strong></td>
                </tr>
                </tfoot>
            </table>
        </div>

        <h3>Per building</h3>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th scope="col">Building</th>
                    <th scope="col">Rooms</th>
                    <th scope="col">Meetings</th>
                    <th scope="col">Booked h</th>
                    <th scope="col">Available h</th>
                    <th scope="col">Utilization</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($buildings as $building): ?>
                    <tr>
                        <td><strong><?= $e($building['building_code']) ?></strong><br><span class="muted"><?= $e($building['building_name']) ?></span></td>
                        <td><?= $e($building['rooms']) ?></td>
                        <td><?= $e($building['meetings']) ?></td>
                        <td><?= $e($building['booked_hours']) ?></td>
                        <td><?= $e($building['available_hours']) ?></td>
                        <td><?= $e($building['utilization']) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="hint">
            Bands: under 40 % is low, 40–75 % is balanced, 75 % and above is close to saturated. A room booked on a
            Saturday still counts its hours, so it can exceed 100 % of its weekday capacity.
        </p>
    <?php endif; ?>
</section>
