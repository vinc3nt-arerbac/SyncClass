<?php

declare(strict_types=1);

/**
 * Notifications (NTF-01…NTF-04).
 *
 * One row per delivered payload: an in-app badge plus, when the channel says
 * so, an SMTP row parallel to it. The service owns the in-app reads and the
 * email packet; the repository owns SQL.
 *
 * Scope: every read is `user_id = ?` guarded upstream by the authenticated
 * actor (deny by default, see AuthorizationService). Nothing in this class is
 * public scope logic — scope is applied at the HTTP layer.
 */
final class NotificationRepository extends MasterDataRepository
{
    public const TYPES = [
        'schedule.created',
        'schedule.updated',
        'schedule.cancelled',
        'schedule.conflict',
        'schedule.approved',
        'schedule.rejected',
        'change_request.created',
        'change_request.approved',
        'change_request.rejected',
        'change_request.cancelled',
        'task.assigned',
        'task.due',
        'task.completed',
        'mention',
        'system.general',
    ];

    public const SUMMARY = ['unread', 'new', 'all'];

    /** @var array<int, string> */
    public const CHANNELS = ['in_app', 'email', 'both'];

    private const SELECT = <<<'SQL'
        SELECT n.id, n.user_id, n.type, n.title, n.body, n.subject_type,
               n.subject_id, n.action_url, n.channel, n.is_read, n.read_at,
               n.email_status, n.email_attempts, n.email_last_error,
               n.sent_at, n.created_at
          FROM notifications n
         WHERE n.user_id = :user_id
         ORDER BY n.created_at DESC
         LIMIT :limit OFFSET :offset
    SQL;

    private const COUNT = <<<'SQL'
        SELECT COUNT(*) FROM notifications WHERE user_id = :user_id
    SQL;

    public function __construct(private readonly Database $database)
    {
    }

    /** @return array{id: int, user_id: int, type: string, title: string, body: string|null, subject_type: string|null, subject_id: int|null, action_url: string|null, channel: string, is_read: int, read_at: string|null, email_status: string, email_attempts: int, email_last_error: string|null, sent_at: string|null, created_at: string} */
    public function forUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->database->select(self::SELECT, [
            'user_id' => $userId,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return $rows;
    }

    /** @return array<int, array{id: int, unread: int}> */
    public function userSummary(int $userId): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->database->select(
            'SELECT '
            . '(SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0) AS unread, '
            . '(SELECT COUNT(*) FROM notifications WHERE user_id = :uid) AS all_count',
            ['uid' => $userId]
        );
    }

    /**
     * Mark the latest page of rows read. `older_than` limits it to rows that are
     * older than a given timestamp so a click-to-read jump does not clear the
     * whole inbox in a long list. Returns the count actually updated.
     */
    public function markRead(int $userId, string $until): int
    {
        return $this->database->update(
            'notifications',
            ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            'user_id = :uid AND is_read = 0 AND created_at <= :until',
            ['uid' => $userId, 'until' => $until]
        );
    }

    /** @return array<int, int> */
    public function markReadAll(int $userId): array
    {
        return $this->database->update(
            'notifications',
            ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            'user_id = :uid AND is_read = 0',
            ['uid' => $userId]
        );
    }

    public function countUnread(int $userId): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0',
            ['uid' => $userId]
        );
    }

    public function markAsRead(int $id, int $userId): int
    {
        return $this->database->update(
            'notifications',
            ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            'id = :id AND user_id = :uid',
            ['id' => $id, 'uid' => $userId]
        );
    }

    /** @param array<string, mixed> $values */
    public function create(int $userId, string $type, string $title, ?string $body, ?string $subjectType, ?int $subjectId, string $channel, ?string $actionUrl): int
    {
        return $this->database->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action_url' => $actionUrl,
            'channel' => $channel,
            'is_read' => 0,
            'email_status' => 'none',
            'email_attempts' => 0,
            'email_last_error' => null,
            'sent_at' => null,
        ]);
    }

    /**
     * Drain the email queue (console command). Returns the number of rows
     * that were dispatched, whether each send succeeded per rows.
     *
     * @return array<int, array{id: int, email_status: string, sent_at: string|null, error: string|null}>
     */
    public function drainQueue(): array
    {
        return $this->database->select(
            'SELECT id, user_id, title, body, subject_type, subject_id, action_url, email_status, email_attempts, email_last_error '
            . 'FROM notifications '
            . 'WHERE email_status = :status '
            . 'ORDER BY created_at ASC '
            . 'LIMIT 50',
            ['status' => 'pending']
        );
    }

    /**
     * Mark a single email as sent, failed, or left pending after retries.
     *
     * Callers pass back the live PDO error from PHPMailer when it is present;
     * we only store the first 500 characters.
     */
    public function setEmailStatus(int $id, string $status, ?string $error = null): int
    {
        $error = $error !== null ? mb_substr(trim($error), 0, 500) : null;

        return $this->database->update(
            'notifications',
            [
                'email_status' => $status,
                'email_last_error' => $error,
                'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ],
            'id = :id',
            ['id' => $id]
        );
    }

    public function deleteForUser(int $userId, int $sinceId): int
    {
        return $this->database->delete(
            'notifications',
            'user_id = :uid AND id < :since_id',
            ['uid' => $userId, 'since_id' => $sinceId]
        );
    }
}
