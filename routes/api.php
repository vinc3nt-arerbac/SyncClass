<?php

declare(strict_types=1);

/** Web API routes (Phase 6+). */
return static function (Router $router, Container $container): void {
    // Generator endpoint (GEN-01…06): deterministic plan + commit.
    $router->post('/generator/preview', [GeneratorController::class, 'preview'], ['auth', 'permission:generator.run'], 'Generator draft')
        ->name('generator.preview');

    $router->post('/generator/commit', [GeneratorController::class, 'commit'], ['auth', 'permission:generator.approve'], 'Commit generated schedule')
        ->name('generator.commit');
};
