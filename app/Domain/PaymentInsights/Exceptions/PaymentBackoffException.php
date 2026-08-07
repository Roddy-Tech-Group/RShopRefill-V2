<?php

namespace App\Domain\PaymentInsights\Exceptions;

use Exception;

class PaymentBackoffException extends Exception
{
    public readonly int $waitSeconds;

    public function __construct(int $waitSeconds, string $message = 'Too many failed payment attempts. Please wait.')
    {
        parent::__construct($message);
        $this->waitSeconds = $waitSeconds;
    }

    /**
     * Customer-facing copy. Always name the wait: "try again later" reads as a
     * dead end and sends the buyer to a competitor, whereas a concrete number
     * reads as a queue and they come back. Both checkout controllers and the
     * wallet funding component surface this verbatim.
     */
    public function customerMessage(): string
    {
        return 'Too many payment attempts. Please wait '.$this->waitLabel().' and try again.';
    }

    /** Human-readable wait, e.g. "30 seconds" or "5 minutes". */
    public function waitLabel(): string
    {
        if ($this->waitSeconds < 60) {
            return max(1, $this->waitSeconds).' seconds';
        }

        $minutes = (int) ceil($this->waitSeconds / 60);

        return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
    }

    public function render($request)
    {
        return response()->json([
            'error' => 'Too many failed payment attempts.',
            'message' => $this->customerMessage(),
            'is_rate_limited' => true,
            'wait_seconds' => $this->waitSeconds,
        ], 429);
    }
}
