<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Raw sign-in attempts and the AUTH-07 throttle window.
 *
 * The table deliberately has no foreign key: a failed attempt for an email that
 * does not exist is exactly the evidence the throttle needs, and tying it to
 * `users` would make that row unattributable (and let an attacker probe for
 * existing addresses by watching insert failures).
 *
 * The window is measured in server time on `attempted_at`, which is written by
 * this repository rather than left to the column default, so a test with an
 * injected clock sees deterministic rows.
 */
final class LoginAttemptRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Append one attempt (successful or not).
     */
    public function record(string $email, string $ip, bool $successful, string $userAgent = ''): int
    {
        return $this->database->insert('login_attempts', [
            'email' => strtolower(trim($email)),
            'ip_address' => $ip !== '' ? $ip : '0.0.0.0',
            'successful' => $successful ? 1 : 0,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Failures for this email + IP since $since (a 'Y-m-d H:i:s' timestamp).
     *
     * Both dimensions are counted: a single IP spraying many accounts and a
     * single account sprayed from many IPs each hit a different limit, but the
     * pair is what a password-spray attempt against one account produces.
     */
    public function countFailuresSince(string $email, string $ip, string $since): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND ip_address = :ip AND successful = 0 AND attempted_at >= :since',
            ['email' => strtolower(trim($email)), 'ip' => $ip, 'since' => $since]
        );
    }

    /**
     * Most recent failure timestamp in the window, used to work out how long
     * the client should wait before trying again.
     */
    public function latestFailureSince(string $email, string $ip, string $since): ?string
    {
        $value = $this->database->scalar(
            'SELECT MAX(attempted_at) FROM login_attempts
             WHERE email = :email AND ip_address = :ip AND successful = 0 AND attempted_at >= :since',
            ['email' => strtolower(trim($email)), 'ip' => $ip, 'since' => $since]
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Drop the failure history for an email + IP after a successful sign-in, so
     * a user who mistyped twice is not locked out later by their own past.
     */
    public function clearFor(string $email, string $ip): int
    {
        return $this->database->delete(
            'login_attempts',
            'email = :email AND ip_address = :ip AND successful = 0',
            ['email' => strtolower(trim($email)), 'ip' => $ip]
        );
    }

    /**
     * Retention: the audit trail keeps aggregates, the raw passwords attempts
     * are only needed for the throttle window (NFR-11).
     */
    public function purgeBefore(string $before): int
    {
        return $this->database->delete('login_attempts', 'attempted_at < :before', ['before' => $before]);
    }
}
