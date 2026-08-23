<?php

use App\Domain\Shared\Enums\ComplianceCategory;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment eligibility cells
    |--------------------------------------------------------------------------
    |
    | The restricted product categories we actually sell, and the gateways each
    | one may be approved on. Every entry below becomes ONE admin-toggleable
    | feature flag:
    |
    |     features.eligibility_{gateway}_{category}_enabled
    |
    | seeded OFF by 2026_08_08_100003_seed_payment_eligibility_cell_flags.
    |
    | Turning a cell ON in the admin settings approves that category on that
    | gateway. It only has any effect while the master switch
    | features.payment_eligibility_enforce_enabled is on; until then the gate is
    | in shadow mode and nothing is blocked.
    |
    | This list is only the SEED registry (which flags exist for admins to see).
    | PaymentEligibilityService reads the flags directly and defaults to deny, so:
    |   - Normal digital goods are never gated (always payable).
    |   - Any restricted category NOT listed here (wallet funding, crypto
    |     purchase, travel, stored value, ...) has no flag and is denied by
    |     default. It can only ever be enabled by adding it here, in code, which
    |     is the intended friction for the most sensitive cells.
    |
    | Currency-specific approval is not modelled yet; the dimension can be added
    | later without changing any call site.
    |
    */

    'cells' => [
        ComplianceCategory::GiftCard->value => ['flutterwave', 'paystack', 'korapay', 'nowpayments', 'wallet'],
        ComplianceCategory::Esim->value => ['flutterwave', 'paystack', 'korapay', 'nowpayments', 'wallet'],
        ComplianceCategory::Airtime->value => ['flutterwave', 'paystack', 'korapay', 'nowpayments', 'wallet'],

        // Wallet funding (stored value): approve WHICH provider may fund a wallet.
        // No 'wallet' gateway here - you cannot fund a wallet from a wallet. This
        // is the most sensitive row; keep every cell off unless stored-value
        // funding is genuinely approved on that provider.
        ComplianceCategory::Wallet->value => ['flutterwave', 'paystack', 'korapay', 'nowpayments'],
    ],

];
