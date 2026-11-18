<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cookie;

/**
 * Outcome of one sign-in attempt (Phase 7).
 *
 * The service never returns a bare boolean: the controller needs to distinguish
 * "wrong password" from "too many attempts" to choose between 422 and 429, and
 * it needs the remember-me cookie to attach when the attempt succeeded.
 *
 * `reason` is machine-readable and safe to branch on; `message()` is what the
 * user sees and is deliberately identical for unknown emails, wrong passwords
 * and deactivated accounts, so the form cannot be used to enumerate accounts
 * (AUTH-03).
 */
final class AuthResult
{
    private function __construct(
        private readonly bool $ok,
        private readonly ?array $user,
        private readonly string $reason,
        private readonly int $retryAfterSeconds = 0,
        private readonly ?Cookie $cookie = null
    ) {
    }

    /**
     * @param array<string, mixed> $user The row returned by UserRepository.
     */
    public static function success(array $user, ?Cookie $cookie = null): self
    {
        return new self(true, $user, 'success', 0, $cookie);
    }

    /**
     * Unknown email, wrong password or inactive account — indistinguishable on
     * purpose.
     */
    public static function invalid(): self
    {
        return new self(false, null, 'invalid');
    }

    /**
     * Too many failures in the window, or the account is temporarily locked.
     */
    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(false, null, 'throttled', max(1, $retryAfterSeconds));
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Seconds the client should wait; 0 when not throttled.
     */
    public function retryAfter(): int
    {
        return $this->retryAfterSeconds;
    }

    public function cookie(): ?Cookie
    {
        return $this->cookie;
    }

    /**
     * User-facing message. Never reveals whether the email exists.
     */
    public function message(): string
    {
        return match ($this->reason) {
            'success' => 'Signed in.',
            'throttled' => $this->retryAfterSeconds > 0
                ? sprintf('Too many failed attempts. Try again in %d minute(s).', max(1, (int) ceil($this->retryAfterSeconds / 60)))
                : 'Too many failed attempts. Try again shortly.',
            default => 'These credentials do not match our records.',
        };
    }

    /**
     * HTTP status for the controller: 429 when throttled, 422 otherwise.
     */
    public function status(): int
    {
        return $this->reason === 'throttled' ? 429 : 422;
    }
}
