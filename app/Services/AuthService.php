<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Cookie;
use App\Core\Security;
use App\Core\Session;
use App\Repositories\AuditRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use Closure;

/**
 * Authentication (AUTH-01…AUTH-07).
 *
 * The single place where "may this visitor become this user?" is answered.
 * Controllers call attempt()/logout(); middleware calls check()/currentUser().
 *
 * Order of checks in attempt() is deliberate:
 *
 *   1. throttle (email + IP failures in the window)  → 429, no user lookup;
 *   2. account lookup                                 → dummy hash when absent,
 *                                                       so timing does not
 *                                                       reveal existence;
 *   3. active flag and lockout                        → indistinguishable 422;
 *   4. password verification                          → counters on failure;
 *   5. success: rehash if needed, reset counters, regenerate the session,
 *      rotate the CSRF token, audit, optionally issue a remember token.
 *
 * Session regeneration and CSRF rotation happen inside loginUser(), which is
 * also used by the remember-me path — so every route into an authenticated
 * session gets the same fixation protection.
 */
final class AuthService
{
    /**
     * Session key holding the authenticated user id. Public because the
     * middleware, the logout controller and the tests all read the same
     * literal.
     */
    public const SESSION_KEY = '_syncclass_auth_id';

    /**
     * Session key holding where to send the user after signing in.
     */
    public const INTENDED_KEY = '_syncclass_intended_url';

    private readonly Closure $clock;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly RememberMeService $remember,
        private readonly AuditRepository $audit,
        private readonly Security $security,
        private readonly Session $session,
        private readonly Config $config,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Attempt a password sign-in.
     */
    public function attempt(string $email, string $password, bool $remember, string $ip, string $userAgent): AuthResult
    {
        $email = strtolower(trim($email));
        $now = ($this->clock)();

        $maxAttempts = max(1, (int) $this->config->get('security.throttle.max_attempts', 5));
        $windowMinutes = max(1, (int) $this->config->get('security.throttle.window_minutes', 15));
        $lockoutMinutes = max(1, (int) $this->config->get('security.throttle.lockout_minutes', 15));
        $since = date('Y-m-d H:i:s', $now - $windowMinutes * 60);

        // 1. Throttle, before any account lookup.
        $failures = $this->attempts->countFailuresSince($email, $ip, $since);

        if ($failures >= $maxAttempts) {
            $this->audit->record(
                'auth.throttled',
                $this->userId(),
                null,
                null,
                'Sign-in blocked by the attempt throttle.',
                ['email' => $email, 'failures' => $failures],
                $ip,
                $userAgent
            );

            return AuthResult::throttled($this->retryAfter($email, $ip, $since, $now, $windowMinutes));
        }

        $user = $this->users->findByEmail($email);

        // 2. Unknown email: spend the same time as a real verification.
        if ($user === null) {
            $this->security->verifyDummyPassword($password);
            $this->attempts->record($email, $ip, false, $userAgent);
            $this->audit->record('auth.login_failed', null, null, null, 'Unknown email.', ['email' => $email], $ip, $userAgent);

            return AuthResult::invalid();
        }

        $userId = (int) $user['id'];

        // 3. Deactivated account (reported exactly like a wrong password).
        if (!$this->users->isActive($user)) {
            $this->attempts->record($email, $ip, false, $userAgent);
            $this->audit->record('auth.login_failed', $userId, null, null, 'Inactive account.', ['email' => $email], $ip, $userAgent);

            return AuthResult::invalid();
        }

        // 3b. Account lockout set by earlier failures on this account.
        $lockedUntil = $user['locked_until'] !== null ? strtotime((string) $user['locked_until']) : false;

        if ($lockedUntil !== false && $lockedUntil > $now) {
            $this->attempts->record($email, $ip, false, $userAgent);
            $this->audit->record('auth.locked', $userId, null, null, 'Account temporarily locked.', ['email' => $email], $ip, $userAgent);

            return AuthResult::throttled($lockedUntil - $now);
        }

        // 4. Password.
        if (!$this->security->verifyPassword($password, (string) $user['password_hash'])) {
            $this->users->recordFailedLogin($userId, $maxAttempts, $lockoutMinutes);
            $this->attempts->record($email, $ip, false, $userAgent);
            $this->audit->record('auth.login_failed', $userId, null, null, 'Wrong password.', ['email' => $email], $ip, $userAgent);

            return AuthResult::invalid();
        }

        // 5. Success. Upgrade a hash produced with weaker parameters (AUTH-02).
        if ((bool) $this->config->get('security.password.rehash_on_login', true)
            && $this->security->needsRehash((string) $user['password_hash'])
        ) {
            $this->users->updatePasswordHash($userId, $this->security->hashPassword($password));
        }

        $this->users->recordSuccessfulLogin($userId);
        $this->attempts->clearFor($email, $ip);
        $this->attempts->record($email, $ip, true, $userAgent);
        $this->loginUser($userId);

        $this->audit->record(
            'auth.login',
            $userId,
            null,
            null,
            'Signed in with a password.',
            ['remember' => $remember],
            $ip,
            $userAgent
        );

        return AuthResult::success($user, $remember ? $this->remember->issue($userId, $ip, $userAgent) : null);
    }

    /**
     * Establish the authenticated session for a user.
     *
     * Used after a password sign-in and after a remember-me sign-in, so both
     * paths get session-ID regeneration (AUTH-03) and CSRF rotation (AUTH-09).
     */
    public function loginUser(int $userId): void
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        $this->session->regenerate(true);
        $this->session->set(self::SESSION_KEY, $userId);

        // The token captured before authentication must not survive it.
        $this->security->rotateCsrfToken();
    }

    /**
     * Sign out: forget the token, audit, destroy the session, clear the cookie.
     *
     * The returned cookie must be attached to the redirect response.
     */
    public function logout(?string $rememberCookie, string $ip, string $userAgent): Cookie
    {
        $userId = $this->userId();

        $this->remember->forgetCookieValue($rememberCookie);

        $this->audit->record('auth.logout', $userId, null, null, 'Signed out.', [], $ip, $userAgent);

        $this->session->destroy();

        return $this->remember->forget();
    }

    /**
     * The authenticated user id, or null.
     */
    public function userId(): ?int
    {
        $value = $this->session->get(self::SESSION_KEY);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * True when a session id resolves to an active account.
     */
    public function check(): bool
    {
        $user = $this->currentUser();

        return $user !== null && $this->users->isActive($user);
    }

    /**
     * The authenticated user row (role and department included), or null.
     *
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $id = $this->userId();

        return $id === null ? null : $this->users->findById($id);
    }

    /**
     * Seconds until the throttle window frees up again.
     */
    private function retryAfter(string $email, string $ip, string $since, int $now, int $windowMinutes): int
    {
        $latest = $this->attempts->latestFailureSince($email, $ip, $since);

        if ($latest === null) {
            return $windowMinutes * 60;
        }

        $timestamp = strtotime($latest);

        if ($timestamp === false) {
            return $windowMinutes * 60;
        }

        return max(1, min($windowMinutes * 60, ($timestamp + $windowMinutes * 60) - $now));
    }
}
