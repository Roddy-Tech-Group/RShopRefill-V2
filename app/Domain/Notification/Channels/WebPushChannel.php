<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\DTOs\NotificationPayload;
use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Providers\WebPushProvider;
use App\Domain\Notification\Services\DeviceManager;
use App\Models\Notification;
use App\Models\NotificationDelivery;

class WebPushChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly WebPushProvider $provider,
        private readonly DeviceManager $deviceManager
    ) {}

    public function send(NotificationPayload $payload, ?Notification $dbNotification = null): void
    {
        $user = $payload->user;

        // 1. Get user's push subscriptions
        $subscriptions = $this->deviceManager->getSubscriptionsFor($user);

        if ($subscriptions->isEmpty()) {
            $this->recordDelivery($payload, DeliveryStatus::Failed, 'No active push subscriptions for user');

            return;
        }

        $pushPayload = [
            'title' => $payload->title,
            'body' => $payload->message,
            'icon' => '/icon-192x192.png',
            'badge' => '/badge-72x72.png',
            'url' => $payload->metadata['url'] ?? config('app.url'),
            'id' => $payload->id ?? null,
            // Carried through so the service worker's notificationclick can
            // attribute the click back to its campaign (the Clicked stat).
            'campaign_id' => $payload->metadata['campaign_id'] ?? null,
        ];

        $overallSuccess = false;
        $expiredEndpoints = [];
        $lastError = null;

        // 2. Dispatch to provider
        foreach ($subscriptions as $sub) {
            $subArray = [
                'endpoint' => $sub->endpoint,
                'keys' => [
                    'p256dh' => $sub->p256dh_key,
                    'auth' => $sub->auth_token,
                ],
            ];

            $results = $this->provider->send($subArray, $pushPayload);

            foreach ($results as $result) {
                if ($result['success']) {
                    $overallSuccess = true;
                } else {
                    $lastError = $result['reason'];
                    if ($result['expired']) {
                        $expiredEndpoints[] = $result['endpoint'];
                    }
                }
            }
        }

        // 3. Clean up expired subscriptions
        if (! empty($expiredEndpoints)) {
            $this->deviceManager->deleteSubscriptionsByEndpoint($expiredEndpoints);
        }

        // 4. Record delivery audit
        if ($overallSuccess) {
            $this->recordDelivery($payload, DeliveryStatus::Delivered, null);
        } else {
            $this->recordDelivery($payload, DeliveryStatus::Failed, $lastError ?? 'All endpoints failed');
        }
    }

    private function recordDelivery(NotificationPayload $payload, DeliveryStatus $status, ?string $error): void
    {
        NotificationDelivery::create([
            'user_id' => $payload->user->id,
            'title' => $payload->title,
            'message' => $payload->message,
            'channel' => NotificationChannel::Push,
            'provider' => 'web-push',
            'recipient' => 'browser-endpoints',
            'status' => $status,
            'priority' => $payload->priority,
            'error_message' => $error,
            'metadata' => $payload->metadata,
        ]);
    }
}
