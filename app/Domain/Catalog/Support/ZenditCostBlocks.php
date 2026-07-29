<?php

namespace App\Domain\Catalog\Support;

use App\Models\ProductVariant;

/**
 * Reader for the money blocks Zendit ships on every catalog offer, interpreted
 * per Zendit's own documentation (developers.zendit.io, zendit-concepts):
 *
 *   "In the zendit catalog, each item has a cost provided in your wallet
 *    currency." / cost.currency: "This will be the same currency as your
 *    zendit wallet."
 *   cost.fx: "the FX rate from the cost to the sent value."
 *   cost.discount: "The discount from the full value of the item to your cost."
 *
 * So cost.fixed / currencyDivisor IS the USD amount deducted from the wallet
 * per unit, and fx is a VALUE ratio (value delivered per unit of cost), not a
 * currency conversion. A both-USD row with fx < 1 is therefore legitimate and
 * means the offer costs MORE than the nominal value it delivers - Straight
 * Talk's $35 plan costing $39, Alfa's $77.28 of airtime costing $136.18
 * (Zendit's suggested retail for it: $161). Any storefront price anchored on
 * face value alone is structurally below cost on those rows.
 *
 * The one documented invariant a row can violate: cost.currency must equal the
 * wallet currency (USD). A row labeled otherwise (an AED-labeled cost block
 * has been observed historically) cannot be trusted as USD. A mislabeled block
 * - local-currency amounts under a 'USD' label - is invisible here by
 * definition; only comparing stored cost against the `cost` field of a real
 * purchase response ("Zendit price for partner inclusive of fees") catches
 * that, which is what `zendit:audit-costs` does with fulfillment_logs.
 */
final class ZenditCostBlocks
{
    /** The reseller wallet settles in USD; a cost block labeled otherwise violates Zendit's documented invariant. */
    private const WALLET_CURRENCY = 'USD';

    /**
     * The variant's raw Zendit money blocks, normalized.
     *
     * Top-ups and vouchers store the raw offer blocks at the top level of
     * `metadata`; the eSIM normalizer nests the raw offer under
     * `metadata.raw_payload`, so both locations are read. The counterpart
     * block is `send` (value credited to the recipient) when present, else
     * `price` (eSIMs carry no send block).
     *
     * @return array{cost_usd: float, face: float|null, fx: float, discount: float, cost_currency: string|null, counterpart_currency: string|null}|null
     *                                                                                                                                                  null when the variant carries no usable cost block.
     */
    public static function read(ProductVariant $variant): ?array
    {
        $meta = $variant->metadata;
        if (! is_array($meta)) {
            return null;
        }

        $raw = is_array($meta['cost'] ?? null) ? $meta : ($meta['raw_payload'] ?? null);
        if (! is_array($raw)) {
            return null;
        }

        $cost = $raw['cost'] ?? null;
        if (! is_array($cost) || ! is_numeric($cost['fixed'] ?? null)) {
            return null;
        }

        $counterpart = is_array($raw['send'] ?? null)
            ? $raw['send']
            : (is_array($raw['price'] ?? null) ? $raw['price'] : null);

        $divisor = (float) ($cost['currencyDivisor'] ?? 100) ?: 100.0;

        $counterFixed = is_array($counterpart) && is_numeric($counterpart['fixed'] ?? null)
            ? (float) $counterpart['fixed']
            : null;
        $counterDivisor = is_array($counterpart)
            ? ((float) ($counterpart['currencyDivisor'] ?? 100) ?: 100.0)
            : 100.0;

        return [
            'cost_usd' => ((float) $cost['fixed']) / $divisor,
            'face' => $counterFixed !== null ? $counterFixed / $counterDivisor : null,
            'fx' => is_numeric($cost['fx'] ?? null) ? (float) $cost['fx'] : 1.0,
            'discount' => is_numeric($cost['discount'] ?? null) ? (float) $cost['discount'] : 0.0,
            'cost_currency' => is_string($cost['currency'] ?? null) ? $cost['currency'] : null,
            'counterpart_currency' => is_array($counterpart) && is_string($counterpart['currency'] ?? null)
                ? $counterpart['currency']
                : null,
        ];
    }

    /**
     * Does the cost block carry a currency label other than the wallet
     * currency? Zendit documents that it never legitimately can, so such a row
     * (the historically-observed AED case) must not be read as USD.
     */
    public static function hasWalletCurrencyViolation(ProductVariant $variant): bool
    {
        $reading = self::read($variant);

        return $reading !== null
            && $reading['cost_currency'] !== null
            && $reading['cost_currency'] !== self::WALLET_CURRENCY;
    }

    /**
     * Does this offer cost more than the nominal USD value it delivers?
     *
     * Legitimate per the docs (fee-loaded US prepaid plans, Lebanese airtime),
     * but it means face-anchored storefront pricing sells the row below cost
     * on every unit: these are exactly the rows the cost-anchored floor
     * (PRICING_FLOOR_MODE=enforce) exists to reprice.
     */
    public static function costExceedsFace(ProductVariant $variant): bool
    {
        $reading = self::read($variant);

        return $reading !== null
            && $reading['face'] !== null
            && $reading['cost_currency'] === self::WALLET_CURRENCY
            && $reading['counterpart_currency'] === self::WALLET_CURRENCY
            && $reading['cost_usd'] > $reading['face'] + 0.01;
    }
}
