<?php

namespace App\Domain\Payment\Support;

/**
 * The one place that turns any spelling of a payment method into the canonical
 * key the application uses.
 *
 * Three vocabularies describe the same handful of methods today: the checkout
 * tiles ('mobile_wallet'), PaymentSession::getAvailableMethods() ('opay'), and
 * whatever the gateway reports back in `data.payment_type` ('banktransfer',
 * 'mobilemoneyghana', 'credit'). Recording those verbatim would leave telemetry
 * counting the same method under three names, and a kill switch keyed to one
 * spelling would silently miss the others.
 *
 * Unknown input returns null rather than a best guess. A null tells the truth —
 * "we do not know how this was paid" — where a fallback to 'card' would launder
 * a guess into a fact that later analysis, and later kill switches, would trust.
 */
final class PaymentMethodNormaliser
{
    /**
     * Canonical method keys. This is the vocabulary every surface must speak.
     *
     * @var array<int, string>
     */
    public const CANONICAL = [
        'card',
        'bank_transfer',
        'mobile_money',
        'pay_with_bank',
        'ussd',
        'bank_qr',
        'mobile_wallet',
        'apple_pay',
        'crypto',
        'wallet',
    ];

    /**
     * Exact-match aliases, checked before any fuzzy rule.
     *
     * Covers three sources: our own legacy internal keys ('opay'), the gateway's
     * reported payment types ('banktransfer', 'credit'), and the harmless
     * punctuation drift between them.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        // Card family — the gateway reports the funding type, not the method.
        'credit' => 'card',
        'debit' => 'card',
        'cards' => 'card',

        // Bank transfer to a (usually virtual) account.
        'banktransfer' => 'bank_transfer',
        'bank transfer' => 'bank_transfer',
        'transfer' => 'bank_transfer',

        // Internet/mobile banking redirect.
        'paywithbank' => 'pay_with_bank',
        'internetbanking' => 'pay_with_bank',

        // Scan-to-pay in a bank app.
        'qr' => 'bank_qr',
        'bankqr' => 'bank_qr',

        // Nigerian e-wallets. 'opay' is the legacy key emitted by
        // PaymentSession::getAvailableMethods(); the checkout has always called
        // the same tile 'mobile_wallet'.
        'opay' => 'mobile_wallet',
        'enaira' => 'mobile_wallet',
        'mobilewallet' => 'mobile_wallet',

        'applepay' => 'apple_pay',
        'momo' => 'mobile_money',
        'mobilemoney' => 'mobile_money',

        // Gateway families that map 1:1 onto a single method.
        'nowpayments' => 'crypto',
    ];

    /**
     * Canonicalise any spelling of a method, or null when it cannot be
     * identified with confidence.
     */
    public static function normalise(?string $raw): ?string
    {
        $value = strtolower(trim((string) $raw));

        if ($value === '') {
            return null;
        }

        if (in_array($value, self::CANONICAL, true)) {
            return $value;
        }

        // Compare alias keys against both the raw value and a
        // punctuation-stripped form, so 'bank_transfer', 'bank-transfer' and
        // 'banktransfer' all resolve without listing every permutation.
        $collapsed = preg_replace('/[^a-z0-9]/', '', $value) ?? $value;

        foreach ([$value, $collapsed] as $candidate) {
            if (isset(self::ALIASES[$candidate])) {
                return self::ALIASES[$candidate];
            }
        }

        // Fuzzy tail. The gateway suffixes mobile money by market
        // ('mobilemoneyghana', 'mobilemoneyfranco', 'mobilemoneyuganda') and
        // there is no closed list of those, so match the family by prefix.
        if (str_starts_with($collapsed, 'mobilemoney')) {
            return 'mobile_money';
        }

        if (str_starts_with($collapsed, 'ussd')) {
            return 'ussd';
        }

        return null;
    }

    /**
     * Recover the method from a gateway verification/webhook payload's
     * `data.payment_type`, or null when the payload predates the field or
     * reports something we do not offer (e.g. Google Pay).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromGatewayPayload(?array $payload): ?string
    {
        return self::normalise(data_get($payload, 'data.payment_type'));
    }
}
