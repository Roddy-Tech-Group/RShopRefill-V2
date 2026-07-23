<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Providers\WebPushProvider;

/**
 * Delivers browser/device push notifications via VAPID.
 */
class WebPushChannel implements ChannelInterface
{
    public function __construct(
        private readonly WebPushProvider $provider,
    ) {}

    public function send(CommunicationPayload $payload): DeliveryResult
    {
        try {
            // Push requires a user with active push subscriptions
            if (! method_exists($payload->recipient, 'pushSubscriptions')) {
                return new DeliveryResult(
                    channel: CommunicationChannel::Push,
                    status: DeliveryStatus::Failed,
                    errorMessage: 'Recipient model does not support push subscriptions.',
                );
            }

            $subscriptions = $payload->recipient->pushSubscriptions;

            // Having no push subscriptions is an expected state for most
            // users, not a delivery failure — recording it as Failed floods
            // the failure metrics with noise (prod hot-fix ported from the
            // legacy channel). Suppressed = intentionally not attempted.
            if ($subscriptions->isEmpty()) {
                return new DeliveryResult(
                    channel: CommunicationChannel::Push,
                    status: DeliveryStatus::Suppressed,
                    errorMessage: 'No active push subscriptions for user.',
                );
            }

            // Fire to provider — it handles parallel delivery to all endpoints
            $success = $this->provider->send(
                subscriptions: $subscriptions,
                title: $payload->title,
                body: $payload->message,
                url: $payload->metadata['url'] ?? null,
                icon: $payload->metadata['icon'] ?? null
            );

            if ($success) {
                return new DeliveryResult(
                    channel: CommunicationChannel::Push,
                    status: DeliveryStatus::Sent,
                );
            }

            return new DeliveryResult(
                channel: CommunicationChannel::Push,
                status: DeliveryStatus::Failed,
                errorMessage: 'WebPush provider returned failure.',
            );

        } catch (\Throwable $e) {
            return new DeliveryResult(
                channel: CommunicationChannel::Push,
                status: DeliveryStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }
}
