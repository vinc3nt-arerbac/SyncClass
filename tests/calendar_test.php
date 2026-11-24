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

use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Repositories\BuildingRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\RoomRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\TermRepository;
use App\Services\AuthorizationService;
use App\Services\ConflictService;
use App\Services\RbacService;
use App\Services\ScheduleService;
use App\Services\Scope;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<int, array{label: string, passed: bool, detail: string}> $checks */
$checks = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks;

    $checks[] = ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

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

require dirname(__DIR__) . '/tests/support/auth_schema.php';
require dirname(__DIR__) . '/tests/support/master_schema.php';
require dirname(__DIR__) . '/tests/support/schedule_schema.php';

// The fixtures only define the schema functions; the tables are created here.
auth_schema($database);
master_schema($database);
schedule_schema($database);

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
// auth_create_user() fills the required identity columns (username, role,
// password hash); a hand-written insert missed `username` and broke the run.
$facultyCbaId = auth_create_user($database, [
    'email' => 'faculty.jcruz@sac.edu.ph',
    'role_code' => 'faculty',
    'first_name' => 'Jean',
    'last_name' => 'Cruz',
    'department_id' => $cas,
]);

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
// A second department and room so CAL-04's department filter has something to
// exclude: CBA meets on Tuesday 08:00–09:30 in B-201.
// ---------------------------------------------------------------------------
$database->insert('departments', ['code' => 'CBA', 'name' => 'College of Business Administration']);
$cba = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CBA']);

$roomCba = $rooms->create([
    'building_id' => $building,
    'code' => 'B-201',
    'room_type' => 'lecture',
    'capacity' => 40,
]);

$subjectCba = $subjects->create([
    'code' => 'BA 101',
    'title' => 'Introduction to Business',
    'department_id' => $cba,
    'units' => 3.0,
    'lecture_hours' => 3,
    'lab_hours' => 0,
]);

$sectionCba = $sections->create([
    'code' => 'BA101A',
    'name' => 'BA 101A — Intro',
    'program' => 'BSc',
    'year_level' => 1,
    'department_id' => $cba,
    'student_count' => 30,
]);

$facultyCbaUserId = auth_create_user($database, [
    'email' => 'faculty.cba@sac.edu.ph',
    'role_code' => 'faculty',
    'first_name' => 'Paolo',
    'last_name' => 'Santos',
    'department_id' => $cba,
]);

$facultyCbaProfile = $faculty->create([
    'faculty_id' => $facultyCbaUserId,
    'department_id' => $cba,
    'employment_type' => 'full_time',
    'max_load_units' => 18,
]);

$adminScope = Scope::forUser($admin);

// The CAS class the calendar paints: Monday 09:00–10:30 in A-101.
$first = $service->create($adminScope, $meeting(), $admin);
check('fixture: the CAS class is created', count($first) === 1);

// The CBA class: Tuesday 08:00–09:30 in B-201.
$cbaIds = $service->create($adminScope, $meeting([
    'section_id' => $sectionCba,
    'subject_id' => $subjectCba,
    'faculty_id' => $facultyCbaProfile,
    'room_id' => $roomCba,
    'days' => [2],
    'start_time' => '08:00',
    'end_time' => '09:30',
]), $admin);
check('fixture: the CBA class is created', count($cbaIds) === 1);

// ---------------------------------------------------------------------------
// CAL-04 / CAL-05: server-side filters and the visible window. The repository
// expands weekly rows into dated occurrences inside the requested range.
// ---------------------------------------------------------------------------
/** @var array<string, mixed> $facultyCasUser */
$facultyCasUser = $database->selectOne(
    'SELECT u.id, u.email, u.first_name, u.last_name, u.role_id, r.code AS role_code,
            u.is_active, u.department_id
       FROM users u
       LEFT JOIN roles r ON r.id = u.role_id
      WHERE u.id = :id',
    ['id' => $facultyCbaId]
);

// 24–30 August 2026 contains exactly one Monday and one Tuesday.
$rows = $schedules->calendarFeed(
    '2026-08-24T00:00:00Z',
    '2026-08-30T23:59:59Z',
    $semesterId,
    null,
    $admin
);
check('CAL-04: the admin feed returns both departments\u2019 occurrences', count($rows) === 2,
    'count: ' . count($rows));

$casRows = $schedules->calendarFeed(
    '2026-08-24T00:00:00Z',
    '2026-08-30T23:59:59Z',
    $semesterId,
    $cas,
    $admin
);
check('CAL-04: the department filter excludes the other department', count($casRows) === 1
    && (int) $casRows[0]['department_id'] === $cas);

$facultyRows = $schedules->calendarFeed(
    '2026-08-24T00:00:00Z',
    '2026-08-30T23:59:59Z',
    $semesterId,
    null,
    $facultyCasUser
);
check('CAL-04: a faculty member sees only their own class', count($facultyRows) === 1);

$outside = $schedules->calendarFeed(
    '2026-09-07T00:00:00Z',
    '2026-09-13T23:59:59Z',
    $semesterId,
    $cas,
    $admin
);
check('CAL-05: the feed is bounded by the requested window', count($outside) === 1,
    'one Monday occurrence in the following week, none outside it');

$beforeTerm = $schedules->calendarFeed(
    '2026-01-05T00:00:00Z',
    '2026-01-11T23:59:59Z',
    $semesterId,
    $cas,
    $admin
);
check('CAL-05: nothing is painted outside the term', $beforeTerm === []);

// ---------------------------------------------------------------------------
// CAL-01: the page and its four views render through the real HTTP kernel.
// ---------------------------------------------------------------------------
$kernel = Kernel::boot($testConfig);
$container = $kernel->container();
$container->instance(Database::class, $database);

$security = $container->get(App\Core\Security::class);
$auth = $container->get(App\Services\AuthService::class);

// CLI has already sent headers, so PHP refuses to start a session; the kernel
// reuses the session binding without a PHP session handle (see schedule_test).
@session_start();

$auth->loginUser($adminId);
$page = $kernel->handle(new Request('GET', '/calendar'));
check('CAL-01: calendar index page loads', $page->status() === 200);
$html = (string) $page->body();
check('CAL-01: the view switcher offers month/week/day/timetable',
    str_contains($html, 'view=week') && str_contains($html, 'view=day')
    && str_contains($html, 'view=timetable') && str_contains($html, 'month-picker'));
check('CAL-01: the month grid renders weekday headers', substr_count(strtolower($html), 'monday') >= 1);
$pageHeaders = array_change_key_case($page->headers(), CASE_LOWER);
check('CAL-01: the rendered page is private',
    str_contains(strtolower((string) ($pageHeaders['cache-control'] ?? '')), 'no-store'));

$api = $kernel->handle(new Request('GET', '/api/calendar/events', [
    'start' => '2026-08-24T00:00:00Z',
    'end' => '2026-08-30T23:59:59Z',
    'semester_id' => (string) $semesterId,
]));
check('CAL-05: the JSON feed answers 200', $api->status() === 200);
$payload = json_decode((string) $api->body(), true, 512, JSON_THROW_ON_ERROR);
check('CAL-05: the JSON feed returns the visible occurrences',
    ($payload['data']['total'] ?? -1) === 2, 'event count from the API envelope');
check('CAL-05: the JSON feed carries the class codes',
    str_contains((string) $api->body(), 'CSC 101') && str_contains((string) $api->body(), 'BA 101'));

$deanScoped = $kernel->handle(new Request('GET', '/api/calendar/events', [
    'start' => '2026-08-24T00:00:00Z',
    'end' => '2026-08-30T23:59:59Z',
    'department_id' => (string) $cas,
]));
$deanPayload = json_decode((string) $deanScoped->body(), true, 512, JSON_THROW_ON_ERROR);
check('CAL-04: the API honours department_id', ($deanPayload['data']['total'] ?? -1) === 1);

// ---------------------------------------------------------------------------
// CAL-03: a drag move that clashes is refused by the same write path and the
// preview offers bounded room alternatives (this is UAT-6's engine).
// ---------------------------------------------------------------------------
$rooms->create(['building_id' => $building, 'code' => 'A-102', 'room_type' => 'lecture', 'capacity' => 40]);

$overlap = $meeting([
    'start_time' => '10:00',
    'end_time' => '10:30',
]);

$existing = $schedules->activeInSemester($semesterId);
$resolved = $service->resolve($adminScope, $overlap);
$conflictCheck = $conflict->check($resolved, $existing);
check('CAL-03: conflict engine reports a clash', ($conflictCheck['status'] ?? '') === 'conflict'
    && count($conflictCheck['errors']) > 0);
check('CAL-03: the clash names the room rule', in_array('room', array_column($conflictCheck['errors'], 'conflict_type'), true));
check('CAL-03: the write path refuses the same move',
    (static function () use ($service, $adminScope, $overlap, $admin): ?int {
        try {
            $service->create($adminScope, $overlap, $admin);
        } catch (App\Core\HttpException $exception) {
            return $exception->status();
        }

        return null;
    })() === 422);

$suggestions = $conflict->suggestAlternatives($resolved, $existing);
check('CAL-03: alternatives are offered on a clash',
    isset($suggestions['alternatives']['rooms']) && is_array($suggestions['alternatives']['rooms']));
check('CAL-03: a free room is proposed', count($suggestions['alternatives']['rooms'] ?? []) >= 1);
check('CAL-03: room alternatives are bounded',
    count($suggestions['alternatives']['rooms'] ?? []) <= ConflictService::ALTERNATIVE_MAX_ROOMS);

// ---------------------------------------------------------------------------
// Summary. A test script that never reports is indistinguishable from a crash,
// so the calendar suite ends with the same PASS/FAIL table as the others and a
// non-zero exit status on any failure.
// ---------------------------------------------------------------------------
$failures = 0;
foreach ($checks as $result) {
    if (!$result['passed']) {
        $failures++;
        echo 'FAIL ' . $result['label']
            . ($result['detail'] !== '' ? ' — ' . $result['detail'] : '') . PHP_EOL;
    }
}

echo PHP_EOL . count($checks) . ' check(s), ' . $failures . ' failure(s).' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
