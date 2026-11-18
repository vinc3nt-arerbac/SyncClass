<?php

declare(strict_types=1);

/**
 * Core framework test — Phase 6 exit criterion.
 *
 *     php tests/core_framework_test.php
 *
 * Exercises the real classes (no mocks except a controllable request): the
 * container, router, request/response, view engine, database wrapper, the
 * middleware pipeline and the kernel end to end. The database section runs on
 * an in-memory SQLite database because the development environment has no
 * MySQL server; the SQL it emits is the same for MySQL.
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
 */

use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Core\Html;
use App\Core\HttpException;
use App\Core\Kernel;
use App\Core\Pipeline;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Security;
use App\Core\Session;
use App\Core\View;
use App\Middleware\StartSession;
use App\Middleware\VerifyCsrfToken;
use App\Services\AuthService;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var array<string, mixed> $config */
$config = require dirname(__DIR__) . '/bootstrap.php';

/** @var array<int, array{label: string, passed: bool, detail: string}> $checks */
$checks = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks;

    $checks[] = ['label' => $label, 'passed' => $passed, 'detail' => $detail];
}

/**
 * Run a request through a pipeline and return either the Response or the
 * HttpException, so both outcomes can be asserted without try/catch noise.
 *
 * @param array<int, string|object> $middleware
 */
function run_pipeline(Container $container, Request $request, array $middleware, ?callable $destination = null): Response|HttpException
{
    $destination ??= static fn (Request $request): Response => Response::html('destination');

    try {
        return (new Pipeline($container))->send($request)->through($middleware)->then($destination);
    } catch (HttpException $exception) {
        return $exception;
    }
}

// ---------------------------------------------------------------------------
// Test doubles
//
// Declared before the checks run: a class that implements an interface is
// bound at runtime, and this file exits before reaching its own end.
// ---------------------------------------------------------------------------

final class ContainerDependency
{
}

final class ContainerConsumer
{
    public function __construct(public readonly ContainerDependency $dependency)
    {
    }
}

final class ContainerCircularA
{
    public function __construct(ContainerCircularB $b)
    {
    }
}

final class ContainerCircularB
{
    public function __construct(ContainerCircularA $a)
    {
    }
}

final class OrderedMiddleware implements App\Core\MiddlewareInterface
{
    /** @var array<int, string> */
    private array $order;

    /**
     * @param array<int, string> $order Shared log of the execution order.
     */
    public function __construct(private readonly string $name, array &$order)
    {
        $this->order = &$order;
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->order[] = $this->name;

        return $next($request);
    }
}

final class ShortCircuitMiddleware implements App\Core\MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::html('blocked', 403);
    }
}

// ---------------------------------------------------------------------------
// Config (dot notation)
// ---------------------------------------------------------------------------
$configuration = new Config([
    'app' => ['name' => 'SyncClass', 'session' => ['idle_timeout_minutes' => 30, 'secure' => false]],
    'database' => ['driver' => 'mysql'],
]);

check('config reads a nested value', $configuration->get('app.name') === 'SyncClass');
check('config returns the default for a missing path', $configuration->get('app.missing.deep', 'fallback') === 'fallback');
check('config reports existing keys', $configuration->has('app.session.secure') && !$configuration->has('app.session.missing'));
check('config returns a section as an array', $configuration->section('database') === ['driver' => 'mysql']);
check('config section of an absent key is empty', $configuration->section('nope') === []);

$configuration->set('app.session.idle_timeout_minutes', 45);
check('config set writes a nested value', $configuration->get('app.session.idle_timeout_minutes') === 45);

// ---------------------------------------------------------------------------
// Escaping (single implementation)
// ---------------------------------------------------------------------------
check(
    'html escape neutralises metacharacters',
    Html::escape('<script>"x"&\'</script>') === '&lt;script&gt;&quot;x&quot;&amp;&#039;&lt;/script&gt;'
);
check('html escape renders null and bools safely', Html::escape(null) === '' && Html::escape(false) === '' && Html::escape(true) === '1');
check('html escape refuses arrays', Html::escape(['a']) === '');
check('the global e() helper uses the same escaping', e('<b>') === '&lt;b&gt;');
check(
    'html attributes skips null and false',
    Html::attributes(['class' => 'card', 'disabled' => false, 'hidden' => true, 'title' => 'a"b']) === ' class="card" hidden title="a&quot;b"'
);

// ---------------------------------------------------------------------------
// Container (DI + autowiring)
// ---------------------------------------------------------------------------
$container = new Container();
$shared = new \stdClass();
$container->instance('shared.thing', $shared);
check('container returns a registered instance', $container->get('shared.thing') === $shared);

$calls = 0;
$container->singleton('counter', static function () use (&$calls): \stdClass {
    $calls++;

    return new \stdClass();
});
$container->get('counter');
$container->get('counter');
check('singleton is built once', $calls === 1, 'built ' . $calls . ' time(s)');

$container->bind('transient', static fn (): \stdClass => new \stdClass(), false);
check('non-shared binding returns a new object', $container->get('transient') !== $container->get('transient'));

check('container autowires class dependencies', $container->get(ContainerConsumer::class)->dependency instanceof ContainerDependency);

$circularFailed = false;
try {
    $container->get(ContainerCircularA::class);
} catch (RuntimeException $exception) {
    $circularFailed = str_contains($exception->getMessage(), 'Circular');
}
check('container detects circular dependencies', $circularFailed);

$missingFailed = false;
try {
    $container->get('No\\Such\\ClassName');
} catch (RuntimeException) {
    $missingFailed = true;
}
check('container reports unresolvable classes', $missingFailed);

// ---------------------------------------------------------------------------
// Request
// ---------------------------------------------------------------------------
$request = new Request(
    'POST',
    '/schedules/12',
    ['filter' => 'draft'],
    ['title' => 'CS 101', 'filter' => 'body-wins'],
    ['content-type' => 'application/x-www-form-urlencoded', 'accept' => 'text/html']
);

check('request exposes method and path', $request->isMethod('post') && $request->path() === '/schedules/12');
check('request merges input with body precedence', $request->input('filter') === 'body-wins');
check('request falls back to the query string', $request->input('missing', 'q') === 'q');
check('request reports safe methods', $request->isSafeMethod() === false && (new Request('GET', '/'))->isSafeMethod());
check('request builds uri with query string', (new Request('GET', '/schedules', ['page' => 2]))->uri() === '/schedules?page=2');
check('request detects XMLHttpRequest', (new Request('GET', '/', headers: ['x-requested-with' => 'XMLHttpRequest']))->isAjax());
check(
    'request detects a JSON preference',
    (new Request('GET', '/', headers: ['accept' => 'application/json']))->wantsJson()
        && !(new Request('GET', '/', headers: ['accept' => 'text/html']))->wantsJson()
);
check('request parses a JSON body', (new Request('POST', '/', rawBody: '{"room_id":3}'))->json() === ['room_id' => 3]);

$badJson = false;
try {
    (new Request('POST', '/', headers: ['content-type' => 'application/json'], rawBody: '{"room_id"'))->json();
} catch (HttpException $exception) {
    $badJson = $exception->status() === 400;
}
check('malformed JSON is a 400', $badJson);

$enriched = $request->withAttribute('auth_user_id', 7);
check(
    'request attributes are immutable',
    $enriched->attribute('auth_user_id') === 7 && $request->attribute('auth_user_id') === null
);

check('request strips an exact mount prefix', Request::stripBasePath('/SyncClass', '/SyncClass') === '/');
check('request strips a nested mount prefix', Request::stripBasePath('/SyncClass/health', '/SyncClass') === '/health');
check('request strips the prefix case-insensitively', Request::stripBasePath('/syncclass/health', '/SyncClass') === '/health');
check('request leaves unprefixed paths alone', Request::stripBasePath('/health', '/SyncClass') === '/health');
check('request tolerates an empty base path', Request::stripBasePath('/health', '') === '/health');

$originalUri = $_SERVER['REQUEST_URI'] ?? null;
$originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_URI'] = '/SyncClass/health?probe=1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$captured = Request::capture('/SyncClass');
check('capture strips the prefix and keeps the query', $captured->path() === '/health' && $captured->query('probe') === '1');

$_SERVER['REQUEST_URI'] = $originalUri ?? '/';
$_SERVER['REQUEST_METHOD'] = $originalMethod ?? 'GET';

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------
$json = Response::json(['ok' => true, 'data' => ['id' => 1]], 201);
check('json response sets status and content type', $json->status() === 201 && str_starts_with((string) $json->header('content-type'), 'application/json'));
check('json response body decodes', json_decode($json->body(), true) === ['ok' => true, 'data' => ['id' => 1]]);

$redirect = Response::redirect('/SyncClass/login');
check('redirect sets location and status', $redirect->status() === 302 && $redirect->header('Location') === '/SyncClass/login' && $redirect->isRedirect());

$withHeader = $redirect->withHeader('X-Test', 'yes');
check('response headers are immutable', $withHeader->header('x-test') === 'yes' && $redirect->header('X-Test') === null);
check('no content has an empty body', Response::noContent()->status() === 204 && Response::noContent()->body() === '');

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------
$router = new Router();
$router->get('/', 'home-handler')->name('home');
$router->get('/schedules/{id}', 'show-handler')->name('schedules.show');
$router->post('/schedules', 'store-handler')->middleware('csrf')->name('schedules.store');
$router->get('/reports/{year?}', 'reports-handler')->name('reports.index');
$router->setBasePath('/SyncClass');

$exact = $router->match(new Request('GET', '/'));
check('router matches an exact path', $exact->route()->handler() === 'home-handler');

$parameterised = $router->match(new Request('GET', '/schedules/42'));
check('router decodes a route parameter', $parameterised->parameters() === ['id' => '42']);
check('router matches a HEAD request to a GET route', $router->match(new Request('HEAD', '/'))->route()->handler() === 'home-handler');

$notFound = null;
try {
    $router->match(new Request('GET', '/nope'));
} catch (HttpException $exception) {
    $notFound = $exception;
}
check('router throws 404 for an unknown path', $notFound instanceof HttpException && $notFound->status() === 404);

$notAllowed = null;
try {
    $router->match(new Request('DELETE', '/'));
} catch (HttpException $exception) {
    $notAllowed = $exception;
}
$allowHeader = $notAllowed instanceof HttpException ? (string) ($notAllowed->headers()['Allow'] ?? '') : '';
check('router throws 405 and advertises allowed methods', $notAllowed instanceof HttpException
    && $notAllowed->status() === 405
    && $allowHeader === 'GET, HEAD', $allowHeader);

check('router builds a named url with the mount prefix', $router->url('schedules.show', ['id' => 42]) === '/SyncClass/schedules/42');
check('router omits an optional parameter', $router->url('reports.index') === '/SyncClass/reports');
check('router knows its named routes', $router->hasNamed('home') && !$router->hasNamed('missing'));

$missingParameter = false;
try {
    $router->url('schedules.show');
} catch (InvalidArgumentException) {
    $missingParameter = true;
}
check('router refuses to build a url without a required parameter', $missingParameter);
check('router matches an optional parameter route without the segment', $router->match(new Request('GET', '/reports'))->parameters() === []);
check('router prefixes arbitrary paths', $router->to('/login') === '/SyncClass/login');

// ---------------------------------------------------------------------------
// Database wrapper (in-memory SQLite)
// ---------------------------------------------------------------------------
$database = new Database(['driver' => 'sqlite', 'database' => ':memory:']);
$database->pdo()->exec(
    'CREATE TABLE rooms (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, capacity INTEGER NOT NULL)'
);

$roomId = $database->insert('rooms', ['name' => 'Room 204', 'capacity' => 40]);
check('database insert returns the new id', $roomId === 1, 'id=' . $roomId);

$database->insert('rooms', ['name' => 'Lab 3', 'capacity' => 30]);
$rows = $database->select('SELECT name, capacity FROM rooms ORDER BY id');
check('database select returns all rows', count($rows) === 2 && $rows[0]['name'] === 'Room 204');
check('database selectOne returns null when nothing matches', $database->selectOne('SELECT * FROM rooms WHERE id = :id', ['id' => 999]) === null);
check('database scalar returns a single value', (int) $database->scalar('SELECT COUNT(*) FROM rooms') === 2);

check('database update reports affected rows', $database->update('rooms', ['capacity' => 45], 'id = :id', ['id' => $roomId]) === 1);
check('database update persisted the value', (int) $database->selectOne('SELECT capacity FROM rooms WHERE id = :id', ['id' => $roomId])['capacity'] === 45);
check('database delete reports affected rows', $database->delete('rooms', 'id = :id', ['id' => 2]) === 1);

$database->transaction(static function (Database $db): void {
    $db->insert('rooms', ['name' => 'Committed', 'capacity' => 20]);
});
check('transaction commits on success', (int) $database->scalar('SELECT COUNT(*) FROM rooms') === 2);

$rolledBack = false;
try {
    $database->transaction(static function (Database $db): void {
        $db->insert('rooms', ['name' => 'Ghost', 'capacity' => 20]);
        throw new RuntimeException('forced rollback');
    });
} catch (RuntimeException) {
    $rolledBack = true;
}
check('transaction rolls back on an exception', $rolledBack && (int) $database->scalar('SELECT COUNT(*) FROM rooms') === 2);
check('transaction leaves no open transaction', $database->pdo()->inTransaction() === false);

$injectionRefused = false;
try {
    $database->insert('rooms; DROP TABLE rooms', ['name' => 'x', 'capacity' => 1]);
} catch (InvalidArgumentException) {
    $injectionRefused = true;
}
check('database refuses an invalid table identifier', $injectionRefused && (int) $database->scalar('SELECT COUNT(*) FROM rooms') === 2);
check('database refuses an empty insert', (static function () use ($database): bool {
    try {
        $database->insert('rooms', []);
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
})());
check('database refuses an update without a WHERE clause', (static function () use ($database): bool {
    try {
        $database->update('rooms', ['name' => 'x'], '');
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
})());
check('database refuses an invalid column identifier', (static function () use ($database): bool {
    try {
        $database->insert('rooms', ['name` = 1 --' => 'x']);
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
})());

// ---------------------------------------------------------------------------
// Middleware pipeline
// ---------------------------------------------------------------------------
$session = new Session($config['app']['session']);
$security = new Security($session, $config['security']);
$middlewareRouter = new Router();
$middlewareRouter->setBasePath('/SyncClass');

$middlewareContainer = new Container();
$middlewareContainer->instance(Session::class, $session);
$middlewareContainer->instance(Security::class, $security);
$middlewareContainer->instance(Router::class, $middlewareRouter);

$order = [];
$ordered = (new Pipeline($middlewareContainer))
    ->send(new Request('GET', '/'))
    ->through([
        new OrderedMiddleware('first', $order),
        new OrderedMiddleware('second', $order),
    ])
    ->then(static function (Request $request) use (&$order): Response {
        $order[] = 'destination';

        return Response::html('done');
    });
check('pipeline runs middleware in order', $order === ['first', 'second', 'destination'] && $ordered->body() === 'done');

$shortCircuit = (new Pipeline($middlewareContainer))
    ->send(new Request('GET', '/'))
    ->through([new ShortCircuitMiddleware()])
    ->then(static fn (Request $request): Response => Response::html('should not run'));
check('middleware can short-circuit the pipeline', $shortCircuit->status() === 403);

$sessionStarted = run_pipeline(
    $middlewareContainer,
    new Request('GET', '/'),
    [StartSession::class],
    static fn (Request $request): Response => Response::json(['session_id' => $request->attribute('session_id')])
);
check('StartSession starts the session and exposes its id', $sessionStarted instanceof Response
    && $session->isStarted()
    && str_starts_with((string) $sessionStarted->body(), '{"session_id":"'));

$csrfToken = $security->csrfToken();

$csrfRejected = run_pipeline($middlewareContainer, new Request('POST', '/pipeline/echo', body: ['message' => 'hi']), [VerifyCsrfToken::class]);
check('CSRF middleware rejects a POST without a token', $csrfRejected instanceof HttpException && $csrfRejected->status() === 419);

$csrfWrong = run_pipeline($middlewareContainer, new Request('POST', '/pipeline/echo', body: ['message' => 'hi', '_token' => str_repeat('0', 64)]), [VerifyCsrfToken::class]);
check('CSRF middleware rejects an incorrect token', $csrfWrong instanceof HttpException && $csrfWrong->status() === 419);

$csrfHeader = run_pipeline($middlewareContainer, new Request('POST', '/pipeline/echo', headers: ['x-csrf-token' => $csrfToken]), [VerifyCsrfToken::class]);
check('CSRF middleware accepts the header token', $csrfHeader instanceof Response && $csrfHeader->body() === 'destination');

$csrfField = run_pipeline($middlewareContainer, new Request('POST', '/pipeline/echo', body: ['_token' => $csrfToken]), [VerifyCsrfToken::class]);
check('CSRF middleware accepts the form token', $csrfField instanceof Response);

$csrfSafe = run_pipeline($middlewareContainer, new Request('GET', '/pipeline/echo'), [VerifyCsrfToken::class]);
check('CSRF middleware lets safe methods through', $csrfSafe instanceof Response);

// Authenticate is exercised end to end in tests/auth_test.php, where a real
// user row exists: 401 JSON for API clients, 302 to /login for browsers, the
// intended-URL round trip, and remember-me restoration. It cannot be tested
// here without a users table, and a mocked repository would prove nothing.

// ---------------------------------------------------------------------------
// Kernel end to end (real routes, real views)
// ---------------------------------------------------------------------------
// Phase 7 made the shell private, so this kernel runs on an in-memory SQLite
// database with one real user row (roles + users are all Authenticate needs).
$kernelConfig = $config;
$kernelConfig['database'] = ['driver' => 'sqlite', 'database' => ':memory:'];
$kernelConfig['security']['password']['options']['cost'] = 4;
$kernelConfig['app']['session']['name'] = 'syncclass_core_test';

$kernel = Kernel::boot($kernelConfig);
$kernelView = $kernel->container()->get(View::class);

require __DIR__ . '/support/auth_schema.php';
$kernelDatabase = $kernel->container()->get(Database::class);
auth_schema($kernelDatabase);
$kernelUserId = auth_create_user($kernelDatabase, [
    'email' => 'core.tester@sac.edu.ph',
    'first_name' => 'Core',
    'last_name' => 'Tester',
]);

$anonymous = $kernel->handle(new Request('GET', '/'));
check('an anonymous request is redirected to the login route', $anonymous->status() === 302
    && $anonymous->header('Location') === '/SyncClass/login', (string) $anonymous->header('Location'));

$kernel->handle(new Request('GET', '/login')); // starts the session
$kernel->container()->get(Session::class)->set(AuthService::SESSION_KEY, $kernelUserId);

$home = $kernel->handle(new Request('GET', '/'));
check('GET / is served by the routed home page', $home->status() === 200 && str_contains($home->body(), 'Welcome back, Core'));
check('home page renders the route table', str_contains($home->body(), 'pipeline.echo') && str_contains($home->body(), '/health'));
check('rendered pages carry security headers', $home->header('X-Content-Type-Options') === 'nosniff'
    && str_contains((string) $home->header('Content-Security-Policy'), 'nonce-')
    && str_contains((string) $home->header('Content-Security-Policy'), "frame-ancestors 'self'"));
check('dynamic responses are not cached', $home->header('Cache-Control') === 'no-store');
check('the layout links the stylesheet through the asset helper', str_contains($home->body(), '/assets/css/app.css'));

$health = $kernel->handle(new Request('GET', '/health'));
$healthPayload = json_decode($health->body(), true);
check('GET /health returns the JSON health envelope', $health->status() === 200 && $healthPayload['status'] === 'ok');
check('health check reports every environment probe', count($healthPayload['checks'] ?? []) === 6);

$missing = $kernel->handle(new Request('GET', '/does-not-exist'));
check('an unknown path renders the 404 page', $missing->status() === 404 && str_contains($missing->body(), 'HTTP 404'));

$missingJson = $kernel->handle(new Request('GET', '/does-not-exist', headers: ['accept' => 'application/json']));
$missingPayload = json_decode($missingJson->body(), true);
check('an unknown API path returns the JSON error envelope', $missingJson->status() === 404
    && $missingPayload['ok'] === false
    && $missingPayload['error']['code'] === 'NOT_FOUND');

$wrongMethod = $kernel->handle(new Request('DELETE', '/health'));
check('a wrong verb is a 405 with an Allow header', $wrongMethod->status() === 405 && $wrongMethod->header('Allow') === 'GET, HEAD');

$head = $kernel->handle(new Request('HEAD', '/'));
check('HEAD returns the GET headers without a body', $head->status() === 200 && $head->body() === '');

$csrfMissing = $kernel->handle(new Request('POST', '/pipeline/echo', body: ['message' => 'hi'], headers: ['accept' => 'application/json']));
check('the kernel turns a missing CSRF token into 419 JSON', $csrfMissing->status() === 419 && json_decode($csrfMissing->body(), true)['error']['code'] === 'PAGE_EXPIRED');

$kernelToken = $kernel->container()->get(Security::class)->csrfToken();

$echo = $kernel->handle(new Request('POST', '/pipeline/echo', body: ['message' => 'Pipeline online'], headers: [
    'accept' => 'application/json',
    'content-type' => 'application/x-www-form-urlencoded',
    'x-csrf-token' => $kernelToken,
]));
$echoPayload = json_decode($echo->body(), true);
check('a CSRF-protected POST reaches the controller', $echo->status() === 200
    && $echoPayload['ok'] === true
    && $echoPayload['csrf'] === 'verified'
    && $echoPayload['message'] === 'Pipeline online', $echo->body());

$echoInvalid = $kernel->handle(new Request('POST', '/pipeline/echo', body: ['message' => ''], headers: [
    'accept' => 'application/json',
    'x-csrf-token' => $kernelToken,
]));
check('validation failures return 422 with field errors', $echoInvalid->status() === 422
    && isset(json_decode($echoInvalid->body(), true)['error']['errors']['message']));

// Drop the authenticated id so this assertion sees an anonymous visitor.
$kernel->container()->get(Session::class)->remove(AuthService::SESSION_KEY);

$guarded = $kernel->handle(new Request('GET', '/pipeline/guarded', headers: ['accept' => 'application/json']));
check('the guarded route is protected by the auth middleware', $guarded->status() === 401, $guarded->body());

$kernel->container()->get(Session::class)->set(AuthService::SESSION_KEY, $kernelUserId);
$guardedAllowed = $kernel->handle(new Request('GET', '/pipeline/guarded'));
check('the guarded route reaches the controller once authenticated', $guardedAllowed->status() === 200
    && (int) json_decode($guardedAllowed->body(), true)['auth_user_id'] === $kernelUserId, $guardedAllowed->body());

$escaped = $kernelView->render('errors/http', [
    'title' => 'probe',
    'status' => 404,
    'message' => '<script>alert(1)</script>',
    'errors' => [],
], 'layouts/app');
check('views escape dynamic values', str_contains($escaped, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($escaped, '<script>alert(1)</script>'));

$missingView = false;
try {
    $kernelView->render('nope/missing', []);
} catch (RuntimeException) {
    $missingView = true;
}
check('the view engine reports a missing template', $missingView);

// A second kernel with its own route file proves that placeholders are passed
// to the handler positionally, through the real dispatch path.
$temporaryRoutes = dirname(__DIR__) . '/storage/cache/routes-phase6-test.php';
file_put_contents($temporaryRoutes, <<<'PHP'
<?php

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    $router->get('/schedules/{id}', static fn (Request $request, string $id): Response => Response::html('schedule ' . $id));
    $router->post('/probe', static fn (Request $request): Response => Response::html('accepted'));
};
PHP);

$parameterKernel = Kernel::boot([
    'app' => ['url' => 'http://localhost', 'debug' => true, 'session' => ['name' => 'syncclass_test_session']],
    'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
    'security' => ['headers_enabled' => false],
], $temporaryRoutes);

$parameterResponse = $parameterKernel->handle(new Request('GET', '/schedules/9'));
check('route parameters reach the handler positionally', $parameterResponse->status() === 200 && $parameterResponse->body() === 'schedule 9', $parameterResponse->body());

$undeclaredPost = $parameterKernel->handle(new Request('POST', '/probe', body: ['x' => '1']));
check('CSRF is enforced globally on a route that declares no middleware', $undeclaredPost->status() === 419, (string) $undeclaredPost->status());

$probeToken = $parameterKernel->container()->get(Security::class)->csrfToken();
$declaredPost = $parameterKernel->handle(new Request('POST', '/probe', headers: ['x-csrf-token' => $probeToken]));
check('a globally protected route accepts a valid token', $declaredPost->status() === 200 && $declaredPost->body() === 'accepted');

@unlink($temporaryRoutes);

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$failures = array_values(array_filter($checks, static fn (array $check): bool => !$check['passed']));

foreach ($checks as $check) {
    printf(
        "%s %s%s\n",
        $check['passed'] ? 'PASS' : 'FAIL',
        $check['label'],
        $check['passed'] || $check['detail'] === '' ? '' : ' — ' . $check['detail']
    );
}

printf("\n%d check(s), %d failure(s).\n", count($checks), count($failures));

exit($failures === [] ? 0 : 1);


