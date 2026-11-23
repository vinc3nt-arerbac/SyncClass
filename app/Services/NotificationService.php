<?php

declare(strict_types=1);

/**
 * The notification hub (NTF-01…NTF-04).
 *
 * Four responsibilities, each a method with a single job:
 *
 *   1. **Delivery.** `notify()` writes one in-app row and, when the channel is
 *      `email`/`both`, enqueues the SMTP packet. The actual send is async:
 *      `bin/dispatch-notifications.php` drains `email_status = pending`.
 *   2. **In-app reads.** `markAsRead(id)`, `markRead(until)`, `countUnread()`,
 *      `deleteForUser()` read the scoped inbox.
 *   3. **Scoping.** Every `user_id` is fixed at insert time; the controller
 *      never lets a view pick a recipient.
 *   4. **Audit.** Every payload is recorded in `activity_log` (SYS-02), so a
 *      deleted or orphaned account does not orphan the record of who was told.
 *
 * Called from: the schedule/comment/change-request/task workflows, the
 * NotificationController (list + mark read), and the CLI dispatcher.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly AuthorizationService $authorization,
        private readonly AuditRepository $audit,
        private readonly Config $config
    ) {
    }

    /** @param int|string|null $userId Set the recipient, never infer it. */
    public function notify(
        int|string|null $userId,
        string $type,
        string $title,
        ?string $body,
        string $channel = 'in_app',
        ?string $actionUrl = null
    ): void {
        $user = $this->userOrFail($userId);

        // The channel must be one the UI and the dispatcher agree on.
        if (!in_array($channel, NotificationRepository::CHANNELS, true)) {
            $channel = 'in_app';
        }

        $this->notifications->create(
            $user['id'],
            $type,
            $title,
            $body,
            null,
            null,
            $channel,
            $actionUrl
        );

        // Every known payload lands in the audit trail, so NTF rows are
        // traceable even if the account or the row is later removed.
        $this->audit->record(
            $type,
            $user['id'],
            'notification',
            $user['id'],
            $title,
            ['user_id' => $user['id'], 'type' => $type, 'channel' => $channel],
            $this->ip(),
            $this->userAgent()
        );
    }

    /** @param int|string|null $userId null = the current actor (web) or none (CLI). */
    public function markAsRead(int $id, int|string|null $userId): int
    {
        $user = $this->userOrFail($userId);

        return $this->notifications->markAsRead($id, $user['id']);
    }

    /** @param int|string|null $userId */
    public function markRead(int $userId, string $until): int
    {
        $user = $this->userOrFail($userId);

        return $this->notifications->markRead($user['id'], $until);
    }

    /** @param int|string|null $userId */
    public function countUnread(int $userId): int
    {
        $user = $this->userOrFail($userId);

        return $this->notifications->countUnread($user['id']);
    }

    /** @param int|string|null $userId */
    public function listForUser(int $userId, int $perPage = 50, int $page = 1): array
    {
        $user = $this->userOrFail($userId);

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->notifications->forUser($user['id'], $perPage, $offset);

        return [
            'rows' => $rows,
            'total' => $this->notifications->scalar(
                'SELECT COUNT(*) FROM notifications WHERE user_id = :uid',
                ['uid' => $user['id']]
            ),
            'unread' => $this->notifications->countUnread($user['id']),
        ];
    }

    /**
     * Push unread items ahead of the older-id marker so a long inbox does not
     * re-render from the bottom on every page load. Returns ids the caller can
     * hand to $row->deleteForUser() to prune the tail.
     *
     * @return array<int, int>
     */
    public function latestIds(int $userId, int $limit = 200): array
    {
        $user = $this->userOrFail($userId);

        $rows = $this->notifications->forUser(
            $user['id'],
            $limit,
            0
        );

        return array_column($rows, 'id');
    }

    /** @param int|string|null $userId */
    public function deleteForUser(int $userId, int $sinceId): int
    {
        $user = $this->userOrFail($userId);

        return $this->notifications->deleteForUser($user['id'], $sinceId);
    }

    /**
     * Drain the pending email queue into PHPMailer. Idempotent per call: each
     * row's `email_status` is set to 'pending' once and handled exactly once.
     * Returns the rows handed to the transport, keyed by row id.
     *
     * @return array<int, array{id: int, user_id: int, title: string, body: string, email_status: string, email_attempts: int, email_last_error: string|null}>
     */
    public function dispatchQueued(): array
    {
        $rows = $this->notifications->drainQueue();

        foreach ($rows as $row) {
            $ok = $this->sendSmtp($row);

            // Record the outcome so a persistent SMTP failure is visible.
            // The dispatcher drives retries: a row is only pending, sent, or
            // exhausted attempts and marked failed. This method records the
            // latest outcome so the row never stays stale.
            $this->notifications->setEmailStatus((int) $row['id'], $ok ? 'sent' : 'failed');
        }

        return $rows;
    }

    /**
     * One send attempt. Caller catches PHPMailer exceptions, so this wraps the
     * transport in try/catch and returns a plain boolean.
     */
    private function sendSmtp(array $row): bool
    {
        $attempts = (int) ($row['email_attempts'] ?? 0) + 1;

        // With no SMTP configuration the queue is dormant: the dispatcher
        // (bin/dispatch-notifications.php) skips pending rows and leaves
        // them for the next run, so a request that lacks mail credentials
        // never blocks on SMTP.
        if (!$this->mailerIsConfigured()) {
            return false;
        }

        // Resolve the deliverable address from the authoritative user row.
        // Mailer is a thin PHPMailer wrapper; it does not know identity.
        $recipient = $this->mailer->recipient($row);

        if ($recipient === '') {
            return false;
        }

        try {
            $this->mailer->send($row);

            return true;
        } catch (Throwable $exception) {
            // Keep the record of the failure; the dispatcher will retry.
            return false;
        }
    }

    private function userOrFail(int|string|null $userId): array
    {
        if ($userId === null) {
            return []; // CLI/yield contexts pass nothing; the repo decides.
        }

        // Web context: the authenticated row already came from the DB; we
        // validate it here so every caller gets the same guard.
        if (!is_array($userId)) {
            throw new InvalidArgumentException('Notification user must be an array row or null.');
        }

        if ((int) ($userId['id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Notification user id is required.');
        }

        return $userId;
    }

    private function ip(): ?string
    {
        return $this->config->get('request.ip', null);
    }

    private function userAgent(): ?string
    {
        return $this->config->get('request.user_agent', null);
    }

    private function mailerIsConfigured(): bool
    {
        $host = $this->config->get('mail.host', '');

        return $host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN);
    }

}
