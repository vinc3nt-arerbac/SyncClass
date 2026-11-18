<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable HTTP request (Phase 6).
 *
 * The request is built once per dispatch and never mutated: middleware that
 * needs to enrich it calls withAttribute() and passes the clone down the
 * pipeline. That is what makes the middleware order safe to reason about.
 *
 * Input access is explicit about where a value came from:
 *
 *   $request->query('page')     // ?page=2
 *   $request->post('email')     // form body
 *   $request->json()            // decoded JSON body
 *   $request->input('email')    // body, then JSON, then query
 *
 * No method touches the database and none trusts proxy headers: ip() returns
 * the connecting address, which is what the throttle and the audit log record.
 */
final class Request
{
    /** @var array<string, mixed>|null Decoded JSON body, cached per request. */
    private ?array $jsonBody = null;

    /**
     * @param array<string, mixed>  $query      Query string parameters.
     * @param array<string, mixed>  $body       Parsed form body.
     * @param array<string, string> $headers    Header map, names lower-cased.
     * @param array<string, mixed>  $cookies    Cookie values.
     * @param array<string, mixed>  $files      Uploaded files ($_FILES shape).
     * @param array<string, mixed>  $server     Server parameters.
     * @param array<string, mixed>  $attributes Values added by middleware.
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly array $server = [],
        private readonly string $rawBody = '',
        // Not readonly: withAttribute() enriches the request by cloning it and
        // writing into the clone's attribute map. Every other property stays
        // readonly, so a request can never change under a middleware.
        private array $attributes = []
    ) {
    }

    /**
     * Build the request from PHP's superglobals.
     *
     * $basePath is the URL path the application is mounted on
     * (`/SyncClass` on XAMPP, empty when the document root is public/). It is
     * stripped here once, so routes and redirects are always written without
     * the prefix, exactly as they would be on a domain root.
     */
    public static function capture(string $basePath = ''): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?? '');
        $query = $_GET;

        if ($queryString !== '' && $query === []) {
            parse_str($queryString, $query);
        }

        return new self(
            $method,
            self::stripBasePath($path, $basePath),
            $query,
            $_POST,
            self::captureHeaders($_SERVER),
            $_COOKIE,
            $_FILES,
            $_SERVER,
            self::captureRawBody($method)
        );
    }

    /**
     * Remove a mount prefix, case-insensitively.
     *
     * Windows serves `D:\xampp\htdocs\SyncClass` for `/syncclass`, so the
     * comparison ignores case; on a case-sensitive filesystem the configured
     * prefix is what the browser actually requests.
     */
    public static function stripBasePath(string $path, string $basePath): string
    {
        $normalisedBase = '/' . trim(str_replace('\\', '/', $basePath), '/');
        $path = '/' . trim(str_replace('\\', '/', $path), '/');

        if ($normalisedBase === '/') {
            return $path;
        }

        $pathLower = strtolower($path);
        $baseLower = strtolower($normalisedBase);

        if ($pathLower === $baseLower) {
            return '/';
        }

        if (str_starts_with($pathLower, $baseLower . '/')) {
            $stripped = substr($path, strlen($normalisedBase));

            return $stripped === '' ? '/' : $stripped;
        }

        return $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * GET, HEAD and OPTIONS are "safe": they must not change state, so the
     * CSRF middleware lets them through.
     */
    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Path with the mount prefix removed and no trailing slash (except "/").
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Path plus query string, for "back to where you were" redirects.
     */
    public function uri(): string
    {
        $query = http_build_query($this->query);

        return $this->path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }

    /**
     * Read from the form body, then the JSON body, then the query string.
     *
     * The order matches browser expectations: an explicit POST field wins over
     * a query parameter of the same name.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        $json = $this->json();

        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        return array_key_exists($key, $this->query) ? $this->query[$key] : $default;
    }

    /**
     * All input merged into one array for the Validator.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, $this->json(), $this->body);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Decoded JSON body (empty array when there is none).
     *
     * A malformed body is a client error, so it becomes a 400 rather than a
     * PHP warning. The result is cached: the stream can only be read once.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $this->jsonBody = [];
        $raw = trim($this->rawBody);

        if ($raw === '') {
            return $this->jsonBody;
        }

        $contentType = strtolower($this->header('content-type', ''));
        $looksJson = str_contains($contentType, 'json') || str_starts_with($raw, '{') || str_starts_with($raw, '[');

        if (!$looksJson) {
            return $this->jsonBody;
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::badRequest('The request body is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw HttpException::badRequest('The request body must be a JSON object.');
        }

        $this->jsonBody = $decoded;

        return $this->jsonBody;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * XMLHttpRequest or a fetch() call that asked for JSON.
     */
    public function isAjax(): bool
    {
        return strtolower((string) $this->header('x-requested-with', '')) === 'xmlhttprequest';
    }

    /**
     * Whether the client prefers a JSON response.
     *
     * Used by the Kernel to choose between an HTML error page and a JSON
     * envelope, and by the Authenticate middleware to return 401 instead of a
     * login redirect.
     */
    public function wantsJson(): bool
    {
        if ($this->isAjax()) {
            return true;
        }

        $accept = strtolower((string) $this->header('accept', ''));

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    /**
     * Connecting address. Deliberately does not read X-Forwarded-For: a
     * spoofable header must never drive throttling or audit records.
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($this->header('user-agent', '') ?? '');
    }

    /**
     * Read a middleware-provided value.
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Immutable enrichment: the original request is untouched.
     */
    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function captureHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', strtolower($key));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Read the raw body once. GET/HEAD requests have none, and php://input is
     * unavailable for multipart bodies, so both return ''.
     */
    private static function captureRawBody(string $method): string
    {
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return '';
        }

        $body = file_get_contents('php://input');

        return is_string($body) ? $body : '';
    }
}
