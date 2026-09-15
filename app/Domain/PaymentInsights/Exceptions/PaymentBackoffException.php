<?php

namespace App\Domain\PaymentInsights\Exceptions;

use Exception;

class PaymentBackoffException extends Exception
{
    public readonly int $waitSeconds;

    public readonly bool $locked;

    public function __construct(int $waitSeconds, ?string $message = null, bool $locked = false)
    {
        $this->waitSeconds = $waitSeconds;
        $this->locked = $locked;

        parent::__construct($message ?? $this->customerMessage());
    }

    /**
     * Customer-facing copy, weighted to the rung. Always name the wait: "try
     * again later" reads as a dead end and sends the buyer to a competitor,
     * whereas a concrete number reads as a queue and they come back.
     *
     * A minute barely deserves a sentence, so it gets none of the scolding.
     * Five minutes says why, briefly. Only the six hour lock explains itself
     * and offers a door, because the one customer it ever catches by mistake
     * cannot wait it out and needs a human. None of them says "failed": the
     * payment was never attempted, and a customer told their payment failed
     * goes looking for another card, which is the behaviour being throttled.
     *
     * Both checkout controllers, the wallet funding component and the eSIM
     * top-up page surface this verbatim.
     */
    public function customerMessage(): string
    {
        if ($this->locked) {
            // No duration in the sentence: it would be frozen at the moment of
            // refusal, while the notice's live countdown carries the real wait.
            return 'Several cards were declined, so new payments are paused for a while. If you believe this is a mistake, contact support.';
        }

        if ($this->waitSeconds >= 60) {
            return 'Too many attempts, try again in '.$this->waitLabel().'.';
        }

        return 'Try again in '.$this->waitLabel().'.';
    }

    /** Human-readable wait, e.g. "30 seconds", "5 minutes" or "6 hours". */
    public function waitLabel(): string
    {
        if ($this->waitSeconds < 60) {
            return max(1, $this->waitSeconds).' seconds';
        }

        if ($this->waitSeconds >= 3600) {
            $hours = (int) ceil($this->waitSeconds / 3600);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        $minutes = (int) ceil($this->waitSeconds / 60);

        return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
    }

    public function render($request)
    {
        return response()->json([
            'error' => $this->customerMessage(),
            'message' => $this->customerMessage(),
            'is_rate_limited' => true,
            'locked' => $this->locked,
            'wait_seconds' => $this->waitSeconds,
        ], 429);
    }
}
