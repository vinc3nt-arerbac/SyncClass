<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use LogicException;
use RuntimeException;

/**
 * Security primitives used by every layer (Phase 5, NFR-07).
 *
 * This class is deliberately a thin, explicit toolkit rather than a framework
 * "security manager": password hashing, token generation, CSRF tokens, output
 * escaping, JSON embedding and response headers. Business rules (throttling,
 * remember-me rotation, RBAC) live in the Phase 7/8 services that call these
 * primitives.
 *
 * No method here accepts or returns a secret through configuration: the only
 * secrets are user passwords and the session, both handled by PHP itself.
 */
final class Security
{
    /**
     * Session key holding the CSRF token state. Public so middleware and tests
     * can inspect expiry without duplicating the literal.
     */
    public const CSRF_SESSION_KEY = '_syncclass_csrf';

    /**
     * Defaults mirroring config/security.php. Keeping them here means the
     * class is safe to instantiate in a test or CLI script with no config.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'password' => [
            'algorithm' => 'default',
            'options' => ['cost' => 12],
            'argon2_options' => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2],
            'min_length' => 10,
            'max_length' => 72,
            'require_mixed_case' => true,
            'require_number' => true,
            'require_symbol' => false,
            'rehash_on_login' => true,
            'remember_token_days' => 30,
        ],
        'csrf' => [
            'token_bytes' => 32,
            'lifetime_minutes' => 120,
            'field_name' => '_token',
            'header_name' => 'X-CSRF-Token',
        ],
        'headers' => [
            'frame_options' => 'SAMEORIGIN',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'content_security_policy' => "default-src 'self'; base-uri 'self'; object-src 'none'; "
                . "frame-ancestors 'self'; form-action 'self'; img-src 'self' data:; "
                . "font-src 'self' data:; style-src 'self' 'unsafe-inline'; "
                . "script-src 'self' 'nonce-{nonce}'; connect-src 'self'",
            'csp_report_only' => false,
            'hsts_max_age' => 31536000,
            'hsts_include_subdomains' => true,
            'hsts_preload' => false,
        ],
    ];

    /** @var array<string, mixed> */
    private array $config;

    private Session $session;

    /** @var Closure(): int */
    private Closure $clock;

    private ?string $nonce = null;

    /**
     * Cached hash used to equalise login timing when an email does not exist.
     * Generated with the *current* algorithm/options so the dummy verification
     * costs the same as a real one.
     */
    private static ?string $dummyHash = null;

    /**
     * @param array<string, mixed> $config Security configuration (config/security.php).
     * @param (Closure(): int)|null $clock  Injected for deterministic CSRF tests.
     */
    public function __construct(Session $session, array $config = [], ?Closure $clock = null)
    {
        $this->session = $session;
        $this->config = array_replace_recursive(self::DEFAULTS, $config);
        $this->clock = $clock ?? static fn (): int => time();
    }

    // -----------------------------------------------------------------------
    // Password hashing (AUTH-02)
    // -----------------------------------------------------------------------

    /**
     * Hash a plaintext password with the configured algorithm.
     *
     * The plaintext is never logged, never stored and never included in an
     * exception message.
     */
    public function hashPassword(string $plain): string
    {
        $hash = password_hash($plain, $this->passwordAlgorithm(), $this->passwordOptions());

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hashing failed; check the configured algorithm and options.');
        }

        return $hash;
    }

    /**
     * Verify a plaintext password against a stored hash.
     *
     * Returns false — not an exception — for empty or malformed hashes so a
     * corrupted row can never authenticate somebody.
     */
    public function verifyPassword(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * Perform a dummy verification so a login attempt for a non-existent email
     * takes as long as one for an existing account (AUTH-03: no user
     * enumeration through timing). Phase 7 calls this when the lookup misses.
     */
    public function verifyDummyPassword(string $plain): void
    {
        self::$dummyHash ??= (string) password_hash(
            bin2hex(random_bytes(16)),
            $this->passwordAlgorithm(),
            $this->passwordOptions()
        );

        password_verify($plain, self::$dummyHash);
    }

    /**
     * True when a stored hash was produced with weaker parameters than the
     * current configuration (Phase 7 re-hashes transparently on login).
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->passwordAlgorithm(), $this->passwordOptions());
    }

    /**
     * The algorithm value expected by password_hash().
     */
    public function passwordAlgorithm(): int|string
    {
        $configured = strtolower((string) ($this->config['password']['algorithm'] ?? 'default'));

        return match ($configured) {
            'default' => PASSWORD_DEFAULT,
            'bcrypt' => PASSWORD_BCRYPT,
            'argon2id' => $this->argon2Algorithm(),
            default => throw new RuntimeException(
                sprintf('Unsupported password algorithm "%s"; use default, bcrypt or argon2id.', $configured)
            ),
        };
    }

    /**
     * Options matching the algorithm currently in use.
     *
     * @return array<string, int>
     */
    public function passwordOptions(): array
    {
        $password = $this->config['password'];

        // Guarded because PASSWORD_ARGON2ID only exists on Argon2-enabled builds.
        if (defined('PASSWORD_ARGON2ID') && PASSWORD_ARGON2ID === $this->passwordAlgorithm()) {
            /** @var array<string, int> $options */
            $options = $password['argon2_options'] ?? [];

            return $options;
        }

        // bcrypt only accepts `cost`.
        return ['cost' => (int) ($password['options']['cost'] ?? 12)];
    }

    // -----------------------------------------------------------------------
    // Tokens (AUTH-05, remember-me; audit correlation)
    // -----------------------------------------------------------------------

    /**
     * A URL-safe hex token. 32 bytes = 64 hex characters of entropy.
     */
    public function randomToken(int $bytes = 32): string
    {
        $bytes = max(16, min(64, $bytes));

        return bin2hex(random_bytes($bytes));
    }

    /**
     * Remember-me selector: 12 bytes → exactly 24 hex characters, matching
     * the CHAR(24) column in remember_tokens.
     */
    public function rememberSelector(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * Hash a token before it is stored (remember-me validators, API keys).
     * SHA-256 is appropriate because the input is high-entropy; there is
     * nothing to brute-force, and the hash must be cheap to verify per request.
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Constant-time comparison against a stored token hash.
     */
    public function verifyTokenHash(string $token, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hashToken($token));
    }

    /**
     * Constant-time string comparison for non-hashed values.
     */
    public function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    // -----------------------------------------------------------------------
    // CSRF (AUTH-09)
    // -----------------------------------------------------------------------

    /**
     * The current session's CSRF token, creating one when absent or expired.
     */
    public function csrfToken(): string
    {
        $this->assertSession();
        $state = $this->session->get(self::CSRF_SESSION_KEY);

        if (is_array($state) && isset($state['value'], $state['issued_at'])
            && !$this->csrfExpired((int) $state['issued_at'])
        ) {
            return (string) $state['value'];
        }

        return $this->rotateCsrfToken();
    }

    /**
     * Replace the token. Called after login, logout and any privilege change so
     * a token captured before authentication cannot be replayed afterwards.
     */
    public function rotateCsrfToken(): string
    {
        $this->assertSession();

        $token = $this->randomToken((int) $this->config['csrf']['token_bytes']);

        $this->session->set(self::CSRF_SESSION_KEY, [
            'value' => $token,
            'issued_at' => $this->now(),
        ]);

        return $token;
    }

    /**
     * Validate a submitted token in constant time.
     *
     * Missing tokens fail; expired tokens are removed so an old form cannot be
     * replayed later in the session.
     */
    public function validateCsrf(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $this->assertSession();
        $state = $this->session->get(self::CSRF_SESSION_KEY);

        if (!is_array($state) || !isset($state['value'], $state['issued_at'])) {
            return false;
        }

        if ($this->csrfExpired((int) $state['issued_at'])) {
            $this->session->remove(self::CSRF_SESSION_KEY);

            return false;
        }

        return hash_equals((string) $state['value'], $token);
    }

    /**
     * Hidden input for a form. The token is hex, but it is still escaped:
     * escaping is unconditional in this codebase.
     */
    public function csrfField(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            $this->escape((string) $this->config['csrf']['field_name']),
            $this->escape($this->csrfToken())
        );
    }

    /**
     * Meta tag consumed by assets/js (fetch wrapper adds the header).
     */
    public function csrfMeta(): string
    {
        return sprintf(
            '<meta name="csrf-token" content="%s">',
            $this->escape($this->csrfToken())
        );
    }

    public function csrfFieldName(): string
    {
        return (string) $this->config['csrf']['field_name'];
    }

    public function csrfHeaderName(): string
    {
        return (string) $this->config['csrf']['header_name'];
    }

    // -----------------------------------------------------------------------
    // Output encoding (XSS)
    // -----------------------------------------------------------------------

    /**
     * Escape a value for HTML text or a quoted attribute.
     *
     * Views must use this for every dynamic value (`$e($value)`). Arrays and
     * objects are refused rather than stringified, because "Array" in a page
     * usually means the developer made a mistake.
     */
    public function escape(mixed $value): string
    {
        // Phase 6 moved the implementation to Html so that views, the global
        // e() helper and this method share exactly one escaping routine.
        return Html::escape($value);
    }

    /**
     * Encode data for safe embedding inside a <script> block or an HTML
     * attribute. HEX flags neutralise `</script>`, quotes and ampersands.
     *
     * @throws \JsonException
     */
    public function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    // -----------------------------------------------------------------------
    // Response hardening (NFR-07)
    // -----------------------------------------------------------------------

    /**
     * Per-response CSP nonce. Inline <script> tags must carry
     * nonce="<?= $e($security->nonce()) ?>" to execute.
     */
    public function nonce(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(18));
    }

    /**
     * Build the security headers for this response.
     *
     * Returned as an array (instead of sent directly) so the Phase 6 middleware
     * can attach them to the Response object and tests can assert them.
     *
     * @return array<string, string>
     */
    public function securityHeaders(?bool $secureRequest = null): array
    {
        $config = $this->config['headers'];
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => (string) $config['referrer_policy'],
            'Permissions-Policy' => (string) $config['permissions_policy'],
        ];

        if ((string) $config['frame_options'] !== '') {
            $headers['X-Frame-Options'] = (string) $config['frame_options'];
        }

        if ((string) $config['content_security_policy'] !== '') {
            $name = (bool) $config['csp_report_only']
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $headers[$name] = str_replace('{nonce}', $this->nonce(), (string) $config['content_security_policy']);
        }

        $secureRequest ??= $this->isHttps();

        if ($secureRequest) {
            $hsts = 'max-age=' . max(0, (int) $config['hsts_max_age']);

            if ((bool) $config['hsts_include_subdomains']) {
                $hsts .= '; includeSubDomains';
            }

            if ((bool) $config['hsts_preload']) {
                $hsts .= '; preload';
            }

            $headers['Strict-Transport-Security'] = $hsts;
        }

        return $headers;
    }

    /**
     * Emit the headers built by securityHeaders(). No-op once output started.
     */
    public function sendSecurityHeaders(?bool $secureRequest = null): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($this->securityHeaders($secureRequest) as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    /**
     * Detect HTTPS without trusting arbitrary proxy headers. A deployment
     * behind a TLS-terminating proxy sets APP_HTTPS=true (config/local.php),
     * which drives session.secure and HSTS explicitly.
     */
    public function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return ($https !== '' && strtolower((string) $https) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    // -----------------------------------------------------------------------
    // Privacy helpers (logging and audit, NFR-11)
    // -----------------------------------------------------------------------

    /**
     * Mask an email for logs: john.doe@sac.edu.ph → j***@sac.edu.ph.
     *
     * Audit rows store the real actor ID; logs should not accumulate a second,
     * less-protected directory of personal data.
     */
    public function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function csrfExpired(int $issuedAt): bool
    {
        $lifetime = (int) $this->config['csrf']['lifetime_minutes'];

        // 0 disables the age check (still bound to the session and rotated on
        // login/logout).
        return $lifetime > 0 && ($this->now() - $issuedAt) > $lifetime * 60;
    }

    /**
     * CSRF is meaningless without a session: fail loudly during development
     * rather than silently issuing tokens that cannot be verified.
     */
    private function assertSession(): void
    {
        if (!$this->session->isStarted()) {
            throw new LogicException(
                'A session must be started before using CSRF tokens. '
                . 'Start the Session middleware before the request reaches the controller.'
            );
        }
    }

    private function argon2Algorithm(): int|string
    {
        if (!defined('PASSWORD_ARGON2ID') || !in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            throw new RuntimeException(
                'argon2id was requested but this PHP build does not provide it. '
                . 'Install PHP with Argon2 support or set password.algorithm to bcrypt/default.'
            );
        }

        return PASSWORD_ARGON2ID;
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
