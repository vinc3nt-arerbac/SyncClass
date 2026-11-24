<?php

declare(strict_types=1);

/**
 * Dashboard test — Phase 16 exit criterion (DASH-01…DASH-03).
 *
 *     php tests/dashboard_test.php
 *
 * What is asserted, and why each part can fail independently:
 *
 *   1. role-aware widgets — the administrator sees institution totals, a dean
 *      their department, a teacher their own day and rooms. The same fixture is
 *      read through all three audiences, so a scope leak in one aggregate is
 *      caught even when the others are right;
 *   2. the agenda — "today's classes" and the seven-day look-ahead are expanded
 *      from the weekly timetable plus one-offs. The clock is pinned to Monday
 *      2026-06-01 so the assertions do not depend on the day the suite runs;
 *   3. chart data (DASH-03) — the JSON endpoint returns label/data series from
 *      the same scoped queries, and the rendered page carries an endpoint URL
 *      and canvases but no dataset;
 *   4. HTTP — the dashboard is every authenticated role's landing page, an
 *      anonymous visitor is redirected, and the chart endpoint is per-user
 *      `no-store` JSON.
 *
 * The suite uses the in-memory SQLite mirror shared by the other suites. The
 * dashboard's own aggregates avoid MySQL-only functions, but the widgets reuse
 * the Phase 15 report read models (faculty load, room utilization), which are
 * MySQL-flavoured; the test connection therefore registers the same test-only
 * `TIME_TO_SEC` / `TIMEDIFF` / `FIELD` shims as `tests/reports_test.php`.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Session;
use App\Repositories\AnalyticsRepository;
use App\Repositories\BuildingRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SemesterRepository;
use App\Repositories\UserRepository;
use App\Services\DashboardService;
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
$testConfig['app']['session']['name'] = 'syncclass_dashboard_test';

$configObject = new Config($testConfig);

$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
auth_schema($database);
master_schema($database);
schedule_schema($database);

// MySQL time functions used by the reused report read models (test-only).
$pdo = $database->pdo();
$pdo->sqliteCreateFunction('TIME_TO_SEC', static function (mixed $time): float {
    if (!is_string($time) || preg_match('/^-?(\d+):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
        return 0.0;
    }

    return (float) (((int) $parts[1]) * 3600 + ((int) $parts[2]) * 60 + (int) $parts[3]);
}, 1);
$pdo->sqliteCreateFunction('TIMEDIFF', static function (mixed $end, mixed $start): string {
    $secondsOf = static function (mixed $time): int {
        if (!is_string($time) || preg_match('/^(\d+):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            return 0;
        }

        return ((int) $parts[1]) * 3600 + ((int) $parts[2]) * 60 + (int) $parts[3];
    };
    $seconds = $secondsOf($end) - $secondsOf($start);
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

// The dashboard reads the persisted conflict log for its counter.
$database->pdo()->exec(
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
// Fixture: CAS with two teachers, one CBA teacher, three rooms
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
$facultyCas2 = $users->findById($facultyCas2Id);

foreach ([$admin, $dean, $secretary, $facultyCas, $facultyCas2] as $row) {
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

$terms = new App\Repositories\TermRepository($database);
$year = $terms->createYear(['code' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31'], true);
$termA = $terms->createSemester([
    'academic_year_id' => $year, 'term_no' => 1, 'name' => 'First Semester',
    'code' => '2026-2027-1ST', 'start_date' => '2026-06-01', 'end_date' => '2026-06-26',
    'status' => 'active',
], true);
$termC = $terms->createSemester([
    'academic_year_id' => $year, 'term_no' => 3, 'name' => 'Summer',
    'code' => '2026-2027-SUM', 'start_date' => '2026-08-03', 'end_date' => '2026-08-14',
    'status' => 'planned',
], false);

$scheduleRows = [
    // Monday 09:00–10:30, CAS, room 204 (today's first class).
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt201, 'faculty_id' => $profileCas, 'room_id' => $room204, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '10:30:00', 'recurrence' => 'weekly', 'status' => 'published'],
    // Wednesday 09:00–10:30, same subject.
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt201, 'faculty_id' => $profileCas, 'room_id' => $room204, 'day_of_week' => 3, 'start_time' => '09:00:00', 'end_time' => '10:30:00', 'recurrence' => 'weekly', 'status' => 'published'],
    // Tuesday 08:00–10:00, room 101.
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt202, 'faculty_id' => $profileCas, 'room_id' => $room101, 'day_of_week' => 2, 'start_time' => '08:00:00', 'end_time' => '10:00:00', 'recurrence' => 'weekly', 'status' => 'published'],
    // Wednesday 13:00–14:00 one-off on 2026-06-03.
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas, 'subject_id' => $subjectIt202, 'faculty_id' => $profileCas, 'room_id' => $room204, 'day_of_week' => 3, 'start_time' => '13:00:00', 'end_time' => '14:00:00', 'meeting_date' => '2026-06-03', 'recurrence' => 'one_off', 'status' => 'published'],
    // Friday 10:00–11:00, CAS teacher 2, room 101.
    ['semester_id' => $termA, 'department_id' => $cas, 'section_id' => $sectionCas2, 'subject_id' => $subjectIt201, 'faculty_id' => $profileCas2, 'room_id' => $room101, 'day_of_week' => 5, 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'recurrence' => 'weekly', 'status' => 'published'],
    // Thursday 08:00–09:30, CBA, room 201.
    ['semester_id' => $termA, 'department_id' => $cba, 'section_id' => $sectionCba, 'subject_id' => $subjectBa101, 'faculty_id' => $profileCba, 'room_id' => $room201, 'day_of_week' => 4, 'start_time' => '08:00:00', 'end_time' => '09:30:00', 'recurrence' => 'weekly', 'status' => 'published'],
];

$scheduleIds = [];

foreach ($scheduleRows as $row) {
    $scheduleIds[] = $database->insert('schedules', $row);
}

// One open CAS conflict and one resolved CBA conflict.
$database->insert('schedule_conflicts', [
    'semester_id' => $termA, 'primary_schedule_id' => $scheduleIds[0], 'rule_code' => 'BR-02',
    'conflict_type' => 'room', 'severity' => 'error', 'status' => 'open',
    'message' => 'Room 204 is already occupied.',
]);
$database->insert('schedule_conflicts', [
    'semester_id' => $termA, 'primary_schedule_id' => $scheduleIds[5], 'rule_code' => 'BR-03',
    'conflict_type' => 'faculty', 'severity' => 'warning', 'status' => 'resolved',
    'message' => 'Teacher double-booked.',
]);

$reports = new ReportService(
    new App\Repositories\ReportRepository($database),
    new SemesterRepository($database),
    new ScheduleRepository($database),
    $configObject
);
$service = new DashboardService(new AnalyticsRepository($database), $reports, $configObject);

// Monday, 1 June 2026, 07:00 — before the first meeting of the term.
$monday = new DateTimeImmutable('2026-06-01 07:00:00', new DateTimeZone('UTC'));

/** Find an item by one field value. */
$find = static function (array $rows, string $column, string $value): ?array {
    foreach ($rows as $row) {
        if ((string) ($row[$column] ?? '') === $value) {
            return $row;
        }
    }

    return null;
};

$adminOverview = $service->overview($admin, $termA, $monday);
$deanOverview = $service->overview($dean, $termA, $monday);
$secretaryOverview = $service->overview($secretary, $termA, $monday);
$facultyOverview = $service->overview($facultyCas, $termA, $monday);
$faculty2Overview = $service->overview($facultyCas2, $termA, $monday);

// ---------------------------------------------------------------------------
// 1. Role-aware widgets (DASH-02)
// ---------------------------------------------------------------------------
check('the administrator audience is institution-wide', $adminOverview['audience'] === 'institution'
    && $adminOverview['scope_label'] === 'Institution-wide');
check('the dean audience is departmental', $deanOverview['audience'] === 'department'
    && str_contains($deanOverview['scope_label'], 'College of Arts and Sciences'));
check('the secretary shares the dean audience', $secretaryOverview['audience'] === 'department');
check('the teacher audience is self-only', $facultyOverview['audience'] === 'faculty'
    && $facultyOverview['scope_label'] === 'Your own classes only');

$adminStats = [];
foreach ($adminOverview['stats'] as $stat) {
    $adminStats[$stat['key']] = $stat['value'];
}

check('the administrator counts every active teacher', ($adminStats['teachers'] ?? -1) === 3);
check('the administrator counts the whole active catalogue', ($adminStats['subjects'] ?? -1) === 3);
check('the administrator counts the room inventory', ($adminStats['rooms'] ?? -1) === 3);
check('today has one meeting for the administrator', ($adminStats['today'] ?? -1) === 1);

$deanStats = [];
foreach ($deanOverview['stats'] as $stat) {
    $deanStats[$stat['key']] = $stat['value'];
}

check('the dean counts only department teachers', ($deanStats['teachers'] ?? -1) === 2);
check('the dean counts only department subjects', ($deanStats['subjects'] ?? -1) === 2);
check('the dean counts only the rooms the department books', ($deanStats['rooms'] ?? -1) === 2);
check('the dean sees the same single class today', ($deanStats['today'] ?? -1) === 1);

$facultyStats = [];
foreach ($facultyOverview['stats'] as $stat) {
    $facultyStats[$stat['key']] = $stat['value'];
}

check('the teacher widget row starts from their own day', ($facultyStats['today'] ?? -1) === 1
    && ($facultyStats['meetings'] ?? -1) === 4);
check('the teacher counts only their own subjects and rooms', ($facultyStats['subjects'] ?? -1) === 2
    && ($facultyStats['rooms'] ?? -1) === 2);
check('the second teacher has no Monday class', $faculty2Overview['today']['count'] === 0);

// ---------------------------------------------------------------------------
// 2. Today and the seven-day agenda (DASH-01)
// ---------------------------------------------------------------------------
check('today is pinned to Monday 1 June 2026', $adminOverview['today']['date'] === '2026-06-01'
    && $adminOverview['today']['is_teaching_day'] === true);
check('today holds the Monday meeting', $adminOverview['today']['count'] === 1
    && (string) ($adminOverview['today']['classes'][0]['subject_code'] ?? '') === 'IT-201');
check('today totals 1.5 contact hours', (float) $adminOverview['today']['hours'] === 1.5);
check('today carries the room and teacher for a dean', (string) ($adminOverview['today']['classes'][0]['room_code'] ?? '') === 'MB 204'
    && (string) ($adminOverview['today']['classes'][0]['faculty_name'] ?? '') === 'Juan Cruz');

// Monday 1, Tuesday 1, Wednesday 2 (weekly + one-off), Thursday 1, Friday 1.
check('the seven-day agenda has six meetings', count($adminOverview['upcoming']) === 6, (string) count($adminOverview['upcoming']));
check('the agenda starts with Monday 09:00', (string) ($adminOverview['upcoming'][0]['start_time'] ?? '') === '09:00'
    && (string) ($adminOverview['upcoming'][0]['day_label'] ?? '') === 'Today');
check('Wednesday carries both the weekly and the one-off meeting', count(array_filter(
    $adminOverview['upcoming'],
    static fn (array $item): bool => $item['date'] === '2026-06-03'
)) === 2);
check('the one-off appears with its own time', $find($adminOverview['upcoming'], 'start_time', '13:00') !== null);
check('the agenda spans the other department too for an administrator', $find($adminOverview['upcoming'], 'department_code', 'CBA') !== null);

$teacherAgenda = $facultyOverview['upcoming'];
check('the teacher agenda holds only their own meetings', count($teacherAgenda) === 4);
check('the teacher agenda never shows the CBA class', $find($teacherAgenda, 'department_code', 'CBA') === null);
check('the second teacher sees only Friday', count($faculty2Overview['upcoming']) === 1
    && (string) ($faculty2Overview['upcoming'][0]['date'] ?? '') === '2026-06-05');

$later = new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC'));
$afterMonday = $service->overview($admin, $termA, $later);
check('a finished meeting leaves the agenda', count($afterMonday['upcoming']) === 5
    && $find($afterMonday['upcoming'], 'date', '2026-06-01') === null);
check('finished meetings still count as today for the widget', $afterMonday['today']['count'] === 1);

// ---------------------------------------------------------------------------
// 3. Utilization, workload and conflicts reuse the scoped report read models
// ---------------------------------------------------------------------------
check('the administrator utilization is the report percentage', (float) $adminOverview['utilization']['overall'] === 4.7
    && (int) $adminOverview['utilization']['rooms'] === 3
    && (float) $adminOverview['utilization']['booked_hours'] === 31.0, json_encode($adminOverview['utilization']));
check('the dean utilization denominator follows the visible rooms', (float) $deanOverview['utilization']['overall'] === 5.7
    && (int) $deanOverview['utilization']['rooms'] === 2);
check('the top utilization row is room 204 at 5.9 %', (string) ($adminOverview['utilization']['top'][0]['room_code'] ?? '') === '204'
    && (float) ($adminOverview['utilization']['top'][0]['utilization'] ?? 0) === 5.9);
check('the teacher utilization lists only their rooms', count($facultyOverview['utilization']['top']) === 2);
check('the administrator workload totals every teacher', (int) $adminOverview['workload']['totals']['teachers'] === 3
    && (float) $adminOverview['workload']['totals']['units'] === 12.0
    && (float) $adminOverview['workload']['totals']['hours'] === 8.5);
check('the teacher workload is their own load', count($facultyOverview['workload']['rows']) === 1
    && (float) ($facultyOverview['workload']['rows'][0]['units'] ?? 0) === 6.0
    && (string) ($facultyOverview['workload']['rows'][0]['band'] ?? '') === 'under');
check('the administrator sees both department rows', count($adminOverview['departments']) === 2);
check('the dean sees only their department row', count($deanOverview['departments']) === 1
    && (string) ($deanOverview['departments'][0]['department_code'] ?? '') === 'CAS');
check('conflict counts are scoped for the administrator', $adminOverview['conflicts'] === [
    'open' => 1, 'resolved' => 1, 'ignored' => 0, 'total' => 2,
], json_encode($adminOverview['conflicts']));
check('conflict counts are scoped for the dean', $deanOverview['conflicts'] === [
    'open' => 1, 'resolved' => 0, 'ignored' => 0, 'total' => 1,
], json_encode($deanOverview['conflicts']));

// ---------------------------------------------------------------------------
// 4. Chart payload (DASH-03)
// ---------------------------------------------------------------------------
$adminCharts = $service->charts($admin, $termA);
$facultyCharts = $service->charts($facultyCas, $termA);
$emptyCharts = $service->charts($admin, $termC);

check('the chart payload names the selected term and scope', (string) ($adminCharts['term']['code'] ?? '') === '2026-2027-1ST'
    && (string) ($adminCharts['scope'] ?? '') === 'Institution-wide');
check('the payload carries four charts', array_keys($adminCharts['charts']) === [
    'departments', 'workload', 'utilization', 'conflicts',
]);
check('department meetings are series data, not view markup', $adminCharts['charts']['departments']['labels'] === ['CAS', 'CBA']
    && $adminCharts['charts']['departments']['datasets'][0]['data'] === [5, 1], json_encode($adminCharts['charts']['departments']));
check('workload plots units and cap per teacher',
    $adminCharts['charts']['workload']['labels'] === ['Juan Cruz', 'Pedro Garcia', 'Rosa Lim']
    && $adminCharts['charts']['workload']['datasets'][0]['data'] === [6.0, 3.0, 3.0]
    && $adminCharts['charts']['workload']['datasets'][1]['data'] === [18.0, 12.0, 18.0],
    json_encode($adminCharts['charts']['workload']));
check('utilization plots the highest room first', $adminCharts['charts']['utilization']['labels'] === ['MB 204', 'MB 101', 'AB 201']
    && $adminCharts['charts']['utilization']['datasets'][0]['data'] === [5.9, 5.5, 2.7]);
check('conflicts plot open, resolved and ignored', $adminCharts['charts']['conflicts']['datasets'][0]['data'] === [1, 1, 0]);
check('the teacher chart payload is scoped to their meetings', $facultyCharts['charts']['departments']['datasets'][0]['data'] === [4]
    && $facultyCharts['charts']['workload']['labels'] === ['Juan Cruz']);
check('the empty term returns empty series', $emptyCharts['charts']['departments']['labels'] === []
    && $emptyCharts['charts']['departments']['datasets'][0]['data'] === []
    && $emptyCharts['charts']['conflicts']['datasets'][0]['data'] === [0, 0, 0]);

// ---------------------------------------------------------------------------
// 5. Empty term and term fallback
// ---------------------------------------------------------------------------
$emptyOverview = $service->overview($admin, $termC, $monday);
check('the empty term renders a zero day', $emptyOverview['today']['count'] === 0
    && $emptyOverview['today']['hours'] === 0.0
    && $emptyOverview['upcoming'] === []);
check('the empty term keeps the term context', (string) ($emptyOverview['context']['semester']['code'] ?? '') === '2026-2027-SUM');
check('the empty term has no utilization and no conflicts', (float) $emptyOverview['utilization']['overall'] === 0.0
    && $emptyOverview['conflicts']['total'] === 0
    && $emptyOverview['departments'] === []);
check('an unknown term falls back to the current term', (int) ($service->overview($admin, 999999, $monday)['context']['semester']['id'] ?? 0) === $termA);
check('a null term uses the current term', (int) ($service->overview($admin, null, $monday)['context']['semester']['id'] ?? 0) === $termA);

// ---------------------------------------------------------------------------
// 6. HTTP: landing page, roles, JSON endpoint, no-store
// ---------------------------------------------------------------------------
$kernel = Kernel::boot($testConfig);
$container = $kernel->container();
$container->instance(Database::class, $database);

$session = $container->get(Session::class);
$session->start();
$auth = $container->get(App\Services\AuthService::class);

$anonymous = $kernel->handle(new Request('GET', '/'));
check('an anonymous visitor is redirected from the dashboard', $anonymous->status() === 302
    && str_ends_with((string) $anonymous->header('Location'), '/login'));

$auth->loginUser($adminId);
$adminPage = $kernel->handle(new Request('GET', '/'));
check('the administrator opens the dashboard', $adminPage->status() === 200);
check('the dashboard greets the signed-in user', str_contains($adminPage->body(), 'Welcome back, Ana'));
check('the page carries the widget row', str_contains($adminPage->body(), 'data-widget="teachers"')
    && str_contains($adminPage->body(), 'stat-grid'));
check('the page carries chart canvases but no dataset', str_contains($adminPage->body(), 'data-chart-key="departments"')
    && !str_contains($adminPage->body(), 'backgroundColor')
    && !str_contains($adminPage->body(), '"datasets"'));
check('the page names the scoped chart endpoint', str_contains($adminPage->body(), 'data-dashboard-endpoint')
    && str_contains($adminPage->body(), '/api/dashboard?semester=' . $termA));

$adminJson = $kernel->handle(new Request('GET', '/api/dashboard'));
check('the administrator reads the chart JSON', $adminJson->status() === 200
    && str_contains((string) $adminJson->header('Content-Type'), 'application/json'));
$payload = json_decode($adminJson->body(), true);
check('the JSON envelope is ok', is_array($payload) && ($payload['ok'] ?? false) === true);
check('the JSON carries the four chart series', isset($payload['data']['charts']['departments']['labels'])
    && $payload['data']['charts']['departments']['labels'] === ['CAS', 'CBA']);
check('the chart JSON is not cached', $adminJson->header('Cache-Control') === 'no-store');

$auth->loginUser($deanId);
$deanPage = $kernel->handle(new Request('GET', '/'));
check('the dean opens the dashboard', $deanPage->status() === 200
    && str_contains($deanPage->body(), 'Department — College of Arts and Sciences'));
check('the dean page hides the other department teacher', !str_contains($deanPage->body(), 'faculty.cba@sac.edu.ph'));
$deanPayload = json_decode($kernel->handle(new Request('GET', '/api/dashboard'))->body(), true);
check('the dean chart JSON is department-scoped', ($deanPayload['data']['charts']['departments']['labels'] ?? []) === ['CAS']
    && ($deanPayload['data']['charts']['conflicts']['datasets'][0]['data'] ?? []) === [1, 0, 0]);

$auth->loginUser($facultyCasId);
$facultyPage = $kernel->handle(new Request('GET', '/'));
check('a teacher opens the dashboard', $facultyPage->status() === 200
    && str_contains($facultyPage->body(), 'Scope: Your own classes only'));
check('the teacher page hides other teachers', !str_contains($facultyPage->body(), 'Pedro Garcia')
    && !str_contains($facultyPage->body(), 'Rosa Lim'));
$facultyPayload = json_decode($kernel->handle(new Request('GET', '/api/dashboard'))->body(), true);
check('the teacher chart JSON holds only their workload', ($facultyPayload['data']['charts']['workload']['labels'] ?? []) === ['Juan Cruz']);

$auth->loginUser($adminId);
$emptyPage = $kernel->handle(new Request('GET', '/', ['semester' => $termC]));
check('the dashboard renders an empty term over HTTP', $emptyPage->status() === 200
    && str_contains($emptyPage->body(), '2026-2027-SUM'));
$unknownPage = $kernel->handle(new Request('GET', '/', ['semester' => 999999]));
check('an unknown term over HTTP falls back to the current term', $unknownPage->status() === 200
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
