<?php

namespace App\Domain\Shared\Enums;

/**
 * Supported currencies for wallets and transactions.
 */
enum Currency: string
{
    case NGN = 'NGN';
    case USD = 'USD';
    case GBP = 'GBP';
    case GHS = 'GHS';
    case XAF = 'XAF';
    case RCOIN = 'RCOIN';

    public function symbol(): string
    {
        return match ($this) {
            self::NGN => '₦',
            self::USD => '$',
            self::GBP => '£',
            self::GHS => '₵',
            self::XAF => 'XAF ',
            self::RCOIN => 'R',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NGN => 'Nigerian Naira',
            self::USD => 'US Dollar',
            self::GBP => 'British Pound',
            self::GHS => 'Ghanaian Cedi',
            self::XAF => 'Central African CFA Franc',
            self::RCOIN => 'RShop Coin',
        };
    }

    public function minimumFundingAmount(): float
    {
        return match ($this) {
            self::NGN => 1000.00,
            self::USD => 2.00,
            self::GBP => 5.00,
            self::GHS => 20.00,
            self::XAF => 1500.00,
            self::RCOIN => 0.00,
        };
    }

    /**
     * Hard upper bound for a single wallet funding. Anything above this is
     * rejected at the service layer before a payment session is created.
     * Sized to ~$5,000 USD-equivalent at typical rates — large enough for
     * any legitimate top-up, small enough to stop fat-finger UX bugs and
     * gateway sandbox accidents like the ₦100,000 ghost funding.
     */
    public function maximumFundingAmount(): float
    {
        return match ($this) {
            self::NGN => 8_000_000.00,   // ~$5,000 USD
            self::USD => 5_000.00,
            self::GBP => 4_000.00,
            self::GHS => 75_000.00,
            self::XAF => 3_000_000.00,
            self::RCOIN => 1_000_000.00,
        };
    }

    public function decimalPrecision(): int
    {
        // For rendering, all standard fiat here use 2 decimal places.
        // XAF is technically 0 decimals for coins, but 2 is safe for accounting.
        // RCOIN is strictly 0 decimal places (whole coins only).
        return match ($this) {
            self::RCOIN => 0,
            default => 2,
        };
    }
}
