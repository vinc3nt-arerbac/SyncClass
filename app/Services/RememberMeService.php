<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Cookie;
use App\Core\Security;
use App\Repositories\RememberTokenRepository;
use Closure;

/**
 * Remember-me tokens (AUTH-05).
 *
 * Cookie format: `selector:validator`.
 *   - the selector is stored in clear and indexed, so lookup is one query;
 *   - the validator is stored only as a SHA-256 hash;
 *   - both are single-use: consuming a cookie deletes the row and issues a new
 *     pair, so a stolen cookie is only useful until the real browser returns.
 *
 * If a selector is found but the validator does not match, the token is treated
 * as stolen: every remembered browser for that user is invalidated rather than
 * just the one row.
 *
 * Cookie policy: HttpOnly (JavaScript never needs it), SameSite=Lax (survives
 * normal top-level navigation, not cross-site POSTs), Secure when the app is
 * served over HTTPS, and an explicit lifetime matching
 * security.password.remember_token_days.
 */
final class RememberMeService
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RememberTokenRepository $tokens,
        private readonly Security $security,
        private readonly Config $config,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function cookieName(): string
    {
        return (string) $this->config->get('security.remember.cookie_name', 'syncclass_remember');
    }

    public function cookiePath(): string
    {
        return (string) $this->config->get('security.remember.cookie_path', '/');
    }

    /**
     * Lifetime in days, shared with the token row's expiry.
     */
    public function lifetimeDays(): int
    {
        return max(1, (int) $this->config->get('security.password.remember_token_days', 30));
    }

    /**
     * Issue a token for a user and return the cookie to send.
     */
    public function issue(int $userId, string $ip, string $userAgent): Cookie
    {
        $selector = $this->security->rememberSelector();
        $validator = $this->security->randomToken(32);
        $expiresAt = ($this->clock)() + $this->lifetimeDays() * 86400;

        $this->tokens->create(
            $userId,
            $selector,
            $this->security->hashToken($validator),
            date('Y-m-d H:i:s', $expiresAt),
            $ip,
            $userAgent
        );

        return $this->cookie($selector . ':' . $validator, $expiresAt);
    }

    /**
     * Validate a presented cookie and rotate it.
     *
     * Returns null for a missing, malformed, unknown, expired or mismatched
     * token — the caller treats all of those identically (no sign-in).
     */
    public function consume(?string $value, string $ip, string $userAgent): ?RememberLogin
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = explode(':', $value, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$selector, $validator] = $parts;

        // Shape check before touching the database: rejects junk cheaply.
        if (preg_match('/^[a-f0-9]{24}$/', $selector) !== 1 || preg_match('/^[a-f0-9]{64}$/', $validator) !== 1) {
            return null;
        }

        $row = $this->tokens->findBySelector($selector);

        if ($row === null) {
            return null;
        }

        $expiresAt = strtotime((string) $row['expires_at']);

        if ($expiresAt === false || $expiresAt <= ($this->clock)()) {
            $this->tokens->deleteBySelector($selector);

            return null;
        }

        if (!$this->security->verifyTokenHash($validator, (string) $row['validator_hash'])) {
            // The selector exists but the secret does not match: assume the
            // cookie was copied and sign every remembered browser out.
            $this->tokens->deleteForUser((int) $row['user_id']);

            return null;
        }

        $userId = (int) $row['user_id'];

        // Single-use: replace the pair before it can be replayed.
        $this->tokens->deleteBySelector($selector);

        return new RememberLogin($userId, $this->issue($userId, $ip, $userAgent));
    }

    /**
     * Delete the token referenced by a cookie value (sign-out).
     */
    public function forgetCookieValue(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $selector = explode(':', $value, 2)[0];

        if (preg_match('/^[a-f0-9]{24}$/', $selector) === 1) {
            $this->tokens->deleteBySelector($selector);
        }
    }

    /**
     * The expired cookie that clears a remembered browser.
     */
    public function forget(): Cookie
    {
        return Cookie::forget($this->cookieName(), $this->cookiePath(), $this->isSecure());
    }

    /**
     * Build the cookie for a fresh token.
     */
    private function cookie(string $value, int $expiresAt): Cookie
    {
        return new Cookie(
            $this->cookieName(),
            $value,
            $expiresAt,
            $this->cookiePath(),
            '',
            $this->isSecure(),
            true,
            'Lax'
        );
    }

    private function isSecure(): bool
    {
        return (bool) $this->config->get('app.session.secure', false);
    }
}
