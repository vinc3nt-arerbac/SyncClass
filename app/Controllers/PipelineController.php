<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Validator;

/**
 * Pipeline probes for Phase 6 (removed once real modules exist).
 *
 * These two endpoints exist to make the middleware pipeline observable without
 * building business features early:
 *
 *   POST /pipeline/echo     requires a valid CSRF token (`csrf` middleware),
 *                           validates one field, and echoes what it received.
 *   GET  /pipeline/guarded  requires a session identity (`auth` middleware):
 *                           401 JSON for API clients, 302 to /login for a
 *                           browser — which is the exact behaviour Phase 7's
 *                           login page will complete.
 *
 * They are ordinary controllers: nothing here is special-cased in the Kernel.
 */
final class PipelineController extends Controller
{
    /**
     * CSRF-protected POST that echoes validated input as JSON.
     *
     * Validation failure returns the same JSON envelope the rest of the API
     * uses, so the probe doubles as a contract example for Phase 10+.
     */
    public function echo(Request $request): Response
    {
        $validator = Validator::make(
            $request->all(),
            ['message' => 'required|string|min:1|max:120'],
            ['message' => 'Message']
        );

        if ($validator->fails()) {
            return $this->json([
                'ok' => false,
                'status' => 422,
                'error' => [
                    'code' => 'UNPROCESSABLE_CONTENT',
                    'message' => 'Please correct the highlighted fields.',
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $data = $validator->validated();

        return $this->json([
            'ok' => true,
            'route' => (string) $request->attribute('route_name', 'pipeline.echo'),
            'middleware' => ['session', 'csrf'],
            'csrf' => 'verified',
            'message' => (string) $data['message'],
            'session_id' => substr((string) $request->attribute('session_id', ''), 0, 12),
            'received_at' => date('c'),
        ]);
    }

    /**
     * Reached only when the Authenticate middleware found a session identity.
     */
    public function guarded(Request $request): Response
    {
        return $this->json([
            'ok' => true,
            'auth_user_id' => $request->attribute('auth_user_id'),
            'message' => 'Authenticate middleware passed; Phase 7 will replace this probe.',
        ]);
    }
}
