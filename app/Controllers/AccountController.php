<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Security;
use App\Core\Session;
use App\Core\View;
use App\Helpers\Validator;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;

/**
 * Account self-service (AUTH-02, AUTH-09).
 *
 * One screen: the signed-in user rotates their own password. This is
 * deliberately *not* part of the master-data CRUD family. There is no scope
 * check because there is no target: the account is always the one the
 * Authenticate middleware resolved, never an id from the request, so a user
 * cannot reach another person's credentials by editing a URL.
 *
 * The order of operations in update() matters:
 *
 *   1. validate the shape (and the password policy from config/security.php);
 *   2. verify the **current** password against the stored hash — a stolen
 *      session alone must not be enough to lock the real owner out;
 *   3. store the new hash (rehash-through-`Security`, never bcrypt directly);
 *   4. regenerate the session id and rotate the CSRF token, so every other
 *      form already open in this browser becomes stale and a captured cookie
 *      from before the change is worthless;
 *   5. write the audit event, then redirect (PRG) so a refresh cannot
 *      resubmit the form.
 *
 * Failures are re-rendered with 422 rather than redirected: the field-level
 * message stays attached to the field that caused it.
 */
final class AccountController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly UserRepository $users,
        private readonly AuditRepository $audit,
        private readonly Security $security,
        private readonly Session $session
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    /**
     * GET /account/password — the form.
     */
    public function edit(Request $request): Response
    {
        return $this->renderForm($request, 200, []);
    }

    /**
     * POST /account/password — verify the current password, store the new one,
     * rotate the session and CSRF token, audit, redirect.
     */
    public function update(Request $request): Response
    {
        $user = $this->actor($request);

        // The `password` rule enforces the policy configured in
        // config/security.php (length, mixed case, number, optional symbol);
        // `confirmed` compares against password_confirmation.
        $validator = Validator::make(
            $request->all(),
            [
                'current_password' => ['required', 'string', 'max:200'],
                'password' => [
                    'required',
                    'string',
                    'password',
                    'confirmed',
                    'different:current_password',
                ],
            ],
            [
                'current_password' => 'Current password',
                'password' => 'New password',
            ],
            [
                'password.different' => 'The new password must be different from the current one.',
            ],
            (array) $this->config->get('security.password', [])
        );

        if ($validator->fails()) {
            return $this->renderForm($request, 422, $validator->errors());
        }

        $input = $validator->validated();
        $current = (string) ($input['current_password'] ?? '');

        // Constant-time verification through Security; an empty or malformed
        // stored hash simply fails.
        if (!$this->security->verifyPassword($current, (string) ($user['password_hash'] ?? ''))) {
            $this->audit->record(
                'auth.password_change_failed',
                (int) $user['id'],
                'user',
                (int) $user['id'],
                'Password change rejected: the current password did not match.',
                [],
                $request->ip(),
                $request->userAgent()
            );

            return $this->renderForm($request, 422, [
                'current_password' => 'The current password does not match our records.',
            ]);
        }

        $this->users->updatePasswordHash(
            (int) $user['id'],
            $this->security->hashPassword((string) $input['password'])
        );

        // AUTH-03/AUTH-09: the old session id and the old CSRF token must not
        // survive a credential change. Data (including the auth id) is kept;
        // only the identifiers rotate.
        $this->session->regenerate(true);
        $this->security->rotateCsrfToken();

        $this->audit->record(
            'auth.password_changed',
            (int) $user['id'],
            'user',
            (int) $user['id'],
            'Password changed by the account owner.',
            [],
            $request->ip(),
            $request->userAgent()
        );

        return Response::redirect(
            $this->router->url('account.password.edit') . '?saved=changed',
            303
        );
    }

    /**
     * Render the form, optionally with field errors and a status code.
     *
     * @param array<string, string> $errors
     */
    private function renderForm(Request $request, int $status, array $errors): Response
    {
        $user = $this->actor($request);
        $policy = (array) $this->config->get('security.password', []);

        $saved = strtolower(trim((string) $request->query('saved', ''))) === 'changed';

        $html = $this->view->render('account/password', [
            'title' => 'Change password',
            'user' => $user,
            'errors' => $errors,
            'saved' => $saved,
            'policy' => [
                'min_length' => (int) ($policy['min_length'] ?? 10),
                'max_length' => (int) ($policy['max_length'] ?? 72),
                'require_symbol' => (bool) ($policy['require_symbol'] ?? false),
            ],
        ]);

        return Response::html($html, $status);
    }
}
