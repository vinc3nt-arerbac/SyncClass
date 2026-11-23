<?php

/**
 * Pagination footer (Phase 9).
 *
 * @var callable(mixed): string $e
 * @var callable(int): string|null $page_url Builds a link to one page, keeping filters.
 * @var int $page
 * @var int $last_page
 * @var int $per_page
 * @var int $total
 */

// Controllers may hand in their own $page_url; when they do not, reuse the
// current path and query string and only swap the page number. That keeps
// every filter (search, status, building, sort…) on the Previous/Next links and
// means a new list screen cannot ship with dead pagination.
if (!isset($page_url) || !is_callable($page_url)) {
    $page_url = static function (int $target): string {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $query = $_GET;
        unset($query['page']);

        if ($target > 1) {
            $query['page'] = $target;
        }

        return $path . ($query === [] ? '' : '?' . http_build_query($query));
    };
}
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
