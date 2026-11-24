<?php

declare(strict_types=1);

/**
 * Reports test — Phase 15 exit criterion (RPT-01…RPT-07, read side).
 *
 *     php tests/reports_test.php
 *
 * Three things are asserted here, because they fail in different ways:
 *
 *   1. scope isolation — the administrator sees the institution, the dean and
 *      secretary their department, faculty only their own meetings. The same
 *      fixture is read through every report so a scope leak in one query is
 *      caught even when the others are correct;
 *   2. utilization math — a known term (four teaching weeks, 11 usable hours a
 *      weekday) with known bookings: the expected hours and percentages are
 *      spelled out in the assertions rather than recomputed by the code under
 *      test;
 *   3. the empty term — a term with no schedules renders zero totals, keeps
 *      the room inventory, and an unknown `?semester=` falls back to the
 *      current term instead of showing an empty report.
 *
 * HTTP is exercised last: the six routes render for each role, the page shows
 * the scope it was rendered with, and an anonymous visitor is redirected.
 *
 * The production reports SQL is MySQL-flavoured (TIME_TO_SEC, TIMEDIFF,
 * FIELD). The in-memory SQLite mirror used by the other suites has no such
 * functions, so this file registers faithful shims on the test connection —
 * the repository SQL itself runs unchanged. These shims are test-only.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\BuildingRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\ReportRepository;
use App\Repositories\RoleRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SemesterRepository;
use App\Repositories\UserRepository;
use App\Services\RbacService;
use App\Services\ReportService;
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

// ---------------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------------
$testConfig = $config;
$testConfig['security']['password']['options']['cost'] = 4;
$testConfig['app']['session']['name'] = 'syncclass_reports_test';

$configObject = new Config($testConfig);

$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
auth_schema($database);
master_schema($database);
schedule_schema($database);

/**
 * MySQL time functions used by ReportRepository, mirrored for SQLite.
 *
 * TIME_TO_SEC('10:30:00') → 37800, TIMEDIFF('10:30:00', '09:00:00') →
 * '01:30:00', FIELD(value, a, b, …) → 1-based position or 0.
 */
$pdo = $database->pdo();
$pdo->sqliteCreateFunction('TIME_TO_SEC', static function (mixed $time): float {
    if (!is_string($time) || preg_match('/^-?(\d+):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
        return 0.0;
    }

    // A float keeps `TIME_TO_SEC(...) / 3600` a decimal division, as MySQL's
    // `/` always is; SQLite would otherwise truncate 5400 / 3600 to 1.
    return (float) (((int) $parts[1]) * 3600 + ((int) $parts[2]) * 60 + (int) $parts[3]);
}, 1);
$pdo->sqliteCreateFunction('TIMEDIFF', static function (mixed $end, mixed $start): string {
    $seconds = (static function (mixed $time): int {
        if (!is_string($time) || preg_match('/^(\d+):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            return 0;
        }

        return ((int) $parts[1]) * 3600 + ((int) $parts[2]) * 60 + (int) $parts[3];
    })(is_string($end) ? $end : '') - (static function (mixed $time): int {
        if (!is_string($time) || preg_match('/^(\d+):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            return 0;
        }

        return ((int) $parts[1]) * 3600 + ((int) $parts[2]) * 60 + (int) $parts[3];
    })(is_string($start) ? $start : '');

    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);

    return sprintf('%s%02d:%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}, 2);
$pdo->sqliteCreateFunction('FIELD', static function (mixed $value, mixed ...$list): int {
    foreach ($list as $index => $candidate) {
        if ((string) $candidate === (string) $value) {
            return $index + 1;
        }
    }

    return 0;
}, -1);

// schedule_conflicts is only read by RPT-04 and the hub's open-conflict count.
$pdo->exec(
    'CREATE TABLE schedule_conflicts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        semester_id INTEGER NOT NULL,
        primary_schedule_id INTEGER NOT NULL,
        secondary_schedule_id INTEGER NULL,
        rule_code TEXT NOT NULL,
        conflict_type TEXT NOT NULL,
        severity TEXT NOT NULL DEFAULT "error",
        status TEXT NOT NULL DEFAULT "open",
        message TEXT NULL,
        detected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at TEXT NULL,
        resolution_note TEXT NULL
    )'
);

(new RbacService(new RoleRepository($database), new PermissionRepository($database), $configObject))->synchronise();

// ---------------------------------------------------------------------------
// Fixture: one CAS department with two teachers, one CBA teacher, three rooms
// ---------------------------------------------------------------------------
$database->insert('departments', ['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
$database->insert('departments', ['code' => 'CBA', 'name' => 'College of Business Administration']);
$cas = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CAS']);
$cba = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CBA']);

$adminId = auth_create_user($database, ['email' => 'admin@sac.edu.ph', 'role_code' => 'administrator', 'first_name' => 'Ana', 'last_name' => 'Reyes']);
$deanId = auth_create_user($database, ['email' => 'dean.cas@sac.edu.ph', 'role_code' => 'dean', 'first_name' => 'Maria', 'last_name' => 'Santos', 'department_id' => $cas]);
$secretaryId = auth_create_user($database, ['email' => 'secretary.cas@sac.edu.ph', 'role_code' => 'secretary', 'first_name' => 'Liza', 'last_name' => 'Bautista', 'department_id' => $cas]);
$facultyCasId = auth_create_user($database, ['email' => 'faculty.cas@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Juan', 'last_name' => 'Cruz', 'department_id' => $cas]);
$facultyCas2Id = auth_create_user($database, ['email' => 'faculty2.cas@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Pedro', 'last_name' => 'Garcia', 'department_id' => $cas]);
$facultyCbaId = auth_create_user($database, ['email' => 'faculty.cba@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Rosa', 'last_name' => 'Lim', 'department_id' => $cba]);

$users = new UserRepository($database);
$admin = $users->findById($adminId);
$dean = $users->findById($deanId);
$secretary = $users->findById($secretaryId);
$facultyCas = $users->findById($facultyCasId);

foreach ([$admin, $dean, $secretary, $facultyCas] as $row) {
    if (!is_array($row)) {
        fwrite(STDERR, "Fixture error: a user row could not be loaded.\n");
        exit(1);
    }
}

$buildings = new BuildingRepository($database);
$main = $buildings->create(['code' => 'MB', 'name' => 'Main Building', 'campus' => 'Sta. Ana']);
$annex = $buildings->create(['code' => 'AB', 'name' => 'Annex Building', 'campus' => 'Sta. Ana']);

$rooms = new App\Repositories\RoomRepository($database);
$room204 = $rooms->create(['building_id' => $main, 'code' => '204', 'name' => 'Lecture 204', 'room_type' => 'lecture', 'capacity' => 45]);
$room101 = $rooms->create(['building_id' => $main, 'code' => '101', 'name' => 'Lab 101', 'room_type' => 'laboratory', 'capacity' => 30]);
$room201 = $rooms->create(['building_id' => $annex, 'code' => '201', 'name' => 'Annex 201', 'room_type' => 'lecture', 'capacity' => 40]);

$subjects = new App\Repositories\SubjectRepository($database);
$subjectIt201 = $subjects->create(['code' => 'IT-201', 'title' => 'Data Structures', 'department_id' => $cas, 'units' => 3, 'lecture_hours' => 2, 'lab_hours' => 1, 'subject_type' => 'mixed']);
$subjectIt202 = $subjects->create(['code' => 'IT-202', 'title' => 'Operating Systems', 'department_id' => $cas, 'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_type' => 'lecture']);
$subjectBa101 = $subjects->create(['code' => 'BA-101', 'title' => 'Principles of Management', 'department_id' => $cba, 'units' => 3, 'lecture_hours' => 3, 'lab_hours' => 0, 'subject_type' => 'lecture']);

$sections = new App\Repositories\SectionRepository($database);
$sectionCas = $sections->create(['code' => 'BSIT-2A', 'program' => 'BSIT', 'year_level' => 2, 'department_id' => $cas, 'student_count' => 40]);
$sectionCas2 = $sections->create(['code' => 'BSIT-2B', 'program' => 'BSIT', 'year_level' => 2, 'department_id' => $cas, 'student_count' => 38]);
$sectionCba = $sections->create(['code' => 'BSBA-1A', 'program' => 'BSBA', 'year_level' => 1, 'department_id' => $cba, 'student_count' => 50]);

$faculty = new App\Repositories\FacultyRepository($database);
$profileCas = $faculty->create(['faculty_id' => $facultyCasId, 'department_id' => $cas, 'employment_type' => 'full_time', 'max_load_units' => 18]);
$profileCas2 = $faculty->create(['faculty_id' => $facultyCas2Id, 'department_id' => $cas, 'employment_type' => 'part_time', 'max_load_units' => 12]);
$profileCba = $faculty->create(['faculty_id' => $facultyCbaId, 'department_id' => $cba, 'employment_type' => 'full_time', 'max_load_units' => 18]);

// Terms. A is the current, known term; B is history; C is empty.
$terms = new App\Repositories\TermRepository($database);
$year = $terms->createYear(['code' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31'], true);
$termA = $terms->createSemester([
    'academic_year_id' => $year, 'term_no' => 1, 'name' => 'First Semester',
    'code' => '2026-2027-1ST', 'start_date' => '2026-06-01', 'end_date' => '2026-06-26',
    'status' => 'active',
], true);
$terms->createSemester([
    'academic_year_id' => $year, 'term_no' => 2, 'name' => 'Second Semester',
    'code' => '2026-2027-2ND', 'start_date' => '2026-11-03', 'end_date' => '2027-03-31',
    'status' => 'planned',
], false);
$termC = $terms->createSemester([
    'academic_year_id' => $year, 'term_no' => 3, 'name' => 'Summer',
    'code' => '2026-2027-SUM', 'start_date' => '2026-08-03', 'end_date' => '2026-08-14',
    'status' => 'planned',
], false);

$scheduleRows = [
    // Faculty 1, room 204: one 1.5 h meeting on Monday and one on Wednesday.
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt201, 'faculty_id' => $profileCas, 'room_id' => $room204, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '10:30:00', 'recurrence' => 'weekly', 'status' => 'published'],
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt201, 'faculty_id' => $profileCas, 'room_id' => $room204, 'day_of_week' => 3, 'start_time' => '09:00:00', 'end_time' => '10:30:00', 'recurrence' => 'weekly', 'status' => 'published'],
    // Faculty 1, room 101: one 2 h weekly meeting on Tuesday.
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt202, 'faculty_id' => $profileCas, 'room_id' => $room101, 'day_of_week' => 2, 'start_time' => '08:00:00', 'end_time' => '10:00:00', 'recurrence' => 'weekly', 'status' => 'published'],
    // Faculty 1, room 204: a 1 h one-off on Wednesday 2026-06-03 (counts once).
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt202, 'faculty_id' => $profileCas, 'room_id' => $room204, 'day_of_week' => 3, 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'meeting_date' => '2026-06-03', 'recurrence' => 'one_off', 'status' => 'published'],
    // Faculty 2, room 101: a 1 h weekly meeting on Friday (same department).
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas2, 'subject_id' => $subjectIt201, 'faculty_id' => $profileCas2, 'room_id' => $room101, 'day_of_week' => 5, 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'recurrence' => 'weekly', 'status' => 'published'],
    // CBA teacher, room 201: a 1.5 h weekly meeting on Thursday.
    ['semester_id' => $termA, 'department_id' => $cba, 'section_id' => $sectionCba, 'subject_id' => $subjectBa101, 'faculty_id' => $profileCba, 'room_id' => $room201, 'day_of_week' => 4, 'start_time' => '08:00:00', 'end_time' => '09:30:00', 'recurrence' => 'weekly', 'status' => 'published'],
];

$scheduleIds = [];

foreach ($scheduleRows as $row) {
    $scheduleIds[] = $database->insert('schedules', $row);
}

// Two persisted conflicts: one open CAS room clash, one resolved CBA clash.
$database->insert('schedule_conflicts', [
    'semester_id' => $termA, 'primary_schedule_id' => $scheduleIds[0], 'rule_code' => 'BR-02',
    'conflict_type' => 'room', 'severity' => 'error', 'status' => 'open',
    'message' => 'Room 204 is already occupied.',
]);
$database->insert('schedule_conflicts', [
    'semester_id' => $termA, 'primary_schedule_id' => $scheduleIds[5], 'rule_code' => 'BR-03',
    'conflict_type' => 'faculty', 'severity' => 'warning', 'status' => 'resolved',
    'message' => 'Teacher double-booked.',
    'resolved_at' => '2026-06-02 10:00:00', 'resolution_note' => 'Moved to Friday.',
]);

$service = new ReportService(
    new ReportRepository($database),
    new SemesterRepository($database),
    new ScheduleRepository($database),
    $configObject
);

$adminScope = Scope::forUser($admin);
$deanScope = Scope::forUser($dean);
$secretaryScope = Scope::forUser($secretary);
$facultyScope = Scope::forUser($facultyCas);

/** Find a row by one column without depending on row order. */
$rowWhere = static function (array $rows, string $column, string $value): ?array {
    foreach ($rows as $row) {
        if ((string) ($row[$column] ?? '') === $value) {
            return $row;
        }
    }

    return null;
};

/** Total a column across rows. */
$sum = static function (array $rows, string $column): float {
    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float) ($row[$column] ?? 0);
    }

    return $total;
};

// ---------------------------------------------------------------------------
// 1. Scope isolation (RPT-01…RPT-05)
// ---------------------------------------------------------------------------
$adminLoad = $service->facultyLoad($admin, $termA);
$deanLoad = $service->facultyLoad($dean, $termA);
$secretaryLoad = $service->facultyLoad($secretary, $termA);
$facultyLoad = $service->facultyLoad($facultyCas, $termA);

check('the administrator load report lists every active teacher', count($adminLoad['rows']) === 3);
check('the dean load report lists only their department', count($deanLoad['rows']) === 2);
check('the secretary sees the same department as the dean', count($secretaryLoad['rows']) === 2);
check('a teacher load report lists only themselves', count($facultyLoad['rows']) === 1);
check('the faculty row is the signed-in teacher', (string) ($facultyLoad['rows'][0]['email'] ?? '') === 'faculty.cas@sac.edu.ph');
check('the dean rows are both CAS teachers', $rowWhere($deanLoad['rows'], 'email', 'faculty.cas@sac.edu.ph') !== null
    && $rowWhere($deanLoad['rows'], 'email', 'faculty2.cas@sac.edu.ph') !== null);
check('the dean never sees the CBA teacher', $rowWhere($deanLoad['rows'], 'email', 'faculty.cba@sac.edu.ph') === null);
check('the administrator does see the CBA teacher', $rowWhere($adminLoad['rows'], 'email', 'faculty.cba@sac.edu.ph') !== null);

check('institution load totals are exact', $adminLoad['totals']['teachers'] === 3
    && $adminLoad['totals']['units'] === 12.0
    && $adminLoad['totals']['hours'] === 8.5
    && $adminLoad['totals']['meetings'] === 6, json_encode($adminLoad['totals']));
check('department load totals are exact', $deanLoad['totals']['teachers'] === 2
    && $deanLoad['totals']['units'] === 9.0
    && $deanLoad['totals']['hours'] === 7.0
    && $deanLoad['totals']['meetings'] === 5, json_encode($deanLoad['totals']));
check('a teacher load total is exact', $facultyLoad['totals']['teachers'] === 1
    && $facultyLoad['totals']['units'] === 6.0
    && $facultyLoad['totals']['hours'] === 6.0
    && $facultyLoad['totals']['meetings'] === 4, json_encode($facultyLoad['totals']));

$facultyRow = $facultyLoad['rows'][0] ?? [];
check('units count each allocation once, not once per meeting', (float) ($facultyRow['units'] ?? 0) === 6.0
    && (int) ($facultyRow['subjects'] ?? 0) === 2
    && (int) ($facultyRow['sections'] ?? 0) === 1, json_encode($facultyRow));
check('contact hours do count every meeting', (float) ($facultyRow['hours'] ?? 0) === 6.0
    && (int) ($facultyRow['meetings'] ?? 0) === 4);
check('the under-loaded band is derived from the cap', (float) ($facultyRow['cap_share'] ?? 0) === 33.3
    && (string) ($facultyRow['band'] ?? '') === 'under');
check('a part-time cap changes the share', (float) ($rowWhere($deanLoad['rows'], 'email', 'faculty2.cas@sac.edu.ph')['cap_share'] ?? 0) === 25.0);
check('no teacher is over the cap in this fixture', $adminLoad['totals']['over'] === 0);

$adminRooms = $service->roomUtilization($admin, $termA);
$deanRooms = $service->roomUtilization($dean, $termA);
$facultyRooms = $service->roomUtilization($facultyCas, $termA);

check('the administrator sees the whole room inventory', count($adminRooms['rows']) === 3
    && $rowWhere($adminRooms['rows'], 'room_code', '201') !== null);
check('the dean sees only rooms their department books', count($deanRooms['rows']) === 2
    && $rowWhere($deanRooms['rows'], 'room_code', '201') === null);
check('faculty see only the rooms they teach in', count($facultyRooms['rows']) === 2);

$adminDepts = $service->departmentSchedule($admin, $termA);
$deanDepts = $service->departmentSchedule($dean, $termA);
$facultyDepts = $service->departmentSchedule($facultyCas, $termA);

check('the administrator sees both departments', count($adminDepts['rows']) === 2);
check('the dean sees one department row', count($deanDepts['rows']) === 1
    && (string) ($deanDepts['rows'][0]['department_code'] ?? '') === 'CAS');
check('the faculty department row counts only their meetings', (int) ($facultyDepts['rows'][0]['meetings'] ?? 0) === 4);
check('department hours are exact', (float) ($rowWhere($adminDepts['rows'], 'department_code', 'CAS')['hours'] ?? 0) === 7.0
    && (float) ($rowWhere($adminDepts['rows'], 'department_code', 'CBA')['hours'] ?? 0) === 1.5);

$adminBoard = $service->timetable($admin, $termA);
$deanBoard = $service->timetable($dean, $termA);
$facultyBoard = $service->timetable($facultyCas, $termA);

check('the institution timetable prints every meeting', $adminBoard['meetings'] === 6);
check('the department timetable hides the other department', $deanBoard['meetings'] === 5);
check('the teacher timetable prints only their own meetings', $facultyBoard['meetings'] === 4);
check('the timetable total hours are exact', $adminBoard['total_hours'] === 8.5);
check('the weekly grid groups a shared start time', count($adminBoard['grid']['09:00–10:30'] ?? []) === 2);
check('the faculty filter narrows the dean view', count($service->timetable($dean, $termA, ['faculty_id' => $profileCas2])['rows']) === 1);
check('a filter cannot widen the dean scope', $service->timetable($dean, $termA, ['faculty_id' => $profileCba])['rows'] === []
    && $service->timetable($dean, $termA, ['department_id' => $cba])['rows'] === []);
check('a filter cannot widen the faculty scope', $service->timetable($facultyCas, $termA, ['faculty_id' => $profileCas2])['rows'] === []);
check('an in-scope department filter keeps the rows', count($service->timetable($dean, $termA, ['department_id' => $cas])['rows']) === 5);

$adminConflicts = $service->conflicts($admin, $termA);
$deanConflicts = $service->conflicts($dean, $termA);
$facultyConflicts = $service->conflicts($facultyCas, $termA);

check('the administrator reads the whole conflict log', count($adminConflicts['rows']) === 2);
check('conflict status and severity are counted', $adminConflicts['counts']['open'] === 1
    && $adminConflicts['counts']['resolved'] === 1
    && $adminConflicts['counts']['errors'] === 1
    && $adminConflicts['counts']['warnings'] === 1);
check('the dean reads only their department conflicts', count($deanConflicts['rows']) === 1
    && (string) ($deanConflicts['rows'][0]['department_code'] ?? '') === 'CAS');
check('faculty read only their own conflicts', count($facultyConflicts['rows']) === 1);
check('the status filter is applied inside the scope', $service->conflicts($dean, $termA, 'resolved')['rows'] === []);

$adminOverview = $service->overview($admin, $termA);
$deanOverview = $service->overview($dean, $termA);
$facultyOverview = $service->overview($facultyCas, $termA);

check('hub summary is exact for the administrator', $adminOverview['summary'] === [
    'meetings' => 6, 'teachers' => 3, 'rooms' => 3, 'open_conflicts' => 1,
], json_encode($adminOverview['summary']));
check('hub summary is scoped for the dean', $deanOverview['summary'] === [
    'meetings' => 5, 'teachers' => 2, 'rooms' => 2, 'open_conflicts' => 1,
], json_encode($deanOverview['summary']));
check('hub summary is scoped for faculty', $facultyOverview['summary'] === [
    'meetings' => 4, 'teachers' => 1, 'rooms' => 2, 'open_conflicts' => 1,
], json_encode($facultyOverview['summary']));
check('scope labels describe each audience', $service->scopeLabel($admin) === 'Institution-wide'
    && $service->scopeLabel($dean) === 'Department — College of Arts and Sciences'
    && $service->scopeLabel($facultyCas) === 'Your own classes only');

// ---------------------------------------------------------------------------
// 2. Room utilization math against the known term
//
// Term A runs 2026-06-01 (Monday) – 2026-06-26 (Friday): 20 weekdays, four
// teaching weeks. The policy window is 07:00–19:00 minus the 12:00–13:00
// break = 11 usable hours a weekday, so one room has 20 × 11 = 220 bookable
// hours in the term.
// ---------------------------------------------------------------------------
check('the term has 20 bookable weekdays', $adminRooms['availability']['business_days'] === 20);
check('a weekday has 11 usable hours after the break', (float) $adminRooms['availability']['daily_hours'] === 11.0);
check('the term has four teaching weeks', $adminRooms['availability']['weeks'] === 4);
check('one room has 220 bookable hours', (float) ($rowWhere($adminRooms['rows'], 'room_code', '204')['available_hours'] ?? 0) === 220.0);

$room204Row = $rowWhere($adminRooms['rows'], 'room_code', '204') ?? [];
$room101Row = $rowWhere($adminRooms['rows'], 'room_code', '101') ?? [];
$room201Row = $rowWhere($adminRooms['rows'], 'room_code', '201') ?? [];

check('room 204 counts three meetings', (int) ($room204Row['meetings'] ?? 0) === 3);
check('room 204 separates weekly from one-off hours', (float) ($room204Row['weekly_hours'] ?? 0) === 3.0
    && (float) ($room204Row['one_off_hours'] ?? 0) === 1.0);
// 3 h/week × 4 weeks + 1 h one-off = 13 h of 220 h = 5.9 %.
check('room 204 booked hours expand over the teaching weeks', (float) ($room204Row['booked_hours'] ?? 0) === 13.0);
check('room 204 utilization is exact', (float) ($room204Row['utilization'] ?? 0) === 5.9
    && (string) ($room204Row['band'] ?? '') === 'low');
// 3 h/week × 4 weeks = 12 h of 220 h = 5.5 %.
check('room 101 booked hours are exact', (float) ($room101Row['booked_hours'] ?? 0) === 12.0
    && (float) ($room101Row['utilization'] ?? 0) === 5.5);
// 1.5 h/week × 4 weeks = 6 h of 220 h = 2.7 %.
check('the CBA room booked hours are exact', (float) ($room201Row['booked_hours'] ?? 0) === 6.0
    && (float) ($room201Row['utilization'] ?? 0) === 2.7);

check('room totals add up', (int) $adminRooms['totals']['rooms'] === 3
    && (int) $adminRooms['totals']['meetings'] === 6
    && (float) $adminRooms['totals']['booked_hours'] === 31.0
    && (float) $adminRooms['totals']['available_hours'] === 660.0
    && (float) $adminRooms['totals']['utilization'] === 4.7, json_encode($adminRooms['totals']));

// The dean's denominator is the two rooms their department actually uses.
check('the dean utilization denominator follows the visible rooms', (float) $deanRooms['totals']['booked_hours'] === 25.0
    && (float) $deanRooms['totals']['available_hours'] === 440.0
    && (float) $deanRooms['totals']['utilization'] === 5.7, json_encode($deanRooms['totals']));
check('the building roll-up sums rooms back to the total', (float) $sum($adminRooms['buildings'], 'booked_hours') === 31.0
    && (float) $sum($adminRooms['buildings'], 'available_hours') === 660.0);
check('the building roll-up is ordered by utilization', count($adminRooms['buildings']) === 2
    && (string) ($adminRooms['buildings'][0]['building_code'] ?? '') === 'MB');

// A cancelled meeting frees its room: mark the CBA meeting cancelled and check
// the CBA room drops to zero without touching the other rooms.
$database->update('schedules', ['status' => 'cancelled'], 'id = :id', ['id' => $scheduleIds[5]]);
$afterCancel = $service->roomUtilization($admin, $termA);
check('a cancelled meeting stops counting against the room', (float) ($rowWhere($afterCancel['rows'], 'room_code', '201')['booked_hours'] ?? -1) === 0.0);
check('cancelling one meeting leaves the other rooms intact', (float) ($rowWhere($afterCancel['rows'], 'room_code', '204')['booked_hours'] ?? 0) === 13.0);
$database->update('schedules', ['status' => 'published'], 'id = :id', ['id' => $scheduleIds[5]]);

// ---------------------------------------------------------------------------
// 3. The empty term
//
// Term C runs 2026-08-03 (Monday) – 2026-08-14 (Friday): 10 weekdays, two
// teaching weeks, 110 bookable hours per room. It has no schedules.
// ---------------------------------------------------------------------------
$emptyContext = $service->context($admin, $termC);
check('an explicit term is honoured', (int) ($emptyContext['semester']['id'] ?? 0) === $termC
    && (string) ($emptyContext['semester']['code'] ?? '') === '2026-2027-SUM');

$emptyOverview = $service->overview($admin, $termC);
check('an empty term renders zero headline numbers', $emptyOverview['summary'] === [
    'meetings' => 0, 'teachers' => 0, 'rooms' => 0, 'open_conflicts' => 0,
], json_encode($emptyOverview['summary']));

$emptyLoad = $service->facultyLoad($admin, $termC);
check('an empty term still lists the active teachers', count($emptyLoad['rows']) === 3);
check('every teacher shows zero load in an empty term', $emptyLoad['totals']['units'] === 0.0
    && $emptyLoad['totals']['hours'] === 0.0
    && $emptyLoad['totals']['meetings'] === 0);
check('a faculty report in an empty term shows only themselves at zero', count($service->facultyLoad($facultyCas, $termC)['rows']) === 1
    && (int) $service->facultyLoad($facultyCas, $termC)['totals']['meetings'] === 0);

$emptyRooms = $service->roomUtilization($admin, $termC);
check('an empty term keeps the room inventory', count($emptyRooms['rows']) === 3);
check('an empty term has 10 weekdays and two teaching weeks', $emptyRooms['availability']['business_days'] === 10
    && $emptyRooms['availability']['weeks'] === 2);
check('an empty term has zero booked hours', (float) $emptyRooms['totals']['booked_hours'] === 0.0
    && (float) $emptyRooms['totals']['available_hours'] === 330.0
    && (float) $emptyRooms['totals']['utilization'] === 0.0, json_encode($emptyRooms['totals']));

$emptyBoard = $service->timetable($admin, $termC);
check('an empty term has an empty timetable', $emptyBoard['rows'] === []
    && $emptyBoard['meetings'] === 0
    && $emptyBoard['total_hours'] === 0.0
    && $emptyBoard['grid'] === []);
check('an empty term has no department rows', $service->departmentSchedule($admin, $termC)['rows'] === []
    && $service->departmentSchedule($admin, $termC)['totals']['departments'] === 0);
check('an empty term has no conflicts', $service->conflicts($admin, $termC)['rows'] === []
    && $service->conflicts($admin, $termC)['counts']['open'] === 0);

// An unknown term id must not produce a confusing empty report.
$unknownContext = $service->context($admin, 999999);
check('an unknown term falls back to the current term', (int) ($unknownContext['semester']['id'] ?? 0) === $termA);
check('a null term uses the current term', (int) ($service->context($admin, null)['semester']['id'] ?? 0) === $termA);

// ---------------------------------------------------------------------------
// 4. HTTP: routes, roles, scope label, fallbacks
// ---------------------------------------------------------------------------
$kernel = Kernel::boot($testConfig);
$container = $kernel->container();
$container->instance(Database::class, $database);

$session = $container->get(Session::class);
$session->start();
$auth = $container->get(App\Services\AuthService::class);

$anonymous = $kernel->handle(new Request('GET', '/reports'));
check('an anonymous visitor is redirected to the login page', $anonymous->status() === 302
    && str_ends_with((string) $anonymous->header('Location'), '/login'));

$auth->loginUser($adminId);
$adminHub = $kernel->handle(new Request('GET', '/reports'));
check('the administrator opens the reports hub', $adminHub->status() === 200);
check('the hub states the institution-wide scope', str_contains($adminHub->body(), 'Scope: Institution-wide'));
check('the hub names the selected term', str_contains($adminHub->body(), '2026-2027-1ST'));

$adminFaculty = $kernel->handle(new Request('GET', '/reports/faculty-load'));
check('the administrator opens the faculty load report', $adminFaculty->status() === 200);
check('the institution report shows both departments', str_contains($adminFaculty->body(), 'faculty.cas@sac.edu.ph')
    && str_contains($adminFaculty->body(), 'faculty.cba@sac.edu.ph'));

$adminRoomsPage = $kernel->handle(new Request('GET', '/reports/rooms'));
check('the administrator opens the room report', $adminRoomsPage->status() === 200);
check('the room report prints every room code', str_contains($adminRoomsPage->body(), '204')
    && str_contains($adminRoomsPage->body(), '101')
    && str_contains($adminRoomsPage->body(), '201'));

$auth->loginUser($deanId);
$deanFaculty = $kernel->handle(new Request('GET', '/reports/faculty-load'));
check('the dean opens the faculty load report', $deanFaculty->status() === 200);
check('the dean report names their department', str_contains($deanFaculty->body(), 'College of Arts and Sciences'));
check('the dean report hides the other department teacher', !str_contains($deanFaculty->body(), 'faculty.cba@sac.edu.ph'));

$deanRoomsPage = $kernel->handle(new Request('GET', '/reports/rooms'));
check('the dean room report keeps the department rooms', $deanRoomsPage->status() === 200
    && str_contains($deanRoomsPage->body(), '204') && str_contains($deanRoomsPage->body(), '101'));
check('the dean room report hides the other building', !str_contains($deanRoomsPage->body(), 'Annex 201'));

$deanBoardPage = $kernel->handle(new Request('GET', '/reports/timetable'));
check('the dean opens the timetable', $deanBoardPage->status() === 200
    && str_contains($deanBoardPage->body(), 'IT-201'));
check('the dean timetable hides the other department subject', !str_contains($deanBoardPage->body(), 'BA-101'));

$auth->loginUser($facultyCasId);
$facultyFaculty = $kernel->handle(new Request('GET', '/reports/faculty-load'));
check('a teacher opens their own load report', $facultyFaculty->status() === 200);
check('the teacher report lists only themselves', str_contains($facultyFaculty->body(), 'faculty.cas@sac.edu.ph')
    && !str_contains($facultyFaculty->body(), 'faculty2.cas@sac.edu.ph')
    && !str_contains($facultyFaculty->body(), 'faculty.cba@sac.edu.ph'));
check('the teacher scope label explains the filter', str_contains($facultyFaculty->body(), 'Your own classes only'));

$facultyBoardPage = $kernel->handle(new Request('GET', '/reports/timetable'));
check('a teacher opens their own timetable', $facultyBoardPage->status() === 200
    && str_contains($facultyBoardPage->body(), 'IT-201'));
check('a teacher timetable hides another department', !str_contains($facultyBoardPage->body(), 'BA-101'));

foreach (['/reports', '/reports/faculty-load', '/reports/rooms', '/reports/departments', '/reports/conflicts', '/reports/timetable'] as $route) {
    check('the faculty user loads ' . $route . ' without error', $kernel->handle(new Request('GET', $route))->status() === 200);
}

$auth->loginUser($adminId);
$emptyPage = $kernel->handle(new Request('GET', '/reports', ['semester' => $termC]));
check('an empty term renders over HTTP', $emptyPage->status() === 200
    && str_contains($emptyPage->body(), '2026-2027-SUM'));
$unknownPage = $kernel->handle(new Request('GET', '/reports', ['semester' => 999999]));
check('an unknown semester over HTTP falls back to the current term', $unknownPage->status() === 200
    && str_contains($unknownPage->body(), '2026-2027-1ST'));

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
