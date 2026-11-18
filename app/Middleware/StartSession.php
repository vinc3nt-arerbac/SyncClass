<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Start the hardened session for every request (AUTH-04, AUTH-06).
 *
 * Runs globally, before CSRF and authentication, because both store their
 * state in the session. Session itself owns the policy (cookie flags, save
 * path, idle/absolute timeouts); this middleware only guarantees it happens
 * exactly once per request and exposes the outcome to controllers:
 *
 *   $request->attribute('session_id')       current session id
 *   $request->attribute('session_expired')  'idle' | 'absolute' | 'agent'
 *
 * An expired session is not an error here: Session has already rotated the id
 * and cleared the data, and Phase 7's login controller turns the
 * `session_expired` attribute into a "your session expired" message.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(private readonly Session $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->session->start();

        $request = $request->withAttribute('session_id', $this->session->id());

        $expired = $this->session->expiredReason();

        if ($expired !== null) {
            $request = $request->withAttribute('session_expired', $expired);
        }

        return $next($request);
    }
}
