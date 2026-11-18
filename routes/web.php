<?php

declare(strict_types=1);

/**
 * Web routes (Phase 6).
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
 *
 * Because session and CSRF are global, a route that forgets to declare them is
 * still protected. Only authorisation is opt-in, and a route without 'auth' is
 * public by definition — which is why every new route is reviewed for it.
 *
 * @var callable(App\Core\Router, App\Core\Container): void $register
 */

use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\PipelineController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    // Public pages. The landing page becomes the Phase 16 dashboard.
    $router->get('/', [HomeController::class, 'index'], [], 'Pipeline overview')
        ->name('home');

    // Deployment diagnostic; never cached, never authenticated.
    $router->get('/health', [HealthController::class, 'index'], [], 'Health check')
        ->name('health');

    // Phase 6 probes — see App\Controllers\PipelineController. The POST needs
    // no middleware declaration: CSRF verification is global.
    $router->post('/pipeline/echo', [PipelineController::class, 'echo'], [], 'CSRF-protected probe')
        ->name('pipeline.echo');

    $router->get('/pipeline/guarded', [PipelineController::class, 'guarded'], ['auth'], 'Authentication probe')
        ->name('pipeline.guarded');
};
