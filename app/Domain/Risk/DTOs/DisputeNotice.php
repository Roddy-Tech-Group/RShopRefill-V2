<?php

namespace App\Domain\Risk\DTOs;

use App\Domain\Risk\Enums\DisputeStatus;
use Illuminate\Support\Carbon;

/**
 * One gateway's word on one dispute, normalised. Built from a webhook or from
 * a row of the gateway's chargeback list; DisputeRecorder acts only on this.
 */
final readonly class DisputeNotice
{
    /**
     * @param  list<string>  $paymentReferences  every reference the gateway gave for the disputed payment, tried in order
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $gateway,
        public string $disputeId,
        public array $paymentReferences,
        public ?float $amount,
        public ?string $currency,
        public ?string $reason,
        public ?string $stage,
        public string $gatewayStatus,
        public DisputeStatus $status,
        public ?Carbon $dueAt,
        public ?Carbon $openedAt,
        public array $raw,
    ) {}
}
