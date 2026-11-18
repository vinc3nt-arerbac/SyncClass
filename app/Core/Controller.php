<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\Validator;

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
}
