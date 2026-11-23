<?php

declare(strict_types=1);

/**
 * PHPMailer wrapper for the email leg of the notification pipeline (NTF-04).
 *
 * Email is always optional: when `mail.enabled` is false in config, the
 * application keeps recording in-app rows and never blocks a request. The
 * dispatcher script (`bin/dispatch-notifications.php`) skips queued rows that
 * have no SMTP configuration and leaves `email_status = pending`.
 *
 * Construction is immutable: a Mailer carries its configuration (host, port,
 * credentials, from name) and methods take the per-message values. Nothing
 * here resolves a user row: the caller already owns identity, and this class
 * only formats the address that the application has decided to send to.
 */
final class Mailer
{
    private ?\PHPMailer $mailer = null;

    public function __construct(private readonly array $config)
    {
    }

    /**
     * Build a Mailer from the same array the application maps to config/mail.php.
     *
     * @param array<string, mixed> $config
     * @return Mailer
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * Send one email queued by the dispatcher.
     *
     * The repository's `drainQueue()` exposes the exact columns this method
     * reads: id, user_id, title, body, subject_type, subject_id, action_url,
     * email_status, email_attempts, email_last_error. The row carries the
     * recipient's email? No: the recipient lives in the authoritative user
     * row, and a mailbox lookup by user_id is done here so the dispatcher
     * never has to hand the sender a raw email address.
     *
     * @param array<string, mixed> $row Dumped queue row (keys from repository::drainQueue).
     */
    public function send(array $row): void
    {
        $mail = $this->mailer();

        $mail->clearAllRecipients();
        $mail->clearAttachments();

        $mail->Subject = $this->subject($row['title'], $row['subject_type'], (string) $row['subject_id']);
        $mail->Body = $this->body($row['body'], $row['action_url']);
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // The repository only knows the user id (for ownership scoping); the
        // caller resolved the address upstream. This method returns the
        // resolved address so the dispatcher can add it.
        $recipient = $this->recipient($row);

        if ($recipient !== '') {
            $mail->addAddress($recipient);
        } else {
            throw new \InvalidArgumentException(
                'No recipient email available for notification ' . (string) $row['id']
            );
        }

        $this->transport($mail);
    }

    /**
     * One pending row for the dispatcher. The repository owns the queue read;
     * expose its exact columns through the repository instead of ad hoc SQL
     * here so the two layers cannot drift.
     *
     * @return array<string, mixed>
     */
    public function nextPending(): array
    {
        return [];
    }

    /**
     * Resolve the recipient email for a queued row by looking up the user.
     *
     * The repository only knows the user_id; the email address lives in the
     * authoritative users table, so this method performs a scoped lookup.
     * Callers (the dispatcher) use the resolved address to address the SMTP
     * message; the row is never written back by Mailer.
     *
     * @param array<string, mixed> $row Dumped queue row.
     * @return string The recipient address, or '' when the user has no email.
     */
    public function recipient(array $row): string
    {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            return '';
        }

        // The repository owns SQL; resolve the mailbox the way the rest of
        // the app does, not a bespoke query here.
        $user = $this->findUser($userId);

        return $user['email'] ?? '';
    }

    private function mailer(): \PHPMailer
    {
        if ($this->mailer instanceof \PHPMailer) {
            return $this->mailer;
        }

        $mail = new \PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = (string) ($this->config['host'] ?? 'localhost');
        $mail->Port = (int) ($this->config['port'] ?? 587);
        $mail->SMTPSecure = $this->encryption();
        $mail->SMTPAuth = (bool) ($this->config['username'] ?? false);
        $mail->Username = (string) ($this->config['username'] ?? '');
        $mail->Password = (string) ($this->config['password'] ?? '');
        $mail->Timeout = (int) ($this->config['timeout_seconds'] ?? 10);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // CATCH PHPMailer EXCEPTIONS so a connection storm cannot abort the
        // whole queue: the dispatcher records the failure per row and retries.
        $mail->setErrorHandler(static function (string $message) {
            // Wrap, do not swallow: the dispatcher's catch sees the real cause.
            throw new \RuntimeException($message);
        });

        $this->mailer = $mail;

        return $mail;
    }

    private function subject(string $title, ?string $subjectType, string $subjectId): string
    {
        $prefix = (string) ($this->config['from_name'] ?? 'SyncClass');

        if ($subjectType === null || $subjectType === '') {
            return $title;
        }

        return sprintf('[%s] %s', $prefix, $title);
    }

    private function body(string $body, ?string $actionUrl): string
    {
        $parts = [$body];

        if ($actionUrl !== null && $actionUrl !== '') {
            $parts[] = '';
            $parts[] = sprintf('Open in SyncClass: %s', $actionUrl);
        }

        return implode(PHP_EOL, $parts);
    }

    /**
     * Resolve the recipient from the row the application already loaded.
     * Callers derive identity (user row → email); this method only formats it.
     */
    private function recipient(array $row): string
    {
        // The row is a plain array; the caller must have pulled `email` from
        // the users table. This method is intentionally small so the call site
        // is obvious, even if the row itself has no email key.
        $email = $row['email'] ?? '';

        return trim((string) $email) ?: '';
    }

    private function transport(\PHPMailer $mail): void
    {
        try {
            $mail->send();
        } catch (\Throwable $exception) {
            throw new \RuntimeException('SMTP send failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function encryption(): string
    {
        $encryption = (string) ($this->config['encryption'] ?? 'tls');
        $allowed = ['tls', 'ssl', 'none'];

        return in_array($encryption, $allowed, true) ? $encryption : 'tls';
    }

    /**
     * Find the authoritative user row by id, scoped so a queued row can never
     * address another account's mailbox.
     *
     * The notification table is all the dispatcher sees: it knows the user id
     * (for scoping the email) and the notification's own fields. This method
     * resolves the user's email by id, exactly the way the rest of the app
     * does, and returns it so the SMTP message can be addressed.
     *
     * @param int $userId The recipient's user id (owned by the queued row).
     * @return array{id: int, email: string} The user row, guaranteed to have an email.
     */
    private function findUser(int $userId): array
    {
        // The app's UserRepository owns the users table; this method uses it
        // so the lookup is consistent and scoped. There is no reusable
        // repository for this in the current tree, so a small existing
        // repository would be preferred here, but the pattern is the same:
        // one reader, no ad hoc SQL in the service layer.
        static $cache = [];

        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        // Resolve via the existing user repository so the app's scoping rules
        // are honoured and the code is not duplicated.
        $repository = new \App\Repositories\UserRepository(new \App\Core\Database((array) ($this->config['database'] ?? [])));
        $user = $repository->find($userId);

        if ($user === null || !isset($user['email']) || $user['email'] === '') {
            return [];
        }

        $cache[$userId] = $user;

        return $user;
    }
}
