<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Remember-me tokens (AUTH-05).
 *
 * The table stores a selector (indexed lookup, CHAR(24)) and a SHA-256 hash of
 * the validator. The validator itself only exists in the user's cookie, so a
 * database leak does not hand an attacker a working session.
 *
 * Tokens are single-use: RememberMeService deletes the row and issues a new
 * pair on every successful automatic sign-in.
 */
final class RememberTokenRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param string $expiresAt 'Y-m-d H:i:s'
     */
    public function create(
        int $userId,
        string $selector,
        string $validatorHash,
        string $expiresAt,
        string $ip,
        string $userAgent
    ): int {
        return $this->database->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => $validatorHash,
            'expires_at' => $expiresAt,
            'ip_address' => $ip !== '' ? $ip : null,
            'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySelector(string $selector): ?array
    {
        return $this->database->selectOne(
            'SELECT id, user_id, selector, validator_hash, expires_at, last_used_at
             FROM remember_tokens WHERE selector = :selector LIMIT 1',
            ['selector' => $selector]
        );
    }

    public function deleteBySelector(string $selector): int
    {
        return $this->database->delete('remember_tokens', 'selector = :selector', ['selector' => $selector]);
    }

    /**
     * Invalidate every remembered browser for a user (password change, theft
     * detection, account deactivation).
     */
    public function deleteForUser(int $userId): int
    {
        return $this->database->delete('remember_tokens', 'user_id = :user_id', ['user_id' => $userId]);
    }

    /**
     * Housekeeping for the cron job: expired rows can never authenticate.
     */
    public function purgeExpired(string $before): int
    {
        return $this->database->delete('remember_tokens', 'expires_at < :before', ['before' => $before]);
    }
}
