<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Per-request registry for the async-data architecture.
 *
 * Two jobs:
 *  1. Record which ea_cached() datasets the current page consumed and when
 *     each was computed, so the layout can show an honest "Data as of HH:MM"
 *     stamp (the OLDEST dataset on the page — conservative by design).
 *  2. Carry the "force" switch the background warmer (bms:warm) flips so
 *     producers re-run even on a cache hit — that is how the worker refreshes
 *     data every few minutes while web requests only ever read the cache.
 */
final class CacheFreshness
{
    private static bool $force = false;

    /** @var array<string,int> cache key => computed-at unix timestamp */
    private static array $stamps = [];

    public static function force(bool $on): void
    {
        self::$force = $on;
    }

    public static function forcing(): bool
    {
        return self::$force;
    }

    public static function note(string $key, ?int $at): void
    {
        if ($at !== null) {
            self::$stamps[$key] = $at;
        }
    }

    /** Forget stamps between pages (the worker warms many pages per process). */
    public static function reset(): void
    {
        self::$stamps = [];
    }

    /** Oldest computed-at across everything the current page consumed. */
    public static function oldest(): ?int
    {
        return self::$stamps === [] ? null : min(self::$stamps);
    }
}
