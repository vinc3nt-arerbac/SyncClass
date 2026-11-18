<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * An outgoing cookie, formatted exactly once (Phase 7).
 *
 * PHP's setcookie() writes straight to the response headers, which breaks the
 * Phase 6 rule that a request builds a Response and only the front controller
 * sends it. Cookies therefore travel as a `Set-Cookie` header on the Response,
 * and this class is the single place that formats one:
 *
 *     $cookie = new Cookie('syncclass_remember', $value, $expiresAt, '/', '', false, true, 'Lax');
 *     $response = $response->withHeader('Set-Cookie', $cookie->toHeader());
 *
 * Values are restricted to a safe alphabet (hex tokens and separators), so a
 * value can never inject `;` or a newline into the header.
 */
final class Cookie
{
    /** Allowed attribute values for SameSite. */
    private const SAME_SITE_VALUES = ['Lax', 'Strict', 'None'];

    public function __construct(
        private readonly string $name,
        private readonly string $value,
        /** Unix timestamp; 0 means "no Expires/Max-Age" (session cookie). */
        private readonly int $expiresAt = 0,
        private readonly string $path = '/',
        private readonly string $domain = '',
        private readonly bool $secure = false,
        private readonly bool $httpOnly = true,
        private readonly string $sameSite = 'Lax'
    ) {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $this->name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid cookie name "%s".', $this->name));
        }

        if (preg_match('/[^A-Za-z0-9._:-]/', $this->value) === 1) {
            throw new InvalidArgumentException('Cookie values may only contain letters, digits, ".", "_", ":" and "-".');
        }

        if ($this->sameSite !== '' && !in_array($this->sameSite, self::SAME_SITE_VALUES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid SameSite value "%s".', $this->sameSite));
        }
    }

    /**
     * A cookie that tells the browser to delete the value immediately.
     */
    public static function forget(string $name, string $path = '/', bool $secure = false, string $sameSite = 'Lax'): self
    {
        return new self($name, '', time() - 3600, $path, '', $secure, true, $sameSite);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Serialise to a Set-Cookie header value.
     *
     * Max-Age is computed from the current time so the browser and the server
     * agree even when their clocks drift by a few seconds.
     */
    public function toHeader(): string
    {
        $parts = [$this->name . '=' . $this->value];

        if ($this->expiresAt !== 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $this->expiresAt);
            $parts[] = 'Max-Age=' . max(0, $this->expiresAt - time());
        }

        $parts[] = 'Path=' . ($this->path !== '' ? $this->path : '/');

        if ($this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($this->sameSite !== '') {
            $parts[] = 'SameSite=' . $this->sameSite;
        }

        return implode('; ', $parts);
    }
}
