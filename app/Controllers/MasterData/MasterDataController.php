<?php

declare(strict_types=1);

namespace App\Controllers\MasterData;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditRepository;
use App\Services\AuthorizationService;
use App\Services\MasterDataService;
use App\Services\Scope;

/**
 * Base controller for the master-data screens (Phase 9).
 *
 * All seven CRUD screens share the same rhythm:
 *
 *     index   → scope + filters + page → table
 *     create  → empty form with scoped options
 *     store   → validate → scope guard → uniqueness → insert → audit → PRG
 *     edit    → load through findInScope() (404 outside scope) → form
 *     update  → validate → scope guard → uniqueness → update → audit → PRG
 *     toggle  → archive/restore (MD-07, never a hard delete) → audit → PRG
 *
 * That rhythm lives here so each concrete controller only declares what is
 * genuinely different: its repository, its permission prefix, its rules and
 * its view. The Post/Redirect/Get redirect carries a status flag in the query
 * string (`?saved=created`), which is all the flash mechanism these screens
 * need — no session flash queue, no double-submit on refresh.
 */
abstract class MasterDataController extends Controller
{
    public function __construct(
        Request $request,
        View $view,
        Router $router,
        Config $config,
        protected readonly MasterDataService $masterData,
        protected readonly AuthorizationService $authorization,
        protected readonly AuditRepository $audit
    ) {
        parent::__construct($request, $view, $router, $config);
    }

    /**
     * The signed-in user's scope, built from the row the Authenticate
     * middleware resolved (never from request input).
     */
    protected function scope(Request $request): Scope
    {
        $user = $request->attribute('auth_user');

        if (!is_array($user)) {
            $this->abort(401, 'Sign in to continue.');
        }

        return Scope::forUser($user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function actor(?Request $request = null): array
    {
        $request ??= $this->request;
        $user = $request->attribute('auth_user');

        if (!is_array($user)) {
            $this->abort(401, 'Sign in to continue.');
        }

        return $user;
    }

    /**
     * Department options for a form/filter, already limited by scope.
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    protected function departmentOptions(Scope $scope): array
    {
        return $this->masterData->departmentOptions($scope);
    }

    /**
     * Reject a department that is outside the caller's scope.
     */
    protected function guardDepartment(Scope $scope, ?int $departmentId): int
    {
        return $this->masterData->assertDepartment($scope, $departmentId);
    }

    /**
     * Reject a duplicate code with a field-level 422.
     */
    protected function guardUnique(bool $exists, string $field, string $message): void
    {
        $this->masterData->assertUnique($exists, $field, $message);
    }

    /**
     * Append the change to the audit trail (SYS-02).
     *
     * @param array<string, mixed> $properties
     */
    protected function auditChange(
        Request $request,
        string $event,
        string $subjectType,
        int $subjectId,
        string $description,
        array $properties = []
    ): void {
        $actor = $this->actor($request);

        $this->audit->record(
            $event,
            (int) ($actor['id'] ?? 0) ?: null,
            $subjectType,
            $subjectId,
            $description,
            $properties,
            $request->ip(),
            $request->userAgent()
        );
    }

    /**
     * Redirect back to a list with a status the view can turn into a notice.
     *
     * @param array<string, string|int> $parameters Route placeholders (for nested pages).
     */
    protected function saved(string $routeName, string $status, array $parameters = []): Response
    {
        return Response::redirect(
            $this->router->url($routeName, $parameters) . '?saved=' . rawurlencode($status),
            303
        );
    }

    /**
     * Pagination state from the query string, clamped to sane bounds.
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    protected function pagination(Request $request, int $default = 25): array
    {
        $perPage = (int) $request->query('per_page', $default);
        $perPage = max(5, min(100, $perPage > 0 ? $perPage : $default));
        $page = max(1, (int) $request->query('page', 1));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    /**
     * Last page number for the pager.
     */
    protected function lastPage(int $total, int $perPage): int
    {
        return max(1, (int) ceil($total / $perPage));
    }

    /**
     * Keep the query string when building pager links.
     *
     * @param array<string, mixed> $query
     */
    protected function withQuery(string $url, array $query): string
    {
        $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0);

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /**
     * Load a record or answer 404.
     *
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     */
    protected function requireRecord(?array $record, string $message): array
    {
        if ($record === null) {
            $this->abort(404, $message);
        }

        return $record;
    }

    /**
     * Status notice key from `?saved=…`, whitelisted so an arbitrary query
     * string cannot become arbitrary output.
     */
    protected function notice(Request $request): ?string
    {
        $status = strtolower(trim((string) $request->query('saved', '')));

        return in_array($status, ['created', 'updated', 'archived', 'restored', 'current', 'window-added', 'window-removed'], true)
            ? $status
            : null;
    }
}
