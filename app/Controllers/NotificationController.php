<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Services\NotificationService;

/**
 * Notifications (NTF-01…NTF-04).
 *
 * Two things the user asked for, both scoped and read-only on the app side:
 *
 *   - the inbox list + mark-read actions the signed-in user trains;
 *   - the "Your notifications" summary the dashboard renders from the same
 *     scoped query.
 *
 * Delivery (the email leg) is deliberately outside this controller: the CLI
 * command `bin/dispatch-notifications.php` drains `email_status = pending` in a
 * separate process so a slow SMTP host cannot delay a page response. Everything
 * here is read/write on the in-app table only; the email table is driven by the
 * console command.
 */
final class NotificationController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        private readonly NotificationService $service
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    public function index(Request $request): Response
    {
        $user = $this->actor($request);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(10, min(200, (int) $request->query('per_page', 50)));

        $result = $this->service->listForUser($user['id'], $perPage, $page);
        $unread = $result['unread'];

        return $this->view('notifications/index', [
            'title' => 'Notifications',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'unread' => $unread,
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'last_page' => $result['last_page'],
            'notice' => $this->notice($request),
            'csrf_meta' => $this->csrfMeta(),
        ]);
    }

    public function markAsRead(Request $request, string $id): Response
    {
        $user = $this->actor($request);
        $this->service->markAsRead((int) $id, $user['id']);

        return Response::redirect(
            $this->router->url('notifications.index') . '?saved=read',
            303
        );
    }

    public function markReadAll(Request $request): Response
    {
        $user = $this->actor($request);
        $this->service->markReadAll($user['id']);

        return Response::redirect(
            $this->router->url('notifications.index') . '?saved=read-all',
            303
        );
    }

    public function destroy(Request $request, string $id): Response
    {
        $user = $this->actor($request);
        $this->service->delete((int) $id, $user['id']);

        return Response::redirect(
            $this->router->url('notifications.index') . '?saved=deleted',
            303
        );
    }

    /**
     * The dashboard widget pulls exactly the fields this page renders, so a
     * change to the template is one change for both surfaces.
     */
    public function summary(Request $request): Response
    {
        $user = $this->actor($request);

        $rows = $this->service->listForUser($user['id'], 5, 1)['rows'];

        return $this->view('notifications/summary', [
            'title' => 'Your notifications',
            'rows' => $rows,
            'unread' => $unread,
        ]);
    }

    private function notice(Request $request): ?string
    {
        $status = strtolower(trim((string) $request->query('saved', '')));

        return in_array($status, ['created', 'updated', 'deleted'], true)
            ? $status
            : null;
    }

    protected function actor(?Request $request = null): array
    {
        $request ??= $this->request;
        $user = $request->attribute('auth_user');

        if (!is_array($user) || (int) ($user['id'] ?? 0) <= 0) {
            $this->abort(401, 'Sign in to continue.');
        }

        return $user;
    }
}
