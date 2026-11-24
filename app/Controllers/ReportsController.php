<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\DepartmentRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\SectionRepository;
use App\Services\ReportService;
use App\Services\Scope;

/**
 * Reports module (Phase 15, RPT-01…RPT-07).
 *
 * Six read-only pages share one shape: resolve the actor, resolve the term,
 * ask the service for the report, render. There is no POST action here — a
 * report never writes, so the global CSRF middleware has nothing to protect on
 * these routes.
 *
 * Scope is enforced twice, as everywhere else in the application:
 *   1. the route requires `reports.view` (deny by default);
 *   2. the service hands a Scope to the repository, which filters in SQL.
 * Filter parameters (`semester`, `faculty`, `department`, `section`) only ever
 * narrow what the scope already allows, and every one of them is cast to an
 * int; a hand-edited value returns an empty report instead of another
 * department's rows.
 */
final class ReportsController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly ReportService $reports,
        private readonly FacultyRepository $faculty,
        private readonly DepartmentRepository $departments,
        private readonly SectionRepository $sections
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    /**
     * GET /reports — the hub: term picker, the four headline numbers and the
     * cards that link to each report.
     */
    public function index(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'reports.view');

        $overview = $this->reports->overview($authUser, $this->semesterParam($request));

        return $this->view('reports/index', [
            'title' => 'Reports',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
            'context' => $overview['context'],
            'summary' => $overview['summary'],
            'scope_label' => $this->reports->scopeLabel($authUser),
            'semester' => $overview['context']['semester'],
        ])->withHeader('cache-control', 'no-store');
    }

    /**
     * GET /reports/faculty-load — RPT-01.
     */
    public function facultyLoad(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'reports.view');

        $report = $this->reports->facultyLoad($authUser, $this->semesterParam($request));

        return $this->view('reports/faculty_load', [
            'title' => 'Faculty load report',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
            'notice' => null,
        ] + $report)->withHeader('cache-control', 'no-store');
    }

    /**
     * GET /reports/rooms — RPT-02.
     */
    public function rooms(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'reports.view');

        $report = $this->reports->roomUtilization($authUser, $this->semesterParam($request));

        return $this->view('reports/rooms', [
            'title' => 'Room utilization report',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
        ] + $report)->withHeader('cache-control', 'no-store');
    }

    /**
     * GET /reports/departments — RPT-03.
     */
    public function departmentSchedule(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'reports.view');

        $report = $this->reports->departmentSchedule(
            $authUser,
            $this->semesterParam($request),
            $this->intParam($request, 'department')
        );

        return $this->view('reports/departments', [
            'title' => 'Department schedule report',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
            'department_options' => $this->departments->options(Scope::forUser($authUser)),
            'department_filter' => $this->intParam($request, 'department') ?? 0,
        ] + $report)->withHeader('cache-control', 'no-store');
    }

    /**
     * GET /reports/conflicts — RPT-04.
     */
    public function conflicts(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'reports.view');

        $report = $this->reports->conflicts(
            $authUser,
            $this->semesterParam($request),
            (string) ($request->query('status') ?? '')
        );

        return $this->view('reports/conflicts', [
            'title' => 'Conflict report',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
        ] + $report)->withHeader('cache-control', 'no-store');
    }

    /**
     * GET /reports/timetable — RPT-03 (weekly/semester timetable) and RPT-05
     * (teacher schedule): the teacher filter turns the same board into one
     * teacher's timetable.
     */
    public function timetable(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'reports.view');

        $scope = Scope::forUser($authUser);
        $semesterParam = $this->semesterParam($request);

        $filters = [
            'faculty_id' => $this->intParam($request, 'faculty') ?? 0,
            'department_id' => $this->intParam($request, 'department') ?? 0,
            'section_id' => $this->intParam($request, 'section') ?? 0,
        ];

        $report = $this->reports->timetable($authUser, $semesterParam, $filters);
        $semesterId = $report['context']['semester'] === null
            ? 0
            : (int) $report['context']['semester']['id'];

        $departmentIds = $scope->isDepartmental() && $scope->departmentId() !== null
            ? [$scope->departmentId()]
            : [];

        return $this->view('reports/timetable', [
            'title' => 'Timetable report',
            'auth_user' => $authUser,
            'csrf_meta' => $this->csrfMeta(),
            'faculty_options' => $this->faculty->optionsFor($authUser),
            'department_options' => $this->departments->options($scope),
            'section_options' => $semesterId === 0 ? [] : $this->sections->bySemesterAndDepartments($semesterId, $departmentIds),
        ] + $report)->withHeader('cache-control', 'no-store');
    }

    /**
     * `?semester=` as an int, or null when absent/unparseable.
     */
    private function semesterParam(Request $request): ?int
    {
        return $this->intParam($request, 'semester');
    }

    /**
     * A positive int query parameter, or null.
     *
     * Filter ids are never trusted: the service only ever uses them to narrow
     * a query that the scope already bounded.
     */
    private function intParam(Request $request, string $key): ?int
    {
        $raw = $request->query($key);

        if (!is_scalar($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
