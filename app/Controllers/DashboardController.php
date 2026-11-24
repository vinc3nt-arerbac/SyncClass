<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Services\DashboardService;

/**
 * Dashboard (Phase 16, DASH-01…DASH-03).
 *
 * Two entry points:
 *   GET /              → the role-aware dashboard page (route name `home`)
 *   GET /api/dashboard → the Chart.js payload, scoped to the same actor
 *
 * Both require only `auth`: the dashboard is the landing page for every role,
 * and what each role sees is decided by the service's scope, not by a
 * permission. Neither action writes, so CSRF has nothing to police here.
 *
 * `?semester=` narrows the term. It is parsed to an int and validated by the
 * service against the terms the actor may select, so it can never widen the
 * scope. The chart endpoint receives the same parameter from the page's fetch
 * call, which keeps the numbers and the charts on one term.
 */
final class DashboardController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly DashboardService $dashboard
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    /**
     * GET / — the dashboard.
     */
    public function index(Request $request): Response
    {
        $authUser = $this->actor();
        $semesterId = $this->semesterParam($request);
        $overview = $this->dashboard->overview($authUser, $semesterId);

        $chartEndpoint = $this->router->url('dashboard.charts');
        $selectedId = $overview['context']['semester'] === null
            ? null
            : (int) $overview['context']['semester']['id'];

        if ($selectedId !== null) {
            $chartEndpoint .= '?semester=' . $selectedId;
        }

        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
            'chart_endpoint' => $chartEndpoint,
        ] + $overview)->withHeader('cache-control', 'no-store');
    }

    /**
     * GET /api/dashboard — chart series (DASH-03).
     *
     * The payload follows the application envelope (`{ ok, data }`) and is
     * `no-store`: it is a per-user, per-term snapshot, so caching it in a shared
     * proxy would leak one actor's scope to another.
     */
    public function charts(Request $request): Response
    {
        $authUser = $this->actor();
        $payload = $this->dashboard->charts($authUser, $this->semesterParam($request));

        return $this->json(['ok' => true, 'data' => $payload])
            ->withHeader('cache-control', 'no-store');
    }

    /**
     * `?semester=` as a positive int, or null when absent/unparseable.
     */
    private function semesterParam(Request $request): ?int
    {
        $raw = $request->query('semester');

        if (!is_scalar($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '' || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
