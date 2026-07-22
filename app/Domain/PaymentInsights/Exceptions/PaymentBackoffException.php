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

    public function render($request)
    {
        return response()->json([
            'error' => 'Too many failed payment attempts.',
            'is_rate_limited' => true,
            'wait_seconds' => $this->waitSeconds,
        ], 429);
    }
}
