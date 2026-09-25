<?php

namespace App\Domain\Catalog\Enums;

/**
 * Why a completed sync refused to hide the offers it did not see. Each guard
 * protects against the same failure: a supplier (or our own parsing) returning
 * less than the real catalog, which would otherwise take valid products off sale.
 */
enum CatalogCleanupSkipReason: string
{
    case ItemsFailed = 'items_failed';
    case FeedShrank = 'feed_shrank';
    case TooManyMissing = 'too_many_missing';

    public function describe(): string
    {
        return match ($this) {
            self::ItemsFailed => 'Some offers failed to save, so the run cannot tell a dropped offer from one it failed to record.',
            self::FeedShrank => 'The supplier returned far fewer offers than the last completed run.',
            self::TooManyMissing => 'Hiding every missing offer would take too large a share of the live catalog off sale at once.',
        };
    }
}
