<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * PHPMailer wrapper for the email leg of the notification pipeline (NTF-04).
 *
 * Email is optional. With `mail.enabled` false (the default) the application
 * keeps recording in-app notifications and never touches SMTP; the dispatcher
 * leaves queued rows pending. When SMTP *is* configured the dispatcher
 * (`bin/dispatch-notifications.php`) hands each queued row to `send()`.
 *
 * PHPMailer is a Composer suggestion, not a hard requirement: a deployment
 * that only wants in-app notifications does not need it, and `send()` fails
 * with an actionable message if the package is missing.
 */
final class Mailer
{
    private ?PHPMailer $mailer = null;

    /**
     * @param array<string, mixed> $config The `mail` config block.
     * @param Database $database Used only to resolve a recipient's address.
     */
    public function __construct(
        private readonly array $config,
        private readonly Database $database
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config, Database $database): self
    {
        return new self($config, $database);
    }

    /**
     * Send one queued notification row.
     *
     * @param array<string, mixed> $row A row from NotificationRepository::drainQueue().
     */
    public function send(array $row): void
    {
        $recipient = $this->recipient($row);

        if ($recipient === '') {
            throw new \RuntimeException(
                'No recipient email for notification ' . (string) ($row['id'] ?? '?')
            );
        }

        $mail = $this->mailer();
        $mail->clearAllRecipients();
        $mail->addAddress($recipient);

        $mail->Subject = $this->subject((string) ($row['title'] ?? 'SyncClass'));
        $mail->Body = $this->body((string) ($row['body'] ?? ''), $row['action_url'] ?? null);
        $mail->AltBody = $mail->Body;

        try {
            $mail->send();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'SMTP send failed for notification ' . (string) ($row['id'] ?? '?') . ': '
                . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Resolve the recipient address from the authoritative users row.
     *
     * The queue row carries only `user_id`; the mailbox is looked up here so a
     * queued payload can never address an account it does not belong to.
     *
     * @param array<string, mixed> $row
     */
    public function recipient(array $row): string
    {
        $userId = (int) ($row['user_id'] ?? 0);

        if ($userId <= 0) {
            return '';
        }

        $email = $this->database->scalar(
            'SELECT email FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $userId]
        );

        return is_string($email) ? trim($email) : '';
    }

    private function mailer(): PHPMailer
    {
        if ($this->mailer instanceof PHPMailer) {
            return $this->mailer;
        }

        if (!class_exists(PHPMailer::class)) {
            throw new \RuntimeException(
                'PHPMailer is not installed. Run `composer require phpmailer/phpmailer` '
                . 'or set mail.enabled to false to keep notifications in-app only.'
            );
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) ($this->config['host'] ?? 'localhost');
        $mail->Port = (int) ($this->config['port'] ?? 587);
        $mail->SMTPAuth = (string) ($this->config['username'] ?? '') !== '';
        $mail->Username = (string) ($this->config['username'] ?? '');
        $mail->Password = (string) ($this->config['password'] ?? '');
        $mail->Timeout = (int) ($this->config['timeout_seconds'] ?? 10);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $encryption = (string) ($this->config['encryption'] ?? 'tls');
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'none') {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $from = (string) ($this->config['from_address'] ?? 'no-reply@localhost');
        $mail->setFrom($from, (string) ($this->config['from_name'] ?? 'SyncClass'));

        $this->mailer = $mail;

        return $mail;
    }

    private function subject(string $title): string
    {
        $prefix = trim((string) ($this->config['from_name'] ?? 'SyncClass'));

        return $prefix === '' ? $title : '[' . $prefix . '] ' . $title;
    }

    private function body(string $body, mixed $actionUrl): string
    {
        $text = trim($body);

        if (is_string($actionUrl) && $actionUrl !== '') {
            $text .= PHP_EOL . PHP_EOL . 'Open in SyncClass: ' . $actionUrl;
        }

        return $text;
    }
}
