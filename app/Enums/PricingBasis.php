<?php

namespace App\Enums;

/**
 * The figure the markup rules run against when pricing a variant — the
 * explicit replacement for inferring intent from variant currency. Margin
 * protection is separate and universal: whatever the basis, the cost-anchored
 * floor (config pricing.floor_mode) still applies.
 *
 * NULL on products.pricing_basis means "legacy heuristic" (USD face value if
 * present, else supplier cost) until `pricing:backfill-basis` has run.
 */
enum PricingBasis: string
{
    /**
     * Markup on the variant's face value (a $10 gift card priced as $10 x
     * markup). Only meaningful when the face value is USD-denominated; a
     * non-USD variant under this basis degrades to SupplierCost per variant —
     * a ₦4700 face must never be treated as $4700.
     */
    case FaceValue = 'face_value';

    /**
     * Markup on the supplier's USD cost. The right basis for products with no
     * built-in discount margin (US airtime, bill payments) where face value
     * says nothing about what we pay.
     */
    case SupplierCost = 'supplier_cost';

    /**
     * Markup on the provider's recommended retail (Airalo's pkg.price, stored
     * in face_value at sync). Computationally identical to FaceValue today —
     * the distinct case exists so eSIM pricing intent is named honestly and
     * can diverge later without a data migration.
     */
    case ProviderRetail = 'provider_retail';

    public function label(): string
    {
        return match ($this) {
            self::FaceValue => 'Face Value',
            self::SupplierCost => 'Supplier Cost',
            self::ProviderRetail => 'Provider Retail',
        };
    }
}
