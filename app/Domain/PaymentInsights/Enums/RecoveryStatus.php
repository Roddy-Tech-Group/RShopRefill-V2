<?php

namespace App\Domain\PaymentInsights\Enums;

/**
 * Lifecycle of a transaction recovery.
 *
 * The distinction that matters: Retrying means the customer went back to
 * checkout, Recovered means money actually arrived. The original
 * implementation set "recovered" the moment someone clicked the button,
 * which reported clicks as revenue and made the conversion rate fiction.
 */
enum RecoveryStatus: string
{
    /** Email sent, nothing heard back yet. */
    case Pending = 'pending';

    /** Customer opened the recovery link. */
    case Clicked = 'clicked';

    /** Customer chose to continue and was returned to checkout. */
    case Retrying = 'retrying';

    /** A payment actually confirmed. This is the only revenue state. */
    case Recovered = 'recovered';

    /** Customer told us why instead of retrying. */
    case FeedbackReceived = 'feedback_received';

    /** The link expired with no resolution. */
    case Abandoned = 'abandoned';

    /**
     * Superseded: the customer paid through a route we cannot attribute to
     * this recovery (a different order, or before the email landed).
     */
    case Cancelled = 'cancelled';

    /**
     * States where the recovery link is still live and actionable.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::Clicked->value, self::Retrying->value];
    }

    /** Whether the customer has engaged at all. */
    public function hasEngaged(): bool
    {
        return $this !== self::Pending;
    }
}
