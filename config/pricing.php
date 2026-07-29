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
    | DEFAULTS TO LEGACY - enforcing is a deliberate per-environment decision,
    | not a side effect of a missing env var. Production audit 2026-07-29,
    | across 24,404 variants: 396 selling below supplier cost, 460 the floor
    | would reprice (Alfa LB +76%, Somalia/Lebanon airtime +10-15%, Straight
    | Talk US +10%), 1 paid order unit below cost in the store's whole history
    | (-$3.30, a Straight Talk 38).
    |
    | Those below-cost figures are REAL, not corrupt data. Zendit's docs state
    | the catalog cost block is "provided in your wallet currency" - it is the
    | actual USD deduction - and cost.fx is a value-per-cost ratio, not a
    | currency conversion. Some offers genuinely cost more than the face value
    | they deliver (fee-loaded US prepaid plans; Lebanese airtime, where Alfa's
    | $77.28 face costs $136.18 and Zendit's own suggested retail is $161).
    | Face-anchored legacy pricing sells every such row at a loss by
    | construction. `zendit:audit-costs` lists them (COST OVER FACE), verifies
    | stored costs against real purchase responses, and flags the one genuine
    | corruption class: cost blocks labeled in a non-wallet currency.
    |
    | Before flipping to enforce per environment: run `zendit:audit-costs`,
    | confirm the transaction evidence matches the stored costs, and eyeball
    | the big raises in `pricing:audit-below-cost` - they are real costs, but a
    | +76% Alfa reprice is still a product decision (reprice vs. delist).
    |
    | Do not trust a clean LOCAL audit for any of this: the local catalog runs
    | thousands of variants behind production and misses whole operators.
    |
    */

    'floor_mode' => env('PRICING_FLOOR_MODE', 'legacy'),

];
