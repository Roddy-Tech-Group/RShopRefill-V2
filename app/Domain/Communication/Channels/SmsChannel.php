<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use Illuminate\Support\Facades\Log;

/**
 * Stub channel for SMS delivery.
 * To be fully implemented when Twilio/MessageBird is integrated.
 */
class SmsChannel implements ChannelInterface
{
    public function send(CommunicationPayload $payload): DeliveryResult
    {
        Log::info('SMS Channel stub triggered', [
            'recipient' => $payload->recipient->id,
            'message' => $payload->message,
        ]);

        return new DeliveryResult(
            channel: CommunicationChannel::Sms,
            status: DeliveryStatus::Sent, // Pretend sent
            providerMessageId: 'stub_sms_'.uniqid(),
            providerResponse: ['status' => 'stubbed_success'],
        );
    }
}
