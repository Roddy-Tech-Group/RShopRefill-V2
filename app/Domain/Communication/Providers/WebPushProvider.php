<?php

namespace App\Domain\Communication\Providers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers push notifications to browsers via VAPID.
 *
 * VAPID keys are read from config('webpush.vapid.*') - never env() directly,
 * which returns null once config is cached in production.
 */
class WebPushProvider
{
    private ?WebPush $webPush = null;

    /**
     * Lazily instantiate the WebPush client so container registration never
     * crashes queue workers or artisan commands that don't send pushes.
     */
    private function client(): WebPush
    {
        if ($this->webPush !== null) {
            return $this->webPush;
        }

        // XAMPP on Windows needs the OpenSSL config path set explicitly.
        $opensslCandidates = [
            'C:\\xampp\\apache\\conf\\openssl.cnf',
            'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
        ];
        foreach ($opensslCandidates as $cnf) {
            if (is_file($cnf) && ! getenv('OPENSSL_CONF')) {
                putenv("OPENSSL_CONF={$cnf}");
                break;
            }
        }

        $auth = [
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ];

        // Only pass VAPID if we have a public key, otherwise this breaks during testing
        $this->webPush = new WebPush(! empty($auth['VAPID']['publicKey']) ? $auth : []);
        $this->webPush->setReuseVAPIDHeaders(true);

        return $this->webPush;
    }

    /**
     * Send a push notification to every subscription endpoint.
     *
     * @return array{success: bool, expired_endpoints: list<string>, failed_count: int, last_error: ?string}
     */
    public function send(
        Collection $subscriptions,
        string $title,
        string $body,
        ?string $url = null,
        ?string $icon = null
    ): array {
        $expiredEndpoints = [];
        $anySuccess = false;
        $failedCount = 0;
        $lastError = null;

        try {
            $payload = json_encode(array_filter([
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'icon' => $icon,
            ]));

            $queued = 0;
            foreach ($subscriptions as $subscription) {
                if (empty($subscription->endpoint)) {
                    continue;
                }

                $sub = Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    // push_subscriptions stores the browser key as p256dh_key
                    // (see DeviceManager::storeSubscription)
                    'publicKey' => $subscription->p256dh_key ?? null,
                    'authToken' => $subscription->auth_token ?? null,
                ]);

                $this->client()->queueNotification($sub, $payload);
                $queued++;
            }

            if ($queued === 0) {
                return ['success' => false, 'expired_endpoints' => [], 'failed_count' => 0, 'last_error' => 'No usable endpoints.'];
            }

            foreach ($this->client()->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $anySuccess = true;

                    continue;
                }

                $failedCount++;
                $lastError = $report->getReason();
                if ($report->isSubscriptionExpired()) {
                    $expiredEndpoints[] = $endpoint;
                }

                Log::warning('WebPush delivery failed', [
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                    'expired' => $report->isSubscriptionExpired(),
                ]);
            }
        } catch (\Throwable $e) {
            $failedCount++;
            $lastError = $e->getMessage();
            Log::error('WebPushProvider critical failure', ['error' => $e->getMessage()]);
        }

        return [
            'success' => $anySuccess,
            'expired_endpoints' => $expiredEndpoints,
            'failed_count' => $failedCount,
            'last_error' => $lastError,
        ];
    }
}
