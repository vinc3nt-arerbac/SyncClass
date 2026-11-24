<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\Authenticate;
use App\Middleware\Authorize;
use App\Middleware\Guest;
use App\Middleware\StartSession;
use App\Middleware\VerifyCsrfToken;
use App\Repositories\PermissionRepository;
use App\Services\AuthorizationService;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Application kernel (Phase 6).
 *
 * Boots the container and route table once, then turns a Request into a
 * Response:
 *
 *     Request::capture() → Router::match() → Pipeline(session → csrf → auth)
 *         → Controller → View → Response → security headers → send()
 *
 * Failure handling is deliberately split in two:
 *   - HttpException (404, 405, 419, 422, 401…) is *expected* and rendered as
 *     a friendly HTML page or a JSON envelope, with the status the client
 *     caused;
 *   - any other Throwable is *unexpected* and bubbles to the last-resort
 *     handler in bootstrap.php, which logs it and hides the details.
 */
final class Kernel
{
    /**
     * Middleware that runs before every route, in order.
     *
     * The session is first (CSRF tokens and authentication live in it) and
     * CSRF verification is second and *global*: making it opt-in per route
     * would mean a newly added POST could ship unprotected by omission.
     * Safe methods are skipped inside the middleware, so navigation and asset
     * requests are unaffected.
     *
     * @var array<int, class-string<MiddlewareInterface>>
     */
    private array $globalMiddleware = [];

    /**
     * Route-level aliases used in routes/web.php: 'csrf', 'auth', 'session'.
     *
     * @var array<string, class-string<MiddlewareInterface>>
     */
    private array $middlewareAliases = [];

    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
        private readonly Router $router
    ) {
    }

    /**
     * Build the kernel: container bindings, middleware aliases, route table.
     *
     * @param array<string, mixed> $config    Merged configuration from bootstrap.php.
     * @param string|null          $routesFile Overridable for tests.
     */
    /** The kernel that served the current request, for controller helpers. */
    private static ?self $current = null;

    public static function boot(array $config, ?string $routesFile = null): self
    {
        $configObject = new Config($config);
        $container = new Container();
        $router = new Router();

        // URLs are generated with the mount prefix (/SyncClass on XAMPP); the
        // Request already removed it from the incoming path.
        $router->setBasePath((string) parse_url((string) $configObject->get('app.url', ''), PHP_URL_PATH));

        $kernel = new self($configObject, $container, $router);
        $kernel->registerBindings();
        self::$current = $kernel;
        $kernel->registerMiddleware();

        $routesFile ??= BASE_PATH . '/routes/web.php';

        if (!is_file($routesFile)) {
            throw new RuntimeException(sprintf('The route file %s does not exist.', $routesFile));
        }

        $register = require $routesFile;

        if (!is_callable($register)) {
            throw new RuntimeException(sprintf('The route file %s must return a callable that receives the Router and Container.', $routesFile));
        }

        $register($router, $container);

        return $kernel;
    }

    /**
     * Handle one request. Everything the client caused is answered here; the
     * caller only sends the response.
     */
    public function handle(Request $request): Response
    {
        // A fresh controller is built per request, so the container must hand
        // it the current request rather than a cached one.
        $this->container->instance(Request::class, $request);

        // The View singleton outlives a single request in long-running workers
        // and in the test kernel: never let the previous user leak into this
        // render. dispatch() sets the real value once the auth middleware has
        // resolved it.
        $this->container->get(View::class)->share('auth_user', null);

        try {
            $match = $this->router->match($request);
            $route = $match->route();

            $request = $request
                ->withAttribute('route_name', $route->routeName())
                ->withAttribute('route_path', $route->path())
                ->withAttribute('route_summary', $route->summary())
                ->withAttribute('route_middleware', $route->middlewareAliases())
                ->withAttribute('route_rules', $this->authorizationRules($route->middlewareAliases()));

            $middleware = array_merge($this->globalMiddleware, $this->expandMiddleware($route->middlewareAliases()));

            // Short class names for the overview page, so it reports the chain
            // that actually ran rather than a hand-written description.
            $request = $request->withAttribute('middleware_chain', array_map(
                static fn (string $class): string => substr((string) strrchr($class, '\\'), 1) ?: $class,
                $middleware
            ));

            $response = (new Pipeline($this->container))
                ->send($request)
                ->through($middleware)
                ->then(fn (Request $current): Response => $this->dispatch($match, $current));
        } catch (HttpException $exception) {
            $response = $this->renderHttpException($request, $exception);
        }

        $response = $this->decorate($response);

        // RFC 9110 §9.3.2: HEAD returns the GET headers with no body.
        if ($request->isMethod('HEAD')) {
            $response = $response->withBody('');
        }

        return $response;
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * The kernel booted for this process, if any.
     *
     * Used by the base controller for the few services that are not constructor
     * dependencies (authorization, CSRF). Null in a plain CLI process that only
     * loaded bootstrap.php.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Invoke the route handler.
     *
     * Handlers are either a Closure or [ControllerClass::class, 'method'].
     * Route placeholders are passed as positional arguments after the Request,
     * so a route `/schedules/{id}` maps to `show(Request $request, string $id)`.
     */
    private function dispatch(RouteMatch $match, Request $request): Response
    {
        $handler = $match->route()->handler();
        $parameters = array_values($match->parameters());

        // Middleware hands the pipeline a *new* immutable Request carrying the
        // attributes it added (auth_user, route_*, …). Rebind it here so a
        // controller's constructor receives the authenticated request rather
        // than the bare one captured at boot.
        $this->container->instance(Request::class, $request);

        // Layouts render the signed-in user; null for public pages. The route
        // name lets the layout mark the active nav link (Phase 17, NFR-08).
        $view = $this->container->get(View::class);
        $view->share('auth_user', $request->attribute('auth_user'));
        $view->share('current_route', $request->attribute('route_name'));

        if ($handler instanceof Closure) {
            $result = $handler($request, ...$parameters);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = array_values($handler);

            if (!is_string($class) || !is_string($method)) {
                throw new RuntimeException('Controller route handlers must be [ControllerClass::class, "method"].');
            }

            $controller = $this->container->make($class);

            if (!is_callable([$controller, $method])) {
                throw new RuntimeException(sprintf('Route handler %s::%s() is not callable.', $class, $method));
            }

            $result = $controller->{$method}($request, ...$parameters);
        } else {
            throw new RuntimeException('Route handlers must be a Closure or [ControllerClass::class, "method"].');
        }

        if (!$result instanceof Response) {
            throw new RuntimeException(sprintf(
                'The handler for %s returned %s; %s expected.',
                $request->path(),
                get_debug_type($result),
                Response::class
            ));
        }

        return $result;
    }

    /**
     * Turn an HttpException into HTML or JSON, matching what the client asked
     * for. The message is user-facing by construction (see HttpException).
     */
    private function renderHttpException(Request $request, HttpException $exception): Response
    {
        $status = $exception->status();

        if ($request->wantsJson()) {
            $error = [
                'code' => strtoupper(str_replace(' ', '_', HttpException::reasonPhrase($status))),
                'message' => $exception->getMessage(),
            ];

            if ($exception->errors() !== []) {
                $error['errors'] = $exception->errors();
            }

            return Response::json(['ok' => false, 'status' => $status, 'error' => $error], $status, $exception->headers());
        }

        try {
            $html = $this->container->get(View::class)->render('errors/http', [
                'title' => $status . ' — ' . HttpException::reasonPhrase($status),
                'status' => $status,
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 'layouts/app');
        } catch (Throwable) {
            // The error page itself failed (missing template, broken layout):
            // still answer with the correct status and an escaped message.
            $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
                . $status . '</title></head><body><h1>' . $status . '</h1><p>'
                . Html::escape($exception->getMessage()) . '</p></body></html>';
        }

        return Response::html($html, $status, $exception->headers());
    }

    /**
     * Attach the hardening headers from config/security.php and keep private
     * pages out of shared caches.
     */
    private function decorate(Response $response): Response
    {
        if ((bool) $this->config->get('security.headers_enabled', true)) {
            $security = $this->container->get(Security::class);

            foreach ($security->securityHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        if (!$response->hasHeader('Cache-Control') && $response->status() !== 304) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }

    /**
     * Translate route middleware entries into class names.
     *
     * Plain aliases (`auth`, `guest`, …) resolve through the table. The two
     * parameterised aliases — `permission:a,b` and `role:x,y` — resolve to the
     * single Authorize middleware, which reads the parsed rules from the
     * `route_rules` request attribute. Authorize keeps the position the first
     * parameterised alias occupied, so `['auth', 'permission:…']` runs the
     * permission check after authentication. Unknown aliases are passed
     * through so a fully-qualified class name also works.
     *
     * @param array<int, string> $aliases
     * @return array<int, string>
     */
    private function expandMiddleware(array $aliases): array
    {
        $resolved = [];

        foreach ($aliases as $alias) {
            if ($this->isAuthorizationAlias($alias)) {
                if (!in_array(Authorize::class, $resolved, true)) {
                    $resolved[] = Authorize::class;
                }

                continue;
            }

            $resolved[] = $this->middlewareAliases[$alias] ?? $alias;
        }

        return $resolved;
    }

    /**
     * Parse `permission:` / `role:` entries into one rule set.
     *
     * Values are lower-cased and de-duplicated. A missing value is kept as an
     * empty rule set on purpose: the route declared authorisation but named no
     * capability, and Authorize answers that misconfiguration with 403 instead
     * of letting the page through.
     *
     * @param array<int, string> $aliases
     * @return array{permissions: array<int, string>, roles: array<int, string>}
     */
    private function authorizationRules(array $aliases): array
    {
        $permissions = [];
        $roles = [];

        foreach ($aliases as $alias) {
            if (!str_contains($alias, ':')) {
                continue;
            }

            [$name, $values] = explode(':', $alias, 2);

            $parsed = array_values(array_filter(array_map(
                static fn (string $value): string => strtolower(trim($value)),
                explode(',', $values)
            ), static fn (string $value): bool => $value !== ''));

            if ($name === 'permission') {
                $permissions = array_merge($permissions, $parsed);
            } elseif ($name === 'role') {
                $roles = array_merge($roles, $parsed);
            }
        }

        return [
            'permissions' => array_values(array_unique($permissions)),
            'roles' => array_values(array_unique($roles)),
        ];
    }

    private function isAuthorizationAlias(string $alias): bool
    {
        $name = explode(':', $alias, 2)[0];

        return $name === 'permission' || $name === 'role';
    }

    private function registerBindings(): void
    {
        $this->container->instance(self::class, $this);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);

        // One connection, one session, one hasher per request lifecycle.
        $this->container->singleton(Database::class, static fn (Container $container): Database => new Database(
            (array) $container->get(Config::class)->get('database', [])
        ));

        $this->container->singleton(Session::class, static fn (Container $container): Session => new Session(
            (array) $container->get(Config::class)->get('app.session', [])
        ));

        $this->container->singleton(Security::class, static fn (Container $container): Security => new Security(
            $container->get(Session::class),
            (array) $container->get(Config::class)->get('security', [])
        ));

        // One grant cache per request: the middleware, every controller and
        // every `$can()` call in a view must agree, and must not each pay for
        // the role_permissions query.
        $this->container->singleton(AuthorizationService::class, static fn (Container $container): AuthorizationService => new AuthorizationService(
            $container->get(PermissionRepository::class)
        ));

        $assetBase = $this->assetBase();

        $this->container->singleton(View::class, static fn (Container $container): View => new View(
            BASE_PATH . '/app/Views',
            $container->get(Security::class),
            $container->get(Session::class),
            $container->get(Router::class),
            $assetBase,
            $container->get(AuthorizationService::class)
        ));
    }

    private function registerMiddleware(): void
    {
        $this->globalMiddleware = [StartSession::class, VerifyCsrfToken::class];

        // Route-level aliases. 'session' and 'csrf' are already global; the
        // aliases stay for explicit annotation and for routes that need to be
        // unambiguous in review. 'auth' is what makes a route private.
        $this->middlewareAliases = [
            'session' => StartSession::class,
            'csrf' => VerifyCsrfToken::class,
            'auth' => Authenticate::class,
            'guest' => Guest::class,
            // Parameterised aliases (`permission:users.view`, `role:dean`)
            // also resolve to Authorize; listed here for completeness so the
            // alias table documents every name a route may use.
            'authorize' => Authorize::class,
            'permission' => Authorize::class,
            'role' => Authorize::class,
        ];
    }

    /**
     * URL prefix for static assets.
     *
     * When Apache serves public/ directly the prefix is empty; on XAMPP, where
     * the document root is htdocs, assets are reached through the public/
     * directory instead, so links must include it.
     */
    private function assetBase(): string
    {
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $publicRoot = realpath(PUBLIC_PATH);
        $servedFromPublic = $documentRoot !== false && $publicRoot !== false && $documentRoot === $publicRoot;

        return $this->router->basePath() . ($servedFromPublic ? '' : '/public') . '/assets';
    }
}
