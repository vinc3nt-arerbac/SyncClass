<?php

declare(strict_types=1);

/**
 * Authentication test — Phase 7 exit criterion.
 *
 *     php tests/auth_test.php
 *
 * Runs against an in-memory SQLite database with the real repositories and the
 * real AuthService: password verification, transparent rehash, throttle,
 * account lockout, inactive accounts, remember-me issue/rotate/theft handling,
 * logout, and the full HTTP flow through the kernel (login page, 302/303,
 * 419/422/429, private home, guest redirect, remember-me restore).
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Security;
use App\Core\Session;
use App\Repositories\AuditRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\RememberTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RememberMeService;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/support/auth_schema.php';

/** @var array<int, array{label: string, passed: bool, detail: string}> $checks */
$checks = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks;

    $checks[] = ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

/** Controllable clock so expiry and windows are testable without sleeping. */
$now = time();
$clock = static function () use (&$now): int {
    return $now;
};

// ---------------------------------------------------------------------------
// Wiring: cheap bcrypt cost, SQLite, one session for the whole suite
// ---------------------------------------------------------------------------
$testConfig = $config;
$testConfig['security']['password']['options']['cost'] = 4;
$testConfig['app']['session']['name'] = 'syncclass_auth_test';

$configObject = new Config($testConfig);

$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
auth_schema($database);

$session = new Session($testConfig['app']['session'], $clock);
$session->start();
$security = new Security($session, $testConfig['security'], $clock);

$users = new UserRepository($database);
$attempts = new LoginAttemptRepository($database);
$tokens = new RememberTokenRepository($database);
$audit = new AuditRepository($database);
$remember = new RememberMeService($tokens, $security, $configObject, $clock);
$auth = new AuthService($users, $attempts, $remember, $audit, $security, $session, $configObject, $clock);

$userId = auth_create_user($database, [
    'email' => 'faculty.jcruz@sac.edu.ph',
    'first_name' => 'Juan',
    'last_name' => 'Cruz',
    'password' => 'SyncClass2026',
]);

$loaded = $users->findByEmail('FACULTY.JCRUZ@SAC.EDU.PH');
check('repository finds an account by email', $loaded !== null && (int) $loaded['id'] === $userId);
check('repository joins the role', ($loaded['role_code'] ?? '') === 'faculty');
check('display name combines first and last name', $users->displayName($loaded) === 'Juan Cruz');

// ---------------------------------------------------------------------------
// Password verification and failure accounting
// ---------------------------------------------------------------------------
$sessionIdBefore = $session->id();
$csrfBefore = $security->csrfToken();

$wrong = $auth->attempt('faculty.jcruz@sac.edu.ph', 'WrongPassword1', false, '10.0.0.5', 'test-agent');
check('a wrong password is refused', !$wrong->ok() && $wrong->reason() === 'invalid');
check('the refusal is a 422', $wrong->status() === 422);
check('the failure is recorded in login_attempts', (int) $database->scalar('SELECT COUNT(*) FROM login_attempts WHERE successful = 0') === 1);
check('the account failure counter is incremented', (int) $database->scalar('SELECT failed_login_attempts FROM users WHERE id = :id', ['id' => $userId]) === 1);
check('no session was created by a failure', $auth->userId() === null);

$unknown = $auth->attempt('nobody@sac.edu.ph', 'SyncClass2026', false, '10.0.0.6', 'test-agent');
check('an unknown email is refused', !$unknown->ok());
check('unknown and wrong-password messages are identical', $unknown->message() === $wrong->message());
check('unknown emails are still recorded', (int) $database->scalar('SELECT COUNT(*) FROM login_attempts') === 2);

// ---------------------------------------------------------------------------
// Success: session regeneration, CSRF rotation, audit
// ---------------------------------------------------------------------------
$success = $auth->attempt('Faculty.JCruz@SAC.edu.ph', 'SyncClass2026', false, '10.0.0.5', 'test-agent');
check('a correct password signs in (email is case-insensitive)', $success->ok() && (int) $success->user()['id'] === $userId);
check('the session holds the authenticated user id', $auth->userId() === $userId && $session->get(AuthService::SESSION_KEY) === $userId);
check('the session id is regenerated on login', $session->id() !== $sessionIdBefore);
check('the CSRF token is rotated on login', $security->csrfToken() !== $csrfBefore);
check('a successful login clears the failure counter', (int) $database->scalar('SELECT failed_login_attempts FROM users WHERE id = :id', ['id' => $userId]) === 0);
check('last_login_at is stamped', $database->scalar('SELECT last_login_at FROM users WHERE id = :id', ['id' => $userId]) !== null);
check('the login is audited', (int) $database->scalar("SELECT COUNT(*) FROM activity_log WHERE event = 'auth.login'") === 1);
check('no remember cookie without the checkbox', $success->cookie() === null);
check('check() reports the signed-in account', $auth->check() && (int) $auth->currentUser()['id'] === $userId);

// ---------------------------------------------------------------------------
// Remember-me: issue, rotate, replay, theft, expiry
// ---------------------------------------------------------------------------
$rememberCookie = $remember->issue($userId, '10.0.0.5', 'test-agent');
check('remember cookie is selector:validator', preg_match('/^[a-f0-9]{24}:[a-f0-9]{64}$/', $rememberCookie->value()) === 1);
check('remember cookie is HttpOnly + SameSite=Lax', str_contains($rememberCookie->toHeader(), 'HttpOnly')
    && str_contains($rememberCookie->toHeader(), 'SameSite=Lax'));
check('remember cookie has a 30-day expiry', $rememberCookie->expiresAt() > $now + 29 * 86400);

$rememberLogin = $remember->consume($rememberCookie->value(), '10.0.0.5', 'test-agent');
check('remember cookie signs the user in', $rememberLogin !== null && $rememberLogin->userId() === $userId);
check('remember cookie is rotated on use', $rememberLogin !== null && $rememberLogin->cookie()->value() !== $rememberCookie->value());
check('a replayed remember cookie is refused', $remember->consume($rememberCookie->value(), '10.0.0.5', 'test-agent') === null);
check('the rotated cookie is accepted', $remember->consume($rememberLogin?->cookie()->value(), '10.0.0.5', 'test-agent')?->userId() === $userId);

$forgedBase = $remember->issue($userId, '10.0.0.5', 'test-agent');
$forgedSelector = explode(':', $forgedBase->value())[0];
check('a wrong validator is refused', $remember->consume($forgedSelector . ':' . str_repeat('a', 64), '10.0.0.5', 'test-agent') === null);
check('a validator mismatch invalidates every remembered browser', (int) $database->scalar('SELECT COUNT(*) FROM remember_tokens WHERE user_id = :id', ['id' => $userId]) === 0);

$expiring = $remember->issue($userId, '10.0.0.5', 'test-agent');
$now += 31 * 86400;
check('an expired remember cookie is refused', $remember->consume($expiring->value(), '10.0.0.5', 'test-agent') === null);
$now -= 31 * 86400;

// ---------------------------------------------------------------------------
// Throttle (email + IP) and account lockout
// ---------------------------------------------------------------------------
for ($i = 0; $i < 5; $i++) {
    $auth->attempt('faculty.jcruz@sac.edu.ph', 'WrongPassword1', false, '203.0.113.9', 'test-agent');
}

$throttled = $auth->attempt('faculty.jcruz@sac.edu.ph', 'WrongPassword1', false, '203.0.113.9', 'test-agent');
check('five failures in the window trigger the throttle', !$throttled->ok() && $throttled->reason() === 'throttled');
check('the throttle reports a retry delay', $throttled->retryAfter() > 0);
check('the throttle answers 429', $throttled->status() === 429);

$throttledCorrect = $auth->attempt('faculty.jcruz@sac.edu.ph', 'SyncClass2026', false, '203.0.113.9', 'test-agent');
check('the throttle blocks even a correct password', !$throttledCorrect->ok() && $throttledCorrect->reason() === 'throttled');

$lockedId = auth_create_user($database, [
    'email' => 'dean.cas@sac.edu.ph',
    'first_name' => 'Maria',
    'last_name' => 'Santos',
    'role_code' => 'dean',
]);

for ($i = 0; $i < 5; $i++) {
    $auth->attempt('dean.cas@sac.edu.ph', 'WrongPassword1', false, '198.51.100.' . $i, 'test-agent');
}

check('an account is locked after repeated failures', $database->scalar('SELECT locked_until FROM users WHERE id = :id', ['id' => $lockedId]) !== null);

$locked = $auth->attempt('dean.cas@sac.edu.ph', 'SyncClass2026', false, '198.51.100.99', 'test-agent');
check('a locked account refuses the correct password', !$locked->ok() && $locked->reason() === 'throttled');

// ---------------------------------------------------------------------------
// Inactive accounts and transparent rehash
// ---------------------------------------------------------------------------
$inactiveId = auth_create_user($database, [
    'email' => 'faculty.retired@sac.edu.ph',
    'password' => 'SyncClass2026',
    'is_active' => 0,
]);

$inactive = $auth->attempt('faculty.retired@sac.edu.ph', 'SyncClass2026', false, '203.0.113.77', 'test-agent');
check('an inactive account cannot sign in', !$inactive->ok() && $inactive->reason() === 'invalid');
check('the inactive refusal does not reveal the account state', $inactive->message() === $wrong->message());

$rehashId = auth_create_user($database, [
    'email' => 'rehash@sac.edu.ph',
    'password' => 'SyncClass2026',
]);
$weakHash = (string) $database->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $rehashId]);

$strongSecurity = new Security($session, array_replace_recursive($testConfig['security'], ['password' => ['options' => ['cost' => 12]]]), $clock);
$strongConfig = new Config(array_replace_recursive($testConfig, ['security' => ['password' => ['options' => ['cost' => 12]]]]));
$strongAuth = new AuthService($users, $attempts, $remember, $audit, $strongSecurity, $session, $strongConfig, $clock);
$strongAuth->attempt('rehash@sac.edu.ph', 'SyncClass2026', false, '203.0.113.88', 'test-agent');

$strongHash = (string) $database->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $rehashId]);
check('a weaker hash is upgraded on login', $strongHash !== $weakHash);
check('the upgraded hash matches the configured cost', !$strongSecurity->needsRehash($strongHash));

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------
$logoutCookie = $remember->issue($userId, '10.0.0.5', 'test-agent');
$session->set(AuthService::SESSION_KEY, $userId);
$cleared = $auth->logout($logoutCookie->value(), '10.0.0.5', 'test-agent');

check('logout clears the session identity', $auth->userId() === null);
check('logout destroys the session', !$session->isStarted());
check('logout expires the remember cookie', $cleared->value() === '' && str_contains($cleared->toHeader(), 'Max-Age=0'));
check('logout deletes the token row', (int) $database->scalar(
    'SELECT COUNT(*) FROM remember_tokens WHERE selector = :selector',
    ['selector' => explode(':', $logoutCookie->value())[0]]
) === 0);
check('logout is audited', (int) $database->scalar("SELECT COUNT(*) FROM activity_log WHERE event = 'auth.logout'") === 1);

// ---------------------------------------------------------------------------
// HTTP flow through the kernel (real routes, real middleware)
// ---------------------------------------------------------------------------
$kernelConfig = $testConfig;
$kernelConfig['database'] = ['driver' => 'sqlite', 'database' => ':memory:'];
$kernelConfig['app']['session']['name'] = 'syncclass_auth_http_test';

$kernel = Kernel::boot($kernelConfig);
$kernelDatabase = $kernel->container()->get(Database::class);
auth_schema($kernelDatabase);

$adminId = auth_create_user($kernelDatabase, [
    'email' => 'admin@sac.edu.ph',
    'first_name' => 'Ana',
    'last_name' => 'Reyes',
    'password' => 'SyncClass2026',
    'role_code' => 'administrator',
]);

$anonymousHome = $kernel->handle(new Request('GET', '/'));
check('an anonymous visitor is redirected from the app shell', $anonymousHome->status() === 302
    && $anonymousHome->header('Location') === '/SyncClass/login', (string) $anonymousHome->header('Location'));
check('the auth middleware remembers the intended path', $kernel->container()->get(Session::class)->get(AuthService::INTENDED_KEY) === '/');

$apiGuarded = $kernel->handle(new Request('GET', '/pipeline/guarded', headers: ['accept' => 'application/json']));
check('API clients receive 401 JSON instead of a redirect', $apiGuarded->status() === 401
    && json_decode($apiGuarded->body(), true)['error']['code'] === 'UNAUTHORIZED');

$loginPage = $kernel->handle(new Request('GET', '/login'));
check('the login page renders', $loginPage->status() === 200
    && str_contains($loginPage->body(), 'Sign in')
    && str_contains($loginPage->body(), 'name="_token"'));
check('the login form is password-only (no value echoed back)', !str_contains($loginPage->body(), 'type="password" value="'));
check('the login page carries the security headers', $loginPage->header('X-Content-Type-Options') === 'nosniff');

$formHeaders = ['content-type' => 'application/x-www-form-urlencoded'];

$tokenA = $kernel->container()->get(Security::class)->csrfToken();
$formWithoutToken = $kernel->handle(new Request('POST', '/login', body: ['email' => 'admin@sac.edu.ph', 'password' => 'SyncClass2026'], headers: $formHeaders));
check('posting the login form without a CSRF token is 419', $formWithoutToken->status() === 419);

$badCredentials = $kernel->handle(new Request('POST', '/login', body: [
    'email' => 'admin@sac.edu.ph',
    'password' => 'WrongPassword1',
    '_token' => $tokenA,
], headers: $formHeaders));
check('wrong credentials re-render the form with 422', $badCredentials->status() === 422
    && str_contains($badCredentials->body(), 'do not match our records'));
check('the submitted email is preserved on failure', str_contains($badCredentials->body(), 'value="admin@sac.edu.ph"'));

$tokenBeforeLogin = $kernel->container()->get(Security::class)->csrfToken();

$goodCredentials = $kernel->handle(new Request('POST', '/login', body: [
    'email' => 'admin@sac.edu.ph',
    'password' => 'SyncClass2026',
    'remember' => '1',
    '_token' => $tokenBeforeLogin,
], headers: $formHeaders));

check('a correct password redirects into the app', $goodCredentials->status() === 303
    && $goodCredentials->header('Location') === '/SyncClass/', (string) $goodCredentials->header('Location'));

$setCookie = (string) $goodCredentials->header('Set-Cookie');
check('the remember-me cookie is set', str_contains($setCookie, 'syncclass_remember=')
    && str_contains($setCookie, 'HttpOnly')
    && str_contains($setCookie, 'SameSite=Lax'));

check('the CSRF token is rotated after login', $kernel->container()->get(Security::class)->csrfToken() !== $tokenBeforeLogin);

$authenticatedHome = $kernel->handle(new Request('GET', '/'));
check('the authenticated user sees the shell', $authenticatedHome->status() === 200
    && str_contains($authenticatedHome->body(), 'Welcome back, Ana'), substr($authenticatedHome->body(), 0, 120));
check('the shell shows the role', str_contains($authenticatedHome->body(), 'System Administrator'));
check('the shell offers a sign-out form', str_contains($authenticatedHome->body(), 'action="/SyncClass/logout"'));

$guestRedirect = $kernel->handle(new Request('GET', '/login'));
check('a signed-in user is redirected away from the login form', $guestRedirect->status() === 302
    && $guestRedirect->header('Location') === '/SyncClass/');

// Remember-me restore: drop the session, present only the cookie.
$rememberValue = auth_cookie_value($setCookie, 'syncclass_remember');
check('the remember-me cookie value is parseable', $rememberValue !== null);

$kernel->container()->get(Session::class)->destroy();
$remembered = $kernel->handle(new Request('GET', '/', cookies: ['syncclass_remember' => (string) $rememberValue]));
check('remember-me restores an authenticated session', $remembered->status() === 200
    && str_contains($remembered->body(), 'Welcome back, Ana'));
check('remember-me rotates the cookie on restore', str_contains((string) $remembered->header('Set-Cookie'), 'syncclass_remember='));

// Replay of the original cookie must fail after rotation.
$kernel->container()->get(Session::class)->destroy();
$replayed = $kernel->handle(new Request('GET', '/', cookies: ['syncclass_remember' => (string) $rememberValue]));
check('the original remember-me cookie cannot be replayed', $replayed->status() === 302);

// Sign in again for the throttle and logout checks. The destroy() above ended
// the session, so a fresh one has to be started by the middleware before a
// token can be issued for it.
$kernel->container()->get(Session::class)->destroy();
$kernel->handle(new Request('GET', '/login'));
$loginToken = (string) $kernel->container()->get(Security::class)->csrfToken();

$reLogin = $kernel->handle(new Request('POST', '/login', body: [
    'email' => 'admin@sac.edu.ph',
    'password' => 'SyncClass2026',
    '_token' => $loginToken,
], headers: $formHeaders));
check('signing in again works after a remember-me replay', $reLogin->status() === 303);

// Throttle over HTTP: five failures, then 429 with Retry-After.
$throttleToken = (string) $kernel->container()->get(Security::class)->csrfToken();

for ($i = 0; $i < 5; $i++) {
    $kernel->handle(new Request('POST', '/login', body: [
        'email' => 'throttle@sac.edu.ph',
        'password' => 'WrongPassword1',
        '_token' => $throttleToken,
    ], headers: $formHeaders));
}

$blocked = $kernel->handle(new Request('POST', '/login', body: [
    'email' => 'throttle@sac.edu.ph',
    'password' => 'WrongPassword1',
    '_token' => $throttleToken,
], headers: $formHeaders));

check('the login page answers 429 once throttled', $blocked->status() === 429, (string) $blocked->status());
check('the 429 carries Retry-After', $blocked->header('Retry-After') !== null);

// Logout over HTTP.
$logoutToken = (string) $kernel->container()->get(Security::class)->csrfToken();

$logout = $kernel->handle(new Request('POST', '/logout', body: ['_token' => $logoutToken], headers: $formHeaders));
check('sign-out redirects to the login page', $logout->status() === 303
    && $logout->header('Location') === '/SyncClass/login');
check('sign-out clears the remember-me cookie', str_contains((string) $logout->header('Set-Cookie'), 'Max-Age=0'));

$afterLogout = $kernel->handle(new Request('GET', '/'));
check('the session no longer authenticates after sign-out', $afterLogout->status() === 302
    && $afterLogout->header('Location') === '/SyncClass/login');

$healthAfterLogout = $kernel->handle(new Request('GET', '/health'));
check('the public health check stays reachable', $healthAfterLogout->status() === 200);

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$failures = array_values(array_filter($checks, static fn (array $check): bool => !$check['passed']));

foreach ($checks as $check) {
    printf(
        "%s %s%s\n",
        $check['passed'] ? 'PASS' : 'FAIL',
        $check['label'],
        $check['passed'] || $check['detail'] === '' ? '' : ' — ' . $check['detail']
    );
}

printf("\n%d check(s), %d failure(s).\n", count($checks), count($failures));

exit($failures === [] ? 0 : 1);
