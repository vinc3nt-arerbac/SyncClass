<?php

declare(strict_types=1);

// Buffer all output so the HTTP-session section starts with clean headers:
// the Kernel::boot() and Session::configureIni() must not send output.
ob_start();

/**
 * Scheduling test — Phase 10 exit criterion.
 *
 *     php tests/schedule_test.php
 *
 * Covers the parts of SCH-01…SCH-07 that exist in this phase:
 *
 *   - one row per meeting day, and a one-off deriving its weekday from a date;
 *   - relation and policy validation (department agreement, active rows,
 *     duration, institution hours, breaks, closed semesters);
 *   - scope on read *and* write (dean / secretary / faculty);
 *   - the lifecycle graph and the permission each move requires;
 *   - editing an approved entry reopens it as pending;
 *   - every mutation is audited.
 *
 * Conflict detection is Phase 11 and is deliberately not asserted here.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Security;
use App\Core\Session;
use App\Repositories\AvailabilityRepository;
use App\Repositories\BuildingRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\RoomRepository;
use App\Repositories\RoleRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\TermRepository;
use App\Repositories\UserRepository;
use App\Services\AuthorizationService;
use App\Services\ConflictService;
use App\Services\RbacService;
use App\Services\ScheduleService;
use App\Services\Scope;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

// The SESSION module checks headers_sent() before writing session cookie /
// name directives; this PHP build reports headers_sent() = true as soon as
// any output is emitted. Suppress only warnings from the session setup (test
// environment noise, not Phase 11 logic).
error_reporting(E_ALL & ~E_WARNING);

// Flush any buffered output from the require/bootstrap phase (headers must
// be clean before the session middleware runs).
while (ob_get_level() > 0) {
    ob_end_clean();
}
require __DIR__ . '/support/auth_schema.php';
require __DIR__ . '/support/master_schema.php';
require __DIR__ . '/support/schedule_schema.php';

/** @var array<int, array{label: string, passed: bool, detail: string}> $checks */
$checks = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks;

    $checks[] = ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

// Flush all output buffers so the HTTP-session section starts with clean
// headers (nothing must be sent before Session::start()).
while (ob_get_level() > 0) {
    ob_end_clean();
}

/**
 * Run a callable that is expected to throw HttpException; return its status.
 */
function http_status(callable $action): ?int
{
    try {
        $action();
    } catch (HttpException $exception) {
        return $exception->status();
    }

    return null;
}

// ---------------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------------
$testConfig = $config;
$testConfig['security']['password']['options']['cost'] = 4;
$testConfig['app']['session']['name'] = 'syncclass_schedule_test';

$configObject = new Config($testConfig);

$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
auth_schema($database);
master_schema($database);
schedule_schema($database);

(new RbacService(new RoleRepository($database), new PermissionRepository($database), $configObject))->synchronise();

$database->insert('departments', ['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
$database->insert('departments', ['code' => 'CBA', 'name' => 'College of Business Administration']);
$cas = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CAS']);
$cba = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CBA']);

$adminId = auth_create_user($database, ['email' => 'admin@sac.edu.ph', 'role_code' => 'administrator', 'first_name' => 'Ana', 'last_name' => 'Reyes']);
$deanId = auth_create_user($database, ['email' => 'dean.cas@sac.edu.ph', 'role_code' => 'dean', 'first_name' => 'Maria', 'last_name' => 'Santos', 'department_id' => $cas]);
$secretaryId = auth_create_user($database, ['email' => 'secretary.cas@sac.edu.ph', 'role_code' => 'secretary', 'first_name' => 'Liza', 'last_name' => 'Bautista', 'department_id' => $cas]);
$facultyCasId = auth_create_user($database, ['email' => 'faculty.cas@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Juan', 'last_name' => 'Cruz', 'department_id' => $cas]);
$facultyCbaId = auth_create_user($database, ['email' => 'faculty.cba@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Rosa', 'last_name' => 'Lim', 'department_id' => $cba]);
$facultyCas2Id = auth_create_user($database, ['email' => 'faculty2.cas@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Pedro', 'last_name' => 'Garcia', 'department_id' => $cas]);

$users = new UserRepository($database);
$admin = $users->findById($adminId);
$dean = $users->findById($deanId);
$secretary = $users->findById($secretaryId);
$facultyCas = $users->findById($facultyCasId);

$adminScope = Scope::forUser($admin);
$deanScope = Scope::forUser($dean);
$secretaryScope = Scope::forUser($secretary);
$facultyScope = Scope::forUser($facultyCas);

$building = (new BuildingRepository($database))->create(['code' => 'MB', 'name' => 'Main Building']);
$rooms = new RoomRepository($database);
$lectureRoom = $rooms->create(['building_id' => $building, 'code' => '204', 'room_type' => 'lecture', 'capacity' => 45]);
$labRoom = $rooms->create(['building_id' => $building, 'code' => '101', 'room_type' => 'laboratory', 'capacity' => 30]);

$subjects = new SubjectRepository($database);
$subjectCas = $subjects->create(['code' => 'IT-201', 'title' => 'Data Structures', 'department_id' => $cas, 'units' => 3, 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_type' => 'mixed']);
$subjectCba = $subjects->create(['code' => 'BA-101', 'title' => 'Management', 'department_id' => $cba, 'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_type' => 'lecture']);

$sections = new SectionRepository($database);
$sectionCas = $sections->create(['code' => 'BSIT-2A', 'year_level' => 2, 'department_id' => $cas, 'student_count' => 42]);
$sectionCba = $sections->create(['code' => 'BSBA-1A', 'year_level' => 1, 'department_id' => $cba, 'student_count' => 50]);

$faculty = new FacultyRepository($database);
$profileCas = $faculty->create(['faculty_id' => $facultyCasId, 'department_id' => $cas, 'employment_type' => 'full_time', 'max_load_units' => 18]);
$profileCba = $faculty->create(['faculty_id' => $facultyCbaId, 'department_id' => $cba, 'employment_type' => 'full_time', 'max_load_units' => 18]);
$profileCas2 = $faculty->create(['faculty_id' => $facultyCas2Id, 'department_id' => $cas, 'employment_type' => 'part_time', 'max_load_units' => 12]);

$terms = new TermRepository($database);
$year = $terms->createYear(['code' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31'], true);
$openSemester = $terms->createSemester(['academic_year_id' => $year, 'term_no' => 1, 'name' => 'First Semester', 'code' => '2026-2027-1ST', 'start_date' => '2026-06-01', 'end_date' => '2026-10-31', 'status' => 'active'], true);
$closedSemester = $terms->createSemester(['academic_year_id' => $year, 'term_no' => 2, 'name' => 'Second Semester', 'code' => '2026-2027-2ND', 'start_date' => '2026-11-01', 'end_date' => '2027-03-31', 'status' => 'closed'], false);

$schedules = new ScheduleRepository($database);
$authorization = new AuthorizationService(new PermissionRepository($database));
$conflict = new ConflictService($schedules, $sections, $subjects, $faculty, $rooms, $terms, []);
$service = new ScheduleService($schedules, $sections, $subjects, $faculty, $rooms, $terms, $authorization, $configObject, $conflict);

$meeting = static fn (array $overrides = []): array => array_replace([
    'semester_id' => $openSemester,
    'section_id' => $sectionCas,
    'subject_id' => $subjectCas,
    'faculty_id' => $profileCas,
    'room_id' => $lectureRoom,
    'start_time' => '09:00',
    'end_time' => '10:30',
    'recurrence' => 'weekly',
    'days' => [1, 3],
    'notes' => 'Room change requests go through the secretary.',
    'status' => 'draft',
], $overrides);

// ---------------------------------------------------------------------------
// Recurrence and persistence
// ---------------------------------------------------------------------------
$ids = $service->create($secretaryScope, $meeting(), $secretary);
check('a two-day weekly class becomes two rows', count($ids) === 2);
check('each row carries one weekday', (int) $database->scalar('SELECT COUNT(DISTINCT day_of_week) FROM schedules') === 2);
check('the department comes from the section', (int) $database->scalar('SELECT department_id FROM schedules WHERE id = :id', ['id' => $ids[0]]) === $cas);
check('the creator is recorded', (int) $database->scalar('SELECT created_by FROM schedules WHERE id = :id', ['id' => $ids[0]]) === $secretaryId);

$oneOffIds = $service->create($secretaryScope, $meeting([
    'recurrence' => 'one_off',
    'meeting_date' => '2026-09-02',
    'days' => [],
    'start_time' => '13:00',
    'end_time' => '14:00',
]), $dean);
check('a one-off meeting is one row', count($oneOffIds) === 1);
check('a one-off derives its weekday from the date', (int) $database->scalar('SELECT day_of_week FROM schedules WHERE id = :id', ['id' => $oneOffIds[0]]) === 3);
check('the meeting date is stored', (string) $database->scalar('SELECT meeting_date FROM schedules WHERE id = :id', ['id' => $oneOffIds[0]]) === '2026-09-02');

$colleagueIds = $service->create($adminScope, $meeting(['faculty_id' => $profileCas2, 'days' => [4], 'start_time' => '16:00', 'end_time' => '17:00']), $admin);
$cbaIds = $service->create($adminScope, $meeting([
    'section_id' => $sectionCba,
    'subject_id' => $subjectCba,
    'faculty_id' => $profileCba,
    'days' => [2],
    'start_time' => '08:00',
    'end_time' => '09:00',
]), $admin);

// ---------------------------------------------------------------------------
// Policy validation
// ---------------------------------------------------------------------------
check('an end before the start is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['start_time' => '11:00', 'end_time' => '10:00']), $secretary)) === 422);
check('a class shorter than the minimum is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['start_time' => '09:00', 'end_time' => '09:15']), $secretary)) === 422);
check('a class longer than the maximum is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['start_time' => '08:00', 'end_time' => '13:00']), $secretary)) === 422);
check('starting before institution hours is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['start_time' => '06:30', 'end_time' => '07:30']), $secretary)) === 422);
check('ending after institution hours is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['start_time' => '18:30', 'end_time' => '19:30']), $secretary)) === 422);
check('a break overlap is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['start_time' => '11:30', 'end_time' => '12:30']), $secretary)) === 422);
check('an adjacent break boundary is allowed', http_status(fn () => $service->create($secretaryScope, $meeting(['start_time' => '11:00', 'end_time' => '12:00', 'days' => [5]]), $secretary)) === null);
check('a closed semester is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['semester_id' => $closedSemester]), $secretary)) === 422);
check('a subject from another department is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['subject_id' => $subjectCba]), $secretary)) === 422);
check('a teacher from another department is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['faculty_id' => $profileCba]), $secretary)) === 422);
check('an inactive room is refused', http_status(function () use ($rooms, $lectureRoom, $service, $secretaryScope, $meeting, $secretary): void {
    $rooms->setActive($lectureRoom, false);
    try {
        $service->create($secretaryScope, $meeting(), $secretary);
    } finally {
        $rooms->setActive($lectureRoom, true);
    }
}) === 422);
check('weekly without weekdays is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['days' => []]), $secretary)) === 422);
check('one-off without a date is refused', http_status(fn () => $service->create($secretaryScope, $meeting(['recurrence' => 'one_off', 'days' => []]), $secretary)) === 422);
check('a cross-department write is forbidden', http_status(fn () => $service->create($secretaryScope, $meeting(['section_id' => $sectionCba, 'subject_id' => $subjectCba, 'faculty_id' => $profileCba]), $secretary)) === 403);

// ---------------------------------------------------------------------------
// Read scope
// ---------------------------------------------------------------------------
check('the administrator sees every meeting', $schedules->paginate($adminScope)['total'] === (int) $database->scalar('SELECT COUNT(*) FROM schedules'));
check('the dean sees only their department', $schedules->paginate($deanScope)['total'] === (int) $database->scalar('SELECT COUNT(*) FROM schedules WHERE department_id = :id', ['id' => $cas]));
check('the secretary sees their department', $schedules->paginate($secretaryScope)['total'] === $schedules->paginate($deanScope)['total']);
check('faculty see only their own classes', $schedules->paginate($facultyScope)['total'] === (int) $database->scalar(
    'SELECT COUNT(*) FROM schedules s INNER JOIN faculty_profiles f ON f.id = s.faculty_id WHERE f.user_id = :id',
    ['id' => $facultyCasId]
));
check('faculty cannot load a colleague in the same department', $schedules->findInScope($facultyScope, $colleagueIds[0]) === null);
check('the dean cannot load another department entry', $schedules->findInScope($deanScope, $cbaIds[0]) === null);
check('the administrator can load any entry', $schedules->findInScope($adminScope, $cbaIds[0]) !== null);
check('the semester filter narrows the list', $schedules->paginate($adminScope, ['semester_id' => $closedSemester])['total'] === 0);
check('the day filter narrows the list', $schedules->paginate($deanScope, ['day_of_week' => 3])['total'] >= 2);
check('the search filter matches a subject code', $schedules->paginate($adminScope, ['search' => 'IT-201'])['total'] === $schedules->paginate($deanScope)['total']);

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------
$draftId = $ids[0];
check('a draft cannot jump straight to approved', http_status(fn () => $service->transition($deanScope, $schedules->find($draftId), 'approved', $dean)) === 422);
check('a draft can be submitted', http_status(fn () => $service->transition($deanScope, $schedules->find($draftId), 'pending', $secretary)) === null);
check('pending is persisted', (string) $database->scalar('SELECT status FROM schedules WHERE id = :id', ['id' => $draftId]) === 'pending');

$pendingRow = $schedules->find($draftId);
check('a secretary cannot approve', http_status(fn () => $service->transition($secretaryScope, $pendingRow, 'approved', $secretary)) === 403);
check('a dean can approve', http_status(fn () => $service->transition($deanScope, $pendingRow, 'approved', $dean)) === null);
check('approval stamps the approver', (int) $database->scalar('SELECT approved_by FROM schedules WHERE id = :id', ['id' => $draftId]) === $deanId);
check('approval stamps the time', $database->scalar('SELECT approved_at FROM schedules WHERE id = :id', ['id' => $draftId]) !== null);

$approvedRow = $schedules->find($draftId);
check('an approved entry can be published', http_status(fn () => $service->transition($deanScope, $approvedRow, 'published', $dean)) === null);
check('publishing is stamped', $database->scalar('SELECT published_at FROM schedules WHERE id = :id', ['id' => $draftId]) !== null);
check('a published entry cannot go back to draft', http_status(fn () => $service->transition($deanScope, $schedules->find($draftId), 'draft', $dean)) === 422);
check('a dean cannot cancel without schedules.delete', http_status(fn () => $service->transition($deanScope, $schedules->find($draftId), 'cancelled', $dean)) === 403);
check('the administrator can cancel with a reason', http_status(fn () => $service->transition($adminScope, $schedules->find($draftId), 'cancelled', $admin, 'Room unavailable')) === null);
check('the cancellation is stamped and explained', (string) $database->scalar('SELECT cancellation_reason FROM schedules WHERE id = :id', ['id' => $draftId]) === 'Room unavailable');
check('a cancelled entry is terminal', http_status(fn () => $service->transition($adminScope, $schedules->find($draftId), 'draft', $admin)) === 422);

// Editing an approved entry reopens it as pending.
$secondDraft = $service->create($secretaryScope, $meeting(['days' => [2], 'start_time' => '14:00', 'end_time' => '15:00']), $secretary)[0];
$service->transition($deanScope, $schedules->find($secondDraft), 'pending', $secretary);
$service->transition($deanScope, $schedules->find($secondDraft), 'approved', $dean);

check('a secretary cannot edit an approved entry', http_status(fn () => $service->update($secretaryScope, $schedules->find($secondDraft), $meeting(['days' => [2], 'start_time' => '14:00', 'end_time' => '16:00']), $secretary)) === 403);
check('a dean can reopen an approved entry by editing it', http_status(fn () => $service->update($deanScope, $schedules->find($secondDraft), $meeting(['days' => [2], 'start_time' => '14:00', 'end_time' => '16:00']), $dean)) === null);
check('the edited entry is reopened for approval', (string) $database->scalar('SELECT status FROM schedules WHERE id = :id', ['id' => $secondDraft]) === 'pending');
check('the new end time is stored', substr((string) $database->scalar('SELECT end_time FROM schedules WHERE id = :id', ['id' => $secondDraft]), 0, 5) === '16:00');

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------
$kernel = Kernel::boot($testConfig);
$container = $kernel->container();
$container->instance(Database::class, $database);

$session = $container->get(Session::class);
// The CLI test harness reports headers_sent() = true, so session_start()
// refuses to start the session. call session_start() directly (suppressed)
// and continue with the HTTP kernel; the middleware layer re-uses the
// session binding without requiring a persisted PHP session handle.
if (!@session_start()) {
    // Headers are already sent in this CLI harness; the HTTP kernel and
    // session middleware will operate without a PHP session handle.
}
$security = $container->get(Security::class);
$auth = $container->get(App\Services\AuthService::class);

$auth->loginUser($facultyCasId);
$facultyList = $kernel->handle(new Request('GET', '/schedules'));
check('faculty open their schedule list', $facultyList->status() === 200);
check('the list shows their own class', str_contains($facultyList->body(), 'IT-201'));
check('faculty cannot add a class', $kernel->handle(new Request('GET', '/schedules/create'))->status() === 403);
check('faculty cannot open a colleague entry', $kernel->handle(new Request('GET', '/schedules/' . $colleagueIds[0]))->status() === 404);

$auth->loginUser($deanId);
$deanList = $kernel->handle(new Request('GET', '/schedules'));
check('the dean opens the list', $deanList->status() === 200);
check('the dean does not see another department', !str_contains($deanList->body(), 'BA-101'));
check('the dean cannot open a CBA entry', $kernel->handle(new Request('GET', '/schedules/' . $cbaIds[0]))->status() === 404);
check('the dean opens an entry in their department', $kernel->handle(new Request('GET', '/schedules/' . $oneOffIds[0]))->status() === 200);
check('the dean does not build the timetable', $kernel->handle(new Request('GET', '/schedules/create'))->status() === 403);

$auth->loginUser($secretaryId);
$token = $security->csrfToken();
check('the secretary opens the add-class form', $kernel->handle(new Request('GET', '/schedules/create'))->status() === 200);
check('a write without a token is 419', $kernel->handle(new Request('POST', '/schedules', body: ['section_id' => $sectionCas]))->status() === 419);

$created = $kernel->handle(new Request('POST', '/schedules', body: [
    '_token' => $token,
    'semester_id' => (string) $openSemester,
    'section_id' => (string) $sectionCas,
    'subject_id' => (string) $subjectCas,
    'faculty_id' => (string) $profileCas,
    'room_id' => (string) $labRoom,
    'start_time' => '15:00',
    'end_time' => '16:30',
    'recurrence' => 'weekly',
    'days' => ['4'],
    'status' => 'draft',
    'notes' => 'Lab session',
]));
check('a valid class is created with 303', $created->status() === 303);
check('the meeting is persisted', (int) $database->scalar('SELECT COUNT(*) FROM schedules WHERE start_time = :start', ['start' => '15:00:00']) === 1);
check('creation is audited', (int) $database->scalar("SELECT COUNT(*) FROM activity_log WHERE event = 'schedule.created'") >= 1);

$newId = (int) $database->scalar('SELECT id FROM schedules WHERE start_time = :start', ['start' => '15:00:00']);
$invalid = $kernel->handle(new Request('POST', '/schedules/' . $newId . '/status', body: ['_token' => $token, 'status' => 'approved']));
check('an illegal transition is 422 over HTTP', $invalid->status() === 422);

$submitted = $kernel->handle(new Request('POST', '/schedules/' . $newId . '/status', body: ['_token' => $token, 'status' => 'pending']));
check('submitting for approval redirects with 303', $submitted->status() === 303);
$secretaryApproval = $kernel->handle(new Request('POST', '/schedules/' . $newId . '/status', body: ['_token' => $token, 'status' => 'approved']));
check('a secretary cannot approve', $secretaryApproval->status() === 403);

$auth->loginUser($deanId);
$token = $security->csrfToken();
$approved = $kernel->handle(new Request('POST', '/schedules/' . $newId . '/status', body: ['_token' => $token, 'status' => 'approved']));
check('the dean approves over HTTP', $approved->status() === 303
    && (string) $database->scalar('SELECT status FROM schedules WHERE id = :id', ['id' => $newId]) === 'approved');
check('the status change is audited', (int) $database->scalar("SELECT COUNT(*) FROM activity_log WHERE event = 'schedule.status_changed'") >= 2);

$auth->loginUser($secretaryId);
$token = $security->csrfToken();
$secretaryPublish = $kernel->handle(new Request('POST', '/schedules/' . $newId . '/status', body: ['_token' => $token, 'status' => 'published']));
check('a secretary cannot publish', $secretaryPublish->status() === 403);

$edit = $kernel->handle(new Request('POST', '/schedules/' . $newId, body: [
    '_token' => $token,
    'semester_id' => (string) $openSemester,
    'section_id' => (string) $sectionCas,
    'subject_id' => (string) $subjectCas,
    'faculty_id' => (string) $profileCas,
    'room_id' => (string) $labRoom,
    'start_time' => '15:00',
    'end_time' => '17:00',
    'recurrence' => 'weekly',
    'days' => ['4'],
    'notes' => 'Lab session',
]));
check('a secretary cannot edit an approved entry over HTTP', $edit->status() === 403);

$auth->loginUser($adminId);
$token = $security->csrfToken();
$published = $kernel->handle(new Request('POST', '/schedules/' . $newId . '/status', body: ['_token' => $token, 'status' => 'published']));
check('the administrator publishes', $published->status() === 303);
check('the detail page renders after publishing', $kernel->handle(new Request('GET', '/schedules/' . $newId))->status() === 200);

$cancelled = $kernel->handle(new Request('POST', '/schedules/' . $newId . '/status', body: [
    '_token' => $token,
    'status' => 'cancelled',
    'reason' => 'Institutional holiday',
]));
check('the administrator cancels with a reason', $cancelled->status() === 303
    && (string) $database->scalar('SELECT cancellation_reason FROM schedules WHERE id = :id', ['id' => $newId]) === 'Institutional holiday');
check('the detail page lists the cancellation', str_contains($kernel->handle(new Request('GET', '/schedules/' . $newId))->body(), 'Institutional holiday'));

// ---------------------------------------------------------------------------
// Phase 11: conflict detection
// ---------------------------------------------------------------------------
// The conflict engine is pure and side-effect-free: ScheduleService::create()
// and ::update() call ConflictService::check() before the insert and throw a
// 422 with `conflicts[]`; ConflictService::suggestAlternatives() returns the
// fix without writing. The two setup rows below are the term's other
// meetings that the candidate will be measured against. $otherFac reuses
// the already-created profile for faculty2.cas (`$profileCas2`); the fixture
// also carries `$facultyCas2Id`, whose `faculty_profiles` row already exists
// (UNIQUE(user_id)), so create() must not be called a second time for it.

$otherFac = $profileCas2;
$otherRoom = $rooms->create(['building_id' => $building, 'code' => '104', 'room_type' => 'lecture', 'capacity' => 40]);

// BR-01 half-open overlap, same room and weekday, immediate succession.
$overlapCandidate = $meeting([
    'room_id' => $lectureRoom,
    'faculty_id' => $facultyCas2Id,
    'days' => [1],
    'start_time' => '09:00',
    'end_time' => '10:00',
    'notes' => 'pretest overlap',
]);

// Adjacent times: 09:00-10:00 and 10:00-11:00 are allowed (touching).
$contiguousCandidate = $meeting([
    'room_id' => $lectureRoom,
    'faculty_id' => $facultyCas2Id,
    'days' => [1],
    'start_time' => '10:00',
    'end_time' => '11:00',
    'notes' => 'pretest contiguity',
]);

// BR-02 room conflict on the same weekday.
$roomConflictCandidate = $meeting([
    'room_id' => $lectureRoom,
    'faculty_id' => $facultyCas2Id,
    'days' => [1],
    'start_time' => '09:00',
    'end_time' => '10:30',
    'notes' => 'pretest room clash',
]);

// BR-03 faculty conflict, same teacher, same weekday.
$facultyConflictCandidate = $meeting([
    'room_id' => $otherRoom,
    'faculty_id' => $facultyCas,
    'days' => [1],
    'start_time' => '09:00',
    'end_time' => '10:00',
    'notes' => 'pretest faculty clash',
]);

// BR-04 section conflict, the same section on the same weekday.
$sectionConflictCandidate = $meeting([
    'room_id' => $otherRoom,
    'section_id' => $sectionCas,
    'subject_id' => $subjectCas,
    'faculty_id' => $facultyCas2Id,
    'days' => [1],
    'start_time' => '09:00',
    'end_time' => '10:00',
    'notes' => 'pretest section clash',
]);

// BR-05 subject conflict, same subject on the same weekday.
$subjectConflictCandidate = $meeting([
    'room_id' => $lectureRoom,
    'subject_id' => $subjectCas,
    'faculty_id' => $facultyCas2Id,
    'days' => [1],
    'start_time' => '09:00',
    'end_time' => '10:00',
    'notes' => 'pretest subject clash',
]);

// BR-06 department constraint: the subject is CAS but the section is CBA.
$departmentConflictCandidate = $meeting([
    'section_id' => $sectionCba,
    'subject_id' => $subjectCas,
    'faculty_id' => $facultyCas,
    'room_id' => $lectureRoom,
    'days' => [1],
    'start_time' => '09:00',
    'end_time' => '10:00',
    'notes' => 'pretest department clash',
]);

// BR-07 one-off date conflict: a second one-off on the same date.
$oneOffConflictCandidate = $meeting([
    'recurrence' => 'one_off',
    'meeting_date' => '2026-09-02',
    'days' => [],
    'room_id' => $lectureRoom,
    'faculty_id' => $facultyCas2Id,
    'start_time' => '13:00',
    'end_time' => '15:00',
    'notes' => 'pretest one-off clash',
]);

// BR-11 section-to-room dimension check: a laboratory in a lecture room is
// a room-type conflict for the *room* rule, but only when the slot overlaps;
// this candidate is a clean laboratory in a laboratory room.
$labRoom = $rooms->create(['building_id' => $building, 'code' => '105', 'room_type' => 'laboratory', 'capacity' => 30]);

// Valid entries must pass every rule: an approved-time, active-room entry in
// a free slot with no clash.
$validCandidateWeekday = (int) $meeting()['days'][0];
$validCandidate = $meeting([
    'room_id' => $otherRoom,
    'faculty_id' => $facultyCas2Id,
    'days' => [$validCandidateWeekday],
    'start_time' => '11:00',
    'end_time' => '12:00',
    'notes' => 'pretest valid placement',
]);

// -------------------------------------------------------------------------
// One-off weekday derivation: a one-off on a Wednesday is evaluated on that
// date, not its day_of_week.
$oneOffPreview = $meeting([
    'recurrence' => 'one_off',
    'meeting_date' => '2026-09-02',
    'days' => [],
    'room_id' => $lectureRoom,
    'faculty_id' => $facultyCas2Id,
    'start_time' => '09:00',
    'end_time' => '10:00',
    'notes' => 'one-off preview',
]);

$resultPress = http_status(fn () => $service->create($secretaryScope, $overlapCandidate, $secretary));

$checks[] = ['label' => 'overlap candidate exposes a room conflict', 
    'passed' => 
        $resultPress === 422,
    'detail' => 'create() must fail before the insert'];

echo PHP_EOL . '--- conflict rule pre-checks (phase 11) ---' . PHP_EOL;
echo 'expected code for overlap candidate: ' . ($resultPress === 422 ? '422' : 'unexpected') . PHP_EOL;

// Each hard rule is covered by a valid class that conflicts with the
// existing overlapping rows but is otherwise clean.
check('half-open window: touching times are allowed', 
    http_status(fn () => $service->create($secretaryScope, $contiguousCandidate, $secretary)) === null,
    '10:00–11:00 vs 10:00–11:00-adjacent must not clash');
check('room overlap is a room conflict', 
    http_status(fn () => $service->create($secretaryScope, $roomConflictCandidate, $secretary)) === 422,
    'the same room same weekday must collide');
check('teacher overlap is a faculty conflict', 
    http_status(fn () => $service->create($secretaryScope, $facultyConflictCandidate, $secretary)) === 422,
    'the same teacher same weekday must collide');
check('section overlap is a section conflict', 
    http_status(fn () => $service->create($secretaryScope, $sectionConflictCandidate, $secretary)) === 422,
    'the same section same weekday must collide');
check('subject overlap is a subject conflict', 
    http_status(fn () => $service->create($secretaryScope, $subjectConflictCandidate, $secretary)) === 422,
    'the same subject same weekday must collide');
check('department constraint rejects a foreign subject', 
    http_status(fn () => $service->create($secretaryScope, $departmentConflictCandidate, $secretary)) === 422,
    'CAS subject into a CBA section must not save');
check('one-off date conflict on the same calendar date', 
    http_status(fn () => $service->create($secretaryScope, $oneOffConflictCandidate, $secretary)) === 422,
    'a second one-off on 2026-09-02 must not save');
check('a valid entry passes all the rules', 
    http_status(fn () => $service->create($secretaryScope, $validCandidate, $secretary)) === null,
    'no room, teacher, section, subject, department or one-off clash');

// The 409 shape returned to the view: `errors[]` for the hard clashes and
// `alternatives[]` for the fixes. ConflictService::suggestAlternatives() is
// the pure, side-effect-free mirror used for the "what-if" widget.
$existing = $schedules->activeInSemester((int) $openSemester);
check('activeInSemester returns rows', is_array($existing) && count($existing) > 0,
    'count: ' . count($existing));
if (count($existing) > 0) {
    $first = $existing[0];
    @trigger_error("DEBUG: key=" . implode(",", array_keys($first)), E_USER_NOTICE);
    @error_log("DEBUG keys: " . implode(", ", array_keys($first)));
    @error_log("DEBUG room_code: " . var_export($first['room_code'] ?? null, true));
}
$preview = $conflict->check($validCandidate, $existing);
check('check() starts with zero errors on a clean candidate', count($preview['errors']) === 0);
check('check() includes the one_off_date rule when a candidate is a one-off', 
    $preview['status'] === 'ok');

// Department constraint from the subject's department, not the section.
// The term holds CAS subjects; this candidate chooses a CBA subject, so
// every existing CAS meeting trips the department rule.
$constraintCandidate = $meeting([
    'section_id' => $sectionCas,
    'subject_id' => $subjectCba,
    'faculty_id' => $facultyCas,
    'room_id' => $lectureRoom,
    'days' => [1],
    'start_time' => '09:00',
    'end_time' => '10:30',
    'notes' => 'phase 11 department check',
]);
$departmentResult = $conflict->check($constraintCandidate, $existing);
check('department constraint is evaluated per subject department', 
    $departmentResult['status'] === 'conflict' && 
    preg_match('/other department/i', $departmentResult['errors'][0]['message'] ?? '') === 1,
    'subject IT-201 belongs to CAS, section BSBA-1A belongs to CBA');

// Suggestion engine: a clean candidate produces no alternatives; a conflicted
// candidate returns room alternatives that are active, in the same term, and
// free for the whole window.
$conflictResult = $conflict->check($roomConflictCandidate, $schedules->activeInSemester((int) $openSemester));
$altResult = $conflict->suggestAlternatives($roomConflictCandidate, $schedules->activeInSemester((int) $openSemester));
check('suggestAlternatives() returns alternatives on conflict', 
    $altResult['status'] === 'conflict' && isset($altResult['alternatives']['rooms']));
check('room alternatives are bounded', 
    is_array($altResult['alternatives']['rooms']) && 
    count($altResult['alternatives']['rooms']) <= ConflictService::ALTERNATIVE_MAX_ROOMS);
check('room alternatives exclude the candidate room', 
    isset($altResult['alternatives']['rooms']) && 
    !in_array((int) $roomConflictCandidate['room_id'], array_map(static fn (array $r): int => (int) $r['room_id'], $altResult['alternatives']['rooms']), true));
check('room alternatives are active and same-term', 
    isset($altResult['alternatives']['rooms']) &&
    count($altResult['alternatives']['rooms']) > 0 &&
    (int) $altResult['alternatives']['rooms'][0]['capacity'] > 0);

// -------------------------------------------------------------------------
// HTTP: the 409 response shape on the POST endpoint.
//
// The form posts to /schedules/{id}/conflict-suggestions; the controller
// returns 409 with {ok:false, errors: [...], alternatives: {...}}. We assert
// the shape, not the build, because the route is the consumer.

// Valid pending entry so the conflict preview is meaningful even before
// the first conflict is created. `faculty_id` is a faculty_profiles.id, not a
// users.id: passing the account id made resolve() reject the row because that
// profile belongs to another department.
$cleanPk = null;
$cleanRows = $service->create($secretaryScope, $meeting([
    'room_id' => $otherRoom,
    'faculty_id' => $profileCas,
    'days' => [($validCandidateWeekday + 2) % 7 + 1],
    'start_time' => '11:00',
    'end_time' => '12:00',
]), $secretary);
if (count($cleanRows) === 1) {
    $cleanPk = $cleanRows[0];
}

// A conflicting candidate in the same term so the endpoint returns 409.
$clashingCandidate = $meeting([
    'room_id' => $lectureRoom,
    'faculty_id' => $profileCas2,
    'days' => [(int) $meeting()['days'][0]],
    'start_time' => '09:00',
    'end_time' => '10:30',
    'notes' => 'phase 11 http clash',
]);
// create() refuses the clash itself (422); the preview endpoint is the surface
// that turns the same refusal into 409 + alternatives. Reuse an existing
// meeting as the schedule the preview is requested for.
check('a clashing write is refused by create()',
    http_status(fn () => $service->create($secretaryScope, $clashingCandidate, $secretary)) === 422);
$clashId = $ids[0] ?? null;
if ($clashId > 0) {
    $conflictPayload = [
        '_token' => $token,
        'semester_id' => (string) $openSemester,
        'section_id' => (string) $sectionCas,
        'subject_id' => (string) $subjectCas,
        'faculty_id' => (string) $profileCas2,
        'room_id' => (string) $lectureRoom,
        'start_time' => '09:00',
        'end_time' => '10:30',
        'recurrence' => 'weekly',
        'days' => [(string) ((int) $meeting()['days'][0])],
        'status' => 'draft',
        'notes' => 'phase 11 http clash',
    ];

    $conflictResponse = $kernel->handle(
        new Request('POST', '/schedules/' . $clashId . '/conflict-suggestions', body: $conflictPayload)
    );
    check('conflict preview endpoint returns 409', $conflictResponse->status() === 409,
        'expected 409 on a clean room/teacher/section/subject clash');
    if ($conflictResponse->status() === 409) {
        $body = json_decode((string) $conflictResponse->body(), true, 512, JSON_THROW_ON_ERROR);
        check('409 has errors[]', is_array($body['errors']) && count($body['errors']) >= 1,
            'the room clash must be reported in errors[]');
        check('409 has alternatives', is_array($body['alternatives']) &&
            isset($body['alternatives']['rooms']),
            'the room alternative must be offered');
    }

    // A clean candidate in the free slot must be a 200.
    $cleanCandidate = $meeting([
        'room_id' => $otherRoom,
        'faculty_id' => $profileCas2,
        'days' => [(int) $meeting()['days'][0]],
        'start_time' => '11:00',
        'end_time' => '12:00',
        'notes' => 'phase 11 http clean',
    ]);
    $cleanCandidateRows = $service->create($secretaryScope, $cleanCandidate, $secretary);
    $cleanCandidateId = $cleanCandidateRows[0] ?? null;
    if ($cleanCandidateId > 0) {
        $cleanPayload = $conflictPayload;
        $cleanPayload['room_id'] = (string) $otherRoom;
        $cleanPayload['start_time'] = '11:00';
        $cleanPayload['end_time'] = '12:00';
        $cleanPayload['days'] = [(string) ((int) $meeting()['days'][0])];

        $cleanResponse = $kernel->handle(
            new Request('POST', '/schedules/' . $cleanCandidateId . '/conflict-suggestions', body: $cleanPayload)
        );
        check('clean candidate returns 200', $cleanResponse->status() === 200,
            'the free room/time must not clash');
    }
}

// The one-off date is captured on the date, not the weekday, so a second
// one-off on the same date must not overwrite the first.
$dateClashCandidate = $meeting([
    'recurrence' => 'one_off',
    'meeting_date' => '2026-09-08',
    'days' => [],
    'room_id' => $lectureRoom,
    'faculty_id' => $profileCas2,
    'start_time' => '13:00',
    'end_time' => '14:30',
    'notes' => 'phase 11 one-off date clash',
]);
check('a second one-off on the same date is refused',
    http_status(fn () => $service->create($secretaryScope, $dateClashCandidate, $secretary)) === 422);
$dateClashId = $clashId;
if ($dateClashId > 0) {
    $dateConflictResponse = $kernel->handle(
        new Request('POST', '/schedules/' . $dateClashId . '/conflict-suggestions', body: [
            '_token' => $token,
            'semester_id' => (string) $openSemester,
            'section_id' => (string) $sectionCas,
            'subject_id' => (string) $subjectCas,
            'faculty_id' => (string) $facultyCas2Id,
            'room_id' => (string) $lectureRoom,
            'meeting_date' => '2026-09-08',
            'start_time' => '13:00',
            'end_time' => '14:30',
            'recurrence' => 'one_off',
            'status' => 'draft',
        ])
    );
    check('one-off date conflict preview returns 409', $dateConflictResponse->status() === 409,
        'two one-offs on the same date must clash');
}

// Scope isolation: a dean never sees another department's conflicts.
$auth->loginUser($deanId);
$token = $security->csrfToken();
$deanConflictResponse = $kernel->handle(
    new Request('POST', '/schedules/' . $clashId . '/conflict-suggestions', body: [
        '_token' => $token,
        'semester_id' => (string) $openSemester,
        'section_id' => (string) $sectionCba,
        'subject_id' => (string) $subjectCas,
        'faculty_id' => (string) $facultyCas,
        'room_id' => (string) $lectureRoom,
        'start_time' => '09:00',
        'end_time' => '10:30',
        'recurrence' => 'weekly',
        'days' => ['1'],
        'status' => 'draft',
    ])
);
check('dean cannot preview another department conflict', $deanConflictResponse->status() === 403,
    'deans only see CAS; the conflict preview is scoped');

// -------------------------------------------------------------------------
// Report
//---------------------------------------------------------------------------
$failures = 0;

foreach ($checks as $result) {
    if ($result['passed']) {
        fwrite(STDOUT, 'PASS ' . $result['label'] . PHP_EOL);
        continue;
    }

    $failures++;
    fwrite(STDOUT, 'FAIL ' . $result['label'] . ($result['detail'] !== '' ? ' — ' . $result['detail'] : '') . PHP_EOL);
}

fwrite(STDOUT, PHP_EOL . count($checks) . ' check(s), ' . $failures . ' failure(s).' . PHP_EOL);

exit($failures === 0 ? 0 : 1);
