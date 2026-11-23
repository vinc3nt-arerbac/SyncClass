<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\Validator;
use App\Services\AuthorizationService;

/**
 * Base controller (Phase 6).
 *
 * Controllers are thin by design: validate, call a service (from Phase 7
 * onwards), return a response. They may not build SQL, read config files or
 * echo — everything below is a convenience wrapper that keeps the HTTP
 * details in one place.
 *
 * Dependencies arrive by constructor injection, so a controller is trivially
 * constructible in a test.
 */
abstract class Controller
{
    public function __construct(
        protected readonly Request $request,
        protected readonly View $view,
        protected readonly Router $router,
        protected readonly Config $config
    ) {
    }

    /**
     * Render a view inside a layout.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        return Response::html($this->view->render($template, $data, $layout));
    }

    protected function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return Response::json($data, $status, $headers);
    }

    protected function redirect(string $location, int $status = 302): Response
    {
        return Response::redirect($location, $status);
    }

    /**
     * Redirect to a named route.
     *
     * @param array<string, string|int|float> $parameters
     */
    protected function redirectToRoute(string $name, array $parameters = [], int $status = 302): Response
    {
        return Response::redirect($this->router->url($name, $parameters), $status);
    }

    /**
     * Validate the request and return only the validated fields.
     *
     * Throws 422 with per-field errors; the Kernel renders them as JSON or as
     * an error page, so controllers never handle validation failure manually.
     *
     * @param array<string, string|array<int, string|callable>> $rules
     * @param array<string, string> $labels
     * @param array<string, string> $messages
     * @return array<string, mixed>
     */
    protected function validate(array $rules, array $labels = [], array $messages = []): array
    {
        $validator = Validator::make($this->request->all(), $rules, $labels, $messages);

        if ($validator->fails()) {
            throw HttpException::unprocessable($validator->errors());
        }

        return $validator->validated();
    }

    /**
     * Abort with an HTTP status.
     *
     * @return never
     */
    protected function abort(int $status, string $message = ''): void
    {
        throw new HttpException($status, $message);
    }

    /**
     * The authenticated user id, set by the Authenticate middleware.
     */
    protected function authId(): ?int
    {
        $id = $this->request->attribute('auth_user_id');

        return is_int($id) ? $id : null;
    }

    /**
     * The authenticated user row, set by the Authenticate middleware.
     *
     * Controllers must not read the session themselves: the middleware already
     * resolved (and re-validated) the account, and a missing row here means the
     * route was reached without `auth`, which is a programming error worth a
     * 401 rather than a null pointer later.
     *
     * @return array<string, mixed>
     */
    protected function actor(?Request $request = null): array
    {
        $request ??= $this->request;
        $user = $request->attribute('auth_user');

        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
            $this->abort(401, 'Sign in to continue.');
        }

        return $user;
    }

    /**
     * Defence in depth for routes whose middleware already checked the
     * permission: the same matrix is consulted again before the action runs, so
     * a controller invoked outside its route (a test, a future CLI action) still
     * cannot act beyond the actor's grants.
     *
     * @param array<string, mixed> $authUser
     */
    protected function authorize(array $authUser, string ...$permissions): void
    {
        if ($permissions === []) {
            return;
        }

        $authorization = Kernel::current()?->container()->get(AuthorizationService::class);

        if (!$authorization instanceof AuthorizationService) {
            throw new \RuntimeException('Authorization is unavailable: boot the kernel before dispatching a controller.');
        }

        foreach ($permissions as $permission) {
            if ($authorization->can($authUser, $permission)) {
                return;
            }
        }

        $this->abort(403, 'You do not have permission to perform this action.');
    }

    /**
     * The `<meta name="csrf-token">` tag for views that call the JSON API.
     *
     * The token itself lives in the session-bound Security service, so this is
     * only reachable once the session middleware has run.
     */
    protected function csrfMeta(): string
    {
        $security = Kernel::current()?->container()->get(Security::class);

        if (!$security instanceof Security) {
            throw new \RuntimeException('CSRF support is unavailable: boot the kernel before dispatching a controller.');
        }

        return $security->csrfMeta();
    }
}
