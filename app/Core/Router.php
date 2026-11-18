<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Explicit route table (Phase 6).
 *
 * Routes are registered in routes/web.php; there is no attribute scanning and
 * no convention-over-configuration. Matching is a linear scan (a few dozen
 * routes at most), which keeps the semantics obvious:
 *
 *   - first match wins;
 *   - a path that matches but not the verb is a 405 with an Allow header;
 *   - no path match at all is a 404;
 *   - HEAD is served by the GET route, with the body removed by the Kernel.
 *
 * Named routes exist so redirects and links never contain hand-written URLs:
 * `$router->url('schedules.show', ['id' => 12])`.
 */
final class Router
{
    /** @var array<int, Route> */
    private array $routes = [];

    /**
     * Mount prefix for generated URLs only. Matching always uses the path the
     * Request has already normalised, so the prefix appears exactly once — in
     * url()/to() — and never in a route definition.
     */
    private string $basePath = '';

    /**
     * @param array<int, string> $methods
     * @param class-string|callable|array{0: class-string, 1: string} $handler
     * @param array<int, string> $middleware
     */
    public function add(array $methods, string $path, mixed $handler, array $middleware = [], ?string $description = null): Route
    {
        $route = new Route($methods, $this->normalisePath($path), $handler, $middleware, null, $description);
        $this->routes[] = $route;

        return $route;
    }

    public function get(string $path, mixed $handler, array $middleware = [], ?string $description = null): Route
    {
        return $this->add(['GET'], $path, $handler, $middleware, $description);
    }

    public function post(string $path, mixed $handler, array $middleware = [], ?string $description = null): Route
    {
        return $this->add(['POST'], $path, $handler, $middleware, $description);
    }

    public function put(string $path, mixed $handler, array $middleware = [], ?string $description = null): Route
    {
        return $this->add(['PUT'], $path, $handler, $middleware, $description);
    }

    public function patch(string $path, mixed $handler, array $middleware = [], ?string $description = null): Route
    {
        return $this->add(['PATCH'], $path, $handler, $middleware, $description);
    }

    public function delete(string $path, mixed $handler, array $middleware = [], ?string $description = null): Route
    {
        return $this->add(['DELETE'], $path, $handler, $middleware, $description);
    }

    /**
     * Resolve a request to a route or throw 404/405.
     */
    public function match(Request $request): RouteMatch
    {
        $method = $request->method();
        $path = $request->path();

        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->matchesPath($path);

            if ($parameters === null) {
                continue;
            }

            foreach ($route->methods() as $routeMethod) {
                $allowed[$routeMethod] = true;
            }

            $matched = $route->matches($method, $path);

            if ($matched !== null) {
                return new RouteMatch($route, $matched);
            }
        }

        if ($allowed !== []) {
            if (isset($allowed['GET'])) {
                $allowed['HEAD'] = true;
            }

            ksort($allowed);

            throw HttpException::methodNotAllowed(
                array_keys($allowed),
                sprintf('%s is not supported by this route.', strtoupper($method))
            );
        }

        throw HttpException::notFound(sprintf('No route matches %s %s.', strtoupper($method), $path));
    }

    /**
     * Build a URL for a named route, encoding parameters.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function url(string $name, array $parameters = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->routeName() !== $name) {
                continue;
            }

            $path = preg_replace_callback(
                '/\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}/',
                static function (array $match) use ($parameters, $name): string {
                    $parameter = $match[1];

                    if (array_key_exists($parameter, $parameters)) {
                        return rawurlencode((string) $parameters[$parameter]);
                    }

                    if (($match[2] ?? '') === '?') {
                        return '';
                    }

                    throw new InvalidArgumentException(sprintf('Route "%s" requires the parameter "%s".', $name, $parameter));
                },
                $route->path()
            );

            // An omitted optional placeholder leaves a trailing slash
            // (`/reports/`); generated URLs must not carry it.
            $path = rtrim((string) $path, '/');

            return $this->basePath . ($path === '' ? '/' : $path);
        }

        throw new InvalidArgumentException(sprintf('No route named "%s" is registered.', $name));
    }

    public function hasNamed(string $name): bool
    {
        foreach ($this->routes as $route) {
            if ($route->routeName() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefix an arbitrary application path with the mount prefix.
     */
    public function to(string $path): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function setBasePath(string $basePath): void
    {
        $prefix = '/' . trim(str_replace('\\', '/', $basePath), '/');
        $this->basePath = $prefix === '/' ? '' : $prefix;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * @return array<int, Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Route paths are stored without a trailing slash (except the root) and
     * always start with one, so the same string is a key, a link and a regex
     * source.
     */
    private function normalisePath(string $path): string
    {
        $path = '/' . trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? '/' : $path;
    }
}
