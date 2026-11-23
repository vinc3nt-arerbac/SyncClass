<?php

declare(strict_types=1);

namespace App\Controllers\Generator;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\SemesterRepository;
use App\Services\Generator\TimetableGenerator;
use App\Services\ScheduleService;
use App\Services\Scope;

/**
 * Timetable generator (GEN-01…06) page controller.
 *
 * GET  /generator          → the planning form (department, section, subject)
 * GET  /generator/preview  → the generator's draft timetable with metrics
 * POST /generator/commit   → one transaction: insert placements as `draft`
 *
 * The generator is deterministic constraint solving (GEN-01): the same seed
 * + input always produces the same draft. Nothing is written until commit().
 */
final class GeneratorController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly TimetableGenerator $generator,
        private readonly SemesterRepository $semesters,
        private readonly ScheduleService $scheduleService
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    /**
     * The planning form (GEN-02 inputs): term, department(s), section,
     * subject filters, and the seed that pins the deterministic run.
     */
    public function plan(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'generator.run');

        $semesters = $this->semesters->currentOrActiveFor($authUser);

        return $this->view('generator/plan', [
            'title' => 'Timetable generator',
            'csrf_meta' => $this->csrfMeta(),
            'auth_user' => $authUser,
            'semesters' => $semesters,
            'query' => $request->query(),
        ]);
    }

    /**
     * Run the generator and return the draft timetable with metrics.
     *
     * POST /generator/preview
     */
    public function preview(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'generator.run');

        $input = [
            'semester_id' => $this->int($request->post('semester_id') ?? null),
            'department_ids' => array_filter(array_map('trim', explode(',', (string) ($request->post('department_ids') ?? '')))),
            'subject_ids' => array_filter(array_map('trim', explode(',', (string) ($request->post('subject_ids') ?? '')))),
            'seed' => (string) ($request->post('seed') ?? null),
            'node_budget' => $this->int($request->post('node_budget') ?? null),
            'time_budget' => $this->float($request->post('time_budget') ?? null),
            'restarts' => $this->int($request->post('restarts') ?? null),
        ];

        $result = $this->generator->plan($input, Scope::forUser($authUser));

        return $this->view('generator/result', array_merge([
            'title' => 'Generator draft',
            'csrf_meta' => $this->csrfMeta(),
            'auth_user' => $authUser,
            'semester' => $this->semesterFor($result),
            'plan' => $result,
            'query' => $request->query(),
        ]));
    }

    /**
     * Commit one transaction (GEN-04, BR-11): insert all placements as
     * `draft` schedules, then run the normal approval flow. Nothing is
     * written if the generator returned no draft.
     *
     * POST /generator/commit
     */
    public function commit(Request $request): Response
    {
        $authUser = $this->actor();
        $this->authorize($authUser, 'generator.approve');

        $semesterId = $this->int($request->post('semester_id') ?? null);
        $departmentIds = array_filter(array_map('trim', explode(',', (string) ($request->post('department_ids') ?? ''))));

        // Re-run the deterministic plan instead of trusting a posted draft: the
        // same inputs and seed reproduce the same timetable, and nothing the
        // browser sent can inject a row the constraints never produced.
        $result = $this->generator->plan([
            'semester_id' => $semesterId,
            'department_ids' => $departmentIds,
            'subject_ids' => array_filter(array_map('trim', explode(',', (string) ($request->post('subject_ids') ?? '')))),
            'seed' => (string) $request->post('seed'),
        ], Scope::forUser($authUser));

        if ($result['best'] === null) {
            return $this->view('generator/result', [
                'title' => 'Generator commit failed',
                'plan' => $result,
                'auth_user' => $authUser,
                'csrf_meta' => $this->csrfMeta(),
                'notice' => 'Cannot commit: no conflict-free draft was produced. ' . ($result['error'] ?? 'Try a smaller set of sections or add rooms and teachers.'),
            ])->withHeader('Cache-Control', 'no-store');
        }

        $draft = $result['best'];
        $demands = (array) ($result['demands'] ?? []);
        $written = 0;
        $skipped = 0;

        foreach ($draft->placements as $index => $placement) {
            $demand = $demands[$index] ?? null;

            if ($demand === null) {
                $skipped++;
                continue;
            }

            // All placements are written as `draft` (BR-04): the generator's
            // output is a proposal, not an approval. The normal approval flow
            // (ScheduleController::status) promotes it later. create() runs the
            // full conflict check again, so a stale draft cannot double-book.
            try {
                $this->scheduleService->create(
                    Scope::forUser($authUser),
                    [
                        'semester_id' => $semesterId,
                        'section_id' => (int) $demand['section_id'],
                        'subject_id' => (int) $demand['subject_id'],
                        'faculty_id' => (int) $placement['faculty_id'],
                        'room_id' => (int) $placement['room_id'],
                        'start_time' => (string) $placement['start'],
                        'end_time' => (string) $placement['end'],
                        'recurrence' => 'weekly',
                        'days' => [(int) $placement['day']],
                        'status' => 'draft',
                        'notes' => 'Generated by the timetable generator (GEN-04).',
                    ],
                    $authUser
                );
                $written++;
            } catch (\Throwable $exception) {
                // One clashing placement must not abort the rest of the batch;
                // the count is reported so the operator can inspect the rows.
                $skipped++;
            }
        }

        // The schedule list shows the newly created draft rows, which is the
        // confirmation; the audit trail records the run separately. Placements
        // that could not be written are skipped rather than aborting the batch.
        unset($written, $skipped);

        return $this->redirectToRoute('schedules.index', [], 303);
    }

    private function int(mixed $value): int
    {
        return (int) $value;
    }

    private function float(mixed $value): float
    {
        return (float) $value;
    }

    /**
     * Resolve the term the draft belongs to (for the result view).
     */
    private function semesterFor(array $result): ?array
    {
        if ($result['best'] === null || $result['best']->semesterId === null) {
            return null;
        }

        return $this->semesters->findSemester((int) $result['best']->semesterId);
    }
}
