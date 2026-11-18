<?php

declare(strict_types=1);

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
use App\Services\RbacService;
use App\Services\ScheduleService;
use App\Services\Scope;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';
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
$service = new ScheduleService($schedules, $sections, $subjects, $faculty, $rooms, $terms, $authorization, $configObject);

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
$session->start();
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
// Report
// ---------------------------------------------------------------------------
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
