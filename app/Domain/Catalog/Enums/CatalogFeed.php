<?php

namespace App\Domain\Catalog\Enums;

/**
 * A supplier catalog feed one sync walks end to end. Each feed owns the
 * variants it created, and only that feed's completed run may hide one of
 * them for disappearing from the supplier.
 */
enum CatalogFeed: string
{
    case ZenditVouchers = 'zendit_vouchers';
    case ZenditTopups = 'zendit_topups';
    case ZenditEsims = 'zendit_esims';
    case AiraloEsims = 'airalo_esims';

    public function label(): string
    {
        return match ($this) {
            self::ZenditVouchers => 'Zendit gift cards and bills',
            self::ZenditTopups => 'Zendit mobile top-ups',
            self::ZenditEsims => 'Zendit eSIMs',
            self::AiraloEsims => 'Airalo eSIMs',
        };
    }
}
