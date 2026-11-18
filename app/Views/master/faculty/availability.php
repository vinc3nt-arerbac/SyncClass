<?php

/**
 * Faculty availability windows (MD-05, CNF-07).
 *
 * Windows are half-open intervals per weekday. `Unavailable` rows carve a
 * break out of a wider available range; the scheduler reads both kinds when it
 * checks availability violations.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed> $profile
 * @var array<int, array<string, mixed>> $windows
 * @var array<int, string> $types
 * @var string|null $notice
 */

$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$dayLabel = static fn (int $day): string => $days[$day] ?? (string) $day;
$shortTime = static fn (string $time): string => substr($time, 0, 5);
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Faculty availability</p>
    <h1><?= $e($profile['first_name'] . ' ' . $profile['last_name']) ?></h1>
    <p class="lede">
        Availability is a filter, not a promise: the generator places classes inside <code>available</code> /
        <code>preferred</code> windows and outside <code>unavailable</code> ones.
    </p>
</section>

<?php require __DIR__ . '/../_notice.php'; ?>

<div class="grid">
    <section class="card">
        <header class="card-head">
            <h2>Current windows</h2>
            <span class="tag"><?= $e(count($windows)) ?> window<?= count($windows) === 1 ? '' : 's' ?></span>
        </header>

        <?php if ($windows === []): ?>
            <p class="hint">No windows yet. Without one, the scheduler treats this faculty member as available during institution hours.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th scope="col">Day</th>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                        <th scope="col">Type</th>
                        <th scope="col">Note</th>
                        <th scope="col"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($windows as $window): ?>
                        <tr>
                            <td><?= $e($dayLabel((int) $window['day_of_week'])) ?></td>
                            <td><?= $e($shortTime((string) $window['start_time'])) ?></td>
                            <td><?= $e($shortTime((string) $window['end_time'])) ?></td>
                            <td><span class="tag"><?= $e(ucfirst((string) $window['availability_type'])) ?></span></td>
                            <td class="muted"><?= $e($window['note'] ?? '—') ?></td>
                            <td>
                                <form class="inline-form" method="post"
                                      action="<?= $e($route('master.faculty.availability.destroy', ['id' => $profile['id'], 'windowId' => $window['id']])) ?>">
                                    <?= $csrf_field() ?>
                                    <button type="submit" class="link-button">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <header class="card-head"><h2>Add a window</h2></header>

        <form method="post" action="<?= $e($route('master.faculty.availability.store', ['id' => $profile['id']])) ?>">
            <?= $csrf_field() ?>

            <div class="form-grid">
                <label class="field">
                    <span>Day</span>
                    <select name="day_of_week" required>
                        <?php foreach ($days as $number => $label): ?>
                            <option value="<?= $e($number) ?>"><?= $e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>From</span>
                    <input type="time" name="start_time" value="08:00" required>
                </label>
                <label class="field">
                    <span>To</span>
                    <input type="time" name="end_time" value="12:00" required>
                </label>
                <label class="field">
                    <span>Type</span>
                    <select name="availability_type" required>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= $e($type) ?>"><?= $e(ucfirst($type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field" style="grid-column: 1 / -1">
                    <span>Note</span>
                    <input name="note" maxlength="255" placeholder="Graduate class, consultation hour…">
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="button">Add window</button>
                <a class="button button-ghost" href="<?= $e($route('master.faculty.index')) ?>">Back to faculty</a>
            </div>
        </form>
    </section>
</div>
