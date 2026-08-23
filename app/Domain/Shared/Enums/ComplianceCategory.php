<?php

namespace App\Domain\Shared\Enums;

/**
 * The compliance classification of a product, independent of its storefront
 * category. This is the fact the payment eligibility gate reads to decide
 * whether a gateway is allowed to charge it.
 *
 * Storefront categories (a DB slug like `gift-cards`) describe how a product is
 * merchandised; a compliance category describes how a card scheme or acquirer
 * treats it. They are related but not the same: several storefront categories
 * can map to one compliance category, and a compliance category can exist with
 * no storefront category behind it (wallet funding, crypto).
 *
 * Everything except NormalDigitalGood is a restricted category that an acquirer
 * gates individually, so the eligibility matrix defaults all of them to deny
 * until a gateway explicitly approves them. See config/payment_eligibility.php
 * and App\Domain\Payment\Services\PaymentEligibilityService.
 */
enum ComplianceCategory: string
{
    case NormalDigitalGood = 'normal_digital_good';
    case GiftCard = 'gift_card';
    case Esim = 'esim';
    case Airtime = 'airtime';
    case Travel = 'travel';
    case StoredValue = 'stored_value';
    case Wallet = 'wallet';
    case Crypto = 'crypto';
    case OtherRestricted = 'other_restricted';

    /**
     * The compliance category a storefront category slug maps to. The known
     * restricted slugs are named explicitly; everything else (including
     * `bill-payments` and any future or missing slug) is treated as a normal
     * digital good, which stays allowed exactly where it is today.
     *
     * Kept as the ONE canonical slug -> compliance map so the backfill
     * migration, the Product accessor and any future classifier all agree.
     */
    public static function fromCategorySlug(?string $slug): self
    {
        return match ($slug) {
            'gift-cards' => self::GiftCard,
            'esims' => self::Esim,
            'mobile-airtime' => self::Airtime,
            default => self::NormalDigitalGood,
        };
    }

    /**
     * Everything but a normal digital good is restricted and therefore
     * default-deny in the eligibility matrix.
     */
    public function isRestricted(): bool
    {
        return $this !== self::NormalDigitalGood;
    }

    /**
     * Customer-facing label. Used in the "cannot be purchased right now"
     * copy, so it names the thing the customer recognises, never a code.
     */
    public function label(): string
    {
        return match ($this) {
            self::NormalDigitalGood => 'Digital products',
            self::GiftCard => 'Gift cards',
            self::Esim => 'eSIMs',
            self::Airtime => 'Airtime',
            self::Travel => 'Travel',
            self::StoredValue => 'Stored value',
            self::Wallet => 'Wallet funding',
            self::Crypto => 'Crypto',
            self::OtherRestricted => 'This product',
        };
    }
}
