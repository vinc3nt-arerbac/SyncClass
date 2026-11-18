<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

/**
 * Require a signed-in user (AUTH-01, AUTH-08).
 *
 * The gate itself is trivial — is there an authenticated user id in the
 * session? — and that is the point: it must be impossible to forget. Every
 * route that is not explicitly public carries the `auth` alias.
 *
 * Phase 6 does not create the identity: there is no login form until Phase 7.
 * The middleware therefore reads (and checks the type of) the session key that
 * Phase 7's AuthService will write, and exposes the id to controllers through
 * `$request->attribute('auth_user_id')`. Nothing here loads the user row: that
 * is the repository's job in Phase 7, and doing it twice would mean two
 * sources of truth for "who is this".
 *
 * Two outcomes on failure, matching the client:
 *   - HTML navigation: 302 to the login route, remembering where the user was
 *     going so Phase 7 can send them back after signing in;
 *   - AJAX/API: 401 JSON, because redirecting a fetch() call to an HTML login
 *     page produces a confusing "unexpected token <" error.
 */
final class Authenticate implements MiddlewareInterface
{
    /**
     * Session key holding the authenticated user id. Public so the Phase 7
     * AuthService and the tests reference the same literal.
     */
    public const SESSION_KEY = '_syncclass_auth_id';

    /**
     * Session key holding the URL to return to after signing in.
     */
    public const INTENDED_KEY = '_syncclass_intended_url';

    public function __construct(
        private readonly Session $session,
        private readonly Router $router
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $id = $this->session->get(self::SESSION_KEY);

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            if ($request->wantsJson()) {
                throw HttpException::unauthorized('Your session has ended. Sign in and try again.');
            }

            $this->session->set(self::INTENDED_KEY, $request->uri());

            return Response::redirect($this->loginUrl(), 302);
        }

        return $next($request->withAttribute('auth_user_id', (int) $id));
    }

    /**
     * Prefer the named login route; fall back to the conventional path so this
     * middleware is safe to enable before Phase 7 registers the route.
     */
    private function loginUrl(): string
    {
        return $this->router->hasNamed('login')
            ? $this->router->url('login')
            : $this->router->to('/login');
    }
}
