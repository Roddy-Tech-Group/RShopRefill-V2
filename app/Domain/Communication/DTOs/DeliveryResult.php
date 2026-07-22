<?php

namespace App\Domain\Communication\DTOs;

use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;

/**
 * Structured outcome of a single channel delivery attempt.
 */
class DeliveryResult
{
    public function __construct(
        public readonly CommunicationChannel $channel,
        public readonly DeliveryStatus $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorMessage = null,
        public readonly array $providerResponse = [],
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->status, [
            DeliveryStatus::Sent,
            DeliveryStatus::Delivered,
        ], true);
    }

    public function failed(): bool
    {
        return $this->status === DeliveryStatus::Failed;
    }
}
