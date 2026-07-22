<?php

namespace App\Domain\Communication\Providers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Enhanced WebPush provider.
 * Delivers push notifications to browsers via VAPID.
 */
class WebPushProvider
{
    public function send(
        Collection $subscriptions,
        string $title,
        string $body,
        ?string $url = null,
        ?string $icon = null
    ): bool {
        try {
            $auth = [
                'VAPID' => [
                    'subject' => env('VAPID_SUBJECT'),
                    'publicKey' => env('VAPID_PUBLIC_KEY'),
                    'privateKey' => env('VAPID_PRIVATE_KEY'),
                ],
            ];

            $webPush = new WebPush($auth);
            $payload = json_encode(array_filter([
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'icon' => $icon,
            ]));

            foreach ($subscriptions as $subscription) {
                $endpoint = $subscription->endpoint ?? null;
                $publicKey = $subscription->public_key ?? null;
                $authToken = $subscription->auth_token ?? null;

                if (! $endpoint) {
                    continue;
                }

                $sub = Subscription::create([
                    'endpoint' => $endpoint,
                    'publicKey' => $publicKey,
                    'authToken' => $authToken,
                ]);

                $webPush->queueNotification($sub, $payload);
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    Log::warning('WebPush delivery failed', [
                        'endpoint' => $report->getRequest()->getUri()->__toString(),
                        'reason' => $report->getReason(),
                    ]);
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('WebPushProvider critical failure', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
