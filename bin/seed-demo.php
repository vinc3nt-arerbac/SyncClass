<?php

declare(strict_types=1);

/**
 * SyncClass — demo data seeder (Phase 19, exercised by Phase 15 reports).
 *
 * Fills a clean `anth04_SyncClass` database with the data set the project
 * specification asks for:
 *
 *   DEMO DATA (docs/REQUIREMENTS.md)
 *     5 departments            → MD-01 demo rows
 *     20 faculty members       → one faculty account + profile per row
 *     50 subjects              → 10 per department, unique course codes
 *     30 rooms                 → spread over 4 buildings (multiple locations)
 *     100 schedule records     → conflict-free weekly meetings
 *     4 user roles             → administrator, dean, secretary, faculty
 *     multiple semesters       → 2 academic years × 2 semesters
 *     multiple sections        → 3 sections per department (15 total)
 *     multiple building sites  → 4 campuses/buildings
 *
 * On top of the specification list, the seeder also creates the supporting
 * accounts the role demos need (one dean and one secretary per department plus
 * one administrator), so the RBAC matrix, department scoping and the report
 * filters can all be shown with real logins.
 *
 * The scheduling pass is deterministic and conflict-free by construction:
 * every meeting is placed on a free (room, faculty, section) triple across a
 * 5-day × 7-slot grid, then the script re-queries the database for room,
 * faculty and section overlaps and fails loudly if any exist. Because
 * `config/schedule.php` forbids sessions across the 12:00–13:00 break, that
 * period is not part of the grid.
 *
 * Re-running is safe: every row is created only when its natural key (role
 * code, email, department/building/room/subject/section code, semester code or
 * meeting slot) is absent. Counts are verified against the database at the
 * end, not against in-memory counters.
 *
 * Usage:
 *     php bin/seed-demo.php
 *
 * Exit codes: 0 success, 1 validation, database or assertion failure.
 */

use App\Core\Database;
use App\Core\Security;
use App\Core\Session;
use App\Repositories\AuditRepository;
use App\Repositories\BuildingRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\RoleRepository;
use App\Repositories\RoomRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\SectionRepository;
use App\Repositories\SubjectRepository;
use App\Repositories\TermRepository;
use App\Repositories\UserRepository;

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Constants — the requirements list, in one place.
// ---------------------------------------------------------------------------

const DEMO_PASSWORD = 'SyncClass2026!';
const DEMO_DOMAIN = 'sac.edu.ph';
const SCHEDULE_NOTE = 'Demo seed dataset.';

/** The counts the specification asks for. Assertions compare against these. */
const REQUIRED_COUNTS = [
    'roles' => 4,
    'departments' => 5,
    'faculty' => 20,
    'subjects' => 50,
    'buildings' => 4,
    'rooms' => 30,
    'sections' => 15,
    'semesters' => 4,
    'schedules' => 100,
];

/** Meetings per department (5 departments × 20 = 100 schedule records). */
const MEETINGS_PER_DEPARTMENT = 20;

/** Monday–Friday teaching days, ISO-8601 numbers used by the schema. */
const TEACHING_DAYS = [1, 2, 3, 4, 5];

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Fail the run with a readable message. Assertions are explicit rather than
 * `assert()` because PHP ships with `zend.assertions=-1` in production, which
 * turns `assert()` into a no-op exactly where a seeder must not guess.
 */
function fail(string $message): never
{
    fwrite(STDERR, 'Seed failed: ' . $message . PHP_EOL);
    exit(1);
}

/**
 * Compare one requirement count with the database and report the row.
 *
 * @param array<int, array{0: string, 1: int, 2: int}> $results
 */
function expectCount(array &$results, string $label, int $actual, int $expected): void
{
    $results[] = [$label, $expected, $actual];

    if ($actual !== $expected) {
        fail(sprintf('%s: expected %d, found %d.', $label, $expected, $actual));
    }
}

/**
 * COUNT(*) of rows whose column is one of the supplied values.
 *
 * Table and column names are code constants, never user input; values are
 * always bound.
 *
 * @param array<int, int|string> $values
 */
function countIn(Database $database, string $table, string $column, array $values): int
{
    $placeholders = [];
    $bindings = [];

    foreach (array_values($values) as $index => $value) {
        $key = 'v' . $index;
        $placeholders[] = ':' . $key;
        $bindings[$key] = $value;
    }

    return (int) $database->scalar(
        sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE `%s` IN (%s)',
            $table,
            $column,
            implode(', ', $placeholders)
        ),
        $bindings
    );
}

/**
 * Resolve a row id from its natural key (the `code` column of reference data).
 *
 * Reference tables use a unique `code`; the master-data repositories expose
 * `codeExists()` for guards and `find(int $id)` for reads, so the seeder reads
 * the id directly rather than adding a one-off lookup method to five repos.
 */
function idByCode(Database $database, string $table, string $code): int
{
    $id = $database->scalar(
        sprintf('SELECT id FROM `%s` WHERE code = :code LIMIT 1', $table),
        ['code' => $code]
    );

    if ($id === null) {
        fail(sprintf('Could not resolve %s with code %s.', $table, $code));
    }

    return (int) $id;
}

/**
 * Derive the demonstration mailbox for a person: first initial + surname,
 * lower-cased, in the institution's domain (jcruz@sac.edu.ph).
 */
function mailbox(string $firstName, string $lastName): string
{
    $local = strtolower(substr(trim($firstName), 0, 1) . preg_replace('/[^a-z]/i', '', $lastName));

    return $local . '@' . DEMO_DOMAIN;
}

// ---------------------------------------------------------------------------
// Wiring.
// ---------------------------------------------------------------------------

$database = new Database((array) $config['database']);
$security = new Security(
    new Session((array) $config['app']['session']),
    (array) ($config['security'] ?? [])
);

$roleRepo = new RoleRepository($database);
$deptRepo = new DepartmentRepository($database);
$userRepo = new UserRepository($database);
$facultyRepo = new FacultyRepository($database);
$buildingRepo = new BuildingRepository($database);
$roomRepo = new RoomRepository($database);
$subjectRepo = new SubjectRepository($database);
$sectionRepo = new SectionRepository($database);
$termRepo = new TermRepository($database);
$scheduleRepo = new ScheduleRepository($database);
$auditRepo = new AuditRepository($database);

$actorIp = '127.0.0.1';
$actorAgent = 'bin/seed-demo.php';

// ---------------------------------------------------------------------------
// 1. The four user roles (DEMO DATA: 4 user roles).
// ---------------------------------------------------------------------------

$roleSpecs = [
    'administrator' => ['System Administrator', 'Institution-wide configuration and master data.', 10],
    'dean' => ['Dean', 'College-level approvals and department oversight.', 20],
    'secretary' => ['Department Secretary', 'Day-to-day scheduling for one department.', 30],
    'faculty' => ['Faculty', 'Reads assigned schedules and notifications.', 40],
];

$roleIds = [];

foreach ($roleSpecs as $code => [$name, $description, $sortOrder]) {
    $roleIds[$code] = $roleRepo->firstOrCreate($code, $name, $description, $sortOrder);
}

// ---------------------------------------------------------------------------
// 2. Departments (5), with their dean, secretary, faculty and subject lists.
// ---------------------------------------------------------------------------

$departmentSpecs = [
    [
        'code' => 'CSM',
        'name' => 'College of Computer Science & Engineering',
        'short_name' => 'CSE',
        'color' => '#808000',
        'program' => 'BS Computer Science',
        'subject_prefix' => 'CMP',
        'dean' => ['Maria', 'Santos'],
        'secretary' => ['Liza', 'Fernandez'],
        'faculty' => [
            ['Juan', 'Cruz'],
            ['Ana', 'Reyes'],
            ['Paolo', 'Mendoza'],
            ['Grace', 'Aquino'],
        ],
        'subjects' => [
            'Introduction to Computing',
            'Programming Fundamentals',
            'Data Structures & Algorithms',
            'Database Management Systems',
            'Web Systems & Technologies',
            'Computer Networks',
            'Operating Systems',
            'Software Engineering',
            'Information Assurance & Security',
            'Capstone Project I',
        ],
    ],
    [
        'code' => 'ENGR',
        'name' => 'College of Engineering',
        'short_name' => 'ENGR',
        'color' => '#400040',
        'program' => 'BS Civil Engineering',
        'subject_prefix' => 'EGR',
        'dean' => ['Carlos', 'Garcia'],
        'secretary' => ['Ramon', 'Villanueva'],
        'faculty' => [
            ['Daniel', 'Torres'],
            ['Benjamin', 'Navarro'],
            ['Teresa', 'Lim'],
            ['Mark', 'Bautista'],
        ],
        'subjects' => [
            'Engineering Graphics',
            'Statics of Rigid Bodies',
            'Dynamics of Rigid Bodies',
            'Mechanics of Deformable Bodies',
            'Circuit Theory',
            'Thermodynamics',
            'Fluid Mechanics',
            'Materials Science',
            'Engineering Economics',
            'Control Systems',
        ],
    ],
    [
        'code' => 'BUS',
        'name' => 'College of Business Administration',
        'short_name' => 'BA',
        'color' => '#008000',
        'program' => 'BS Business Administration',
        'subject_prefix' => 'BUS',
        'dean' => ['Elena', 'Ramos'],
        'secretary' => ['Carmela', 'Domingo'],
        'faculty' => [
            ['Eduardo', 'Salazar'],
            ['Rosario', 'Mercado'],
            ['Alfredo', 'Castillo'],
            ['Nena', 'Pascual'],
        ],
        'subjects' => [
            'Principles of Management',
            'Financial Accounting',
            'Business Statistics',
            'Marketing Management',
            'Human Resource Management',
            'Operations Management',
            'Corporate Finance',
            'Business Law',
            'Strategic Management',
            'Business Analytics',
        ],
    ],
    [
        'code' => 'EDU',
        'name' => 'College of Education',
        'short_name' => 'COED',
        'color' => '#000080',
        'program' => 'Bachelor of Elementary Education',
        'subject_prefix' => 'EDU',
        'dean' => ['Sofia', 'Dela Cruz'],
        'secretary' => ['Victor', 'Buenaventura'],
        'faculty' => [
            ['Felix', 'Aguilar'],
            ['Bianca', 'Rosales'],
            ['Hector', 'Marquez'],
            ['Divina', 'Ocampo'],
        ],
        'subjects' => [
            'Foundations of Education',
            'Child & Adolescent Development',
            'Learning Theories',
            'Instructional Design',
            'Assessment of Learning',
            'Curriculum Development',
            'Educational Technology',
            'Classroom Management',
            'Research Methods in Education',
            'Teaching Internship',
        ],
    ],
    [
        'code' => 'HSS',
        'name' => 'College of Humanities',
        'short_name' => 'CHSS',
        'color' => '#AF640F',
        'program' => 'BA Communication',
        'subject_prefix' => 'HSS',
        'dean' => ['Rosa', 'Villamor'],
        'secretary' => ['Irene', 'Fajardo'],
        'faculty' => [
            ['Gabriel', 'Estrada'],
            ['Cecilia', 'Panganiban'],
            ['Oscar', 'Zamora'],
            ['Lucia', 'Fontanilla'],
        ],
        'subjects' => [
            'Introduction to Philosophy',
            'Ethics & Society',
            'Philippine History',
            'World Civilizations',
            'Introduction to Sociology',
            'General Psychology',
            'Political Science',
            'Principles of Economics',
            'Literature & Culture',
            'Research Writing',
        ],
    ],
];

$departmentIds = [];
$deanIds = [];

foreach ($departmentSpecs as $spec) {
    if ($deptRepo->codeExists($spec['code'])) {
        $departmentIds[$spec['code']] = idByCode($database, 'departments', $spec['code']);

        continue;
    }

    $departmentIds[$spec['code']] = $deptRepo->create([
        'code' => $spec['code'],
        'name' => $spec['name'],
        'short_name' => $spec['short_name'],
        'email' => strtolower($spec['code']) . '@' . DEMO_DOMAIN,
        'phone' => null,
        'color_hex' => $spec['color'],
    ]);
}

// ---------------------------------------------------------------------------
// 3. Users: administrator, one dean + one secretary per department, 20 faculty.
// ---------------------------------------------------------------------------

$adminEmail = 'admin@' . DEMO_DOMAIN;
$admin = $userRepo->findByEmail($adminEmail);

if ($admin === null) {
    $adminId = $userRepo->create([
        'role_id' => $roleIds['administrator'],
        'department_id' => null,
        'email' => $adminEmail,
        'username' => 'admin',
        'password_hash' => $security->hashPassword(DEMO_PASSWORD),
        'first_name' => 'System',
        'last_name' => 'Administrator',
        'employee_no' => 'ADMIN-0001',
    ]);
} else {
    $adminId = (int) $admin['id'];
}

$facultyProfileIds = [];
$sectionIdsByDepartment = [];

foreach ($departmentSpecs as $spec) {
    $departmentId = $departmentIds[$spec['code']];

    // Dean -----------------------------------------------------------------
    [$deanFirst, $deanLast] = $spec['dean'];
    $deanEmail = 'dean.' . strtolower($spec['code']) . '@' . DEMO_DOMAIN;
    $dean = $userRepo->findByEmail($deanEmail);

    if ($dean === null) {
        $deanId = $userRepo->create([
            'role_id' => $roleIds['dean'],
            'department_id' => $departmentId,
            'email' => $deanEmail,
            'username' => 'dean.' . strtolower($spec['code']),
            'password_hash' => $security->hashPassword(DEMO_PASSWORD),
            'first_name' => $deanFirst,
            'last_name' => $deanLast,
            'employee_no' => 'DEAN-' . $spec['code'],
        ]);
    } else {
        $deanId = (int) $dean['id'];
    }

    $deanIds[$spec['code']] = $deanId;

    // Secretary ------------------------------------------------------------
    [$secretaryFirst, $secretaryLast] = $spec['secretary'];
    $secretaryEmail = 'secretary.' . strtolower($spec['code']) . '@' . DEMO_DOMAIN;

    if ($userRepo->findByEmail($secretaryEmail) === null) {
        $userRepo->create([
            'role_id' => $roleIds['secretary'],
            'department_id' => $departmentId,
            'email' => $secretaryEmail,
            'username' => 'secretary.' . strtolower($spec['code']),
            'password_hash' => $security->hashPassword(DEMO_PASSWORD),
            'first_name' => $secretaryFirst,
            'last_name' => $secretaryLast,
            'employee_no' => 'SEC-' . $spec['code'],
        ]);
    }

    // Faculty --------------------------------------------------------------
    foreach ($spec['faculty'] as $index => [$firstName, $lastName]) {
        $email = mailbox($firstName, $lastName);
        $user = $userRepo->findByEmail($email);

        if ($user === null) {
            $userId = $userRepo->create([
                'role_id' => $roleIds['faculty'],
                'department_id' => $departmentId,
                'email' => $email,
                'username' => strstr($email, '@', true) ?: $email,
                'password_hash' => $security->hashPassword(DEMO_PASSWORD),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'employee_no' => sprintf('FAC-%s-%02d', $spec['code'], $index + 1),
            ]);
        } else {
            $userId = (int) $user['id'];
        }

        $profile = $facultyRepo->findByUserId($userId);

        if ($profile === null) {
            $profileId = $facultyRepo->create([
                'faculty_id' => $userId,
                'department_id' => $departmentId,
                'employment_type' => $index === 0 ? 'full_time' : ($index === 1 ? 'full_time' : 'part_time'),
                'max_load_units' => $index <= 1 ? 24.0 : 18.0,
                'specialization' => $spec['name'],
                'office' => $spec['short_name'] . ' Faculty Room ' . ($index + 1),
            ]);
        } else {
            $profileId = (int) $profile['id'];
        }

        $facultyProfileIds[$spec['code']][] = $profileId;
    }
}

// Point each department at its dean (reports and the department screen read it).
foreach ($deanIds as $code => $deanId) {
    $database->update('departments', ['dean_user_id' => $deanId], 'id = :id', [
        'id' => $departmentIds[$code],
    ]);
}

// ---------------------------------------------------------------------------
// 4. Buildings (4) and rooms (30) across them.
// ---------------------------------------------------------------------------

/**
 * Each building receives a fixed mix of room types: 16 lecture rooms, 8
 * laboratories and 6 seminar rooms overall. Every room seats at least 40, so a
 * 40-student section never trips the capacity rule.
 */
$buildingSpecs = [
    [
        'code' => 'MAIN',
        'name' => 'Main Building',
        'campus' => 'Main Campus',
        'address' => '100 University Avenue',
        'rooms' => ['lecture' => 5, 'laboratory' => 2, 'seminar' => 2],
    ],
    [
        'code' => 'SCI',
        'name' => 'Science Building',
        'campus' => 'Main Campus',
        'address' => '102 Education Boulevard',
        'rooms' => ['lecture' => 4, 'laboratory' => 2, 'seminar' => 2],
    ],
    [
        'code' => 'ENGH',
        'name' => 'Engineering Hall',
        'campus' => 'North Campus',
        'address' => '200 Technology Park Drive',
        'rooms' => ['lecture' => 4, 'laboratory' => 2, 'seminar' => 1],
    ],
    [
        'code' => 'HSC',
        'name' => 'Humanities & Sciences Center',
        'campus' => 'South Campus',
        'address' => '300 Liberal Arts Parkway',
        'rooms' => ['lecture' => 3, 'laboratory' => 2, 'seminar' => 1],
    ],
];

$buildingIds = [];
$roomsByType = ['lecture' => [], 'laboratory' => [], 'seminar' => []];
$roomIds = [];

foreach ($buildingSpecs as $spec) {
    if ($buildingRepo->codeExists($spec['code'])) {
        $buildingId = idByCode($database, 'buildings', $spec['code']);
    } else {
        $buildingId = $buildingRepo->create([
            'code' => $spec['code'],
            'name' => $spec['name'],
            'campus' => $spec['campus'],
            'address' => $spec['address'],
        ]);
    }

    $buildingIds[] = $buildingId;

    $roomNumber = 0;

    foreach ($spec['rooms'] as $roomType => $count) {
        for ($i = 0; $i < $count; $i++) {
            $roomNumber++;
            $floor = $roomNumber <= 5 ? 1 : 2;
            $code = sprintf('R%d%02d', $floor, $roomNumber <= 5 ? $roomNumber : $roomNumber - 5);

            if ($roomRepo->codeExistsInBuilding($buildingId, $code)) {
                // Room codes are unique per building, not institution-wide.
                $existingRoom = $database->selectOne(
                    'SELECT id FROM rooms WHERE building_id = :building_id AND code = :code LIMIT 1',
                    ['building_id' => $buildingId, 'code' => $code]
                );

                if ($existingRoom === null) {
                    fail(sprintf('Room %s exists in building #%d but could not be read back.', $code, $buildingId));
                }

                $roomsByType[$roomType][] = (int) $existingRoom['id'];
                $roomIds[] = (int) $existingRoom['id'];

                continue;
            }

            $roomId = $roomRepo->create([
                'building_id' => $buildingId,
                'code' => $code,
                'name' => $spec['name'] . ' Room ' . $roomNumber,
                'floor' => $floor,
                'room_type' => $roomType,
                'capacity' => $roomType === 'lecture' ? 45 : 40,
                'facilities' => match ($roomType) {
                    'laboratory' => 'Projector, Smartboard, Lab benches',
                    'seminar' => 'Video conferencing, Whiteboard',
                    default => 'Projector, Whiteboard',
                },
            ]);

            $roomsByType[$roomType][] = $roomId;
            $roomIds[] = $roomId;
        }
    }
}

// ---------------------------------------------------------------------------
// 5. Subjects (50: 10 per department) and sections (15: 3 per department).
// ---------------------------------------------------------------------------

$subjectIdsByDepartment = [];

foreach ($departmentSpecs as $spec) {
    $departmentId = $departmentIds[$spec['code']];

    foreach ($spec['subjects'] as $index => $title) {
        $code = $spec['subject_prefix'] . (101 + $index);

        // Catalogue shape: the last two subjects of every department are lab
        // subjects; the humanities/education catalogues also carry seminar
        // subjects, so all three room types appear in the utilisation report.
        $isLaboratory = $index >= 8;
        $isSeminar = !$isLaboratory && $index >= 6 && in_array($spec['code'], ['EDU', 'HSS'], true);
        $preferredRoomType = $isLaboratory ? 'laboratory' : ($isSeminar ? 'seminar' : 'lecture');

        if ($subjectRepo->codeExists($code)) {
            $subjectIdsByDepartment[$spec['code']][] = idByCode($database, 'subjects', $code);

            continue;
        }

        $subjectIdsByDepartment[$spec['code']][] = $subjectRepo->create([
            'code' => $code,
            'title' => $title,
            'department_id' => $departmentId,
            'units' => $isLaboratory ? 3.0 : 3.0,
            'lecture_hours' => $isLaboratory ? 2.0 : 3.0,
            'lab_hours' => $isLaboratory ? 1.0 : 0.0,
            'subject_type' => $isLaboratory ? 'mixed' : 'lecture',
            'preferred_room_type' => $preferredRoomType,
            'description' => 'Demonstration catalogue entry used by the Phase 15 reports.',
        ]);
    }

    for ($year = 1; $year <= 3; $year++) {
        $sectionCode = sprintf('%s-%d%s', $spec['short_name'], $year, 'A');

        if ($sectionRepo->codeExists($sectionCode)) {
            $sectionIdsByDepartment[$spec['code']][] = idByCode($database, 'sections', $sectionCode);

            continue;
        }

        $sectionIdsByDepartment[$spec['code']][] = $sectionRepo->create([
            'code' => $sectionCode,
            'name' => $spec['program'] . ' — Year ' . $year,
            'program' => $spec['program'],
            'year_level' => $year,
            'department_id' => $departmentId,
            'student_count' => 40,
        ]);
    }
}

// ---------------------------------------------------------------------------
// 6. Academic years and semesters (multiple semesters, one current).
// ---------------------------------------------------------------------------

$yearSpecs = [
    ['code' => 'SY2025-2026', 'start' => '2025-06-01', 'end' => '2026-05-31', 'current' => false],
    ['code' => 'SY2026-2027', 'start' => '2026-06-01', 'end' => '2027-05-31', 'current' => true],
];

$yearIds = [];

foreach ($yearSpecs as $spec) {
    if ($termRepo->yearCodeExists($spec['code'])) {
        $yearIds[$spec['code']] = idByCode($database, 'academic_years', $spec['code']);

        continue;
    }

    $yearIds[$spec['code']] = $termRepo->createYear([
        'code' => $spec['code'],
        'start_date' => $spec['start'],
        'end_date' => $spec['end'],
    ], $spec['current']);
}

$semesterSpecs = [
    ['code' => '2025-1ST', 'year' => 'SY2025-2026', 'term_no' => 1, 'name' => 'First Semester 2025–2026', 'start' => '2025-06-16', 'end' => '2025-10-31', 'status' => 'closed'],
    ['code' => '2025-2ND', 'year' => 'SY2025-2026', 'term_no' => 2, 'name' => 'Second Semester 2025–2026', 'start' => '2025-11-04', 'end' => '2026-03-31', 'status' => 'closed'],
    ['code' => '2026-1ST', 'year' => 'SY2026-2027', 'term_no' => 1, 'name' => 'First Semester 2026–2027', 'start' => '2026-06-15', 'end' => '2026-10-31', 'status' => 'active'],
    ['code' => '2026-2ND', 'year' => 'SY2026-2027', 'term_no' => 2, 'name' => 'Second Semester 2026–2027', 'start' => '2026-11-03', 'end' => '2027-03-31', 'status' => 'planned'],
];

$semesterIds = [];

foreach ($semesterSpecs as $spec) {
    if ($termRepo->semesterCodeExists($spec['code'])) {
        $semesterIds[$spec['code']] = idByCode($database, 'semesters', $spec['code']);

        continue;
    }

    $semesterIds[$spec['code']] = $termRepo->createSemester([
        'academic_year_id' => $yearIds[$spec['year']],
        'term_no' => $spec['term_no'],
        'name' => $spec['name'],
        'code' => $spec['code'],
        'start_date' => $spec['start'],
        'end_date' => $spec['end'],
        'status' => $spec['status'],
    ], $spec['code'] === '2026-1ST');
}

$currentSemesterCode = '2026-1ST';
$currentSemesterId = $semesterIds[$currentSemesterCode];
$termRepo->setCurrentSemester($currentSemesterId);

// ---------------------------------------------------------------------------
// 7. Schedule records (100), conflict-free by construction.
// ---------------------------------------------------------------------------

/**
 * 90-minute slots inside the allowed window (07:00–19:00) that do not cross
 * the 12:00–13:00 lunch break configured in config/schedule.php.
 */
$timeSlots = [
    ['07:00:00', '08:30:00'],
    ['08:30:00', '10:00:00'],
    ['10:00:00', '11:30:00'],
    ['13:00:00', '14:30:00'],
    ['14:30:00', '16:00:00'],
    ['16:00:00', '17:30:00'],
    ['17:30:00', '19:00:00'],
];

$pairs = [];

foreach (TEACHING_DAYS as $day) {
    foreach ($timeSlots as $slotIndex => $slot) {
        $pairs[] = [
            'day' => $day,
            'slot' => $slotIndex,
            'start' => $slot[0],
            'end' => $slot[1],
        ];
    }
}

$pairCount = count($pairs);
$pairCursor = 0;

/** @var array<string, array<int, true>> $roomBusy */
$roomBusy = [];
/** @var array<string, array<int, true>> $facultyBusy */
$facultyBusy = [];
/** @var array<string, array<int, true>> $sectionBusy */
$sectionBusy = [];

$schedulesCreated = 0;
$schedulesReused = 0;

// Rotating room cursor: without it the greedy search would keep filling the
// first free room of each slot and leave most rooms unused, which makes the
// room-utilisation report meaningless.
$roomRotation = 0;

foreach ($departmentSpecs as $spec) {
    $departmentId = $departmentIds[$spec['code']];
    $facultyList = $facultyProfileIds[$spec['code']];
    $sectionList = $sectionIdsByDepartment[$spec['code']];
    $subjectList = $subjectIdsByDepartment[$spec['code']];

    if (count($facultyList) !== 4 || count($sectionList) !== 3 || count($subjectList) !== 10) {
        fail(sprintf(
            'Department %s is incomplete: %d faculty, %d sections, %d subjects (expected 4/3/10).',
            $spec['code'],
            count($facultyList),
            count($sectionList),
            count($subjectList)
        ));
    }

    for ($meeting = 0; $meeting < MEETINGS_PER_DEPARTMENT; $meeting++) {
        $subjectId = $subjectList[$meeting % count($subjectList)];
        $facultyId = $facultyList[intdiv($meeting, 5) % count($facultyList)];
        $sectionId = $sectionList[$meeting % count($sectionList)];
        $subject = $subjectRepo->find($subjectId);

        if ($subject === null) {
            fail('A seeded subject disappeared while scheduling.');
        }

        $requiredType = (string) ($subject['preferred_room_type'] ?? 'lecture');
        $roomTypeKey = in_array($requiredType, ['laboratory', 'seminar'], true) ? $requiredType : 'lecture';
        $candidates = $roomsByType[$roomTypeKey];

        if ($candidates === []) {
            fail('No rooms of type "' . $requiredType . '" exist; cannot place meetings.');
        }

        $placed = false;

        for ($attempt = 0; $attempt < $pairCount; $attempt++) {
            $pairIndex = ($pairCursor + $attempt) % $pairCount;
            $pair = $pairs[$pairIndex];
            $slotKey = $pair['day'] . ':' . $pair['slot'];

            if (isset($facultyBusy[$slotKey][$facultyId]) || isset($sectionBusy[$slotKey][$sectionId])) {
                continue;
            }

            $roomId = null;
            $candidateCount = count($candidates);
            $offset = $roomRotation % $candidateCount;

            for ($r = 0; $r < $candidateCount; $r++) {
                $candidateId = $candidates[($offset + $r) % $candidateCount];

                if (!isset($roomBusy[$slotKey][$candidateId])) {
                    $roomId = $candidateId;
                    break;
                }
            }

            if ($roomId === null) {
                continue;
            }

            // Idempotency: the same (term, room, day, start) already holds this
            // meeting on a re-run, so reuse it instead of duplicating the row.
            $existing = $database->selectOne(
                'SELECT id FROM schedules
                  WHERE semester_id = :semester_id AND room_id = :room_id
                    AND day_of_week = :day_of_week AND start_time = :start_time
                  LIMIT 1',
                [
                    'semester_id' => $currentSemesterId,
                    'room_id' => $roomId,
                    'day_of_week' => $pair['day'],
                    'start_time' => $pair['start'],
                ]
            );

            if ($existing !== null) {
                $schedulesReused++;
            } else {
                $scheduleId = $scheduleRepo->create([
                    'semester_id' => $currentSemesterId,
                    'department_id' => $departmentId,
                    'section_id' => $sectionId,
                    'subject_id' => $subjectId,
                    'faculty_id' => $facultyId,
                    'room_id' => $roomId,
                    'day_of_week' => $pair['day'],
                    'start_time' => $pair['start'],
                    'end_time' => $pair['end'],
                    'meeting_date' => null,
                    'recurrence' => 'weekly',
                    'status' => 'approved',
                    'notes' => SCHEDULE_NOTE,
                    'actor_id' => $adminId,
                ]);

                $scheduleRepo->applyStatus($scheduleId, 'approved', [
                    'approved_by' => $adminId,
                    'approved_at' => date('Y-m-d H:i:s'),
                ]);

                $schedulesCreated++;
            }

            $roomBusy[$slotKey][$roomId] = true;
            $facultyBusy[$slotKey][$facultyId] = true;
            $sectionBusy[$slotKey][$sectionId] = true;
            $pairCursor = ($pairIndex + 1) % $pairCount;
            $roomRotation++;
            $placed = true;

            break;
        }

        if (!$placed) {
            fail(sprintf(
                'No conflict-free slot left for %s (%s, meeting %d).',
                $subject['code'],
                $spec['code'],
                $meeting + 1
            ));
        }
    }
}

// ---------------------------------------------------------------------------
// 8. Verification — every count comes back out of the database.
// ---------------------------------------------------------------------------

$results = [];

expectCount(
    $results,
    'User roles',
    countIn($database, 'roles', 'code', array_keys($roleSpecs)),
    REQUIRED_COUNTS['roles']
);

expectCount(
    $results,
    'Departments',
    countIn($database, 'departments', 'code', array_keys($departmentIds)),
    REQUIRED_COUNTS['departments']
);

expectCount(
    $results,
    'Faculty members',
    (int) $database->scalar(
        "SELECT COUNT(*)
           FROM faculty_profiles f
           INNER JOIN users u ON u.id = f.user_id
          WHERE u.employee_no LIKE 'FAC-%'"
    ),
    REQUIRED_COUNTS['faculty']
);

expectCount(
    $results,
    'Faculty accounts',
    (int) $database->scalar("SELECT COUNT(*) FROM users WHERE role_id = :role_id AND employee_no LIKE 'FAC-%'", [
        'role_id' => $roleIds['faculty'],
    ]),
    REQUIRED_COUNTS['faculty']
);

expectCount(
    $results,
    'Subjects',
    countIn($database, 'subjects', 'department_id', array_values($departmentIds)),
    REQUIRED_COUNTS['subjects']
);

expectCount(
    $results,
    'Buildings',
    countIn($database, 'buildings', 'code', array_column($buildingSpecs, 'code')),
    REQUIRED_COUNTS['buildings']
);

expectCount(
    $results,
    'Rooms',
    countIn($database, 'rooms', 'id', $roomIds),
    REQUIRED_COUNTS['rooms']
);

expectCount(
    $results,
    'Sections',
    countIn($database, 'sections', 'id', array_merge(...array_values($sectionIdsByDepartment))),
    REQUIRED_COUNTS['sections']
);

expectCount(
    $results,
    'Semesters',
    countIn($database, 'semesters', 'code', array_keys($semesterIds)),
    REQUIRED_COUNTS['semesters']
);

expectCount(
    $results,
    'Schedule records',
    (int) $database->scalar(
        'SELECT COUNT(*) FROM schedules WHERE semester_id = :semester_id AND notes = :notes',
        ['semester_id' => $currentSemesterId, 'notes' => SCHEDULE_NOTE]
    ),
    REQUIRED_COUNTS['schedules']
);

// Semantic checks: the data must also be usable, not only countable.
$roomOverlaps = (int) $database->scalar(
    'SELECT COUNT(*)
       FROM schedules a
       INNER JOIN schedules b
         ON a.id < b.id
        AND a.semester_id = b.semester_id
        AND a.room_id = b.room_id
        AND a.day_of_week = b.day_of_week
        AND a.start_time < b.end_time
        AND a.end_time > b.start_time
      WHERE a.semester_id = :semester_id AND a.status <> :cancelled',
    ['semester_id' => $currentSemesterId, 'cancelled' => 'cancelled']
);

$facultyOverlaps = (int) $database->scalar(
    'SELECT COUNT(*)
       FROM schedules a
       INNER JOIN schedules b
         ON a.id < b.id
        AND a.semester_id = b.semester_id
        AND a.faculty_id = b.faculty_id
        AND a.day_of_week = b.day_of_week
        AND a.start_time < b.end_time
        AND a.end_time > b.start_time
      WHERE a.semester_id = :semester_id AND a.status <> :cancelled',
    ['semester_id' => $currentSemesterId, 'cancelled' => 'cancelled']
);

$sectionOverlaps = (int) $database->scalar(
    'SELECT COUNT(*)
       FROM schedules a
       INNER JOIN schedules b
         ON a.id < b.id
        AND a.semester_id = b.semester_id
        AND a.section_id = b.section_id
        AND a.day_of_week = b.day_of_week
        AND a.start_time < b.end_time
        AND a.end_time > b.start_time
      WHERE a.semester_id = :semester_id AND a.status <> :cancelled',
    ['semester_id' => $currentSemesterId, 'cancelled' => 'cancelled']
);

expectCount($results, 'Room double-bookings', $roomOverlaps, 0);
expectCount($results, 'Faculty double-bookings', $facultyOverlaps, 0);
expectCount($results, 'Section double-bookings', $sectionOverlaps, 0);

$currentSemester = $termRepo->currentSemester();

if ($currentSemester === null || (int) $currentSemester['id'] !== $currentSemesterId) {
    fail('The current semester was not set.');
}

$auditRepo->record(
    'system.demo_seeded',
    $adminId,
    'institution',
    null,
    'Demo data set created by bin/seed-demo.php.',
    [
        'departments' => REQUIRED_COUNTS['departments'],
        'faculty' => REQUIRED_COUNTS['faculty'],
        'subjects' => REQUIRED_COUNTS['subjects'],
        'rooms' => REQUIRED_COUNTS['rooms'],
        'schedules' => REQUIRED_COUNTS['schedules'],
        'semester' => (string) $currentSemester['code'],
    ],
    $actorIp,
    $actorAgent
);

// ---------------------------------------------------------------------------
// 9. Report.
// ---------------------------------------------------------------------------

echo PHP_EOL;
echo '======================================================================' . PHP_EOL;
echo '  SyncClass — demo data set' . PHP_EOL;
echo '======================================================================' . PHP_EOL;
echo PHP_EOL;
printf("  %-28s %10s %10s  %s%s", 'Requirement', 'Expected', 'In database', 'Status', PHP_EOL);
echo '  --------------------------------------------------------------------' . PHP_EOL;

foreach ($results as [$label, $expected, $actual]) {
    printf(
        "  %-28s %10d %10d  %s%s",
        $label,
        $expected,
        $actual,
        $expected === $actual ? 'OK' : 'FAILED',
        PHP_EOL
    );
}

echo PHP_EOL;
printf("  Schedule rows written this run : %d%s", $schedulesCreated, PHP_EOL);
printf("  Schedule rows already present  : %d%s", $schedulesReused, PHP_EOL);
printf("  Current semester               : %s (%s)%s", (string) $currentSemester['code'], (string) $currentSemester['year_code'], PHP_EOL);
echo PHP_EOL;
echo '  Demo sign-ins (password: ' . DEMO_PASSWORD . ')' . PHP_EOL;
echo '  --------------------------------------------------------------------' . PHP_EOL;
printf("    %-34s %s%s", $adminEmail, 'System Administrator', PHP_EOL);
printf("    %-34s %s%s", 'dean.csm@' . DEMO_DOMAIN, 'Dean — CSM', PHP_EOL);
printf("    %-34s %s%s", 'secretary.csm@' . DEMO_DOMAIN, 'Department Secretary — CSM', PHP_EOL);
printf("    %-34s %s%s", 'jcruz@' . DEMO_DOMAIN, 'Faculty — CSM', PHP_EOL);
echo PHP_EOL;
echo '  Re-run safely: existing rows with the same natural keys are reused.' . PHP_EOL;
echo '  Run against a fresh anth04_SyncClass: rows left by an older seed would' . PHP_EOL;
echo '  make the counts above fail, which is the intended signal.' . PHP_EOL;
echo '======================================================================' . PHP_EOL;
echo PHP_EOL;

exit(0);
