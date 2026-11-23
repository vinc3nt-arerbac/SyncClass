<?php

declare(strict_types=1);

/**
 * NTF-04: Drain the pending email queue.
 *
 *     php bin/dispatch-notifications.php
 *
 * Open a separate process (cron / Task Scheduler / systemd timer) so a slow or
 * failing SMTP host cannot delay a web page response. The command is
 * idempotent: each row's `email_status` is moved from 'pending' to 'sent' or
 * 'failed' exactly once per call, and all rows are sent in publication order.
 *
 * Exit codes: 0 success (rows dispatched), 1 a configuration error.
 */
use App\Core\Config;
use App\Core\Database;
use App\Repositories\NotificationRepository;
use App\Services\Mailer;

$config = require dirname(__DIR__) . '/bootstrap.php';

$database = new Database((array) $config['database']);
$repository = new NotificationRepository($database);

// The queue only has work when SMTP is actually configured: the feature flag
// must be on and the host must not still be the shipped placeholder.
$enabled = (bool) ($config['mail']['enabled'] ?? false);
$host = trim((string) ($config['mail']['host'] ?? ''));
$hasSmtp = $enabled && $host !== '' && $host !== 'smtp.example.edu';

if (!$hasSmtp) {
    echo "SMTP is not configured (mail.enabled = false and no host set).\n" .
        "In-app notifications are still recorded; the email queue stays empty.\n";
    exit(0);
}

try {
    $queued = $repository->drainQueue();

    if ($queued === []) {
        echo 'No pending email notifications.' . PHP_EOL;
        exit(0);
    }

    echo 'Dispatching ' . count($queued) . ' notification(s) ...' . PHP_EOL;

    $sent = 0;
    $failed = 0;

    foreach ($queued as $row) {
        $attempts = (int) ($row['email_attempts'] ?? 0) + 1;

        // Every row in the queue has already been persisted with
        // `email_status = pending`; we only write back on success or final failure.
        try {
            Mailer::fromArray((array) $config['mail'], $database)->send($row);

            $repository->setEmailStatus((int) $row['id'], 'sent');

            $sent++;
        } catch (\Throwable $exception) {
            if ($attempts >= (int) ($config['mail']['max_attempts'] ?? 3)) {
                $repository->setEmailStatus((int) $row['id'], 'failed', $exception->getMessage());
                $failed++;
            } else {
                // Still pending: the dispatcher will retry on the next run.
                $repository->setEmailStatus((int) $row['id'], 'pending', $exception->getMessage());
            }
        }

        echo '  ' . $row['id'] . ' -> ' . ($sent > 0 ? 'sent' : 'pending') . PHP_EOL;
    }

    echo 'Done: ' . $sent . ' sent, ' . $failed . ' failed.' . PHP_EOL;
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Could not dispatch notifications: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
