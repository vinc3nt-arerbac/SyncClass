<?php

/**
 * Pagination footer (Phase 9).
 *
 * @var callable(mixed): string $e
 * @var callable(int): string $page_url Builds a link to one page, keeping filters.
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var int $total
 */
?>
<nav class="pager" aria-label="Pagination">
    <?php if ($page > 1): ?>
        <a class="button button-ghost" href="<?= $e($page_url($page - 1)) ?>">Previous</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>

    <span>
        <?= $e($total) ?> record<?= $total === 1 ? '' : 's' ?> ·
        page <?= $e($page) ?> of <?= $e($last_page) ?> ·
        <?= $e($per_page) ?> per page
    </span>

    <?php if ($page < $last_page): ?>
        <a class="button button-ghost" href="<?= $e($page_url($page + 1)) ?>">Next</a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</nav>
