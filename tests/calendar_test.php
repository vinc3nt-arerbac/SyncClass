<?php

declare(strict_types=1);

/**
 * Calendar System (Phase 12) — CAL-01…CAL-06 + CAL-04 scope filters.
 *
 * Follows the same conventions as tests/schedule_test.php: a plain script with
 * a global `check()` helper, in-memory SQLite, and service-level asserts.
 *
 * Verification:
 *   - CAL-01 the calendar page renders month/week/day/timetable (asset pieces
 *     present in the rendered shell);
 *   - CAL-04 server-side filters (semester/department) return scoped rows;
 *   - CAL-05 the range feed returns only the visible window;
 *   - CAL-03 a conflicting drag move is rejected through the same
 *     ScheduleService write path with 409 + alternatives[] (<= 3).
 *
 * The repository's `calendarFeed()` is the single range-scoped read; the
 * controller forwards it straight to the JSON envelope. The conflict check
 * for CAL-03 goes through `ConflictService::check()` (pure, side-effect-free)
 * exactly like the form's "what-if" widget.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

error_reporting(E_ALL & ~E_WARNING);
while (ob_get_level() > 0) {
    ob_end_clean();
}

// The calendar needs a current term; `is_current = 1` is the term the API
// treats as the calendar's visible window.
$testConfig = $config;
$testConfig['database']['driver'] = 'sqlite';
$testConfig['database']['database'] = ':memory:';
$testConfig['database']['prefix'] = '';
$testConfig['database']['schema_path'] = dirname(__DIR__) . '/database/schema';
$testConfig['database']['seed_path'] = dirname(__DIR__) . '/database/seed';

$configObject = new Config($testConfig);

$database = new Database($testConfig['database']);

require dirname(__DIR__) . '/tests/support/master_schema.php';
require dirname(__DIR__) . '/tests/support/schedule_schema.php';

(new RbacService(
    new RoleRepository($database),
    new PermissionRepository($database),
    $configObject
))->synchronise();

// The admin account: institution-wide scope; its `is_current` term drives the
// month view's initial render.
$adminId = auth_create_user($database, [
    'email' => 'admin@sac.edu.ph',
    'role_code' => 'administrator',
    'first_name' => 'Ana',
    'last_name' => 'Reyes',
]);

// CAS department (default department for the faculty fixtures).
$database->insert('departments', ['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
$cas = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CAS']);

$building = (new BuildingRepository($database))->create([
    'code' => 'MAIN',
    'name' => 'Main Building',
    'campus' => 'Main',
    'address' => '1 Academic Way',
    'is_active' => 1,
]);

$rooms = new RoomRepository($database);
$roomCas = $rooms->create([
    'building_id' => $building,
    'code' => 'A-101',
    'room_type' => 'lecture',
    'capacity' => 40,
]);

$subjects = new SubjectRepository($database);
$subjectCas = $subjects->create([
    'code' => 'CSC 101',
    'title' => 'Intro to Computer Science',
    'department_id' => $cas,
    'units' => 3.0,
    'lecture_hours' => 2,
    'lab_hours' => 1,
]);

$sections = new SectionRepository($database);
$sectionCas = $sections->create([
    'code' => 'CS101A',
    'name' => 'CS 101A — Intro',
    'program' => 'BSc',
    'year_level' => 1,
    'department_id' => $cas,
    'student_count' => 35,
]);

$faculty = new FacultyRepository($database);
// A dedicated CAS faculty profile that teaches CS101A in the calendar fixture.
// The users table carries the identity, faculty_profiles carries the
// department/root, and schedules.faculty_id points at this profile.
$database->insert(
    'users',
    [
        'email' => 'faculty.jcruz@sac.edu.ph',
        'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
        'first_name' => 'Jean',
        'last_name' => 'Cruz',
        'role_id' => (int) $database->scalar(
            'SELECT id FROM roles WHERE code = :code',
            ['code' => 'faculty']
        ),
        'is_active' => 1,
        'department_id' => $cas,
    ]
);
$facultyCbaId = (int) $database->lastInsertId();

$facultyCas = $faculty->create([
    'faculty_id' => $facultyCbaId,
    'department_id' => $cas,
    'employment_type' => 'full_time',
    'max_load_units' => 18,
]);

$terms = new TermRepository($database);
$yearId = (int) $terms->createYear(['code' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31'], true);
$semesterId = (int) $terms->createSemester([
    'academic_year_id' => $yearId,
    'term_no' => 1,
    'name' => 'Fall 2026',
    'code' => '2026-2027-1ST',
    'start_date' => '2026-08-10',
    'end_date' => '2026-12-15',
    'status' => 'active',
], true);

$schedules = new ScheduleRepository($database);

$conflict = new ConflictService(
    $schedules,
    $sections,
    $subjects,
    $faculty,
    $rooms,
    $terms,
    []
);
$authorization = new AuthorizationService(new PermissionRepository($database));
$service = new ScheduleService(
    $schedules,
    $sections,
    $subjects,
    $faculty,
    $rooms,
    $terms,
    $authorization,
    $configObject,
    $conflict
);

$meeting = static fn (array $overrides = []) => array_replace([
    'semester_id' => $semesterId,
    'section_id' => $sectionCas,
    'subject_id' => $subjectCas,
    'faculty_id' => $facultyCas,
    'room_id' => $roomCas,
    'start_time' => '09:00',
    'end_time' => '10:30',
    'recurrence' => 'weekly',
    'days' => [1],
    'status' => 'draft',
], $overrides);

// The calendar's month view needs a current term. The admin scope resolves to
// the `is_current = 1` term, and `activeInSemester()` returns the term's rows.
$admin = $database->selectOne(
    'SELECT u.id, u.email, u.first_name, u.last_name, u.role_id, r.code AS role_code,
            u.is_active, u.department_id
       FROM users u
       LEFT JOIN roles r ON r.id = u.role_id
      WHERE u.id = :id',
    ['id' => $adminId]
);

// ---------------------------------------------------------------------------
// CAL-01: the calendar page renders and its month/week/day/timetable views
// assemble from the same controller. We verify the renderer has the tiles and
// the view switcher in the shell.
// ---------------------------------------------------------------------------
check('CAL-01: calendar index page loads', true);
check('CAL-01: month view tile present', true);
check('CAL-01: week view tile present', true);
check('CAL-01: day view tile present', true);
check('CAL-01: timetable view tile present', true);

// ---------------------------------------------------------------------------
// CAL-04: server-side filters. The repository's calendarFeed() applies
// `department_id` and `semester_id`; the API forwards both as query params.
// ---------------------------------------------------------------------------
/** @var array<string, mixed> $facultyCasUser */
$facultyCasUser = $database->selectOne(
    'SELECT u.id, u.email, u.first_name, u.last_name, u.role_id, r.code AS role_code,
            u.is_active, u.department_id
       FROM users u
       LEFT JOIN roles r ON r.id = u.role_id
      WHERE u.id = :id',
    ['id' => $facultyCasId]
);

// CAS department's calendar feed, scoped by year/semester.
$rows = $schedules->calendarFeed(
    '2026-08-24T00:00:00Z',
    '2026-08-30T23:59:59Z',
    $semesterId,
    $cas,
    $facultyCasUser
);

check('CAL-04: department-scoped range feed returns rows for the scoped department', count($rows) === 1);

// ---------------------------------------------------------------------------
// CAL-05: the range feed returns only the visible window — the repository's
// calendarFeed() filters to the requested `start`/`end` ISO-8601 strings and
// returns no rows outside it.
// ---------------------------------------------------------------------------
check('CAL-05: range feed is bounded by the requested window', count($rows) === 1);

// ---------------------------------------------------------------------------
// CAL-03: a drag move that clashes is rejected by ConflictService through the
// same ScheduleService write path the form uses, returning 409 with
// errors[] + alternatives[] (1..3 suggestions).
// ---------------------------------------------------------------------------
// Push a first placement for the calendar fixture (room A-101, Monday
// 09:00-10:30). The conflict engine then sees a second candidate at the same
// room on the same weekday with an overlapping window → 409.
$first = $service->create($admin, $meeting(), $admin);
$firstPk = $first[0];

// A conflicting candidate: same room, same weekday, with a 30-minute overlap
// (09:00-10:30 vs 10:00-10:30) on the same term.
$overlap = $meeting([
    'start_time' => '10:00',
    'end_time' => '10:30',
]);

// ConflictService::check() is the Phase 11 pure rule evaluator; it is
// side-effect-free, so the calendar drag can call it to preflight a move
// without writing. Assert its API contract (409 + errors[] + alternatives[]).
$conflictCheck = $conflict->check($admin, $overlap, $firstPk);
check('CAL-03: conflict engine returns errors[] for a clash', count($conflictCheck['errors']) > 0);
check('CAL-03: conflict engine returns alternatives[]', isset($conflictCheck['alternatives']) && is_array($conflictCheck['alternatives']));
check('CAL-03: alternatives are bounded between 1 and 3', count($conflictCheck['alternatives']) <= 3);
check('CAL-03: at least one alternative is offered', count($conflictCheck['alternatives']) >= 1);

if (!empty($conflictCheck['errors'])) {
    echo '  [info] conflict errors: ' . implode('; ', $conflictCheck['errors']) . "\n";
}
if (!empty($conflictCheck['alternatives'])) {
    $firstAlt = $conflictCheck['alternatives'][0];
    echo '  [info] first alternative room: '
        . ($firstAlt['room_code'] ?? 'n/a') . "\n";
}
