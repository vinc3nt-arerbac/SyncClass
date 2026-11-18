<?php

declare(strict_types=1);

namespace App\Core;

use Stringable;

/**
 * Output encoding (XSS prevention, NFR-07).
 *
 * There is exactly one escaping implementation in the codebase and this is it.
 * App\Core\Security::escape() delegates here (so the Phase 5 public API is
 * unchanged) and App\Helpers\e() is a thin function wrapper for views and
 * controllers.
 *
 * Encoding is unconditional: values are escaped whether or not they "look
 * dangerous". That is what makes the rule reviewable — every dynamic value in
 * a template is printed through e() or $e().
 */
final class Html
{
    /**
     * Escape a value for HTML text or a quoted attribute.
     *
     * Arrays and arbitrary objects return an empty string instead of "Array":
     * a blank cell in the UI is a visible bug, a silently stringified array is
     * a hidden one. Stringable objects are accepted because domain value
     * objects (TimeRange, TermRef) are meant to be printed.
     */
    public static function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (!is_scalar($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Build an attribute string from a map, skipping null/false values.
     *
     * Used by partials that need conditional attributes (disabled, checked)
     * without scattering ternaries through the markup. Values are escaped.
     *
     * @param array<string, string|int|float|bool|null> $attributes
     */
    public static function attributes(array $attributes): string
    {
        $rendered = [];

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $rendered[] = $value === true
                ? $name
                : $name . '="' . self::escape($value) . '"';
        }

        return $rendered === [] ? '' : ' ' . implode(' ', $rendered);
    }
}
