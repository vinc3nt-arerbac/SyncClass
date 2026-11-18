<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * HTTP response value object (Phase 6).
 *
 * A controller returns a Response; the Kernel decorates it with security
 * headers and the front controller sends it. Nothing echoes during a request,
 * which is what allows the error path to replace an already-built body and
 * allows tests to assert on responses without headers being sent.
 *
 * Immutable like Request: withHeader()/withStatus()/withBody() return clones.
 */
final class Response
{
    /**
     * @param array<string, string> $headers Header map, names as written on the wire.
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, array_replace([
            'Content-Type' => 'text/html; charset=UTF-8',
        ], $headers));
    }

    /**
     * JSON envelope. The application contract is documented in
     * docs/ARCHITECTURE.md §10: `{ ok: true, data }` or
     * `{ ok: false, error: { code, message, errors? } }`.
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        try {
            $body = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('The response payload could not be encoded as JSON.', 0, $exception);
        }

        return new self($body, $status, array_replace([
            'Content-Type' => 'application/json; charset=UTF-8',
        ], $headers));
    }

    /**
     * Redirect. 302 is the default; pass 303 after a POST when the follow-up
     * must be a GET (PRG), 301/308 only for permanent moves.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->body, $this->status, $headers);
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers);
    }

    public function withBody(string $body): self
    {
        return new self($body, $this->status, $this->headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && $this->hasHeader('Location');
    }

    /**
     * Emit the response. Called exactly once, from the front controller.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }
}
