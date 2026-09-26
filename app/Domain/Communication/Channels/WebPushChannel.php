<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Providers\WebPushProvider;
use App\Domain\Communication\Services\DeviceManager;

/**
 * Delivers browser/device push notifications via VAPID.
 */
class WebPushChannel implements ChannelInterface
{
    public function __construct(
        private readonly WebPushProvider $provider,
        private readonly DeviceManager $deviceManager,
    ) {}

    public function send(CommunicationPayload $payload): DeliveryResult
    {
        try {
            // Push requires a user with active push subscriptions
            if (! method_exists($payload->recipient, 'pushSubscriptions')) {
                return new DeliveryResult(
                    channel: CommunicationChannel::Push,
                    status: DeliveryStatus::Suppressed,
                    errorMessage: 'Recipient model does not support push subscriptions.',
                );
            }

            // Through the device manager, not the raw relation: it drops rows
            // whose expires_at has passed. Reading the relation directly meant
            // every send also went to endpoints we already knew were dead, and
            // they were only pruned once the push service rejected them, so a
            // known-expired row cost a request on every notification until
            // something happened to send to it.
            $subscriptions = $this->deviceManager->getSubscriptionsFor($payload->recipient);

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
            $report = $this->provider->send(
                subscriptions: $subscriptions,
                title: $payload->title,
                body: $payload->message,
                url: $payload->metadata['url'] ?? null,
                icon: $payload->metadata['icon'] ?? null
            );

            // Prune endpoints the push service says are gone so we stop
            // sending to them (ported from the legacy channel)
            if ($report['expired_endpoints'] !== []) {
                $this->deviceManager->deleteSubscriptionsByEndpoint($report['expired_endpoints']);
            }

            if ($report['success']) {
                return new DeliveryResult(
                    channel: CommunicationChannel::Push,
                    status: DeliveryStatus::Sent,
                );
            }

            // Every failure was an expired subscription: rot, not an error
            if ($report['failed_count'] > 0 && count($report['expired_endpoints']) === $report['failed_count']) {
                return new DeliveryResult(
                    channel: CommunicationChannel::Push,
                    status: DeliveryStatus::Suppressed,
                    errorMessage: 'All push subscriptions expired (pruned).',
                );
            }

            return new DeliveryResult(
                channel: CommunicationChannel::Push,
                status: DeliveryStatus::Failed,
                errorMessage: $report['last_error'] ?? 'WebPush provider returned failure.',
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
