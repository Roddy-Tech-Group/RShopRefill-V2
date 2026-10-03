<?php

namespace App\Domain\PaymentInsights\Support;

/**
 * Files a refused card under one of three classes, and says how much each
 * class weighs when the rate limiter counts cards.
 *
 * The verdict text is evidence, never a trigger: twelve real customers in the
 * first three months received "Suspected Fraud" once and then paid, several
 * of them from the US where cross-border charges are routinely refused on
 * sight. So a fraud verdict weighs fully, a soft bank refusal weighs half,
 * and anything that was our limitation rather than the customer's (a
 * compliance block, a brand the gateway cannot process, a 3-D Secure
 * enrolment gap) weighs nothing at all.
 */
final class DeclineVerdict
{
    public const CLASS_FRAUD = 'fraud';

    public const CLASS_SOFT = 'soft';

    public const CLASS_IGNORED = 'ignored';

    /** @var array<string, float> */
    public const WEIGHTS = [
        self::CLASS_FRAUD => 1.0,
        self::CLASS_SOFT => 0.5,
        self::CLASS_IGNORED => 0.0,
    ];

    /**
     * Classifier codes that read as a fraud-class refusal.
     *
     * @var list<string>
     */
    private const FRAUD_CODES = ['suspected_fraud', 'card_reported_lost', 'card_restricted'];

    /**
     * Classifier codes that were never the customer's doing: platform faults,
     * gateway trouble, and the authentication step we force on international
     * cards, which is why US customers fail it.
     *
     * @var list<string>
     */
    private const IGNORED_CODES = [
        'gateway_config_error',
        'gateway_compliance_block',
        'system_error',
        'provider_error',
        'provider_rate_limited',
        'authentication_failed',
        // The bank never switched online verification on for the card, and a
        // gateway rail down for maintenance: neither says anything about who
        // holds the card.
        'card_not_enrolled',
        'provider_maintenance',
        'unknown_error',
        'unrecognised_gateway_message',
        'orphaned_timeout',
    ];

    /**
     * Card brands Flutterwave cannot collect for us. A refusal on one of these
     * is our catalogue gap, not a signal about the customer.
     *
     * @var list<string>
     */
    private const UNSUPPORTED_BRANDS = ['american express', 'amex', 'discover', 'diners', 'diners club', 'jcb', 'unionpay'];

    /** @var list<string> */
    private const FRAUD_PHRASES = ['fraud', 'security error', 'stolen', 'lost card', 'restricted', 'pick up', 'pick-up', 'do_not_proceed'];

    /** @var list<string> */
    private const IGNORED_PHRASES = ['not enrolled', '3-d secure', '3d secure', 'transaction not found', 'unable to process your request', 'compliance'];

    public static function classify(?string $reasonCode, ?string $verdict, ?string $cardBrand = null): string
    {
        $brand = strtolower(trim((string) $cardBrand));
        if ($brand !== '' && in_array($brand, self::UNSUPPORTED_BRANDS, true)) {
            return self::CLASS_IGNORED;
        }

        $text = strtolower((string) $verdict);

        foreach (self::IGNORED_PHRASES as $phrase) {
            if ($text !== '' && str_contains($text, $phrase)) {
                return self::CLASS_IGNORED;
            }
        }

        if (in_array((string) $reasonCode, self::FRAUD_CODES, true)) {
            return self::CLASS_FRAUD;
        }

        foreach (self::FRAUD_PHRASES as $phrase) {
            if ($text !== '' && str_contains($text, $phrase)) {
                return self::CLASS_FRAUD;
            }
        }

        if (in_array((string) $reasonCode, self::IGNORED_CODES, true)) {
            return self::CLASS_IGNORED;
        }

        return self::CLASS_SOFT;
    }

    public static function weight(string $class): float
    {
        return self::WEIGHTS[$class] ?? 0.0;
    }

    /**
     * The strongest of two classes, for a card refused more than once.
     */
    public static function stronger(string $a, string $b): string
    {
        return self::weight($a) >= self::weight($b) ? $a : $b;
    }

    /**
     * A keyed hash of the card's first six and last four digits. Enough to
     * recognise the same card twice, useless for reconstructing it.
     */
    public static function fingerprint(?string $firstSix, ?string $lastFour): ?string
    {
        $firstSix = preg_replace('/\D+/', '', (string) $firstSix);
        $lastFour = preg_replace('/\D+/', '', (string) $lastFour);

        if ($firstSix === '' && $lastFour === '') {
            return null;
        }

        return hash_hmac('sha256', $firstSix.'|'.$lastFour, (string) config('app.key'));
    }
}
