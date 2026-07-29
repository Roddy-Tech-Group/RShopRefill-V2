<?php

namespace App\Domain\Payment\Support;

/**
 * Semantics for every NOWPayments payment_status value.
 *
 * This exists because the status set is the one thing the provider and the
 * webhook controller must agree on exactly, and they previously each carried
 * their own partial hardcoded list — which is how failed/expired/refunded ended
 * up acknowledged but never acted on.
 *
 * Two behaviours here are load-bearing:
 *
 * 1. rank() gives a monotonic ordering so a late-delivered intermediate status
 *    can never overwrite a terminal one. NOWPayments publishes no ordering
 *    guarantee for IPN delivery, so a `confirming` notification can and does
 *    arrive after `finished`.
 * 2. Unknown statuses are treated as non-terminal and verifiable rather than
 *    ignored. NOWPayments' help centre documents at least three statuses absent
 *    from its API reference ("wrong asset confirmed", "Re-deposit", "Cancelled"),
 *    so an unrecognised value means "ask the API", never "assume nothing happened".
 */
final class NowPaymentsStatus
{
    /** Customer's crypto has landed in full. */
    public const SETTLED = ['confirmed', 'sending', 'finished', 'paid', 'completed'];

    /** Money arrived but short of the invoice. Terminal for THIS payment. */
    public const UNDERPAID = ['partially_paid'];

    /** Terminal, no money retained by us. */
    public const FAILED = ['failed', 'cancelled', 'canceled'];

    public const EXPIRED = ['expired'];

    public const REFUNDED = ['refunded'];

    /** In flight — no state should be written yet. */
    public const PENDING = ['waiting', 'confirming'];

    public static function normalize(?string $status): string
    {
        return strtolower(trim((string) $status));
    }

    public static function isSettled(?string $status): bool
    {
        return in_array(self::normalize($status), self::SETTLED, true);
    }

    public static function isUnderpaid(?string $status): bool
    {
        return in_array(self::normalize($status), self::UNDERPAID, true);
    }

    public static function isFailed(?string $status): bool
    {
        return in_array(self::normalize($status), self::FAILED, true);
    }

    public static function isExpired(?string $status): bool
    {
        return in_array(self::normalize($status), self::EXPIRED, true);
    }

    public static function isRefunded(?string $status): bool
    {
        return in_array(self::normalize($status), self::REFUNDED, true);
    }

    public static function isPending(?string $status): bool
    {
        return in_array(self::normalize($status), self::PENDING, true);
    }

    /**
     * Whether this status is final at NOWPayments — no further transition is
     * expected for this payment id.
     */
    public static function isTerminal(?string $status): bool
    {
        $status = self::normalize($status);

        return in_array($status, self::SETTLED, true)
            || in_array($status, self::UNDERPAID, true)
            || in_array($status, self::FAILED, true)
            || in_array($status, self::EXPIRED, true)
            || in_array($status, self::REFUNDED, true);
    }

    /**
     * Whether an inbound IPN carrying this status is worth a server-to-server
     * verification. Everything except the purely informational in-flight states
     * qualifies, including unknown statuses — we ask the API rather than guess.
     */
    public static function warrantsVerification(?string $status): bool
    {
        return ! self::isPending($status);
    }

    /**
     * Monotonic progress rank. Higher wins; equal or lower must never overwrite
     * a status already recorded against the same payment.
     */
    public static function rank(?string $status): int
    {
        return match (self::normalize($status)) {
            'waiting' => 10,
            'confirming' => 20,
            'confirmed' => 30,
            'sending' => 40,
            'partially_paid' => 90,
            'expired' => 95,
            'failed', 'cancelled', 'canceled' => 96,
            'finished', 'paid', 'completed' => 100,
            'refunded' => 110,
            default => 0,
        };
    }
}
