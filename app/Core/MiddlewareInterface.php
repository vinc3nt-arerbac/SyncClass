<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Every middleware in SyncClass implements this contract (Phase 6).
 *
 * One method, one responsibility: inspect the request, optionally enrich it,
 * then call $next or return a response of its own.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next The rest of the pipeline.
     */
    public function handle(Request $request, callable $next): Response;
}
