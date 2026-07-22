<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Models\Notification;
use Illuminate\Support\Str;

/**
 * Handles delivery of the in-app notification bell feed.
 * Records to the existing `notifications` table which the frontend queries.
 */
class InAppChannel implements ChannelInterface
{
    public function send(CommunicationPayload $payload): DeliveryResult
    {
        try {
            // Re-use the existing notifications table for the bell feed
            Notification::create([
                'id' => Str::uuid()->toString(),
                'user_id' => $payload->recipient->id,
                'type' => $payload->category->value,
                'title' => $payload->title,
                'message' => $payload->message,
                'channel' => CommunicationChannel::InApp->value,
                'status' => 'sent',
                'priority' => $payload->priority->value,
                'metadata' => $payload->metadata,
                'sent_at' => now(),
            ]);

            return new DeliveryResult(
                channel: CommunicationChannel::InApp,
                status: DeliveryStatus::Sent,
            );
        } catch (\Throwable $e) {
            return new DeliveryResult(
                channel: CommunicationChannel::InApp,
                status: DeliveryStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }
}
