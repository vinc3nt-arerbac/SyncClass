<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Append-only audit trail (SYS-02, NFR-11).
 *
 * Authentication events are recorded here: successful and failed sign-ins,
 * throttling, lockouts and sign-outs. Rows are never updated or deleted by the
 * application; `actor_id` is nullable so the trail survives account removal.
 *
 * Personal data is kept minimal on purpose: the email of a failed attempt goes
 * into `properties`, the IP and user agent into their columns, and nothing is
 * written to the log files that this table already holds.
 */
final class AuditRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $properties Small, non-secret context.
     */
    public function record(
        string $event,
        ?int $actorId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        array $properties = [],
        ?string $ip = null,
        ?string $userAgent = null
    ): int {
        return $this->database->insert('activity_log', [
            'actor_id' => $actorId,
            'event' => mb_substr($event, 0, 60),
            'subject_type' => $subjectType !== null ? mb_substr($subjectType, 0, 40) : null,
            'subject_id' => $subjectId,
            'description' => $description !== null ? mb_substr($description, 0, 500) : null,
            'properties' => $properties === [] ? null : json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }
}
