<?php

namespace App\Domain\Communication\Enums;

/**
 * Lifecycle states of a communication delivery attempt.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Suppressed = 'suppressed';  // Skipped due to bounce suppression list

    /**
     * Terminal states that should not be retried.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::Opened,
            self::Clicked,
            self::Bounced,
            self::Complained,
            self::Suppressed,
        ], true);
    }

    /**
     * States eligible for retry.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
