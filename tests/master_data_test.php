<?php

declare(strict_types=1);

/**
 * Master data test — Phase 9 exit criterion.
 *
 *     php tests/master_data_test.php
 *
 * Four layers, tested separately:
 *
 *   1. repositories — scope clauses, unique codes, archive flags, pagination;
 *   2. invariants   — one current year, one current semester per year,
 *                     availability overlap, faculty-profile eligibility;
 *   3. HTTP         — the guarded routes, CSRF on writes, 403 vs 404, PRG;
 *   4. audit        — every mutation and refusal lands in activity_log.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Database;
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
use App\Repositories\SectionRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\TermRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;
use App\Services\RbacService;
use App\Services\Scope;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/auth_schema.php';
require __DIR__ . '/support/master_schema.php';

/** @var array<int, array{label: string, passed: bool, detail: string}> $checks */
$checks = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks;

    $checks[] = ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

// ---------------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------------
$testConfig = $config;
$testConfig['security']['password']['options']['cost'] = 4;
$testConfig['app']['session']['name'] = 'syncclass_master_test';

$configObject = new Config($testConfig);

$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
auth_schema($database);
master_schema($database);

$rbac = new RbacService(new RoleRepository($database), new PermissionRepository($database), $configObject);
$rbac->synchronise();

$database->insert('departments', ['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
$database->insert('departments', ['code' => 'CBA', 'name' => 'College of Business Administration']);
$cas = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CAS']);
$cba = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CBA']);

$adminId = auth_create_user($database, ['email' => 'admin@sac.edu.ph', 'role_code' => 'administrator', 'first_name' => 'Ana', 'last_name' => 'Reyes']);
$deanId = auth_create_user($database, ['email' => 'dean.cas@sac.edu.ph', 'role_code' => 'dean', 'first_name' => 'Maria', 'last_name' => 'Santos', 'department_id' => $cas]);
$secretaryId = auth_create_user($database, ['email' => 'secretary.cas@sac.edu.ph', 'role_code' => 'secretary', 'first_name' => 'Liza', 'last_name' => 'Bautista', 'department_id' => $cas]);
$facultyCasId = auth_create_user($database, ['email' => 'faculty.cas@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Juan', 'last_name' => 'Cruz', 'department_id' => $cas]);
$facultyCbaId = auth_create_user($database, ['email' => 'faculty.cba@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Rosa', 'last_name' => 'Lim', 'department_id' => $cba]);

$users = new App\Repositories\UserRepository($database);
$admin = $users->findById($adminId);
$dean = $users->findById($deanId);
$secretary = $users->findById($secretaryId);
$facultyCas = $users->findById($facultyCasId);
$facultyCba = $users->findById($facultyCbaId);

$adminScope = Scope::forUser($admin);
$deanScope = Scope::forUser($dean);
$secretaryScope = Scope::forUser($secretary);
$facultyScope = Scope::forUser($facultyCas);

$departments = new DepartmentRepository($database);
$buildings = new BuildingRepository($database);
$rooms = new RoomRepository($database);
$subjects = new SubjectRepository($database);
$sections = new SectionRepository($database);
$faculty = new FacultyRepository($database);
$availability = new AvailabilityRepository($database);
$terms = new TermRepository($database);
$masterData = new MasterDataService($departments, $terms);

// ---------------------------------------------------------------------------
// Departments: scope, uniqueness, archive
// ---------------------------------------------------------------------------
check('the administrator sees both departments', $departments->paginate($adminScope)['total'] === 2);
check('the dean sees only their department', $departments->paginate($deanScope)['total'] === 1);
check('department options are scoped', count($departments->options($deanScope)) === 1 && count($departments->options($adminScope)) === 2);
check('department codes are unique', $departments->codeExists('cas') && !$departments->codeExists('nursing'));
check('a department code is unique except for itself', !$departments->codeExists('CAS', $cas));

$departments->setActive($cas, false);
check('archiving a department only flips the flag', (int) $database->scalar('SELECT is_active FROM departments WHERE id = :id', ['id' => $cas]) === 0);
$departments->setActive($cas, true);

$dependencies = $departments->dependencies($cas);
check('dependencies are counted for the archive explanation', $dependencies['users'] === 3 && $dependencies['subjects'] === 0);

// ---------------------------------------------------------------------------
// Buildings and rooms
// ---------------------------------------------------------------------------
$main = $buildings->create(['code' => 'MB', 'name' => 'Main Building', 'campus' => 'Sta. Ana']);
$annex = $buildings->create(['code' => 'AB', 'name' => 'Annex Building', 'campus' => 'Sta. Ana']);
check('buildings are created and listed', $buildings->paginate()['total'] === 2);
check('building codes are unique', $buildings->codeExists('mb') && !$buildings->codeExists('MB', $main));

$room204 = $rooms->create(['building_id' => $main, 'code' => '204', 'name' => 'Lecture 204', 'floor' => 2, 'room_type' => 'lecture', 'capacity' => 45]);
$rooms->create(['building_id' => $annex, 'code' => '204', 'name' => 'Lab 204', 'floor' => 2, 'room_type' => 'laboratory', 'capacity' => 30]);
$rooms->create(['building_id' => $main, 'code' => '101', 'name' => 'Lab 101', 'floor' => 1, 'room_type' => 'laboratory', 'capacity' => 25]);

check('the same room code may exist in two buildings', $rooms->paginate()['total'] === 3);
check('the room code is unique inside a building', $rooms->codeExistsInBuilding($main, '204') && !$rooms->codeExistsInBuilding($main, '999'));
check('the room filter narrows by type', $rooms->paginate(['room_type' => 'laboratory'])['total'] === 2);
check('the room filter narrows by building', $rooms->paginate(['building_id' => $main])['total'] === 2);

$rooms->setActive($room204, false);
check('a room is archived, not deleted', (int) $database->scalar('SELECT is_active FROM rooms WHERE id = :id', ['id' => $room204]) === 0
    && $rooms->paginate(['status' => 'inactive'])['total'] === 1);
$rooms->setActive($room204, true);

// ---------------------------------------------------------------------------
// Subjects: department scope, uniqueness
// ---------------------------------------------------------------------------
$subjectCas = $subjects->create([
    'code' => 'IT-201', 'title' => 'Data Structures', 'department_id' => $cas,
    'units' => 3, 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_type' => 'mixed',
    'preferred_room_type' => 'laboratory',
]);
$subjectCba = $subjects->create([
    'code' => 'BA-101', 'title' => 'Principles of Management', 'department_id' => $cba,
    'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_type' => 'lecture',
]);

check('the administrator sees every subject', $subjects->paginate($adminScope)['total'] === 2);
check('the dean sees only their department', $subjects->paginate($deanScope)['total'] === 1);
check('the secretary sees the same catalogue as the dean', $subjects->paginate($secretaryScope)['total'] === 1);
check('faculty read their department catalogue', $subjects->paginate($facultyScope)['total'] === 1);
check('a client department filter cannot widen the scope', $subjects->paginate($deanScope, ['department_id' => $cba])['total'] === 0);
check('the subject search matches code or title', $subjects->paginate($adminScope, ['search' => 'management'])['total'] === 1);
check('an out-of-scope subject is absent, not forbidden', $subjects->findInScope($deanScope, $subjectCba) === null);
check('an in-scope subject loads', $subjects->findInScope($deanScope, $subjectCas) !== null);
check('subject codes are unique across departments', $subjects->codeExists('it-201'));

$subjects->setActive($subjectCas, false);
check('subjects archive instead of deleting', $subjects->paginate($deanScope, ['status' => 'inactive'])['total'] === 1);
$subjects->setActive($subjectCas, true);

// ---------------------------------------------------------------------------
// Sections
// ---------------------------------------------------------------------------
$sectionCas = $sections->create(['code' => 'BSIT-2A', 'program' => 'BSIT', 'year_level' => 2, 'department_id' => $cas, 'student_count' => 42]);
$sectionCba = $sections->create(['code' => 'BSBA-1A', 'program' => 'BSBA', 'year_level' => 1, 'department_id' => $cba, 'student_count' => 50]);

check('sections are scoped by department', $sections->paginate($deanScope)['total'] === 1 && $sections->paginate($adminScope)['total'] === 2);
check('an out-of-scope section is a 404', $sections->findInScope($deanScope, $sectionCba) === null);
check('the year-level filter works inside the scope', $sections->paginate($deanScope, ['year_level' => 2])['total'] === 1);

// ---------------------------------------------------------------------------
// Faculty profiles and availability
// ---------------------------------------------------------------------------
check('only faculty accounts without a profile are eligible', count($faculty->availableUsers($adminScope)) === 2);
check('candidate lists are scoped', count($faculty->availableUsers($deanScope)) === 1);

$profileCas = $faculty->create([
    'faculty_id' => $facultyCasId, 'department_id' => $cas, 'employment_type' => 'full_time',
    'max_load_units' => 18, 'specialization' => 'Networks', 'office' => 'CAS 305',
]);
$profileCba = $faculty->create([
    'faculty_id' => $facultyCbaId, 'department_id' => $cba, 'employment_type' => 'part_time',
    'max_load_units' => 12,
]);

check('a profile links the account to the department', (int) $faculty->find($profileCas)['user_id'] === $facultyCasId);
check('the profile list is scoped for the dean', $faculty->paginate($deanScope)['total'] === 1);
check('faculty see only their own profile', count($faculty->paginate($facultyScope)['rows']) === 1
    && (int) $faculty->paginate($facultyScope)['rows'][0]['id'] === $profileCas);
check('faculty cannot load a colleague profile', $faculty->findInScope($facultyScope, $profileCba) === null);
check('the dean cannot load another department profile', $faculty->findInScope($deanScope, $profileCba) === null);
check('accounts with a profile disappear from the candidate list', count($faculty->availableUsers($adminScope)) === 0);
check('the edit form keeps the current account selectable', count($faculty->availableUsers($adminScope, $facultyCasId)) === 1);
check('employment type filters work', $faculty->paginate($adminScope, ['employment_type' => 'part_time'])['total'] === 1);

$window = $availability->create($profileCas, [
    'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
    'availability_type' => 'available', 'note' => 'Morning block',
]);
$availability->create($profileCas, [
    'day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '17:00', 'availability_type' => 'available',
]);

check('windows are stored for the profile', $availability->countForFaculty($profileCas) === 2);
check('adjacent windows do not overlap', !$availability->overlaps($profileCas, 1, '12:00:00', '13:00:00'));
check('an overlapping window is detected', $availability->overlaps($profileCas, 1, '11:00:00', '14:00:00'));
check('overlap is day-specific', !$availability->overlaps($profileCas, 2, '11:00:00', '14:00:00'));

$availability->delete($window);
check('a window can be removed', $availability->countForFaculty($profileCas) === 1);

// ---------------------------------------------------------------------------
// Terms: current year, current semester, uniqueness
// ---------------------------------------------------------------------------
$yearA = $terms->createYear(['code' => '2025-2026', 'start_date' => '2025-06-01', 'end_date' => '2026-05-31'], true);
$yearB = $terms->createYear(['code' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31'], false);

check('two academic years exist', $terms->years()['total'] === 2);
check('only one year is current', (int) $database->scalar('SELECT COUNT(*) FROM academic_years WHERE is_current = 1') === 1);

$terms->setCurrentYear($yearB);
check('setting a current year clears the previous', (int) $database->scalar('SELECT is_current FROM academic_years WHERE id = :id', ['id' => $yearA]) === 0
    && (int) $database->scalar('SELECT is_current FROM academic_years WHERE id = :id', ['id' => $yearB]) === 1);

$sem1 = $terms->createSemester([
    'academic_year_id' => $yearB, 'term_no' => 1, 'name' => 'First Semester',
    'code' => '2026-2027-1ST', 'start_date' => '2026-06-01', 'end_date' => '2026-10-31', 'status' => 'planned',
], true);
$sem2 = $terms->createSemester([
    'academic_year_id' => $yearB, 'term_no' => 2, 'name' => 'Second Semester',
    'code' => '2026-2027-2ND', 'start_date' => '2026-11-01', 'end_date' => '2027-03-31', 'status' => 'planned',
], false);

check('semesters are created under a year', $terms->semesters()['total'] === 2);
check('setting a current semester activates it', (int) $database->scalar('SELECT is_current FROM semesters WHERE id = :id', ['id' => $sem1]) === 1
    && (string) $database->scalar('SELECT status FROM semesters WHERE id = :id', ['id' => $sem1]) === 'active');

$terms->setCurrentSemester($sem2);
check('a second current semester clears the first', (int) $database->scalar('SELECT is_current FROM semesters WHERE id = :id', ['id' => $sem1]) === 0
    && (int) $database->scalar('SELECT is_current FROM semesters WHERE id = :id', ['id' => $sem2]) === 1);
check('the current semester is readable for later phases', (int) ($terms->currentSemester()['id'] ?? 0) === $sem2);
check('a term number is unique within its year', $terms->termTaken($yearB, 1) && !$terms->termTaken($yearB, 3));
check('semester codes are unique', $terms->semesterCodeExists('2026-2027-1ST'));

// ---------------------------------------------------------------------------
// HTTP: permissions, CSRF, scope, PRG
// ---------------------------------------------------------------------------
$kernel = Kernel::boot($testConfig);
$container = $kernel->container();
$container->instance(Database::class, $database);

$session = $container->get(Session::class);
$session->start();
$security = $container->get(Security::class);
$auth = $container->get(App\Services\AuthService::class);

$auth->loginUser($deanId);
$token = $security->csrfToken();

$deanSubjects = $kernel->handle(new Request('GET', '/master/subjects'));
check('the dean opens the subject list', $deanSubjects->status() === 200);
check('the list shows their department subject', str_contains($deanSubjects->body(), 'IT-201'));
check('the list hides another department subject', !str_contains($deanSubjects->body(), 'BA-101'));

$deanDepartments = $kernel->handle(new Request('GET', '/master/departments'));
check('the dean opens the department list', $deanDepartments->status() === 200);
check('the department list is scoped', str_contains($deanDepartments->body(), 'CAS') && !str_contains($deanDepartments->body(), 'CBA'));

$auth->loginUser($facultyCasId);
check('faculty may not open the subject form without subjects.create', $kernel->handle(new Request('GET', '/master/subjects/create'))->status() === 403);

$auth->loginUser($secretaryId);
check('the secretary may not create subjects', $kernel->handle(new Request('GET', '/master/subjects/create'))->status() === 403);
check('the secretary may open the subject list', $kernel->handle(new Request('GET', '/master/subjects'))->status() === 200);

$auth->loginUser($adminId);
// A sign-in rotates the CSRF token, so the token is read after the last login
// and reused for the write checks below.
$token = $security->csrfToken();
check('the administrator opens the subject form', $kernel->handle(new Request('GET', '/master/subjects/create'))->status() === 200);
check('the administrator opens the terms screens', $kernel->handle(new Request('GET', '/master/semesters'))->status() === 200);

// CSRF: a write without a token is refused before the repository is touched.
$noToken = $kernel->handle(new Request('POST', '/master/subjects', body: ['code' => 'IT-999', 'title' => 'No token']));
check('a write without a CSRF token is 419', $noToken->status() === 419);
check('the refused write inserted nothing', (int) $database->scalar('SELECT COUNT(*) FROM subjects WHERE code = :code', ['code' => 'IT-999']) === 0);

$created = $kernel->handle(new Request('POST', '/master/subjects', body: [
    '_token' => $token,
    'code' => 'IT-301',
    'title' => 'Operating Systems',
    'department_id' => $cas,
    'units' => '3',
    'lecture_hours' => '2',
    'lab_hours' => '1',
    'subject_type' => 'mixed',
]));
check('a valid subject is created with 303', $created->status() === 303);
check('the new subject is persisted', (int) $database->scalar('SELECT COUNT(*) FROM subjects WHERE code = :code', ['code' => 'IT-301']) === 1);
check('the write is audited', (int) $database->scalar("SELECT COUNT(*) FROM activity_log WHERE event = 'master.subject.created'") === 1);

$duplicate = $kernel->handle(new Request('POST', '/master/subjects', body: [
    '_token' => $token,
    'code' => 'IT-301',
    'title' => 'Duplicate',
    'department_id' => $cas,
    'units' => '3',
    'lecture_hours' => '3',
    'lab_hours' => '0',
    'subject_type' => 'lecture',
]));
check('a duplicate code is a 422', $duplicate->status() === 422);
check('the duplicate was not inserted twice', (int) $database->scalar('SELECT COUNT(*) FROM subjects WHERE code = :code', ['code' => 'IT-301']) === 1);

// Scope on write: the dean cannot post a subject into another department.
$auth->loginUser($deanId);
$token = $security->csrfToken();
$crossScope = $kernel->handle(new Request('POST', '/master/subjects', body: [
    '_token' => $token,
    'code' => 'BA-999',
    'title' => 'Smuggled subject',
    'department_id' => $cba,
    'units' => '3',
    'lecture_hours' => '3',
    'lab_hours' => '0',
    'subject_type' => 'lecture',
]));
check('a cross-department write is refused', $crossScope->status() === 403);
check('the smuggled subject does not exist', (int) $database->scalar('SELECT COUNT(*) FROM subjects WHERE code = :code', ['code' => 'BA-999']) === 0);
check('the refusal is audited', (int) $database->scalar("SELECT COUNT(*) FROM activity_log WHERE event = 'authz.denied'") >= 1);

// Archive through the route, then restore.
$toggle = $kernel->handle(new Request('POST', '/master/subjects/' . $subjectCas . '/toggle', body: ['_token' => $token]));
check('archiving redirects with 303', $toggle->status() === 303);
check('the subject is archived', (int) $database->scalar('SELECT is_active FROM subjects WHERE id = :id', ['id' => $subjectCas]) === 0);
$kernel->handle(new Request('POST', '/master/subjects/' . $subjectCas . '/toggle', body: ['_token' => $token]));
check('the subject can be restored', (int) $database->scalar('SELECT is_active FROM subjects WHERE id = :id', ['id' => $subjectCas]) === 1);

// Out-of-scope edit is a 404, not a form.
check('editing another department subject is 404', $kernel->handle(new Request('GET', '/master/subjects/' . $subjectCba . '/edit'))->status() === 404);

// Faculty availability: the owner may edit, a colleague may not.
$auth->loginUser($facultyCasId);
$token = $security->csrfToken();
check('faculty open their own availability page', $kernel->handle(new Request('GET', '/master/faculty/' . $profileCas . '/availability'))->status() === 200);
check('faculty cannot open a colleague availability page', $kernel->handle(new Request('GET', '/master/faculty/' . $profileCba . '/availability'))->status() === 404);

$windowForm = $kernel->handle(new Request('POST', '/master/faculty/' . $profileCas . '/availability', body: [
    '_token' => $token,
    'day_of_week' => '3',
    'start_time' => '09:00',
    'end_time' => '11:00',
    'availability_type' => 'preferred',
    'note' => 'Consultation',
]));
check('an availability window is added', $windowForm->status() === 303 && $availability->countForFaculty($profileCas) === 2);

$overlap = $kernel->handle(new Request('POST', '/master/faculty/' . $profileCas . '/availability', body: [
    '_token' => $token,
    'day_of_week' => '3',
    'start_time' => '10:00',
    'end_time' => '12:00',
    'availability_type' => 'available',
]));
check('an overlapping window is refused with 422', $overlap->status() === 422);
check('the overlapping window was not stored', $availability->countForFaculty($profileCas) === 2);

// Faculty cannot reach the profile editor at all.
check('faculty cannot edit their own profile', $kernel->handle(new Request('GET', '/master/faculty/' . $profileCas . '/edit'))->status() === 403);

// Terms through HTTP: create a year and make it current.
$auth->loginUser($adminId);
$token = $security->csrfToken();

$yearResponse = $kernel->handle(new Request('POST', '/master/years', body: [
    '_token' => $token,
    'code' => '2027-2028',
    'start_date' => '2027-06-01',
    'end_date' => '2028-05-31',
    'is_current' => '1',
]));
check('a new academic year is created', $yearResponse->status() === 303
    && (int) $database->scalar('SELECT COUNT(*) FROM academic_years WHERE code = :code', ['code' => '2027-2028']) === 1);
check('the new year became current alone', (int) $database->scalar('SELECT is_current FROM academic_years WHERE code = :code', ['code' => '2027-2028']) === 1
    && (int) $database->scalar('SELECT COUNT(*) FROM academic_years WHERE is_current = 1') === 1);

$badYear = $kernel->handle(new Request('POST', '/master/years', body: [
    '_token' => $token,
    'code' => '2027/2028',
    'start_date' => '2027-06-01',
    'end_date' => '2027-05-31',
]));
check('a malformed year code is rejected', $badYear->status() === 422);

$semesterResponse = $kernel->handle(new Request('POST', '/master/semesters', body: [
    '_token' => $token,
    'academic_year_id' => (string) $yearA,
    'term_no' => '1',
    'name' => 'First Semester',
    'code' => '2025-2026-1ST',
    'start_date' => '2025-06-01',
    'end_date' => '2025-10-31',
    'status' => 'planned',
    'is_current' => '1',
]));
check('a semester is created and made current', $semesterResponse->status() === 303
    && (int) $database->scalar('SELECT COUNT(*) FROM semesters WHERE code = :code', ['code' => '2025-2026-1ST']) === 1);

$duplicateTerm = $kernel->handle(new Request('POST', '/master/semesters', body: [
    '_token' => $token,
    'academic_year_id' => (string) $yearA,
    'term_no' => '1',
    'name' => 'Duplicate first semester',
    'code' => '2025-2026-1ST-DUP',
    'start_date' => '2025-06-01',
    'end_date' => '2025-10-31',
    'status' => 'planned',
]));
check('a duplicate term number is a 422', $duplicateTerm->status() === 422);

// Nav: the master-data sub-navigation reflects permissions.
$adminSubjects = $kernel->handle(new Request('GET', '/master/subjects'));
check('the sub-navigation links the modules', str_contains($adminSubjects->body(), '/master/rooms'));

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
