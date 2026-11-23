<?php

declare(strict_types=1);

/**
 * Notifications inbox (NTF-01).
 *
 * @var array<string, mixed> $rows          App\Services\NotificationService::listForUser().
 * @var int                  $total         Scoped count for the page.
 * @var int                  $unread        Rows the caller has not read.
 * @var int                  $page          Current page.
 * @var int                  $per_page      Rows per page (10–200, default 50).
 * @var int                  $last_page     ceil($total / $perPage), at least 1.
 * @var string               $notice        Optional 'created' / 'updated' / 'deleted' banner.
 * @var string               $csrf_meta     <meta name="csrf-token">.
 */
?>

<section class="card">
    <header class="card-head">
        <div>
            <h2>Notifications</h2>
            <p class="text-muted">
                <?= $unread > 0 ? 'You have ' . $unread . ' unread notification(s).' : 'All read.' ?>
            </p>
        </div>
        <?php if ($unread > 0): ?>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#markReadAllModal">
                Mark all as read
            </button>
        <?php endif; ?>
    </header>
    <div class="card-body">
        <?php if ($total === 0): ?>
            <div class="empty">
                <p class="text-muted">No notifications yet.</p>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($rows as $row): ?>
                    <div class="list-group-item <?= $row['is_read'] === 1 ? 'list-group-item-secondary' : 'list-group-item-action list-group-item-danger' ?>">
                        <div class="d-flex w-100 align-items-center justify-content-between">
                            <h6 class="mt-0 mb-1"><?= $e($row['title']) ?></h6>
                            <small class="text-muted"><?= $e($row['created_at']) ?></small>
                        </div>
                        <p class="mb-1 text-muted small"><?= $e($row['body'] ?? '') ?></p>
                        <div class="d-flex gap-2 gap-md-3 flex-wrap">
                            <?php if ($row['channel'] === 'email'): ?>
                                <span class="badge bg-info-subtle text-info-emphasis">email</span>
                            <?php endif; ?>
                            <?php if ($row['subject_type'] !== null && $row['subject_type'] !== ''): ?>
                                <span class="badge bg-secondary-subtle text-secondary"><?= $e($row['subject_type']) ?></span>
                            <?php endif; ?>
                            <?php if ($row['action_url'] !== null && $row['action_url'] !== ''): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= $e($route('notifications.index')) ?>">View</a>
                            <?php endif; ?>
                            <form method="post" action="<?= $e($route('notifications.destroy', ['id' => $row['id']])) ?>" class="d-inline">
                                <?= $csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Delete this notification?')">Delete</button>
                            </form>
                            <?php if ($row['is_read'] === 0): ?>
                                <form method="post" action="<?= $e($route('notifications.mark-as-read', ['id' => $row['id']])) ?>" class="d-inline">
                                    <?= $csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-success" type="submit">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($last_page > 1): ?>
                <nav class="d-flex justify-content-between align-items-center">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= $e($route('notifications.index', [
                            'page' => $page - 1,
                            'per_page' => $per_page,
                        ])) ?>">Previous</a>
                    <?php endif; ?>
                    <span>
                        <?= $page ?> / <?= $last_page ?>
                        <span class="text-muted">(<?= $total ?> items)</span>
                    </span>
                    <?php if ($page < $last_page): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= $e($route('notifications.index', [
                            'page' => $page + 1,
                            'per_page' => $per_page,
                        ])) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Mark all as read (modal) -->
<div class="modal fade" id="markReadAllModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= $e($route('notifications.mark-read-all')) ?>">
            <?= $csrf_field() ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Mark all as read</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    This will mark every notification in your inbox as read. This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit">Yes, mark all as read</button>
                </div>
            </div>
        </form>
    </div>
</div>
