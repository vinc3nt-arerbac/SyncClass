<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;

/**
 * The application's landing page (Phase 6).
 *
 * Until the Phase 16 dashboard exists, the landing page's job is to prove and
 * document the request pipeline: which route matched, which middleware ran,
 * which controller answered, and what the session/CSRF state is. It reads
 * everything from the request attributes the Kernel set — it does not query
 * the database, so the page still renders when MySQL is not running.
 */
final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $routes = array_map(
            static fn (Route $route): array => [
                'methods' => implode('|', $route->methods()),
                'path' => $route->path(),
                'name' => $route->routeName() ?? '—',
                'handler' => $route->summary(),
                'middleware' => $route->middlewareAliases() === [] ? '—' : implode(', ', $route->middlewareAliases()),
            ],
            $this->router->routes()
        );

        $bootMs = defined('SYNC_CLASS_BOOT_AT')
            ? round((microtime(true) - SYNC_CLASS_BOOT_AT) * 1000, 2)
            : null;

        return $this->view('home/index', [
            'title' => 'Pipeline overview',
            'base_url' => (string) $this->config->get('app.url', ''),
            'environment' => (string) $this->config->get('app.env', 'local'),
            'debug' => (bool) $this->config->get('app.debug', false),
            'timezone' => date_default_timezone_get(),
            'php_version' => PHP_VERSION,
            'server_software' => (string) $request->server('SERVER_SOFTWARE', 'CLI'),
            'boot_ms' => $bootMs,
            'route_name' => (string) $request->attribute('route_name', '—'),
            'route_summary' => (string) $request->attribute('route_summary', '—'),
            'middleware_chain' => (array) $request->attribute('middleware_chain', []),
            'session_id' => substr((string) $request->attribute('session_id', ''), 0, 12),
            'session_expired' => $request->attribute('session_expired'),
            'csrf_present' => $request->attribute('session_id') !== null,
            'database' => [
                'driver' => (string) $this->config->get('database.driver', 'mysql'),
                'name' => (string) $this->config->get('database.database', ''),
                'host' => (string) $this->config->get('database.host', ''),
            ],
            'routes' => $routes,
        ]);
    }
}
