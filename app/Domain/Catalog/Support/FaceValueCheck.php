<?php

namespace App\Domain\Catalog\Support;

use App\Models\CurrencyRate;

/**
 * Two guards on what a Zendit offer says its face value is.
 *
 * The send block names the currency the recipient is credited in, and the
 * normalizers store it as the variant currency. Two things go wrong with it
 * in the live feed (found on 2026-09-20):
 *
 *  - Some offers carry an EMPTY currency label (US Amazon cards, data
 *    bundles). `??` let the empty string through, and 15 variants sat in the
 *    catalog with no currency at all.
 *  - Some offers carry the WRONG label or amount: PlayStation UAE cards that
 *    cost $9.79 labelled "10 AED", a Castorama offer named ..._100 that says
 *    500 EUR for $116. The page then shows a 10 AED card for 38 AED, or
 *    promises a 500 EUR card the supplier will deliver as 100 EUR.
 *
 * currency() fills the first gap from the price block. verdict() catches the
 * second by comparing the face, converted at our own rate, with the USD cost:
 * a discount or a premium of a few tens of percent is normal, a factor of
 * MAX_RATIO either way is a label or an amount that cannot be right. Such a
 * row is kept but not offered, with the reading stored on the variant, until
 * the supplier corrects the offer or an operator relabels it.
 *
 * Only a currency we hold a live rate for is judged. The inactive rows in
 * currency_rates are official rates that can sit multiples away from what
 * the supplier prices in (SDG, ARS), and a row cannot be called wrong
 * against a rate we do not trust.
 */
final class FaceValueCheck
{
    /** A face more than this many times its USD cost, or less than 1/this, is not believable. */
    public const MAX_RATIO = 2.5;

    /**
     * The currency an offer's face is in: the send block's, then the price
     * block's, then the item's own, then USD. An empty label is skipped, not
     * kept.
     *
     * @param  array<string, mixed>  $sendBlock
     * @param  array<string, mixed>  $priceBlock
     * @param  array<string, mixed>  $rawItem
     */
    public static function currency(array $sendBlock, array $priceBlock, array $rawItem): string
    {
        foreach ([$sendBlock['currency'] ?? null, $priceBlock['currency'] ?? null, $rawItem['currency'] ?? null] as $candidate) {
            $candidate = strtoupper(trim((string) $candidate));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'USD';
    }

    /**
     * Whether a fixed face value is believable against the offer's USD cost.
     * The status is 'ok', 'suspect', or 'unchecked' for a variable amount, a
     * missing cost, or a currency without a live rate.
     *
     * @return array{status: string, face_usd?: float, cost_usd?: float, ratio?: float, checked_at: string}
     */
    public static function verdict(?float $face, string $currency, float $costUsd, bool $isVariable = false): array
    {
        $unchecked = ['status' => 'unchecked', 'checked_at' => now()->toIso8601String()];

        if ($isVariable || $face === null || $face <= 0 || $costUsd <= 0) {
            return $unchecked;
        }

        $currency = strtoupper(trim($currency));
        $rate = $currency === 'USD' ? 1.0 : self::liveRate($currency);

        if ($rate === null || $rate <= 0) {
            return $unchecked;
        }

        $faceUsd = $face / $rate;
        $ratio = $faceUsd / $costUsd;

        return [
            'status' => ($ratio > self::MAX_RATIO || $ratio < 1 / self::MAX_RATIO) ? 'suspect' : 'ok',
            'face_usd' => round($faceUsd, 4),
            'cost_usd' => round($costUsd, 4),
            'ratio' => round($ratio, 4),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public static function isSuspect(array $verdict): bool
    {
        return ($verdict['status'] ?? null) === 'suspect';
    }

    private static function liveRate(string $currency): ?float
    {
        $rate = CurrencyRate::query()
            ->where('is_active', true)
            ->where('code', $currency)
            ->value('rate_per_usd');

        return $rate === null ? null : (float) $rate;
    }
}
