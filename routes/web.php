<?php

declare(strict_types=1);

/**
 * Web routes (Phase 6, extended in Phase 7).
 *
 * This file is loaded once by Kernel::boot(); it returns a callable that
 * receives the Router (and the container, for route-level bindings). Routes
 * are declared with the mount prefix already removed — `/health`, not
 * `/SyncClass/health` — because Request strips the prefix on the way in and
 * Router::url() adds it back on the way out.
 *
 * Middleware aliases (registered in Kernel::registerMiddleware):
 *   'session'  StartSession        — global (every request)
 *   'csrf'     VerifyCsrfToken     — global; skips safe methods, so every
 *                                   state-changing request is covered without
 *                                   per-route opt-in
 *   'auth'     Authenticate        — required on every non-public route
 *   'guest'    Guest               — keeps signed-in users off the login form
 *
 * Because session and CSRF are global, a route that forgets to declare them is
 * still protected. Only authorisation is opt-in, and a route without 'auth' is
 * public by definition — which is why every new route is reviewed for it.
 *
 * @var callable(App\Core\Router, App\Core\Container): void $register
 */

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\PipelineController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    // -----------------------------------------------------------------------
    // Public: authentication (AUTH-01, AUTH-04)
    // -----------------------------------------------------------------------
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest'], 'Sign-in form')
        ->name('login');

    $router->post('/login', [AuthController::class, 'login'], [], 'Sign-in handler')
        ->name('login.attempt');

    // POST only: a GET sign-out link would be forgeable from any page.
    $router->post('/logout', [AuthController::class, 'logout'], ['auth'], 'Sign-out')
        ->name('logout');

    // -----------------------------------------------------------------------
    // Public: diagnostics
    // -----------------------------------------------------------------------
    $router->get('/health', [HealthController::class, 'index'], [], 'Health check')
        ->name('health');

    // -----------------------------------------------------------------------
    // Private: the application shell (becomes the Phase 16 dashboard)
    // -----------------------------------------------------------------------
    $router->get('/', [HomeController::class, 'index'], ['auth'], 'Scheduler overview')
        ->name('home');

    // -----------------------------------------------------------------------
    // Phase 6 probes — see App\Controllers\PipelineController.
    // /pipeline/echo stays public on purpose: it writes nothing and exists to
    // demonstrate CSRF enforcement. Both probes are removed once real modules
    // replace them.
    // -----------------------------------------------------------------------
    $router->post('/pipeline/echo', [PipelineController::class, 'echo'], [], 'CSRF-protected probe')
        ->name('pipeline.echo');

    $router->get('/pipeline/guarded', [PipelineController::class, 'guarded'], ['auth'], 'Authentication probe')
        ->name('pipeline.guarded');
};
