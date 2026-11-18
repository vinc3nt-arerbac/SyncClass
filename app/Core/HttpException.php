<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * An expected HTTP failure (Phase 6).
 *
 * Anything a client causes — a missing route, a wrong method, a missing CSRF
 * token, failed validation, a missing login — is thrown as an HttpException.
 * The Kernel turns it into an HTML error page or a JSON error envelope,
 * depending on what the request asked for, and the message is safe to show
 * (it is written for the user, never a stack trace).
 *
 * Unexpected Throwables are *not* wrapped: they bubble to the last-resort
 * handler in bootstrap.php, which logs the details and shows a request ID.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers Extra response headers (Allow, Retry-After).
     * @param array<string, string> $errors  Field errors for 422 responses.
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        private readonly array $errors = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message !== '' ? $message : self::reasonPhrase($status), $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function badRequest(string $message = 'The request could not be understood.'): self
    {
        return new self(400, $message);
    }

    public static function unauthorized(string $message = 'Sign in to continue.'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = 'The page you requested does not exist.'): self
    {
        return new self(404, $message);
    }

    /**
     * 405 must advertise what the route does accept (RFC 9110 §15.5.6).
     *
     * @param array<int, string> $allowed
     */
    public static function methodNotAllowed(array $allowed, string $message = ''): self
    {
        return new self(
            405,
            $message !== '' ? $message : 'That HTTP method is not supported by this route.',
            ['Allow' => implode(', ', $allowed)]
        );
    }

    /**
     * 419: Laravel's convention for an expired/absent CSRF token. Keeping the
     * same status makes the behaviour familiar and easy to test.
     */
    public static function tokenMismatch(string $message = 'Your session expired or the form token is missing. Reload the page and try again.'): self
    {
        return new self(419, $message);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function unprocessable(array $errors, string $message = 'Please correct the highlighted fields.'): self
    {
        return new self(422, $message, [], $errors);
    }

    public static function tooManyRequests(int $retryAfterSeconds = 60, string $message = 'Too many requests. Please wait a moment and try again.'): self
    {
        return new self(429, $message, ['Retry-After' => (string) max(1, $retryAfterSeconds)]);
    }

    /**
     * Standard reason phrases for the statuses this class produces.
     */
    public static function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
