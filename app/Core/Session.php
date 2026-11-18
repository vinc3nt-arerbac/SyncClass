<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

/**
 * Hardened PHP session lifecycle (AUTH-04, AUTH-06, NFR-07).
 *
 * Wraps PHP's native session handling so that every request in the application
 * starts sessions the same way: a custom cookie name, HttpOnly, SameSite,
 * strict mode (no attacker-supplied session IDs), server-side storage outside
 * the web root, an idle timeout and an absolute lifetime.
 *
 * The class owns *policy*, not storage: PHP's files handler remains in charge
 * of persistence, which keeps the app compatible with shared hosting where
 * Redis or database session handlers are unavailable.
 *
 * Usage (Phase 6 middleware):
 *
 *     $session = new Session($config['app']['session']);
 *     $session->start();
 *     if ($session->expiredReason() !== null) {
 *         // redirect to /login with "your session expired"
 *     }
 *
 * A clock may be injected for deterministic tests; production code omits it.
 */
final class Session
{
    /**
     * Session key holding the lifetime bookkeeping array. Public so audit and
     * diagnostics code can read it without duplicating the literal.
     */
    public const META_KEY = '_syncclass_meta';

    /** @var array<string, mixed> */
    private array $config;

    private bool $started = false;

    private ?string $expiredReason = null;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param array<string, mixed> $config Session block from config/app.php.
     * @param (Closure(): int)|null $clock Returns a Unix timestamp.
     */
    public function __construct(array $config = [], ?Closure $clock = null)
    {
        $this->config = array_replace([
            'name' => 'syncclass_session',
            'lifetime_minutes' => 480,
            'idle_timeout_minutes' => 30,
            'cookie_path' => '/',
            'cookie_domain' => '',
            'same_site' => 'Lax',
            'secure' => false,
            'http_only' => true,
            'save_path' => null,
            'bind_user_agent' => true,
        ], $config);

        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Start the session once, applying cookie/ini policy first.
     *
     * Safe to call on every request: repeated calls are no-ops. Returns true
     * once a session is active; an id that cannot be started is a fatal
     * environment problem (unwritable save path, headers already sent), so it
     * throws rather than silently running without session state.
     */
    public function start(): bool
    {
        if ($this->started && PHP_SESSION_ACTIVE === session_status()) {
            return true;
        }

        if (PHP_SESSION_ACTIVE !== session_status()) {
            $this->configureIni();

            if (!@session_start()) {
                throw new RuntimeException(
                    'The PHP session could not be started. Check that storage/sessions is writable '
                    . 'and that no output is sent before the session middleware runs.'
                );
            }
        }

        $this->started = true;
        $this->checkTimeouts();

        return true;
    }

    /**
     * Enforce the idle and absolute timeouts and refresh "last seen".
     *
     * Called automatically by start(); exposed so the Phase 6 middleware can
     * re-check on long-running requests (exports, generator runs) without
     * restarting the session. Returns the expiry reason, if any.
     *
     * Expiry does not destroy the session outright: it regenerates the ID,
     * clears all data and keeps the session usable so the middleware can put
     * a "session expired" flash message in it and redirect to the login page.
     */
    public function checkTimeouts(): ?string
    {
        if (!$this->isStarted()) {
            return null;
        }

        $meta = $_SESSION[self::META_KEY] ?? null;

        // First request after login (or a freshly cleared session).
        if (!is_array($meta) || !isset($meta['created_at'], $meta['last_seen'])) {
            $this->initialiseMeta();

            return null;
        }

        $now = $this->now();

        // Session hijacking mitigation: a session cookie replayed from a
        // different browser/profile is discarded. Configurable because some
        // environments (corporate proxies) rewrite User-Agent.
        if ((bool) $this->config['bind_user_agent']
            && !hash_equals((string) ($meta['ua'] ?? ''), $this->fingerprint())
        ) {
            return $this->expire('agent');
        }

        $idleMinutes = (int) $this->config['idle_timeout_minutes'];
        if ($idleMinutes > 0 && ($now - (int) $meta['last_seen']) > $idleMinutes * 60) {
            return $this->expire('idle');
        }

        $absoluteMinutes = (int) $this->config['lifetime_minutes'];
        if ($absoluteMinutes > 0 && ($now - (int) $meta['created_at']) > $absoluteMinutes * 60) {
            return $this->expire('absolute');
        }

        $_SESSION[self::META_KEY]['last_seen'] = $now;

        return null;
    }

    /**
     * Regenerate the session ID, preserving data by default.
     *
     * Call with $deleteOld = true on login and on privilege changes (AUTH-03);
     * the old ID then becomes unusable immediately.
     */
    public function regenerate(bool $deleteOld = true): bool
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            return false;
        }

        return session_regenerate_id($deleteOld);
    }

    /**
     * Destroy the session server-side and expire the cookie (AUTH-04).
     */
    public function destroy(): void
    {
        if (PHP_SESSION_ACTIVE !== session_status()) {
            $this->started = false;

            return;
        }

        $_SESSION = [];

        if (filter_var(ini_get('session.use_cookies'), FILTER_VALIDATE_BOOL)) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => $this->now() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        $this->started = false;
    }

    /**
     * Read a value from the session.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Write a value to the session.
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    /**
     * Remove a key and optionally report its previous value.
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Read a value and remove it in one step (one-time flash data).
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $_SESSION;
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function isStarted(): bool
    {
        return $this->started && PHP_SESSION_ACTIVE === session_status();
    }

    public function id(): string
    {
        return session_id() ?: '';
    }

    /**
     * Why the previous request's session was discarded: idle | absolute |
     * agent. Null when the session is healthy.
     */
    public function expiredReason(): ?string
    {
        return $this->expiredReason;
    }

    /**
     * Discard all session state and start over with a fresh ID.
     */
    private function expire(string $reason): string
    {
        if (PHP_SESSION_ACTIVE === session_status()) {
            session_regenerate_id(true);
        }

        $_SESSION = [];
        $this->expiredReason = $reason;
        $this->initialiseMeta();

        return $reason;
    }

    private function initialiseMeta(): void
    {
        $now = $this->now();

        $_SESSION[self::META_KEY] = [
            'created_at' => $now,
            'last_seen' => $now,
            'ua' => $this->fingerprint(),
        ];
    }

    /**
     * Non-reversible browser fingerprint used only for binding, never as a
     * secret. The cookie/session ID remains the only credential.
     */
    private function fingerprint(): string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return hash('sha256', (string) $agent);
    }

    /**
     * Apply every hardening directive *before* session_start().
     */
    private function configureIni(): void
    {
        $secure = (bool) $this->config['secure'];
        $sameSite = ucfirst(strtolower((string) $this->config['same_site']));

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        // Browsers reject SameSite=None without Secure, so downgrade instead
        // of silently losing the session cookie over plain HTTP in dev.
        if ($sameSite === 'None' && !$secure) {
            $sameSite = 'Lax';
        }

        ini_set('session.use_strict_mode', '1');   // reject unknown session IDs
        ini_set('session.use_only_cookies', '1');  // no SID in URLs
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', (bool) $this->config['http_only'] ? '1' : '0');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', $sameSite);
        ini_set('session.gc_maxlifetime', (string) max(60, ((int) $this->config['lifetime_minutes']) * 60));
        ini_set('session.cache_limiter', 'nocache');

        session_set_cookie_params([
            // A browser-session cookie; server-side meta enforces the real
            // absolute lifetime (AUTH-06) so a copied cookie cannot live
            // beyond 8 hours.
            'lifetime' => 0,
            'path' => (string) $this->config['cookie_path'],
            'domain' => (string) $this->config['cookie_domain'],
            'secure' => $secure,
            'httponly' => (bool) $this->config['http_only'],
            'samesite' => $sameSite,
        ]);

        session_name((string) $this->config['name']);

        $savePath = $this->resolveSavePath();
        if ($savePath !== null) {
            session_save_path($savePath);
        }
    }

    /**
     * Prefer storage/sessions (denied by .htaccess) over the host's shared
     * temp directory. Falls back to the system path when the directory cannot
     * be created or written; the application keeps working.
     */
    private function resolveSavePath(): ?string
    {
        $configured = $this->config['save_path'] ?? null;

        if (is_string($configured) && $configured !== '') {
            $path = $configured;
        } elseif (defined('STORAGE_PATH')) {
            $path = STORAGE_PATH . '/sessions';
        } else {
            return null;
        }

        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
            return null;
        }

        return is_writable($path) ? $path : null;
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
