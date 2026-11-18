<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only-by-convention configuration store (Phase 6).
 *
 * bootstrap.php assembles one nested array from config/*.php (plus the optional
 * config/local.php overrides); this class gives the rest of the application a
 * single, dot-notation entry point to it:
 *
 *     $config->get('app.name');                    // 'SyncClass'
 *     $config->get('app.session.idle_timeout_minutes', 30);
 *     $config->section('database');                // array<string, mixed>
 *
 * Business code never reads getenv() or $_ENV directly: environment variables
 * are resolved once, in the config files, so behaviour is testable and the
 * origin of a value is always traceable to exactly one file.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items;

    /**
     * Sentinel used by has() so a stored `null` is distinguishable from an
     * absent key. Static so it is allocated once per process.
     */
    private static ?object $missing = null;

    /**
     * @param array<string, mixed> $items Fully merged configuration tree.
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Read a value by dot path.
     *
     * Returns $default when any segment is missing, so callers can write
     * `$config->get('app.url', '')` without array_key_exists dances.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }

        $cursor = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * True when the key exists, even when its value is null.
     */
    public function has(string $key): bool
    {
        return $this->get($key, self::missing()) !== self::missing();
    }

    /**
     * Replace a value by dot path, creating intermediate arrays as needed.
     *
     * Used by tests and by the SYS-01 settings overlay in Phase 9; request
     * handling code should treat configuration as immutable.
     */
    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &$this->items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * The whole tree (diagnostics and the settings screen).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * One top-level section as an array — the shape every constructor expects.
     *
     * @return array<string, mixed>
     */
    public function section(string $name): array
    {
        $section = $this->get($name, []);

        return is_array($section) ? $section : [];
    }

    private static function missing(): object
    {
        return self::$missing ??= new \stdClass();
    }
}
