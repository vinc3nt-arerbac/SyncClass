<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;

/**
 * Verify the CSRF token on every state-changing request (AUTH-09).
 *
 * Safe methods (GET, HEAD, OPTIONS) pass through untouched: they must not
 * change state, so requiring a token would only break normal navigation.
 *
 * Every other method must present the token either as the configured hidden
 * field (`_token`) or as the configured header (`X-CSRF-Token`, which is what
 * the fetch() wrapper sends for JSON and calendar drag-and-drop). Comparison
 * is constant-time and lives in Security::validateCsrf().
 *
 * A failure is a 419, rendered by the Kernel as a JSON envelope for AJAX/API
 * clients and as an error page for form posts.
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($request->isSafeMethod()) {
            return $next($request);
        }

        $token = $request->header($this->security->csrfHeaderName());

        if ($token === null || $token === '') {
            $submitted = $request->input($this->security->csrfFieldName());
            $token = is_scalar($submitted) ? (string) $submitted : null;
        }

        if ($token === null || !$this->security->validateCsrf($token)) {
            throw HttpException::tokenMismatch();
        }

        return $next($request);
    }
}
