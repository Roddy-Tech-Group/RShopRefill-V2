<?php

namespace App\Domain\Payment\Support;

/**
 * The sentence a customer is shown for a failed payment, chosen by the
 * classifier's reason code rather than by whichever code path failed the
 * session.
 *
 * A failure has two audiences with two vocabularies. The gateway's own words
 * ("Insufficient Funds", "05 Do Not Honor", "Transaction limit exceeded")
 * belong to the operator and are graded by FailureReasonClassifier into a
 * reason code. The customer gets a sentence that says what happened and what
 * to do next, in their own language. Those sentences live in
 * lang/{locale}/checkout.php as failure_reason_{code}, so a page script can
 * pick the customer's locale from the code alone and the server can write the
 * same sentence, in the request's locale, as the reason on the session.
 *
 * Codes a customer must never see travel under a neighbour's name. A card the
 * gateway flagged as fraud or reported lost is "declined by your bank" here:
 * the truth would tell a card tester exactly what was detected.
 */
final class CustomerFailureCopy
{
    /**
     * Codes with a sentence of their own.
     *
     * @var list<string>
     */
    private const KEYED = [
        'insufficient_funds',
        'limit_exceeded',
        'do_not_honor',
        'bank_declined',
        'expired_card',
        'incorrect_pin',
        'authentication_failed',
        'invalid_card_details',
        'card_restricted',
        'avs_failed',
        'user_cancelled',
        'prompt_declined',
        'prompt_timed_out',
        'mobile_account_not_found',
        'provider_timeout',
        'provider_error',
        'gateway_config_error',
        'system_error',
        'mobile_money_unknown',
        'card_not_enrolled',
        // Not a classifier code: the honest sentence for a card refusal whose
        // words no rule recognises yet. See forVerdict().
        'payment_not_completed',
    ];

    /**
     * Codes told to the customer under another code's sentence: the advice is
     * the same, and the sensitive ones must not reach the browser at all.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'suspected_fraud' => 'bank_declined',
        'card_reported_lost' => 'bank_declined',
        'provider_unreachable' => 'provider_error',
        'provider_rate_limited' => 'provider_error',
        'provider_maintenance' => 'provider_error',
        'gateway_compliance_block' => 'gateway_config_error',
        'orphaned_timeout' => 'provider_timeout',
    ];

    /**
     * The code as it may leave the server: alias-resolved, and null for a
     * code that has no customer sentence (the page then shows the reason the
     * session was failed with).
     */
    public static function customerCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $code = self::ALIASES[$code] ?? $code;

        return in_array($code, self::KEYED, true) ? $code : null;
    }

    public static function sentence(?string $code, ?string $locale = null): ?string
    {
        $code = self::customerCode($code);

        return $code === null ? null : __('checkout.failure_reason_'.$code, [], $locale);
    }

    /**
     * The sentence for a verdict the gateway reported on a charge, by the rail
     * it came over. A mobile money verdict the classifier has no rule for
     * still tells the customer to check balance and limits, the overwhelming
     * cause on those rails.
     *
     * A card verdict with no rule says only that the payment did not go
     * through. It used to say the bank declined it, which sent customers to
     * argue with banks over refusals that were ours or the gateway's
     * ("The currency for this payment is currently not supported").
     *
     * @param  array{code: string}  $classification
     */
    public static function forVerdict(array $classification, bool $mobileMoney, ?string $locale = null): string
    {
        return self::sentence($classification['code'], $locale)
            ?? (string) self::sentence($mobileMoney ? 'mobile_money_unknown' : 'payment_not_completed', $locale);
    }

    /**
     * What a failed session should tell its customer: the sentence for its
     * code in the reader's locale, else the reason it was failed with.
     *
     * @param  array<string, mixed>|null  $paymentPayload
     */
    public static function forSessionPayload(?array $paymentPayload, ?string $locale = null): ?string
    {
        if (! is_array($paymentPayload)) {
            return null;
        }

        $sentence = self::sentence($paymentPayload['failure_code'] ?? null, $locale);

        if ($sentence !== null) {
            return $sentence;
        }

        $reason = $paymentPayload['failure_reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * Every customer sentence by code, for the page scripts that turn a
     * session's failure_code into copy in the customer's language.
     *
     * @return array<string, string>
     */
    public static function map(?string $locale = null): array
    {
        $map = [];

        foreach (self::KEYED as $code) {
            $map[$code] = __('checkout.failure_reason_'.$code, [], $locale);
        }

        return $map;
    }
}
