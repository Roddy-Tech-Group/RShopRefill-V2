<?php

namespace App\Domain\Catalog\Support;

/**
 * Brands that must never appear in the catalog (trademark / IP takedowns).
 *
 * The list lives in config/catalog.php. Matching strips everything but
 * letters and digits before comparing, so "GolfTown", "golf-town" and
 * "golftown" all identify the same brand regardless of where the entry
 * was copied from (Zendit brand key, storefront slug, takedown notice).
 */
class BrandBlocklist
{
    public static function isBlocked(?string $brandKey): bool
    {
        if ($brandKey === null || $brandKey === '') {
            return false;
        }

        $normalized = self::normalize($brandKey);

        foreach (self::entries() as $entry) {
            if (self::normalize($entry) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw configured entries.
     *
     * @return array<int, string>
     */
    public static function entries(): array
    {
        return array_values(array_filter((array) config('catalog.blocked_brands', [])));
    }

    private static function normalize(string $brandKey): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $brandKey));
    }
}
