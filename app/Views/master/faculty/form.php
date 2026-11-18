<?php

/**
 * Faculty profile create/edit form (MD-05).
 *
 * On create the profile must be attached to an existing faculty account; the
 * candidate list is scoped and excludes accounts that already have a profile.
 * On edit the account is fixed (a profile is not transferable) and only the
 * teaching data changes.
 *
 * @var callable(mixed): string $e
 * @var callable(string, array<string, mixed>): string $route
 * @var callable(): string $csrf_field
 * @var array<string, mixed>|null $profile
 * @var array<int, array{id: int, code: string, name: string}> $departments
 * @var array<int, string> $employment_types
 * @var array<int, array{id: int, name: string, email: string}> $candidates
 * @var bool $can_edit
 * @var bool $can_availability
 */

$editing = $profile !== null;
$action = $editing
    ? $route('master.faculty.update', ['id' => $profile['id']])
    : $route('master.faculty.store');

$value = static fn (string $field, string $default = ''): string => (string) ($profile[$field] ?? $default);
$disabled = $can_edit ? '' : 'disabled';
$employmentLabel = static fn (string $type): string => ucwords(str_replace('_', ' ', $type));
?>
<?php require __DIR__ . '/../_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Master data · Faculty</p>
    <h1><?= $e($editing ? 'Edit ' . $profile['first_name'] . ' ' . $profile['last_name'] : 'New faculty profile') ?></h1>
    <p class="lede">
        Maximum load is the ceiling the conflict engine checks against when a subject is assigned; the
        scheduler will not exceed it without an explicit override.
    </p>
</section>

<section class="card">
    <header class="card-head">
        <h2>Profile</h2>
        <?php if ($editing && $can_availability): ?>
            <a class="button button-ghost" href="<?= $e($route('master.faculty.availability', ['id' => $profile['id']])) ?>">
                Availability windows
            </a>
        <?php endif; ?>
    </header>

    <form method="post" action="<?= $e($action) ?>">
        <?= $csrf_field() ?>

        <div class="form-grid">
            <?php if ($editing): ?>
                <label class="field">
                    <span>Faculty account</span>
                    <input value="<?= $e($profile['first_name'] . ' ' . $profile['last_name'] . ' · ' . $profile['email']) ?>" disabled>
                </label>
            <?php else: ?>
                <label class="field">
                    <span>Faculty account</span>
                    <select name="faculty_id" required <?= $disabled ?>>
                        <option value="">Choose an eligible account…</option>
                        <?php foreach ($candidates as $candidate): ?>
                            <option value="<?= $e($candidate['id']) ?>">
                                <?= $e($candidate['name']) ?> · <?= $e($candidate['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <label class="field">
                <span>Department</span>
                <select name="department_id" required <?= $disabled ?>>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= $e($department['id']) ?>" <?= (string) $value('department_id') === (string) $department['id'] ? 'selected' : '' ?>>
                            <?= $e($department['code']) ?> · <?= $e($department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Employment type</span>
                <select name="employment_type" required <?= $disabled ?>>
                    <?php foreach ($employment_types as $type): ?>
                        <option value="<?= $e($type) ?>" <?= $value('employment_type', 'full_time') === $type ? 'selected' : '' ?>>
                            <?= $e($employmentLabel($type)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Maximum load (units)</span>
                <input type="number" step="0.5" name="max_load_units" value="<?= $e($value('max_load_units', '18')) ?>"
                       min="1" max="40" required <?= $disabled ?>>
            </label>

            <label class="field">
                <span>Specialisation</span>
                <input name="specialization" value="<?= $e($value('specialization')) ?>" maxlength="180"
                       placeholder="Networks and cybersecurity" <?= $disabled ?>>
            </label>

            <label class="field">
                <span>Office</span>
                <input name="office" value="<?= $e($value('office')) ?>" maxlength="80"
                       placeholder="CAS 305" <?= $disabled ?>>
            </label>
        </div>

        <?php if (!$editing && $candidates === []): ?>
            <p class="hint">
                Every active account with the <code>faculty</code> role already has a profile. Create the user
                account first (Phase 10 adds the user-management screen; <code>bin/create-user.php</code> works today).
            </p>
        <?php endif; ?>

        <div class="form-actions">
            <?php if ($can_edit): ?>
                <button type="submit" class="button" <?= (!$editing && $candidates === []) ? 'disabled' : '' ?>>
                    <?= $editing ? 'Save changes' : 'Create profile' ?>
                </button>
            <?php endif; ?>
            <a class="button button-ghost" href="<?= $e($route('master.faculty.index')) ?>">Cancel</a>
        </div>
    </form>
</section>
