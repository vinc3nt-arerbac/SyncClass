<?php

/**
 * Post/Redirect/Get notice (Phase 9).
 *
 * The status comes from `?saved=…`, already whitelisted by the controller, so
 * this file only maps it to a human sentence.
 *
 * @var callable(mixed): string $e
 * @var string|null $notice
 */

$messages = [
    'created' => 'Saved. The new record is in the list below.',
    'updated' => 'Changes saved.',
    'archived' => 'Archived. The record is kept for history and can be restored.',
    'restored' => 'Restored. The record is active again.',
    'current' => 'Updated the current term.',
    'window-added' => 'Availability window added.',
    'window-removed' => 'Availability window removed.',
];

if (($notice ?? null) !== null && isset($messages[$notice])): ?>
    <p class="alert alert-ok"><?= $e($messages[$notice]) ?></p>
<?php endif; ?>
