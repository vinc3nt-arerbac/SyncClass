<?php

declare(strict_types=1);

/**
 * Web routes (Phase 6, extended in Phase 7 and Phase 8).
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
 *   'permission:code[,code]'  Authorize — any-of permission check (Phase 8)
 *   'role:code[,code]'        Authorize — any-of role check (Phase 8)
 *
 * Because session and CSRF are global, a route that forgets to declare them is
 * still protected. Only authorisation is opt-in, and a route without 'auth' is
 * public by definition — which is why every new route is reviewed for it.
 * A permission code nobody holds denies everyone (there is no admin bypass),
 * so a typo fails closed and is caught the first time the page is opened.
 *
 * @var callable(App\Core\Router, App\Core\Container): void $register
 */

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\MasterData\AcademicYearController;
use App\Controllers\MasterData\BuildingController;
use App\Controllers\MasterData\DepartmentController;
use App\Controllers\MasterData\FacultyController;
use App\Controllers\MasterData\RoomController;
use App\Controllers\MasterData\SectionController;
use App\Controllers\MasterData\SemesterController;
use App\Controllers\MasterData\SubjectController;
use App\Controllers\PipelineController;
use App\Controllers\Scheduling\ScheduleController;
use App\Controllers\Generator\GeneratorController;
use App\Controllers\UserDirectoryController;
use App\Core\Container;
use App\Core\Router;

return static function (Router $router, Container $container): void {
    // Web API: front-controller dispatches api.php before web.php.
    // (api.php registers REST endpoints; web.php declares page routes.)
};
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
    // Private: staff directory (Phase 8). The route-level permission is the
    // ceiling; UserRepository applies the department/self scope to the rows.
    // -----------------------------------------------------------------------
    $router->get('/admin/users', [UserDirectoryController::class, 'index'], ['auth', 'permission:users.view'], 'People directory (scoped)')
        ->name('admin.users.index');

    // -----------------------------------------------------------------------
    // Master data (Phase 9). Reads require the module's `*.view` permission;
    // writes require create/update/delete. Department scope is applied inside
    // the controllers and repositories, not here.
    // -----------------------------------------------------------------------
    $router->get('/master/departments', [DepartmentController::class, 'index'], ['auth', 'permission:departments.view'], 'Departments')
        ->name('master.departments.index');
    $router->get('/master/departments/create', [DepartmentController::class, 'create'], ['auth', 'permission:departments.create'], 'New department')
        ->name('master.departments.create');
    $router->post('/master/departments', [DepartmentController::class, 'store'], ['auth', 'permission:departments.create'], 'Create department')
        ->name('master.departments.store');
    $router->get('/master/departments/{id}/edit', [DepartmentController::class, 'edit'], ['auth', 'permission:departments.update'], 'Edit department')
        ->name('master.departments.edit');
    $router->post('/master/departments/{id}', [DepartmentController::class, 'update'], ['auth', 'permission:departments.update'], 'Update department')
        ->name('master.departments.update');
    $router->post('/master/departments/{id}/toggle', [DepartmentController::class, 'toggle'], ['auth', 'permission:departments.delete'], 'Archive department')
        ->name('master.departments.toggle');

    $router->get('/master/buildings', [BuildingController::class, 'index'], ['auth', 'permission:rooms.view'], 'Buildings')
        ->name('master.buildings.index');
    $router->get('/master/buildings/create', [BuildingController::class, 'create'], ['auth', 'permission:rooms.create'], 'New building')
        ->name('master.buildings.create');
    $router->post('/master/buildings', [BuildingController::class, 'store'], ['auth', 'permission:rooms.create'], 'Create building')
        ->name('master.buildings.store');
    $router->get('/master/buildings/{id}/edit', [BuildingController::class, 'edit'], ['auth', 'permission:rooms.update'], 'Edit building')
        ->name('master.buildings.edit');
    $router->post('/master/buildings/{id}', [BuildingController::class, 'update'], ['auth', 'permission:rooms.update'], 'Update building')
        ->name('master.buildings.update');
    $router->post('/master/buildings/{id}/toggle', [BuildingController::class, 'toggle'], ['auth', 'permission:rooms.delete'], 'Archive building')
        ->name('master.buildings.toggle');

    $router->get('/master/rooms', [RoomController::class, 'index'], ['auth', 'permission:rooms.view'], 'Rooms')
        ->name('master.rooms.index');
    $router->get('/master/rooms/create', [RoomController::class, 'create'], ['auth', 'permission:rooms.create'], 'New room')
        ->name('master.rooms.create');
    $router->post('/master/rooms', [RoomController::class, 'store'], ['auth', 'permission:rooms.create'], 'Create room')
        ->name('master.rooms.store');
    $router->get('/master/rooms/{id}/edit', [RoomController::class, 'edit'], ['auth', 'permission:rooms.update'], 'Edit room')
        ->name('master.rooms.edit');
    $router->post('/master/rooms/{id}', [RoomController::class, 'update'], ['auth', 'permission:rooms.update'], 'Update room')
        ->name('master.rooms.update');
    $router->post('/master/rooms/{id}/toggle', [RoomController::class, 'toggle'], ['auth', 'permission:rooms.delete'], 'Archive room')
        ->name('master.rooms.toggle');

    $router->get('/master/subjects', [SubjectController::class, 'index'], ['auth', 'permission:subjects.view'], 'Subjects')
        ->name('master.subjects.index');
    $router->get('/master/subjects/create', [SubjectController::class, 'create'], ['auth', 'permission:subjects.create'], 'New subject')
        ->name('master.subjects.create');
    $router->post('/master/subjects', [SubjectController::class, 'store'], ['auth', 'permission:subjects.create'], 'Create subject')
        ->name('master.subjects.store');
    $router->get('/master/subjects/{id}/edit', [SubjectController::class, 'edit'], ['auth', 'permission:subjects.update'], 'Edit subject')
        ->name('master.subjects.edit');
    $router->post('/master/subjects/{id}', [SubjectController::class, 'update'], ['auth', 'permission:subjects.update'], 'Update subject')
        ->name('master.subjects.update');
    $router->post('/master/subjects/{id}/toggle', [SubjectController::class, 'toggle'], ['auth', 'permission:subjects.delete'], 'Archive subject')
        ->name('master.subjects.toggle');

    $router->get('/master/sections', [SectionController::class, 'index'], ['auth', 'permission:sections.view'], 'Sections')
        ->name('master.sections.index');
    $router->get('/master/sections/create', [SectionController::class, 'create'], ['auth', 'permission:sections.create'], 'New section')
        ->name('master.sections.create');
    $router->post('/master/sections', [SectionController::class, 'store'], ['auth', 'permission:sections.create'], 'Create section')
        ->name('master.sections.store');
    $router->get('/master/sections/{id}/edit', [SectionController::class, 'edit'], ['auth', 'permission:sections.update'], 'Edit section')
        ->name('master.sections.edit');
    $router->post('/master/sections/{id}', [SectionController::class, 'update'], ['auth', 'permission:sections.update'], 'Update section')
        ->name('master.sections.update');
    $router->post('/master/sections/{id}/toggle', [SectionController::class, 'toggle'], ['auth', 'permission:sections.delete'], 'Archive section')
        ->name('master.sections.toggle');

    $router->get('/master/faculty', [FacultyController::class, 'index'], ['auth', 'permission:faculty.view'], 'Faculty')
        ->name('master.faculty.index');
    $router->get('/master/faculty/create', [FacultyController::class, 'create'], ['auth', 'permission:faculty.create'], 'New faculty profile')
        ->name('master.faculty.create');
    $router->post('/master/faculty', [FacultyController::class, 'store'], ['auth', 'permission:faculty.create'], 'Create faculty profile')
        ->name('master.faculty.store');
    $router->get('/master/faculty/{id}/edit', [FacultyController::class, 'edit'], ['auth', 'permission:faculty.update'], 'Edit faculty profile')
        ->name('master.faculty.edit');
    $router->post('/master/faculty/{id}', [FacultyController::class, 'update'], ['auth', 'permission:faculty.update'], 'Update faculty profile')
        ->name('master.faculty.update');
    $router->post('/master/faculty/{id}/toggle', [FacultyController::class, 'toggle'], ['auth', 'permission:faculty.delete'], 'Archive faculty profile')
        ->name('master.faculty.toggle');
    $router->get('/master/faculty/{id}/availability', [FacultyController::class, 'availability'], ['auth', 'permission:availability.update'], 'Faculty availability')
        ->name('master.faculty.availability');
    $router->post('/master/faculty/{id}/availability', [FacultyController::class, 'storeAvailability'], ['auth', 'permission:availability.update'], 'Add availability window')
        ->name('master.faculty.availability.store');
    $router->post('/master/faculty/{id}/availability/{windowId}/delete', [FacultyController::class, 'destroyAvailability'], ['auth', 'permission:availability.update'], 'Remove availability window')
        ->name('master.faculty.availability.destroy');

    $router->get('/master/years', [AcademicYearController::class, 'index'], ['auth', 'permission:terms.view'], 'Academic years')
        ->name('master.years.index');
    $router->get('/master/years/create', [AcademicYearController::class, 'create'], ['auth', 'permission:terms.manage'], 'New academic year')
        ->name('master.years.create');
    $router->post('/master/years', [AcademicYearController::class, 'store'], ['auth', 'permission:terms.manage'], 'Create academic year')
        ->name('master.years.store');
    $router->get('/master/years/{id}/edit', [AcademicYearController::class, 'edit'], ['auth', 'permission:terms.manage'], 'Edit academic year')
        ->name('master.years.edit');
    $router->post('/master/years/{id}', [AcademicYearController::class, 'update'], ['auth', 'permission:terms.manage'], 'Update academic year')
        ->name('master.years.update');
    $router->post('/master/years/{id}/current', [AcademicYearController::class, 'current'], ['auth', 'permission:terms.manage'], 'Set current academic year')
        ->name('master.years.current');

    $router->get('/master/semesters', [SemesterController::class, 'index'], ['auth', 'permission:terms.view'], 'Semesters')
        ->name('master.semesters.index');
    $router->get('/master/semesters/create', [SemesterController::class, 'create'], ['auth', 'permission:terms.manage'], 'New semester')
        ->name('master.semesters.create');
    $router->post('/master/semesters', [SemesterController::class, 'store'], ['auth', 'permission:terms.manage'], 'Create semester')
        ->name('master.semesters.store');
    $router->get('/master/semesters/{id}/edit', [SemesterController::class, 'edit'], ['auth', 'permission:terms.manage'], 'Edit semester')
        ->name('master.semesters.edit');
    $router->post('/master/semesters/{id}', [SemesterController::class, 'update'], ['auth', 'permission:terms.manage'], 'Update semester')
        ->name('master.semesters.update');
    $router->post('/master/semesters/{id}/current', [SemesterController::class, 'current'], ['auth', 'permission:terms.manage'], 'Set current semester')
        ->name('master.semesters.current');

    // -----------------------------------------------------------------------
    // Scheduling (Phase 10). Reads are scoped by ScheduleRepository (faculty
    // see their own classes); the lifecycle endpoint is guarded by
    // schedules.update and the service re-checks approve/delete per target.
    // -----------------------------------------------------------------------
    $router->get('/schedules', [ScheduleController::class, 'index'], ['auth', 'permission:schedules.view'], 'Schedule list')
        ->name('schedules.index');
    $router->get('/schedules/create', [ScheduleController::class, 'create'], ['auth', 'permission:schedules.create'], 'Add class')
        ->name('schedules.create');
    $router->post('/schedules', [ScheduleController::class, 'store'], ['auth', 'permission:schedules.create'], 'Create meetings')
        ->name('schedules.store');
    $router->get('/schedules/{id}', [ScheduleController::class, 'show'], ['auth', 'permission:schedules.view'], 'Schedule detail')
        ->name('schedules.show');
    $router->get('/schedules/{id}/edit', [ScheduleController::class, 'edit'], ['auth', 'permission:schedules.update'], 'Edit meeting')
        ->name('schedules.edit');
    $router->post('/schedules/{id}', [ScheduleController::class, 'update'], ['auth', 'permission:schedules.update'], 'Update meeting')
        ->name('schedules.update');
    // Any-of, because approval belongs to a role that does not edit
    // (dean: view + approve). The service enforces the exact permission for
    // the requested target, so this gate only decides who may knock.
    $router->post('/schedules/{id}/status', [ScheduleController::class, 'status'], ['auth', 'permission:schedules.update,schedules.approve,schedules.delete'], 'Change schedule status')
        ->name('schedules.status');
    // Phase 11: dry-run conflict preview. Any of the three scheduling
    // permissions may call it (secretary builds, dean approves, admin owns),
    // but nothing writes: ConflictService::check() returns errors[] and
    // alternatives[] for the form's "what-if" widget.
    $router->post('/schedules/{id}/conflict-suggestions', [ScheduleController::class, 'conflictSuggestions'], ['auth', 'permission:schedules.update,schedules.approve,schedules.delete'], 'Conflict and room alternatives')
        ->name('schedules.conflict_suggestions');

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

    // -----------------------------------------------------------------------
    // Phase 12: Calendar (CAL-01..CAL-06).
    // -----------------------------------------------------------------------
    $router->get('/calendar', [CalendarController::class, 'index'], ['auth', 'permission:schedules.view'], 'Calendar')
        ->name('calendar.index');
    $router->get('/calendar/{year}/{month}', [CalendarController::class, 'index'], ['auth', 'permission:schedules.view'], 'Calendar month')
        ->name('calendar.month');
    $router->get('/api/calendar/events', [CalendarController::class, 'events'], ['auth', 'permission:schedules.view'], 'Calendar JSON feed')
        ->name('calendar.events');

    // -----------------------------------------------------------------------
    // Phase 13: Timetable generator (GEN-01..06).
    // -----------------------------------------------------------------------
    $router->get('/generator', [GeneratorController::class, 'plan'], ['auth', 'permission:generator.run'], 'Run timetable generator')
        ->name('generator.plan');
    $router->post('/generator/preview', [GeneratorController::class, 'preview'], ['auth', 'permission:generator.run'], 'Generator draft')
        ->name('generator.preview');
    $router->post('/generator/commit', [GeneratorController::class, 'commit'], ['auth', 'permission:generator.approve'], 'Commit generated schedule')
        ->name('generator.commit');

    // -----------------------------------------------------------------------
    // End of Phase 12–13 routes.
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // Phase 14: Notifications (NTF-01…NTF-04).
    // -----------------------------------------------------------------------
    $router->get('/notifications', [NotificationController::class, 'index'], ['auth', 'permission:notifications.view'], 'Notifications')
        ->name('notifications.index');
    $router->get('/notifications/{id}', [NotificationController::class, 'markAsRead'], ['auth', 'permission:notifications.view'], 'Mark notification read')
        ->name('notifications.mark-as-read');
    $router->post('/notifications/read-all', [NotificationController::class, 'markReadAll'], ['auth', 'permission:notifications.view'], 'Mark all notifications read')
        ->name('notifications.mark-read-all');
    $router->post('/notifications/{id}/delete', [NotificationController::class, 'destroy'], ['auth', 'permission:notifications.view'], 'Delete notification')
        ->name('notifications.destroy');

    // -----------------------------------------------------------------------
    // End of Phase 14 routes.
    // -----------------------------------------------------------------------
};
