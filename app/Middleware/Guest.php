<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\AuthService;

/**
 * Keep signed-in users away from the login form (Phase 7).
 *
 * Without this, an authenticated user who navigates to /login sees a form they
 * do not need and a successful submit would re-authenticate and rotate the CSRF
 * token for no reason. They are sent to the home route instead.
 */
final class Guest implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Router $router
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->check()) {
            return Response::redirect($this->router->url('home'), 302);
        }

        return $next($request);
    }
}
