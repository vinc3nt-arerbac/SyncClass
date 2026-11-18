<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cookie;

/**
 * A successful automatic sign-in from a remember-me cookie (Phase 7).
 *
 * Because the token is rotated on every use, the middleware must replace the
 * browser's cookie in the same response. This object carries both halves:
 * which user was recognised, and the cookie to send back.
 */
final class RememberLogin
{
    public function __construct(
        private readonly int $userId,
        private readonly Cookie $cookie
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function cookie(): Cookie
    {
        return $this->cookie;
    }
}
