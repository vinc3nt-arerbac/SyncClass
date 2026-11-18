<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Middleware onion (Phase 6).
 *
 * Building the pipeline is a fold over the middleware list from the inside
 * out, so the first registered middleware is the outermost one:
 *
 *     (new Pipeline($container))
 *         ->send($request)
 *         ->through([StartSession::class, VerifyCsrfToken::class])
 *         ->then(fn (Request $r): Response => $controller->index($r));
 *
 * Each middleware receives the Request (possibly enriched by the ones before
 * it) and the next callable. A middleware may return a Response without
 * calling $next — that is how CSRF rejection and the login redirect
 * short-circuit the request.
 */
final class Pipeline
{
    private ?Request $request = null;

    /** @var array<int, string|MiddlewareInterface> */
    private array $middleware = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function send(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * @param array<int, string|MiddlewareInterface> $middleware
     */
    public function through(array $middleware): self
    {
        $this->middleware = array_values($middleware);

        return $this;
    }

    /**
     * Run the pipeline and return the destination's response.
     *
     * @param callable(Request): Response $destination
     */
    public function then(callable $destination): Response
    {
        if ($this->request === null) {
            throw new RuntimeException('Pipeline::send() must be called before then().');
        }

        $carry = array_reduce(
            array_reverse($this->middleware),
            function (callable $next, mixed $middleware): callable {
                return function (Request $request) use ($middleware, $next): Response {
                    return $this->resolve($middleware)->handle($request, $next);
                };
            },
            static function (Request $request) use ($destination): Response {
                $response = $destination($request);

                if (!$response instanceof Response) {
                    throw new RuntimeException(sprintf('A pipeline destination must return %s, %s returned.', Response::class, get_debug_type($response)));
                }

                return $response;
            }
        );

        return $carry($this->request);
    }

    /**
     * Middleware may be given as an alias resolved by the Kernel (already
     * expanded) or as an object; both end up implementing the interface.
     */
    private function resolve(mixed $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (is_string($middleware)) {
            $resolved = $this->container->get($middleware);

            if ($resolved instanceof MiddlewareInterface) {
                return $resolved;
            }
        }

        throw new RuntimeException(sprintf(
            'Middleware must implement %s; got %s.',
            MiddlewareInterface::class,
            is_string($middleware) ? $middleware : get_debug_type($middleware)
        ));
    }
}
