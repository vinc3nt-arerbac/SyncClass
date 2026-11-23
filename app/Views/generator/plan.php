<?php

declare(strict_types=1);

/**
 * Timetable generator — the planning form (GEN-01…06).
 *
 * @var callable(mixed): string $e
 * @var string $csrf_meta
 * @var array<string, mixed> $auth_user
 * @var array<int, array<string, mixed>> $semesters
 * @var array<string, string> $query
 * @var callable(string): string $route
 * @var callable(string): string $asset
 */

$csrf_meta = $csrf_meta;
$e = $e;
$route = $route;
$semesters = $semesters;
$auth_user = $auth_user;
$query = $query;
$asset = $asset;
$post = $_POST ?? [];
?>

<?php require __DIR__ . '/../master/_nav.php'; ?>

<section class="hero">
    <p class="eyebrow">Timetabling · Generator</p>
    <h1>Timetable generator</h1>
    <p class="lede">
        Deterministic constraint solving (GEN-01). The generator expands each
        section's curriculum into weekly contact hours, matches eligible
        faculty and rooms, then searches a bounded search space with
        backtracking and conflict-directed backjumping. The result is a
        conflict-free draft — nothing is written until you commit.
    </p>

    <?php if (!empty($errors ?? [])): ?>
        <div class="alert alert-danger">
            <?= $e(implode(' ', $errors)) ?>
        </div>
    <?php endif; ?>

    <section class="card">
        <header class="card-head">
            <h2>Run the generator</h2>
            <span class="tag status-draft">draft</span>
        </header>

        <form method="post" action="<?= $e($route('generator.preview')) ?>">
            <?= $csrf_meta ?>

            <div class="form-floating">
                <select name="semester_id" class="form-select" id="semester" required>
                    <option value="">Select the term to schedule</option>
                    <?php foreach ($semesters as $s): ?>
                        <option value="<?= $e($s['id']) ?>" <?= ($query['semester_id'] ?? '') === (string) $s['id'] ? 'selected' : '' ?>>
                            <?= $e($s['code'] . ' · ' . $s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label>Term</label>
            </div>

            <div class="form-floating">
                <select name="department_ids" class="form-select" id="department" multiple>
                    <?php foreach ($this->departments ?: [] as $d): ?>
                        <option value="<?= $e($d['id']) ?>" <?= in_array((string) $d['id'], ($query['department_ids'] ?? [])) ? 'selected' : '' ?>>
                            <?= $e($d['code'] . ' · ' . $d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label>Departments</label>
            </div>

            <div class="form-floating">
                <select name="subject_ids" class="form-select" id="subject" multiple>
                    <?php foreach ($this->subjects ?: [] as $s): ?>
                        <option value="<?= $e($s['id']) ?>" <?= in_array((string) $s['id'], ($query['subject_ids'] ?? [])) ? 'selected' : '' ?>>
                            <?= $e($s['code'] . ' · ' . $s['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label>Subjects</label>
            </div>

            <div class="row">
                <div class="col">
                    <button class="btn btn-primary" type="submit">Run generator</button>
                </div>
                <div class="col-auto">
                    <a class="btn btn-outline-secondary" href="<?= $e($route('generator.plan')) ?>">Reset</a>
                </div>
            </div>
        </form>
    </section>

    <section class="card">
        <header class="card-head">
            <h2>How the generator works (GEN-05)</h2>
        </header>
        <ul class="bulleted">
            <li><strong>Expansion</strong> — each section's curriculum becomes (section, subject, weekly hours).</li>
            <li><strong>Candidate space</strong> — eligible faculty (department, remaining load), eligible rooms (type, capacity), legal day/hour blocks (allowed hours minus breaks).</li>
            <li><strong>Ordering</strong> — most constrained first (MRV), tie-broken by largest section.</li>
            <li><strong>Search</strong> — backtracking with forward checking and conflict-directed backjumping.</li>
            <li><strong>Scoring</strong> — minimise unused room-hours → balance faculty load.</li>
            <li><strong>Commit</strong> — the run is one transaction; it is written as `draft` and audited.</li>
        </ul>
    </section>

    <script src="<?= $e($asset('js/generator.js')) ?>" defer></script>
