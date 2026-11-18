<?php

declare(strict_types=1);

/**
 * Security primitives test — Phase 5 exit criterion.
 *
 * A dependency-free test runner (PHPUnit arrives with the Phase 18 test pass)
 * that exercises the three Phase 5 primitives against the real configuration:
 *
 *     php tests/security_primitives_test.php
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Security;
use App\Core\Session;
use App\Helpers\Validator;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

/** @var array<int, array{label: string, passed: bool, detail: string}> $checks */
$checks = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks;

    $checks[] = ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

/**
 * A controllable clock: the primitives accept a Closure so timeout and CSRF
 * expiry can be tested without sleeping.
 */
$now = time();
$clock = static function () use (&$now): int {
    return $now;
};

// ---------------------------------------------------------------------------
// Session lifecycle (AUTH-04, AUTH-06)
// ---------------------------------------------------------------------------
$session = new Session($config['app']['session'], $clock);

check('session starts', $session->start() && $session->isStarted());
check('session id is present', $session->id() !== '');

$session->set('probe', 'value');
check('session stores and reads a value', $session->get('probe') === 'value');
check('session reports an existing key', $session->has('probe'));
check('pull returns and removes', $session->pull('probe') === 'value' && !$session->has('probe'));

check(
    'lifetime metadata is initialised',
    is_array($session->get(Session::META_KEY))
        && isset($session->get(Session::META_KEY)['created_at'], $session->get(Session::META_KEY)['last_seen'])
);

$idBefore = $session->id();
$session->regenerate();
check('regeneration replaces the session id', $session->id() !== '' && $session->id() !== $idBefore);

// ---------------------------------------------------------------------------
// CSRF tokens (AUTH-09)
// ---------------------------------------------------------------------------
$security = new Security($session, $config['security'], $clock);

$token = $security->csrfToken();
check('csrf token is 64 hex characters', preg_match('/^[0-9a-f]{64}$/', $token) === 1, $token);
check('csrf token is stable within a session', $security->csrfToken() === $token);
check('csrf accepts the issued token', $security->validateCsrf($token));
check('csrf rejects an incorrect token', !$security->validateCsrf(str_repeat('0', 64)));
check('csrf rejects a missing token', !$security->validateCsrf(null) && !$security->validateCsrf(''));

$field = $security->csrfField();
check(
    'csrf field renders an escaped hidden input',
    str_contains($field, 'name="_token"') && str_contains($field, 'value="' . $token . '"'),
    $field
);

$rotated = $security->rotateCsrfToken();
check(
    'rotation invalidates the previous token',
    !$security->validateCsrf($token) && $security->validateCsrf($rotated)
);

$now += ((int) $config['security']['csrf']['lifetime_minutes'] + 1) * 60;
check('csrf token expires after its lifetime', !$security->validateCsrf($rotated));
$fresh = $security->csrfToken();
check('a fresh token is issued after expiry', $fresh !== $rotated && $security->validateCsrf($fresh));

// ---------------------------------------------------------------------------
// Session timeouts (AUTH-06)
// ---------------------------------------------------------------------------
$session->set('before_idle', true);
$now += ((int) $config['app']['session']['idle_timeout_minutes'] + 1) * 60;

check('idle timeout is detected', $session->checkTimeouts() === 'idle');
check('expiry reason is exposed', $session->expiredReason() === 'idle');
check('expired session data is cleared', !$session->has('before_idle'));

// Absolute lifetime: disable the idle check so only the 8-hour cap can fire.
$absolute = new Session(
    array_replace($config['app']['session'], ['idle_timeout_minutes' => 0]),
    $clock
);
$absolute->start();
$now += ((int) $config['app']['session']['lifetime_minutes'] + 1) * 60;
$absolute->checkTimeouts();
check('absolute lifetime is enforced', $absolute->expiredReason() === 'absolute');

// ---------------------------------------------------------------------------
// Password hashing (AUTH-02)
// ---------------------------------------------------------------------------
$fastConfig = $config['security'];
$fastConfig['password']['options']['cost'] = 4; // keep the suite fast; policy unchanged
$hasher = new Security($session, $fastConfig, $clock);

$hash = $hasher->hashPassword('CorrectHorse1');
check('password hash differs from the plaintext', $hash !== 'CorrectHorse1');
check('hash format is bcrypt', str_starts_with($hash, '$2y$'), $hash);
check('correct password verifies', $hasher->verifyPassword('CorrectHorse1', $hash));
check('wrong password is rejected', !$hasher->verifyPassword('CorrectHorse2', $hash));
check('empty password is rejected', !$hasher->verifyPassword('', $hash));
check('empty hash is rejected', !$hasher->verifyPassword('CorrectHorse1', ''));
check('current parameters do not need a rehash', !$hasher->needsRehash($hash));

$hasher->verifyDummyPassword('anything'); // must not throw
check('dummy verification completes', true);

// ---------------------------------------------------------------------------
// Tokens and constant-time comparison (AUTH-05)
// ---------------------------------------------------------------------------
$tokenA = $hasher->randomToken();
$tokenB = $hasher->randomToken();
check('random tokens are 64 hex characters', preg_match('/^[0-9a-f]{64}$/', $tokenA) === 1);
check('random tokens are unique', $tokenA !== $tokenB);
check('remember-me selector is 24 characters', strlen($hasher->rememberSelector()) === 24);
check(
    'token hashes verify in constant time',
    $hasher->verifyTokenHash($tokenA, $hasher->hashToken($tokenA))
        && !$hasher->verifyTokenHash($tokenA . 'x', $hasher->hashToken($tokenA))
);
check('equals compares correctly', $hasher->equals('abc', 'abc') && !$hasher->equals('abc', 'abd'));

// ---------------------------------------------------------------------------
// Output encoding and headers (XSS, NFR-07)
// ---------------------------------------------------------------------------
check(
    'escape neutralises HTML metacharacters',
    $security->escape('<script>"x"&\'</script>') === '&lt;script&gt;&quot;x&quot;&amp;&#039;&lt;/script&gt;',
    $security->escape('<script>"x"&\'</script>')
);
check('escape renders null and bool safely', $security->escape(null) === '' && $security->escape(false) === '');
check('json encoding hex-escapes tags', !str_contains($security->json(['x' => '</script>']), '<'));

$headers = $security->securityHeaders(true);
check('nosniff header is present', ($headers['X-Content-Type-Options'] ?? '') === 'nosniff');
check('frame options header is present', ($headers['X-Frame-Options'] ?? '') === 'SAMEORIGIN');
check(
    'CSP carries a nonce and forbids object embedding',
    isset($headers['Content-Security-Policy'])
        && str_contains($headers['Content-Security-Policy'], 'nonce-')
        && str_contains($headers['Content-Security-Policy'], "object-src 'none'")
        && !str_contains($headers['Content-Security-Policy'], '{nonce}'),
    $headers['Content-Security-Policy'] ?? 'missing'
);
check('HSTS is sent over HTTPS', isset($headers['Strict-Transport-Security']));
check('HSTS is omitted over HTTP', !isset($security->securityHeaders(false)['Strict-Transport-Security']));
check('nonce is stable per instance', $security->nonce() === $security->nonce());
check('email masking hides the local part', $security->maskEmail('john.doe@sac.edu.ph') === 'j***@sac.edu.ph');

// ---------------------------------------------------------------------------
// Validation (NFR-07)
// ---------------------------------------------------------------------------
$policy = $config['security']['password'];

$missing = Validator::make([], ['email' => 'required|email'], ['email' => 'Institutional email']);
check('required field fails when missing', $missing->fails());
check(
    'error message uses the field label',
    str_contains((string) $missing->firstError('email'), 'Institutional email'),
    (string) $missing->firstError('email')
);

$email = Validator::make(['email' => 'not-an-email'], ['email' => 'required|email']);
check('invalid email fails', $email->fails());

$domain = Validator::make(
    ['email' => 'user@gmail.com'],
    ['email' => ['required', 'email', 'email_domain:sac.edu.ph']]
);
check('institutional domain rule rejects external domains', $domain->fails());
check(
    'institutional domain rule accepts the configured domain',
    Validator::make(['email' => 'user@sac.edu.ph'], ['email' => ['email', 'email_domain:sac.edu.ph']])->passes()
);

$length = Validator::make(['code' => 'A'], ['code' => 'required|string|min:2|max:5']);
check('string min rule fails on short input', $length->fails());
check(
    'string max rule fails on long input',
    Validator::make(['code' => 'ABCDEF'], ['code' => 'string|max:5'])->fails()
);

$units = Validator::make(['units' => '3'], ['units' => 'required|integer|between:1,6']);
check('integer value passes and is cast', $units->passes() && $units->validated()['units'] === 3);
check(
    'between rule fails outside the range',
    Validator::make(['units' => '9'], ['units' => 'integer|between:1,6'])->fails()
);

$password = Validator::make(['password' => 'short'], ['password' => ['required', 'password']], [], [], $policy);
check('password policy rejects short passwords', $password->fails());
check(
    'password policy explains the length requirement',
    str_contains((string) $password->firstError('password'), (string) $policy['min_length']),
    (string) $password->firstError('password')
);

check(
    'password policy requires mixed case',
    Validator::make(['password' => 'alllowercase1'], ['password' => ['password']], [], [], $policy)->fails()
);
check(
    'password policy requires a number',
    Validator::make(['password' => 'NoNumberHere'], ['password' => ['password']], [], [], $policy)->fails()
);
check(
    'a compliant password passes',
    Validator::make(['password' => 'SyncClass2026'], ['password' => ['password']], [], [], $policy)->passes()
);

$confirmed = Validator::make(
    ['password' => 'SyncClass2026', 'password_confirmation' => 'SyncClass2025'],
    ['password' => 'required|confirmed']
);
check('confirmed rule detects a mismatch', $confirmed->fails());

$times = Validator::make(
    ['start_time' => '09:00', 'end_time' => '10:00'],
    ['start_time' => 'required|time', 'end_time' => 'required|time|after:start_time'],
    ['end_time' => 'End time']
);
check('valid 24-hour times pass', $times->passes());
check(
    'after rule rejects an earlier end time',
    Validator::make(
        ['start_time' => '10:00', 'end_time' => '09:00'],
        ['end_time' => 'time|after:start_time'],
        ['end_time' => 'End time']
    )->fails()
);
check(
    'time rule rejects impossible times',
    Validator::make(['start_time' => '25:00'], ['start_time' => 'time'])->fails()
);
check(
    'in rule rejects values outside the list',
    Validator::make(['status' => 'archived'], ['status' => 'in:draft,pending,approved'])->fails()
);

$nullable = Validator::make(['name' => 'Ana'], ['phone' => 'nullable|string|max:20']);
check('nullable absent field passes', $nullable->passes());
check('nullable absent field is omitted from validated data', !array_key_exists('phone', $nullable->validated()));

$closure = Validator::make(
    ['code' => 'DEPT-1'],
    ['code' => [static fn (mixed $value): bool|string => $value === 'FREE' ? true : 'That code is taken.']]
);
check(
    'closure rule failure returns its message',
    !$closure->passes() && $closure->firstError('code') === 'That code is taken.'
);
check('closure rule can pass', Validator::make(['code' => 'DEPT-1'], ['code' => [static fn (): bool => true]])->passes());

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$failures = array_values(array_filter($checks, static fn (array $check): bool => !$check['passed']));

foreach ($checks as $check) {
    printf("%s %s%s\n", $check['passed'] ? 'PASS' : 'FAIL', $check['label'], $check['passed'] || $check['detail'] === '' ? '' : ' — ' . $check['detail']);
}

printf(
    "\n%d check(s), %d failure(s).\n",
    count($checks),
    count($failures)
);

exit($failures === [] ? 0 : 1);
