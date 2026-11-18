<?php

declare(strict_types=1);

use App\Core\Html;

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output (Phase 6).
     *
     * A convenience wrapper so application code and tests can escape without
     * reaching into a service. Views additionally receive a `$e` closure bound
     * to the same implementation, which is the preferred form inside
     * templates because it needs no import.
     *
     * This is *the* escaping function: Security::escape() delegates to
     * Html::escape(), and so does this.
     */
    function e(mixed $value): string
    {
        return Html::escape($value);
    }
}
