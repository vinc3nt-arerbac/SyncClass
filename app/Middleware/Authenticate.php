<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RememberMeService;

/**
 * Require a signed-in user (AUTH-01, AUTH-08).
 *
 * Three things happen here, in this order:
 *
 *  1. **Session identity.** If the session holds a user id, it is resolved to a
 *     real, active account. A deleted or deactivated account cannot keep a
 *     stale session alive: the id is removed and the visitor is signed out.
 *  2. **Remember-me.** With no session, a remember-me cookie is validated and
 *     rotated; a successful rotation establishes the session through the same
 *     AuthService::loginUser() used by the password form, and the replacement
 *     cookie is attached to the response.
 *  3. **Exposure.** The full user row (role and department included) is passed
 *     to controllers and views as `auth_user` / `auth_user_id`.
 *
 * Failure is answered by content type: 401 JSON for fetch()/API clients (a
 * redirect to an HTML page would surface as "unexpected token <" in the
 * client), and a 302 to the login route for navigation, remembering where the
 * user was heading.
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly Router $router,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly RememberMeService $remember
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = $this->auth->userId();
        $rememberLogin = null;

        if ($userId === null) {
            $rememberLogin = $this->remember->consume(
                $request->cookie($this->remember->cookieName()),
                $request->ip(),
                $request->userAgent()
            );

            if ($rememberLogin !== null) {
                $this->auth->loginUser($rememberLogin->userId());
                $userId = $rememberLogin->userId();
            }
        }

        $user = $userId === null ? null : $this->users->findById($userId);

        if ($user === null || !$this->users->isActive($user)) {
            $this->session->remove(AuthService::SESSION_KEY);

            return $this->unauthenticated($request);
        }

        $response = $next($request
            ->withAttribute('auth_user', $user)
            ->withAttribute('auth_user_id', (int) $user['id']));

        if ($rememberLogin !== null) {
            $response = $response->withHeader('Set-Cookie', $rememberLogin->cookie()->toHeader());
        }

        return $response;
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->wantsJson()) {
            throw HttpException::unauthorized('Your session has ended. Sign in and try again.');
        }

        // Preserved for the login controller, which redirects back here after
        // a successful sign-in (open-redirect safe: the value is a local path).
        $this->session->set(AuthService::INTENDED_KEY, $request->uri());

        return Response::redirect($this->router->url('login'), 302);
    }
}
