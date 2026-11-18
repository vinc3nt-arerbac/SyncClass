<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain-PHP template renderer (Phase 6).
 *
 * There is no template language: a view is a .php file that receives
 * variables and prints them through $e(). Templates are ordinary code, which
 * means the escaping rule is visible in review and the IDE understands them.
 *
 * Every render exposes exactly these helpers:
 *
 *   $e($value)                  escaped output (App\Core\Html::escape)
 *   $asset('css/app.css')       URL under assets/, prefix-aware
 *   $route('name', [...])       URL for a named route
 *   $security                   the Security service (csrfField, nonce, …)
 *   $csrf_meta                  <meta name="csrf-token">, '' without a session
 *   $csrf_field()               hidden input for forms
 *
 * Layouts receive the rendered template as $content.
 */
final class View
{
    /** @var array<string, mixed> Data shared with every view (app name, nav counts…). */
    private array $shared = [];

    public function __construct(
        private readonly string $viewsPath,
        private readonly Security $security,
        private readonly Session $session,
        private readonly Router $router,
        private readonly string $assetBase = '/assets'
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function shareMany(array $data): void
    {
        $this->shared = array_replace($this->shared, $data);
    }

    public function exists(string $template): bool
    {
        return is_file($this->path($template));
    }

    /**
     * Render a template, optionally wrapped in a layout.
     *
     * @param string               $template Dot or slash notation: 'home/index' or 'home/index.php'.
     * @param array<string, mixed> $data
     * @param string|null          $layout   Layout template, null for a bare fragment (JSON islands, partials).
     */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $scope = array_replace($this->shared, $data);

        $scope['e'] = static fn (mixed $value): string => Html::escape($value);
        $scope['asset'] = fn (string $path): string => $this->assetBase . '/' . ltrim($path, '/');
        $scope['route'] = fn (string $name, array $parameters = []): string => $this->router->url($name, $parameters);
        $scope['security'] = $this->security;
        $scope['csrf_meta'] = $this->session->isStarted() ? $this->security->csrfMeta() : '';
        $scope['csrf_field'] = fn (): string => $this->security->csrfField();

        $content = $this->evaluate($template, $scope);

        if ($layout === null) {
            return $content;
        }

        $scope['content'] = $content;

        return $this->evaluate($layout, $scope);
    }

    /**
     * Include a template in an isolated output buffer.
     *
     * A template that throws must not leave a half-rendered buffer behind —
     * the Kernel would otherwise append an error page to partial output.
     *
     * @param array<string, mixed> $scope
     */
    private function evaluate(string $template, array $scope): string
    {
        $file = $this->path($template);
        $level = ob_get_level();

        ob_start();

        try {
            extract($scope, EXTR_SKIP);
            require $file;
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    private function path(string $template): string
    {
        $relative = str_replace(['.', '\\'], '/', $template);
        $relative = preg_replace('/\.php$/', '', (string) $relative);
        $file = rtrim($this->viewsPath, '/') . '/' . ltrim((string) $relative, '/') . '.php';

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('View "%s" was not found (looked for %s).', $template, $file));
        }

        return $file;
    }
}
