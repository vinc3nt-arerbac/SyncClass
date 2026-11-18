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
 * Semesters (MD-06).
 *
 * A semester belongs to an academic year and carries the lifecycle status the
 * rest of the system will filter on: `planned → active → closed`. At most one
 * semester per year is current; the unique key enforces it in storage and
 * TermRepository clears the previous one in a transaction so the user never
 * sees a duplicate-key error.
 *
 * Codes are unique across the institution (`2026-2027-1ST`) because reports and
 * exports address a term by that single string.
 */
final class SemesterController extends MasterDataController
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
            'academic_year_id' => $request->query('year'),
            'status' => (string) $request->query('status', ''),
            'sort' => (string) $request->query('sort', ''),
            'direction' => (string) $request->query('direction', ''),
        ];
        $pagination = $this->pagination($request);
        $result = $this->terms->semesters($filters, $pagination['per_page'], $pagination['offset']);

        return $this->view('master/terms/semesters', [
            'title' => 'Semesters',
            'semesters' => $result['rows'],
            'total' => $result['total'],
            'filters' => $filters,
            'years' => $this->terms->yearOptions(),
            'statuses' => TermRepository::SEMESTER_STATUSES,
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
        $data = $this->validateSemester($request);
        $this->guardUnique($this->terms->semesterCodeExists($data['code']), 'code', 'That semester code is already in use.');
        $this->guardUnique(
            $this->terms->termTaken((int) $data['academic_year_id'], (int) $data['term_no']),
            'term_no',
            'That term number already exists in the selected academic year.'
        );

        $id = $this->terms->createSemester($data, $request->post('is_current') === '1');
        $this->auditChange($request, 'master.semester.created', 'semester', $id, 'Created semester ' . $data['code']);

        return $this->saved('master.semesters.index', 'created');
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($request, $this->requireRecord($this->terms->findSemester((int) $id), 'That semester does not exist.'));
    }

    public function update(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->terms->findSemester((int) $id), 'That semester does not exist.');
        $data = $this->validateSemester($request);
        $this->guardUnique(
            $this->terms->semesterCodeExists($data['code'], (int) $record['id']),
            'code',
            'That semester code is already in use.'
        );
        $this->guardUnique(
            $this->terms->termTaken((int) $data['academic_year_id'], (int) $data['term_no'], (int) $record['id']),
            'term_no',
            'That term number already exists in the selected academic year.'
        );

        $this->terms->updateSemester((int) $record['id'], $data, $request->post('is_current') === '1');
        $this->auditChange($request, 'master.semester.updated', 'semester', (int) $record['id'], 'Updated semester ' . $data['code']);

        return $this->saved('master.semesters.index', 'updated');
    }

    public function current(Request $request, string $id): Response
    {
        $record = $this->requireRecord($this->terms->findSemester((int) $id), 'That semester does not exist.');

        $this->masterData->setCurrentSemester((int) $record['id']);
        $this->auditChange($request, 'master.semester.current', 'semester', (int) $record['id'], 'Set ' . $record['code'] . ' as the current semester');

        return $this->saved('master.semesters.index', 'current');
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function form(Request $request, ?array $record): Response
    {
        return $this->view('master/terms/semester-form', [
            'title' => $record === null ? 'New semester' : 'Edit ' . $record['code'],
            'semester' => $record,
            'years' => $this->terms->yearOptions(),
            'statuses' => TermRepository::SEMESTER_STATUSES,
            'can_manage' => $this->authorization->can($this->actor($request), 'terms.manage'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSemester(Request $request): array
    {
        return $this->validate(
            [
                'academic_year_id' => 'required|integer|min:1',
                'term_no' => 'required|integer|in:1,2,3',
                'name' => 'required|string|max:40',
                'code' => 'required|alpha_dash|max:30',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'status' => 'required|in:' . implode(',', TermRepository::SEMESTER_STATUSES),
            ],
            [
                'academic_year_id' => 'Academic year',
                'term_no' => 'Term number',
                'name' => 'Semester name',
                'code' => 'Semester code',
                'start_date' => 'Start date',
                'end_date' => 'End date',
                'status' => 'Status',
            ],
            ['end_date.after' => 'The end date must be after the start date.']
        );
    }
}
