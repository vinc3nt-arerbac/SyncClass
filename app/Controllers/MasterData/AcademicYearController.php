<?php

declare(strict_types=1);

namespace App\Controllers\MasterData;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Repositories\TermRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;

/**
 * Academic years (MD-06).
 *
 * Years are historical records: there is no active flag, only `is_current`.
 * Exactly one year may be current, and setting a new one clears the previous
 * inside a transaction (TermRepository), so the invariant never depends on the
 * order of two requests.
 */
final class AcademicYearController extends MasterDataController
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        MasterDataService $masterData,
        AuthorizationService $authorization,
        AuditRepository $audit,
        private readonly TermRepository $terms
    ) {
        parent::__construct($request, $view, $router, $config, $masterData, $authorization, $audit);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => (string) $request->query('q', ''),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];
        $pagination = $this->pagination($request);
        $result = $this->terms->years($filters, $pagination['per_page'], $pagination['offset']);

        return $this->view('master/terms/years', [
            'title' => 'Academic years',
            'years' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'last_page' => $this->lastPage($result['total'], $pagination['per_page']),
            'notice' => $this->notice($request),
            'can_manage' => $this->authorization->can($this->actor($request), 'terms.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateYear($request);
        $this->guardUnique($this->terms->yearCodeExists($data['code']), 'code', 'That academic year already exists.');

        $id = $this->terms->createYear($data, $request->post('is_current') === '1');
        $this->auditChange($request, 'master.academic_year.created', 'academic_year', $id, 'Created academic year ' . $data['code']);

        return $this->saved('master.years.index', 'created');
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->requireRecord($this->terms->findYear((int) $id), 'That academic year does not exist.'));
    }

    public function update(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->terms->findYear((int) $id), 'That academic year does not exist.');
        $data = $this->validateYear($request);
        $this->guardUnique(
            $this->terms->yearCodeExists($data['code'], (int) $record['id']),
            'code',
            'That academic year already exists.'
        );

        $this->terms->updateYear((int) $record['id'], $data, $request->post('is_current') === '1');
        $this->auditChange($request, 'master.academic_year.updated', 'academic_year', (int) $record['id'], 'Updated academic year ' . $data['code']);

        return $this->saved('master.years.index', 'updated');
    }

    public function current(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->terms->findYear((int) $id), 'That academic year does not exist.');

        $this->masterData->setCurrentYear((int) $record['id']);
        $this->auditChange($request, 'master.academic_year.current', 'academic_year', (int) $record['id'], 'Set ' . $record['code'] . ' as the current academic year');

        return $this->saved('master.years.index', 'current');
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function form(Request $request, ?array $record): Response
    {
        return $this->view('master/terms/year-form', [
            'title' => $record === null ? 'New academic year' : 'Edit ' . $record['code'],
            'year' => $record,
            'can_manage' => $this->authorization->can($this->actor($request), 'terms.manage'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateYear(Request $request): array
    {
        return $this->validate(
            [
                'code' => 'required|regex:/^\\d{4}-\\d{4}$/',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
            ],
            ['code' => 'Academic year', 'start_date' => 'Start date', 'end_date' => 'End date'],
            [
                'code.regex' => 'Use the format 2026-2027.',
                'end_date.after' => 'The end date must be after the start date.',
            ]
        );
    }
}
