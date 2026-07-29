<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Safety markup
    |--------------------------------------------------------------------------
    |
    | Applied when a product matches no pricing rule at all — i.e. the global
    | rule is missing or has been deactivated. It is a safety net so the
    | platform never sells at zero margin. A product hitting this path should
    | be treated as a configuration alarm, not a normal state.
    |
    | Expressed as a percentage markup on the provider's USD cost.
    |
    */

    'safety_markup_percent' => (float) env('PRICING_SAFETY_MARKUP_PERCENT', 10),

    /*
    |--------------------------------------------------------------------------
    | Minimum margin floor
    |--------------------------------------------------------------------------
    |
    | A hard floor on retail price. Retail is never less than cost plus this
    | markup, even when a misconfigured rule or a supplier cost increase would
    | otherwise push the price down to (or below) cost.
    |
    | Expressed as a percentage markup on the provider's USD cost.
    |
    */

    'min_margin_percent' => (float) env('PRICING_MIN_MARGIN_PERCENT', 1),

    /*
    |--------------------------------------------------------------------------
    | Minimum cash margin
    |--------------------------------------------------------------------------
    |
    | A flat USD floor on profit per unit, applied alongside the percentage
    | above — the effective floor is cost plus whichever of the two is larger.
    |
    | The percentage alone leaves cheap-to-buy items earning almost nothing: 1%
    | of a $39 top-up is 39 cents, which does not cover the cost of making the
    | sale. This guarantees every unit is worth selling. Expensive items are
    | unaffected, since there the percentage is already the larger figure.
    |
    | Defaults to 0 (off) so it is opt-in per environment, the same staged
    | rollout floor_mode uses: set PRICING_MIN_MARGIN_USD=1 to require a dollar
    | of profit on every unit. Verify with `pricing:audit-below-cost` first —
    | raising it reprices the thin end of the catalogue.
    |
    */

    'min_margin_usd' => (float) env('PRICING_MIN_MARGIN_USD', 0),

    /*
    |--------------------------------------------------------------------------
    | Floor mode
    |--------------------------------------------------------------------------
    |
    | Controls how the minimum-margin floor is anchored. Historically the floor
    | was computed against the markup BASE (face value for USD products), which
    | let supplier cost exceed the charged price unnoticed. The cost-anchored
    | floor fixes that, staged via this flag:
    |
    |   legacy  - floor on the markup base only (historical behaviour).
    |   shadow  - charge the legacy price, but log every variant where the
    |             cost-anchored floor WOULD have raised it. Review these logs
    |             (and `pricing:audit-below-cost`) before enforcing: large
    |             would-be raises usually mean corrupt cost data, not a real
    |             below-cost sale.
    |   enforce - floor on max(base, supplier cost). Strictly a ratchet: prices
    |             can only rise relative to legacy, never fall. Manual price
    |             overrides are clamped too unless the variant is explicitly
    |             flagged allow_below_cost.
    |
    | Enforcing since 2026-07-26. Before flipping, `pricing:audit-below-cost`
    | reported 40 variants selling under supplier cost across 18,672 scanned.
    | The 8 variants it flagged SUSPECT-COST (Alfa LB, whose cost sits at a
    | uniform 1.762x face across every denomination, and one Airalo eSIM) were
    | pinned to their existing price via manual_retail_price_usd +
    | allow_below_cost first, so enforcing could not spike them on data that is
    | almost certainly wrong. Repair those costs and drop the pins.
    |
    */

    'floor_mode' => env('PRICING_FLOOR_MODE', 'enforce'),

];
