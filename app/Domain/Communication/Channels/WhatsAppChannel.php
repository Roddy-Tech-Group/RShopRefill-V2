<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use Illuminate\Support\Facades\Log;

/**
 * Stub channel for WhatsApp delivery.
 * To be fully implemented when Meta/Twilio WhatsApp API is integrated.
 */
class WhatsAppChannel implements ChannelInterface
{
    public function send(CommunicationPayload $payload): DeliveryResult
    {
        Log::info('WhatsApp Channel stub triggered', [
            'recipient' => $payload->recipient->id,
            'message' => $payload->message,
        ]);

        return new DeliveryResult(
            channel: CommunicationChannel::WhatsApp,
            status: DeliveryStatus::Sent, // Pretend sent
            providerMessageId: 'stub_wa_'.uniqid(),
            providerResponse: ['status' => 'stubbed_success'],
        );
    }
}
