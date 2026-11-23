<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\AuditRepository;
use App\Repositories\NotificationRepository;

/**
 * The notification hub (NTF-01…NTF-04).
 *
 * Responsibilities:
 *
 *   1. **Delivery.** `notify()` writes one in-app row. The email leg is queued
 *      in the same row (`email_status`), and `bin/dispatch-notifications.php`
 *      drains that queue in a separate process so a slow SMTP host can never
 *      delay a page response.
 *   2. **Scoped reads.** Every method takes the authenticated user id and the
 *      repository filters on `user_id`, so one account can never read or clear
 *      another's inbox.
 *   3. **Audit.** Each delivery is recorded in `activity_log` (SYS-02).
 *
 * The recipient is always chosen by the caller (the schedule, comment or task
 * workflow that knows who is affected); nothing here infers an audience.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly AuditRepository $audit,
        private readonly Config $config
    ) {
    }

    /**
     * Write one notification for one user.
     *
     * @param string $type One of NotificationRepository::TYPES.
     * @param string $channel One of NotificationRepository::CHANNELS.
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        string $channel = 'in_app',
        ?string $actionUrl = null
    ): int {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A notification needs a recipient.');
        }

        if (!in_array($channel, NotificationRepository::CHANNELS, true)) {
            $channel = 'in_app';
        }

        $id = $this->notifications->create(
            $userId,
            $type,
            $title,
            $body,
            null,
            null,
            $channel,
            $actionUrl
        );

        $this->audit->record(
            'notification.' . $type,
            $userId,
            'notification',
            $id,
            $title,
            ['channel' => $channel, 'recipient_id' => $userId]
        );

        return $id;
    }

    /**
     * One page of the user's inbox plus the counters the badge needs.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int, unread: int, page: int, per_page: int, last_page: int}
     */
    public function listForUser(int $userId, int $perPage = 50, int $page = 1): array
    {
        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);

        $total = $this->notifications->countForUser($userId);

        return [
            'rows' => $this->notifications->forUser($userId, $perPage, ($page - 1) * $perPage),
            'total' => $total,
            'unread' => $this->notifications->countUnread($userId),
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function countUnread(int $userId): int
    {
        return $this->notifications->countUnread($userId);
    }

    /** Mark one notification read; returns the number of rows changed (0 or 1). */
    public function markAsRead(int $id, int $userId): int
    {
        return $this->notifications->markAsRead($id, $userId);
    }

    /** Mark everything created up to `$until` read; returns the rows changed. */
    public function markRead(int $userId, string $until): int
    {
        return $this->notifications->markRead($userId, $until);
    }

    public function markReadAll(int $userId): int
    {
        return $this->notifications->markReadAll($userId);
    }

    /** Delete one notification the user owns. */
    public function delete(int $id, int $userId): int
    {
        return $this->notifications->delete($id, $userId);
    }

    /**
     * Whether the email leg can run at all. Rows are still recorded in-app when
     * this is false; the dispatcher simply leaves them pending.
     */
    public function emailConfigured(): bool
    {
        return (bool) $this->config->get('mail.enabled', false)
            && (string) $this->config->get('mail.host', '') !== '';
    }
}
