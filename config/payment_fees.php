<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flutterwave processing fees (customer-facing disclosure)
    |--------------------------------------------------------------------------
    |
    | Flutterwave already charges the customer these fees on top of the order
    | amount (the account is set to "customer bears fee"). We use this table to
    | DISCLOSE that fee up front and on the receipt so there's no surprise at the
    | payment step. The two customer fees are combined into one "Processing fee".
    |
    | `transaction`  - % of the amount, charged on every transaction.
    | `international` - additional % charged only on cross-border payments
    |                  (e.g. a Cameroon merchant taking Senegal mobile money).
    |
    | VAT is Flutterwave's 7.5% tax on the fees - paid by the MERCHANT, so it is
    | never shown to the customer (it reduces our settlement, like margin).
    |
    | These rates are an offline ESTIMATE / fallback. Flutterwave's fee endpoint
    | (/v3/transactions/fee) is the source of truth; confirm against your own
    | dashboard, which is where negotiated/plan rates actually live.
    |
    */

    // Flutterwave's VAT on fees (merchant-paid). Confirmed from the receipt:
    // 7.5% x (60 + 60) = 9 XAF.
    'vat_percent' => 7.5,

    // Methods that do not route through Flutterwave carry no processing fee.
    'fee_free_methods' => ['wallet'],

    // Per-method rates, in percent of the transaction amount.
    'methods' => [
        'mobile_money' => ['transaction' => 2.0, 'international' => 2.0],
        'card' => ['transaction' => 4.8, 'international' => 0.0],
        'apple_pay' => ['transaction' => 4.8, 'international' => 0.0],
        'ussd' => ['transaction' => 2.0, 'international' => 0.0],
        'bank_transfer' => ['transaction' => 2.0, 'international' => 0.0],
        'pay_with_bank' => ['transaction' => 2.0, 'international' => 0.0],
        'bank_qr' => ['transaction' => 2.0, 'international' => 0.0],
        'mobile_wallet' => ['transaction' => 2.0, 'international' => 0.0],
    ],

    // Default rate when a method is not listed above.
    'default' => ['transaction' => 0.0, 'international' => 0.0],

    /*
    |--------------------------------------------------------------------------
    | Per-gateway overrides
    |--------------------------------------------------------------------------
    |
    | The tables above are the Flutterwave account's, where the customer bears
    | the fee. A method the switchboard routes to another gateway discloses
    | THAT gateway's customer fee instead, read from here by gateway key; a
    | gateway with no entry falls back to the tables above.
    |
    | Kora: the merchant bears the fee by default (merchant_bears_cost), so the
    | customer pays the order amount and nothing is disclosed. When Kora goes
    | live with the fee passed to the customer, the confirmed percentages go
    | here and the line reappears on its own.
    |
    */
    'gateways' => [
        'korapay' => [
            'methods' => [],
            'default' => ['transaction' => 0.0, 'international' => 0.0],
        ],
    ],
];
