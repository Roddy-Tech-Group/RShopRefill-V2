<?php

namespace App\Domain\Catalog\Support;

/**
 * Presentable quick-pick amounts for topup operators. Zendit derives many
 * operators' denominations from USD price points at their exchange rate,
 * producing faces like ₦882.43 or ₦1,729 - real, purchasable, embarrassing to
 * present. And even mostly-clean catalogs have gaps: MTN NG carries ₦1,000 and
 * ₦1,500 but its 2,000/2,500 slots are the FX rows ₦1,729/₦2,161.25.
 *
 * plan() produces the ladder a human would write - the operator's real faces
 * where they exist, synthesized custom amounts where they don't - and is the
 * ONE source both the /topups listing range and the detail page's amount
 * selector derive from, so the two pages can never disagree.
 */
final class TopupQuickPicks
{
    /**
     * A face is presentable when it lands on a clean step (multiples of 5 in
     * its own currency: ₦1,000, $35, XAF 500). ₦882.43 and ₦1,316.64 are not.
     */
    public static function isCleanFace(float $face): bool
    {
        return $face > 0 && fmod($face, 5) === 0.0;
    }

    /**
     * A value people recognise as a price point: 1 / 1.5 / 2 / 2.5 / 3 / 4 /
     * 5 / 7.5 at any power of ten (₦1,000, ₦2,500, $15, XAF 40,000).
     */
    public static function isNice(float $value): bool
    {
        if ($value <= 0) {
            return false;
        }

        $mantissa = $value / (10 ** floor(log10($value)));
        foreach ([1.0, 1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 7.5] as $nice) {
            if (abs($mantissa - $nice) < 0.0001) {
                return true;
            }
        }

        return false;
    }

    /**
     * The quick-pick ladder for one operator.
     *
     * - No custom-amount offer: EVERY face, untouched. Collapsing is only
     *   safe when a custom input carries the dropped faces; without one a
     *   dropped face is simply unpurchasable (Koodo CA's C$20.01).
     * - Clean faces anchored on a nice smallest value: a 1/1.5/2/2.5 ladder
     *   from that anchor, each step using the REAL face when the catalog has
     *   it and a synthesized custom amount when it doesn't (MTN NG's missing
     *   ₦2,000/₦2,500), topped by the biggest clean face - or the custom
     *   maximum when it is nice and higher (T-Mobile's $200).
     * - Junk-dominated catalog (Airtel NG, Cameroon): fully synthesized round
     *   amounts inside the custom range.
     *
     * Large-denomination currencies (NGN/XAF-class, rate_per_usd >= 100) get a
     * PRESENTATION floor of 500: Zendit will happily sell ₦5 of airtime, but a
     * ₦5 quick-pick reads as a joke, so the ladder starts at 500 and the
     * custom input advertises the same. This is display/UX policy only - the
     * supplier range is wider and the server still accepts it.
     *
     * @param  list<float>  $cleanFaces  clean CREDIT faces, ascending
     * @return list<array{type: 'face'|'custom', value: float}>
     */
    public static function plan(array $cleanFaces, ?float $varMin, ?float $varMax, float $ratePerUsd = 1.0): array
    {
        $faces = collect($cleanFaces)
            ->map(fn ($face) => (float) $face)
            ->filter(fn ($face) => $face > 0)
            ->unique()
            ->sort()
            ->values();

        $hasVariable = $varMin !== null && $varMax !== null && (float) $varMax > 0;

        if (! $hasVariable) {
            return $faces
                ->map(fn ($face) => ['type' => 'face', 'value' => $face])
                ->all();
        }

        $effectiveMin = self::presentationMin((float) $varMin, $ratePerUsd);

        // Anchor on the smallest clean face at or above the presentation
        // floor, so a catalog's tiny faces cannot drag the ladder below it.
        $anchor = (float) ($faces->first(fn ($face) => $face >= $effectiveMin) ?? 0);

        if ($anchor > 0 && self::isNice($anchor)) {
            $picks = [];
            foreach ([1.0, 1.5, 2.0, 2.5] as $multiplier) {
                $value = $anchor * $multiplier;
                if ($faces->contains(fn ($face) => abs($face - $value) < 0.001)) {
                    $picks[] = ['type' => 'face', 'value' => $value];
                } elseif ($value >= (float) $varMin && $value <= (float) $varMax) {
                    $picks[] = ['type' => 'custom', 'value' => $value];
                }
            }
        } else {
            $picks = collect(self::synthesize($effectiveMin, (float) $varMax))
                ->map(fn ($value) => ['type' => 'custom', 'value' => $value])
                ->all();
            // The synthesized big lives at the end; peel it off so the shared
            // big-pick logic below decides between it and the biggest face.
            $picks = array_slice($picks, 0, 4);
        }

        // Lead with the presentation floor itself when the ladder starts
        // above it (₦500 ahead of MTN's ₦1,000 anchor), keeping four smalls.
        if ($picks !== []
            && $effectiveMin < $picks[0]['value']
            && $effectiveMin >= (float) $varMin
            && self::isNice($effectiveMin)) {
            array_unshift($picks, ['type' => 'custom', 'value' => $effectiveMin]);
            $picks = array_slice($picks, 0, 4);
        }

        $biggestFace = (float) ($faces->last() ?? 0);
        $lastSmall = $picks === [] ? 0.0 : end($picks)['value'];

        // The big pick must be a NICE number, not merely a clean one: Glo NG's
        // FX face ₦47,995 is divisible by 5 but is still junk. Prefer the
        // larger of (nice biggest face, nice custom max); when neither is
        // nice, synthesize the largest round amount under the custom max
        // (₦73,950 -> ₦70,000) before falling back to a raw face.
        $big = null;
        if ($biggestFace > $lastSmall && self::isNice($biggestFace)) {
            $big = ['type' => 'face', 'value' => $biggestFace];
        }
        if (self::isNice((float) $varMax) && (float) $varMax > $lastSmall && (float) $varMax > ($big['value'] ?? 0)) {
            $big = ['type' => 'custom', 'value' => (float) $varMax];
        }
        if ($big === null) {
            $roundBig = self::presentationMax((float) $varMax);
            if ($roundBig > $lastSmall && $roundBig >= (float) $varMin) {
                $big = ['type' => 'custom', 'value' => (float) $roundBig];
            } elseif ($biggestFace > $lastSmall) {
                $big = ['type' => 'face', 'value' => $biggestFace];
            }
        }
        if ($big !== null) {
            $picks[] = $big;
        }

        return $picks;
    }

    /**
     * The smallest amount worth ADVERTISING in a currency: 500 for
     * NGN/XAF-class currencies, the supplier minimum everywhere else - lifted
     * to the next nice rung so a Glo-style ₦1,450 advertises as ₦1,500.
     */
    public static function presentationMin(float $varMin, float $ratePerUsd): float
    {
        return self::niceCeil($ratePerUsd >= 100 ? max($varMin, 500.0) : $varMin);
    }

    /**
     * The largest amount worth ADVERTISING for a custom range: the supplier
     * maximum when it is already nice, otherwise the largest round step under
     * it (₦73,950 advertises as ₦70,000). Matches the ladder's big pick, so
     * the hint and the selector always agree.
     */
    public static function presentationMax(float $varMax): float
    {
        if ($varMax <= 0 || self::isNice($varMax)) {
            return $varMax;
        }

        $bigStep = 10 ** max(0, (int) round(log10($varMax)) - 1);
        $roundBig = floor($varMax / $bigStep) * $bigStep;

        return $roundBig > 0 ? (float) $roundBig : $varMax;
    }

    /**
     * The smallest nice value at or above $value (₦1,450 -> ₦1,500).
     */
    private static function niceCeil(float $value): float
    {
        if ($value <= 0 || self::isNice($value)) {
            return $value;
        }

        $tier = 10 ** floor(log10($value));
        foreach ([1.0, 1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 7.5, 10.0] as $nice) {
            if ($tier * $nice >= $value - 0.0001) {
                return (float) ($tier * $nice);
            }
        }

        return $value;
    }

    /**
     * Round quick-pick amounts for a custom range: the four smallest ladder
     * values inside [min, max] plus the largest round amount the range allows.
     * The ladder anchors two orders of magnitude below the range maximum, so
     * ₦50-₦150,000 yields 1,000 / 1,500 / 2,000 / 2,500 / 150,000 and
     * XAF 1,968-25,000 yields 2,000 / 2,500 / 3,000 / 4,000 / 25,000.
     *
     * @return list<float>
     */
    public static function synthesize(float $min, float $max): array
    {
        if ($max <= 0 || $max <= $min) {
            return [];
        }

        $anchor = 10 ** max(0, (int) round(log10($max)) - 2);

        $ladder = [];
        foreach ([$anchor, $anchor * 10] as $tier) {
            foreach ([1, 1.5, 2, 2.5, 3, 4, 5, 7.5] as $multiplier) {
                $ladder[] = $tier * $multiplier;
            }
        }

        $picks = collect($ladder)
            ->unique()
            ->sort()
            ->values()
            ->filter(fn ($value) => $value >= $min && $value <= $max)
            ->take(4)
            ->values();

        $bigStep = $anchor * 10;
        $big = floor($max / $bigStep) * $bigStep;
        if ($big >= $min && $big > (float) ($picks->last() ?? 0)) {
            $picks->push($big);
        }

        return $picks->map(fn ($value) => (float) $value)->all();
    }
}
