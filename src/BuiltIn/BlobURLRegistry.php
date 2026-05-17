<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

/**
 * Per-process registry of `blob:` URLs minted by `URL.createObjectURL`.
 *
 * Browsers manage these per realm; we keep one process-wide table
 * since the engine is a single-realm host. Each entry holds the raw
 * byte payload + the Blob's MIME type so the fetch / XHR transport
 * can synthesize a 200 response without going through curl.
 *
 * URLs look like `blob:phasis//<uuid>` — the leading `blob:` makes
 * the URL parser route them as opaque, and the UUID is the table key.
 * `URL.revokeObjectURL(url)` removes the entry; subsequent fetches
 * fail with a network error, matching the spec.
 */
final class BlobURLRegistry
{
    /** @var array<string, array{bytes:string,type:string}> */
    private static array $entries = [];

    /**
     * Register a blob's bytes + MIME under a fresh blob: URL.
     */
    public static function register(string $bytes, string $type): string
    {
        $uuid = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFFFFFFFFFF),
        );
        $url = 'blob:phasis//' . $uuid;
        self::$entries[$url] = ['bytes' => $bytes, 'type' => $type];
        return $url;
    }

    /**
     * Look up a blob URL. Returns null when the URL was never
     * registered or has been revoked.
     *
     * @return ?array{bytes:string,type:string}
     */
    public static function lookup(string $url): ?array
    {
        return self::$entries[$url] ?? null;
    }

    public static function revoke(string $url): void
    {
        unset(self::$entries[$url]);
    }

    /** Clear the entire registry — used by tests for isolation. */
    public static function reset(): void
    {
        self::$entries = [];
    }
}
