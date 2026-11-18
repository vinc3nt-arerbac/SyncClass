<?php

declare(strict_types=1);

/**
 * Scheduling policy defaults (BR-06…BR-08, GEN-02…GEN-05).
 *
 * These are the fallbacks used when the `settings` table has no override (SYS-01).
 * Times are 24-hour `H:i` strings; weekday numbers follow ISO-8601 (1 = Monday).
 */

return [
    'days' => [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ],

    // BR-08: no session may start or end outside this window.
    'allowed_hours' => [
        'start' => '07:00',
        'end' => '19:00',
    ],

    // BR-08: sessions may not overlap a break period.
    'breaks' => [
        ['label' => 'Lunch break', 'start' => '12:00', 'end' => '13:00'],
    ],

    'slot_minutes' => 30,
    'min_duration_minutes' => 30,
    'max_duration_minutes' => 240,

    // BR-07 fallback when a faculty profile has no explicit cap.
    'default_max_load_units' => 18.0,

    // BR-06: above this ratio the room is "nearly full" (warning, not error).
    'capacity_warning_ratio' => 0.9,

    // ADR-06: deterministic generator with bounded search.
    'generator' => [
        'node_budget' => 200000,
        'time_budget_seconds' => 30,
        'restarts' => 5,
        'seed' => null, // random per run, recorded with the draft
    ],
];
