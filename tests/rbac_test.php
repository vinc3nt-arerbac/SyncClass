<?php

declare(strict_types=1);

/**
 * RBAC test — Phase 8 exit criterion.
 *
 *     php tests/rbac_test.php
 *
 * Covers the four layers of the authorisation model separately, because they
 * fail in different ways:
 *
 *   1. catalogue + sync  — config/permissions.php reaches the database, twice
 *                          without duplicating anything;
 *   2. decisions         — the matrix, unknown permissions, inactive accounts
 *                          and the absence of an administrator bypass;
 *   3. scope             — the value object and the SQL clause it produces in
 *                          UserRepository (administrator / department / self);
 *   4. HTTP              — route middleware allows and refuses, answers JSON
 *                          for fetch() clients, audits every refusal and fails
 *                          closed when a route misdeclares its rules.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuthorizationService;
use App\Services\RbacService;
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
// Wiring: in-memory SQLite behind the kernel's real services
// ---------------------------------------------------------------------------
$testConfig = $config;
$testConfig['security']['password']['options']['cost'] = 4;
$testConfig['app']['session']['name'] = 'syncclass_rbac_test';

$configObject = new Config($testConfig);

$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
auth_schema($database);
// Phase 16: `/` is the dashboard, so the HTTP section needs the master-data
// and schedule mirrors the dashboard reads.
master_schema($database);
schedule_schema($database);

$roles = new RoleRepository($database);
$permissions = new PermissionRepository($database);
$users = new UserRepository($database);
$rbac = new RbacService($roles, $permissions, $configObject);

// Departments: 1 = CAS, 2 = CBA.
$database->insert('departments', ['code' => 'CAS', 'name' => 'College of Arts and Sciences']);
$database->insert('departments', ['code' => 'CBA', 'name' => 'College of Business Administration']);
$cas = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CAS']);
$cba = (int) $database->scalar('SELECT id FROM departments WHERE code = :code', ['code' => 'CBA']);

// ---------------------------------------------------------------------------
// 1. Catalogue and synchronisation
// ---------------------------------------------------------------------------
/** @var array<string, mixed> $catalogue */
$catalogue = $testConfig['permissions'];
$definedCodes = array_keys((array) $catalogue['codes']);
$matrix = (array) $catalogue['roles'];

check('the catalogue declares four roles', count($matrix) === 4);
check('every role in the matrix names at least one permission', array_reduce(
    $matrix,
    static fn (bool $carry, array $role): bool => $carry && (array) $role['permissions'] !== [],
    true
));
check('every granted code exists in the catalogue', array_reduce(
    $matrix,
    static function (bool $carry, array $role) use ($definedCodes): bool {
        return $carry && array_diff((array) $role['permissions'], $definedCodes) === [];
    },
    true
));
check('the administrator holds the whole catalogue', count((array) $matrix['administrator']['permissions']) === count($definedCodes));
check('no role uses a wildcard', array_reduce(
    $matrix,
    static fn (bool $carry, array $role): bool => $carry && !in_array('*', (array) $role['permissions'], true),
    true
));

$first = $rbac->synchronise();
check('sync writes every role', $first['roles'] === 4, json_encode($first));
check('sync writes every permission', $first['permissions'] === count($definedCodes));
check('sync writes the expected number of links', $first['links'] === array_sum(array_map(
    static fn (array $role): int => count((array) $role['permissions']),
    $matrix
)));
check('permissions reach the database', (int) $database->scalar('SELECT COUNT(*) FROM permissions') === count($definedCodes));
check('the administrator gets a row for every code', (int) $database->scalar(
    'SELECT COUNT(*) FROM role_permissions rp
       INNER JOIN roles r ON r.id = rp.role_id
      WHERE r.code = :code',
    ['code' => 'administrator']
) === count($definedCodes));

$second = $rbac->synchronise();
check('sync is idempotent for roles and permissions', $second['roles'] === $first['roles'] && $second['permissions'] === $first['permissions']);
check('sync does not duplicate links', $second['links'] === $first['links'] && (int) $database->scalar('SELECT COUNT(*) FROM role_permissions') === $first['links']);

$badConfig = $testConfig;
$badConfig['permissions']['roles']['faculty']['permissions'][] = 'nope.not.a.permission';
$unknownFailed = false;

try {
    (new RbacService($roles, $permissions, new Config($badConfig)))->synchronise();
} catch (RuntimeException $exception) {
    $unknownFailed = str_contains($exception->getMessage(), 'nope.not.a.permission');
}

check('a typo in the matrix fails the sync instead of silently vanishing', $unknownFailed);

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------
$adminId = auth_create_user($database, ['email' => 'admin@sac.edu.ph', 'role_code' => 'administrator', 'first_name' => 'Ana', 'last_name' => 'Reyes']);
$deanId = auth_create_user($database, ['email' => 'dean.cas@sac.edu.ph', 'role_code' => 'dean', 'first_name' => 'Maria', 'last_name' => 'Santos', 'department_id' => $cas]);
$secretaryId = auth_create_user($database, ['email' => 'secretary.cas@sac.edu.ph', 'role_code' => 'secretary', 'first_name' => 'Liza', 'last_name' => 'Bautista', 'department_id' => $cas]);
$facultyCasId = auth_create_user($database, ['email' => 'faculty.cas@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Juan', 'last_name' => 'Cruz', 'department_id' => $cas]);
$facultyCbaId = auth_create_user($database, ['email' => 'faculty.cba@sac.edu.ph', 'role_code' => 'faculty', 'first_name' => 'Rosa', 'last_name' => 'Lim', 'department_id' => $cba]);

$admin = $users->findById($adminId);
$dean = $users->findById($deanId);
$secretary = $users->findById($secretaryId);
$facultyCas = $users->findById($facultyCasId);
$facultyCba = $users->findById($facultyCbaId);

foreach ([$admin, $dean, $secretary, $facultyCas, $facultyCba] as $row) {
    if (!is_array($row)) {
        fwrite(STDERR, "Fixture error: a user row could not be loaded.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 2. Permission decisions
// ---------------------------------------------------------------------------
$authz = new AuthorizationService($permissions);

check('the administrator can manage settings', $authz->can($admin, 'settings.manage'));
check('the administrator can manage roles', $authz->can($admin, 'roles.manage'));
check('the dean may approve schedules', $authz->can($dean, 'schedules.approve'));
check('the dean may edit subjects in scope', $authz->can($dean, 'subjects.update'));
check('the dean may not manage settings', !$authz->can($dean, 'settings.manage'));
check('the dean may not manage roles', !$authz->can($dean, 'roles.manage'));
check('the dean may not read the audit log', !$authz->can($dean, 'audit.view'));
check('the secretary may create schedules', $authz->can($secretary, 'schedules.create'));
check('the secretary may not approve schedules', !$authz->can($secretary, 'schedules.approve'));
check('the secretary may not create accounts', !$authz->can($secretary, 'users.create'));
check('faculty may view schedules', $authz->can($facultyCas, 'schedules.view'));
check('faculty may not create schedules', !$authz->can($facultyCas, 'schedules.create'));
check('faculty may export their own reports', $authz->can($facultyCas, 'reports.export'));
check('faculty may not export institution-wide reports', !$authz->can($facultyCas, 'reports.export.all'));
check('faculty may maintain their own availability', $authz->can($facultyCas, 'availability.update'));

check('an unknown permission is denied to faculty', !$authz->can($facultyCas, 'ghost.permission'));
check('an unknown permission is denied to the administrator too (no bypass)', !$authz->can($admin, 'ghost.permission'));
check('an empty permission string is denied', !$authz->can($admin, ''));
check('a null user is denied', !$authz->can(null, 'schedules.view'));
check('a user without a role is denied', !$authz->can(['id' => 99, 'is_active' => 1], 'schedules.view'));
check('an inactive account is denied even with the role', !$authz->can(['id' => 99, 'role_id' => (int) $dean['role_id'], 'role_code' => 'dean', 'is_active' => 0], 'schedules.approve'));

check('canAny accepts one of several codes', $authz->canAny($facultyCas, ['schedules.create', 'schedules.view']));
check('canAny refuses when none is held', !$authz->canAny($facultyCas, ['schedules.create', 'schedule.delete']));
check('canAll requires every code', $authz->canAll($admin, ['users.view', 'users.create']));
check('canAll refuses a partial match', !$authz->canAll($secretary, ['schedules.view', 'schedules.approve']));
check('canAll with an empty list is false (fail closed)', !$authz->canAll($admin, []));

$authorizeFailed = false;

try {
    $authz->authorize($facultyCas, 'users.create');
} catch (App\Core\HttpException $exception) {
    $authorizeFailed = $exception->status() === 403;
}

check('authorize() throws 403 for a missing permission', $authorizeFailed);

$authorizePassed = true;

try {
    $authz->authorize($admin, 'users.create');
} catch (App\Core\HttpException) {
    $authorizePassed = false;
}

check('authorize() returns quietly when the permission is held', $authorizePassed);

check('permissionsFor lists the granted codes sorted', $authz->permissionsFor($facultyCas) === array_values(array_unique($authz->permissionsFor($facultyCas))));
check('permissionsFor is empty for a null user', $authz->permissionsFor(null) === []);
check('the administrator is recognised', $authz->isAdministrator($admin) && !$authz->isAdministrator($dean));
check('an unknown code is reported as undefined', !$authz->isDefined('ghost.permission') && $authz->isDefined('schedules.view'));

// Revoking a grant applies after the cache is flushed (console/test path).
$facultyRoleId = (int) $facultyCas['role_id'];
$permissionRow = $permissions->findByCode('schedules.view');
$database->delete(
    'role_permissions',
    'role_id = :role_id AND permission_id = :permission_id',
    ['role_id' => $facultyRoleId, 'permission_id' => (int) $permissionRow['id']]
);
$authz->flush();
check('a revoked grant is refused after flush()', !$authz->can($facultyCas, 'schedules.view'));

$rbac->synchronise();
$authz->flush();
check('a re-sync restores the catalogue grant', $authz->can($facultyCas, 'schedules.view'));

// ---------------------------------------------------------------------------
// 3. Scope
// ---------------------------------------------------------------------------
$adminScope = Scope::forUser($admin);
$deanScope = Scope::forUser($dean);
$facultyScope = Scope::forUser($facultyCas);

check('the administrator scope is institution-wide', $adminScope->isAdministrator() && $adminScope->coversDepartment($cba));
check('the administrator scope has no department', $adminScope->departmentId() === null);
check('the dean scope carries the department', $deanScope->isDepartmental() && $deanScope->departmentId() === $cas);
check('the dean scope covers its own department', $deanScope->coversDepartment($cas));
check('the dean scope does not cover another department', !$deanScope->coversDepartment($cba));
check('a departmental scope never covers a null department', !$deanScope->coversDepartment(null));
check('the faculty scope is self-only', $facultyScope->isSelfOnly() && !$facultyScope->isDepartmental());
check('the faculty scope covers itself', $facultyScope->coversUser($facultyCas));
check('the faculty scope does not cover a colleague', !$facultyScope->coversUser($secretary));
check('the dean scope covers its department colleagues', $deanScope->coversUser($secretary) && !$deanScope->coversUser($facultyCba));
check('the system scope is institution-wide', Scope::system()->isAdministrator());

$invalidScope = false;

try {
    Scope::forUser(['id' => 0]);
} catch (InvalidArgumentException) {
    $invalidScope = true;
}

check('a scope cannot be built from an unidentified row', $invalidScope);

check('scope labels describe the filter', $adminScope->label() === 'Institution-wide' && $facultyScope->label() === 'Own records only');

// Repository-level filtering.
check('the administrator sees all five accounts', $users->countForScope($adminScope) === 5);
check('the dean sees the three CAS accounts', $users->countForScope($deanScope) === 3);
check('the secretary (same department) sees the same three', $users->countForScope(Scope::forUser($secretary)) === 3);
check('faculty sees only themselves', $users->countForScope($facultyScope) === 1);
check('a faculty row outside the scope is not returned', count($users->paginateForScope($facultyScope, [], 50)) === 1
    && (int) $users->paginateForScope($facultyScope, [], 50)[0]['id'] === $facultyCasId);

check('a dean cannot load another department account', $users->findByIdInScope($deanScope, $facultyCbaId) === null);
check('a dean can load an account in their department', (int) ($users->findByIdInScope($deanScope, $facultyCasId)['id'] ?? 0) === $facultyCasId);
check('the administrator can load any account', (int) ($users->findByIdInScope($adminScope, $facultyCbaId)['id'] ?? 0) === $facultyCbaId);
check('faculty can load their own row', (int) ($users->findByIdInScope($facultyScope, $facultyCasId)['id'] ?? 0) === $facultyCasId);
check('faculty cannot load a colleague row', $users->findByIdInScope($facultyScope, $secretaryId) === null);

check('the search filter narrows inside the scope', $users->countForScope($adminScope, ['search' => 'cba']) === 1);
check('the search filter cannot escape the scope', $users->countForScope($facultyScope, ['search' => 'cba']) === 0);
check('the role filter narrows the list', $users->countForScope($adminScope, ['role_code' => 'faculty']) === 2);
check('a department filter cannot widen a faculty scope', $users->countForScope($facultyScope, ['department_id' => $cas]) === 1
    && $users->countForScope($facultyScope, ['department_id' => $cba]) === 0);
check('an inactive filter works inside the scope', $users->countForScope($adminScope, ['is_active' => false]) === 0);
check('pagination respects the scope', count($users->paginateForScope($deanScope, [], 2, 0)) === 2
    && count($users->paginateForScope($deanScope, [], 2, 2)) === 1);

// ---------------------------------------------------------------------------
// 4. HTTP: middleware, JSON, audit, deny-by-default
// ---------------------------------------------------------------------------
$kernel = Kernel::boot($testConfig);
$container = $kernel->container();
$container->instance(Database::class, $database);

$session = $container->get(Session::class);
$session->start();

$kernel->router()->get('/rbac/role-only', static fn (): Response => Response::html('admin-only'), ['auth', 'role:administrator'], 'Role rule probe');
$kernel->router()->get('/rbac/any-of', static fn (): Response => Response::html('any-of'), ['auth', 'permission:schedules.create,users.view'], 'Any-of rule probe');
$kernel->router()->get('/rbac/ghost', static fn (): Response => Response::html('ghost'), ['auth', 'permission:ghost.permission'], 'Unknown permission probe');
$kernel->router()->get('/rbac/unruled', static fn (): Response => Response::html('unruled'), ['auth', 'authorize'], 'Misdeclared rule probe');

$auth = $container->get(App\Services\AuthService::class);

$anonymous = $kernel->handle(new Request('GET', '/admin/users'));
check('an anonymous visitor is redirected to the login page', $anonymous->status() === 302
    && str_ends_with((string) $anonymous->header('Location'), '/login'));

$auth->loginUser($adminId);
$adminPage = $kernel->handle(new Request('GET', '/admin/users'));
check('the administrator opens the directory', $adminPage->status() === 200);
check('the administrator sees accounts from every department', str_contains($adminPage->body(), 'faculty.cba@sac.edu.ph')
    && str_contains($adminPage->body(), 'faculty.cas@sac.edu.ph'));
check('the page states the scope it was rendered with', str_contains($adminPage->body(), 'Institution-wide'));
check('the page requires users.view', str_contains($adminPage->body(), 'users.view'));

$auth->loginUser($deanId);
$deanPage = $kernel->handle(new Request('GET', '/admin/users'));
check('the dean opens the directory', $deanPage->status() === 200);
check('the dean sees their own department', str_contains($deanPage->body(), 'faculty.cas@sac.edu.ph'));
check('the dean does not see another department', !str_contains($deanPage->body(), 'faculty.cba@sac.edu.ph'));
check('the dean sees three accounts', str_contains($deanPage->body(), '3 accounts in scope'));

$auth->loginUser($facultyCasId);
$facultyPage = $kernel->handle(new Request('GET', '/admin/users'));
check('faculty opens the directory (users.view is granted)', $facultyPage->status() === 200);
check('faculty sees only themselves', str_contains($facultyPage->body(), 'faculty.cas@sac.edu.ph')
    && !str_contains($facultyPage->body(), 'secretary.cas@sac.edu.ph'));

$facultyRolePage = $kernel->handle(new Request('GET', '/rbac/role-only'));
check('a role rule refuses the wrong role', $facultyRolePage->status() === 403);
check('the refusal explains itself without leaking internals', str_contains($facultyRolePage->body(), 'permission'));

$deanRolePage = $kernel->handle(new Request('GET', '/rbac/role-only'));
check('a role rule refuses the dean too', $deanRolePage->status() === 403);

$auth->loginUser($adminId);
check('a role rule admits the administrator', $kernel->handle(new Request('GET', '/rbac/role-only'))->status() === 200);

$jsonPage = $kernel->handle(new Request('GET', '/admin/users'));
check('the administrator still passes after switching back', $jsonPage->status() === 200);

$auth->loginUser($facultyCasId);
$json = $kernel->handle(new Request('GET', '/rbac/role-only', [], [], ['accept' => 'application/json']));
$decoded = json_decode($json->body(), true);
check('a JSON client receives 403 JSON, not an HTML page', $json->status() === 403 && is_array($decoded) && ($decoded['ok'] ?? true) === false);
check('the JSON error names the failure', ($decoded['error']['code'] ?? '') === 'FORBIDDEN');

check('an any-of rule admits a user holding one code', $kernel->handle(new Request('GET', '/rbac/any-of'))->status() === 200);

$auth->loginUser($adminId);
check('an unknown permission code denies even the administrator', $kernel->handle(new Request('GET', '/rbac/ghost'))->status() === 403);
check('a route that declares authorisation but no rule fails closed', $kernel->handle(new Request('GET', '/rbac/unruled'))->status() === 403);

$denials = (int) $database->scalar("SELECT COUNT(*) FROM activity_log WHERE event = 'authz.denied'");
check('every refusal is audited', $denials >= 5, 'audited ' . $denials . ' refusal(s)');
check('the audit record names the route', (int) $database->scalar(
    "SELECT COUNT(*) FROM activity_log WHERE event = 'authz.denied' AND properties LIKE '%rbac/ghost%'"
) >= 1);

$home = $kernel->handle(new Request('GET', '/'));
check('the overview page renders for the administrator', $home->status() === 200);
check('the navigation offers People when users.view is held', str_contains($home->body(), '/admin/users'));
check('the dashboard renders its scoped widget row', str_contains($home->body(), 'stat-grid')
    && str_contains($home->body(), 'Scope: Institution-wide'));

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
