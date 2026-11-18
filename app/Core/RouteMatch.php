<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The result of a successful route match: which route, and the decoded
 * placeholders from the path (Phase 6).
 *
 * Kept as its own object rather than a tuple so the Kernel, the middleware
 * pipeline and tests all read the same two things by name.
 */
final class RouteMatch
{
    /**
     * @param array<string, string> $parameters Route placeholder values.
     */
    public function __construct(
        private readonly Route $route,
        private readonly array $parameters = []
    ) {
    }

    public function route(): Route
    {
        return $this->route;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
