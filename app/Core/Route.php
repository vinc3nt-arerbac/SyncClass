<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One route definition (Phase 6).
 *
 * Supports static paths and `{name}` placeholders; `{name?}` makes a trailing
 * segment optional. Placeholders match a single non-empty segment and are
 * URL-decoded before reaching the controller.
 *
 *     $router->get('/schedules/{id}', [ScheduleController::class, 'show']);
 *     $router->get('/reports/{year?}', [ReportController::class, 'index']);
 *
 * The compiled regular expression is built once in the constructor: routes are
 * declared at boot, matched per request, and never re-compiled.
 */
final class Route
{
    /** @var array<int, string> */
    private readonly array $methods;

    /** @var array<int, string> */
    private array $middleware;

    /** @var array<int, string> Parameter names in the order they appear. */
    private readonly array $parameterNames;

    private readonly string $pattern;

    /**
     * @param array<int, string>   $methods    Upper-case HTTP verbs.
     * @param class-string|callable|array{0: class-string, 1: string} $handler
     * @param array<int, string>   $middleware Middleware aliases or class names.
     */
    public function __construct(
        array $methods,
        private readonly string $path,
        private readonly mixed $handler,
        array $middleware = [],
        private ?string $name = null,
        private readonly ?string $description = null
    ) {
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods)));
        $this->middleware = array_values($middleware);

        [$this->pattern, $this->parameterNames] = $this->compile($path);
    }

    /**
     * The request method this route accepts, mapped so HEAD is served by the
     * matching GET route (RFC 9110 §9.3.2 requires that).
     */
    public function allows(string $method): bool
    {
        $method = strtoupper($method);

        if ($method === 'HEAD' && in_array('GET', $this->methods, true)) {
            return true;
        }

        return in_array($method, $this->methods, true);
    }

    /**
     * Match a path only, ignoring the verb — used to distinguish 404 from 405.
     *
     * @return array<string, string>|null
     */
    public function matchesPath(string $path): ?array
    {
        $result = preg_match($this->pattern, $path, $matches);

        if ($result !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameterNames as $index => $name) {
            $value = $matches[$index + 1] ?? '';

            if ($value === '') {
                continue;
            }

            $parameters[$name] = rawurldecode((string) $value);
        }

        return $parameters;
    }

    /**
     * @return array<string, string>|null
     */
    public function matches(string $method, string $path): ?array
    {
        return $this->allows($method) ? $this->matchesPath($path) : null;
    }

    /**
     * Fluent naming, used by Router::url() and redirect targets.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Fluent middleware attachment: `->middleware('csrf', 'auth')`.
     */
    public function middleware(string|array ...$middleware): self
    {
        foreach ($middleware as $entry) {
            foreach ((array) $entry as $alias) {
                if (!in_array($alias, $this->middleware, true)) {
                    $this->middleware[] = (string) $alias;
                }
            }
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    /**
     * @return array<int, string>
     */
    public function middlewareAliases(): array
    {
        return $this->middleware;
    }

    public function routeName(): ?string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * How the route is displayed in the Phase 6 overview table.
     */
    public function summary(): string
    {
        $handler = $this->handler;

        if (is_array($handler) && count($handler) === 2) {
            return $handler[0] . '::' . $handler[1];
        }

        return 'Closure';
    }

    /**
     * Compile the declared path into an anchored regular expression and record
     * the parameter names in match order.
     *
     * Literal segments are preg_quote()d, so a dot in a path cannot silently
     * become a wildcard.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function compile(string $path): array
    {
        $pattern = '';
        $offset = 0;
        $names = [];

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}/', $path, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$placeholder, $position]) {
            $literal = preg_quote(substr($path, $offset, $position - $offset), '#');
            $optional = ($matches[2][$index][0] ?? '') === '?';

            if ($optional) {
                // A route is written `/reports/{year?}`, so the separator slash
                // belongs to the optional group. Move it out of the literal so
                // both `/reports` and `/reports/2026` match.
                if (str_ends_with($literal, '/')) {
                    $literal = substr($literal, 0, -1);
                }

                $pattern .= $literal . '(?:/([^/]+))?';
            } else {
                // The separator already sits in the literal (`/schedules/`).
                $pattern .= $literal . '([^/]+)';
            }

            $names[] = $matches[1][$index][0];
            $offset = $position + strlen($placeholder);
        }

        $pattern .= preg_quote(substr($path, $offset), '#');

        return ['#^' . $pattern . '$#u', $names];
    }
}
