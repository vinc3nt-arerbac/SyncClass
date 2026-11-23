<?php

declare(strict_types=1);

/**
 * Compact unread count for the dashboard widget (DASH-01, NTF-04).
 *
 * @var array<int, array<string, mixed>> $rows
 * @var int                            $unread
 */
?>

<div class="card notifications-summary">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
            <h6 class="card-title mb-0">
                <span class="badge bg-secondary"><?= $unread ?></span>
                Notifications
            </h6>
            <a class="small text-decoration-none" href="<?= $e($route('notifications.index')) ?>">View all</a>
        </div>
        <?php if ($rows): ?>
            <ul class="list-group list-group-flush list-group-numbered mb-0">
                <?php foreach ($rows as $row): ?>
                    <li class="list-group-item py-2 d-flex justify-content-between gap-2">
                        <span class="text-truncate" title="<?= $e($row['title']) ?>"><?= $e($row['title']) ?></span>
                        <small class="text-muted"><?= $e($row['created_at']) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-muted mb-0 mt-2">No notifications.</p>
        <?php endif; ?>
    </div>
</div>
