<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Helpers\Validator;
use App\Services\AuthService;
use App\Services\RememberMeService;

/**
 * Sign in and sign out (AUTH-01, AUTH-04, AUTH-05, AUTH-07).
 *
 * The controller is deliberately thin: validate the shape of the input, hand
 * the credentials to AuthService, and translate the result into a redirect or
 * a re-rendered form. It never sees a password hash, never decides whether
 * credentials are correct, and never touches the database directly.
 *
 * Failure is re-rendered (422/429) rather than redirected, so the email the
 * user typed is preserved and no flash-message machinery is needed yet.
 */
final class AuthController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly AuthService $auth,
        private readonly RememberMeService $remember,
        private readonly Session $session
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    /**
     * GET /login — the form.
     */
    public function showLogin(Request $request): Response
    {
        return $this->renderForm($request, '', [], 200);
    }

    /**
     * POST /login — verify credentials and establish the session.
     *
     * CSRF is already enforced globally; this method adds input validation and
     * the throttle/lockout logic that lives in AuthService.
     */
    public function login(Request $request): Response
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'required|email|max:150',
                'password' => 'required|string|max:200',
                'remember' => 'nullable|boolean',
            ],
            ['email' => 'Email address', 'password' => 'Password']
        );

        $email = strtolower(trim((string) $request->post('email', '')));

        if ($validator->fails()) {
            return $this->renderForm($request, $email, $validator->errors(), 422);
        }

        $input = $validator->validated();

        $result = $this->auth->attempt(
            $email,
            (string) $request->post('password', ''),
            (bool) ($input['remember'] ?? false),
            $request->ip(),
            $request->userAgent()
        );

        if (!$result->ok()) {
            $response = $this->renderForm($request, $email, ['email' => $result->message()], $result->status());

            if ($result->retryAfter() > 0) {
                $response = $response->withHeader('Retry-After', (string) $result->retryAfter());
            }

            return $response;
        }

        $destination = $this->intendedUrl();
        $response = Response::redirect($destination, 303);

        if ($result->cookie() !== null) {
            $response = $response->withHeader('Set-Cookie', $result->cookie()->toHeader());
        }

        return $response;
    }

    /**
     * POST /logout — destroy the session and clear the remember-me cookie.
     *
     * POST only, and therefore CSRF-protected: a GET link would let any page
     * sign a user out.
     */
    public function logout(Request $request): Response
    {
        $cookie = $this->auth->logout(
            is_string($request->cookie($this->remember->cookieName())) ? (string) $request->cookie($this->remember->cookieName()) : null,
            $request->ip(),
            $request->userAgent()
        );

        return Response::redirect($this->router->url('login'), 303)
            ->withHeader('Set-Cookie', $cookie->toHeader());
    }

    /**
     * Where to go after signing in.
     *
     * The value was written by the auth middleware from the requested URI, so
     * it is always a local path. The `//` guard blocks protocol-relative URLs
     * in case a future caller ever writes something else into the key.
     */
    private function intendedUrl(): string
    {
        $intended = $this->session->pull(AuthService::INTENDED_KEY, '');
        $intended = is_string($intended) ? trim($intended) : '';

        if ($intended === '' || str_starts_with($intended, '//')) {
            return $this->router->url('home');
        }

        return $this->router->to($intended);
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderForm(Request $request, string $email, array $errors, int $status): Response
    {
        $expired = $request->attribute('session_expired');

        $html = $this->view->render('auth/login', [
            'title' => 'Sign in',
            'email' => $email,
            'errors' => $errors,
            'session_expired' => is_string($expired) ? $expired : null,
        ], 'layouts/auth');

        return Response::html($html, $status);
    }
}
