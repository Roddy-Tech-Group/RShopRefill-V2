<?php

namespace App\Domain\Notification\Providers;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushProvider
{
    private ?WebPush $webPush = null;

    /**
     * Lazily instantiate the WebPush client.
     *
     * We defer construction so that the singleton can be registered in the
     * container without crashing queue workers or artisan commands that
     * never actually send a push notification.
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

        // Setup rate limiting or bulk behavior if needed
        $this->webPush->setReuseVAPIDHeaders(true);

        return $this->webPush;
    }

    /**
     * Send a web push notification.
     */
    public function send(array $subscriptionArray, array $payload): array
    {
        try {
            $subscription = Subscription::create($subscriptionArray);

            // Queue the notification
            $this->client()->queueNotification(
                $subscription,
                json_encode($payload)
            );

            // Flush the queue immediately for single send
            $results = [];
            foreach ($this->client()->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $results[] = [
                        'endpoint' => $endpoint,
                        'success' => true,
                    ];
                } else {
                    $results[] = [
                        'endpoint' => $endpoint,
                        'success' => false,
                        'expired' => $report->isSubscriptionExpired(),
                        'reason' => $report->getReason(),
                    ];

                    Log::warning('Web Push failed.', [
                        'endpoint' => $endpoint,
                        'reason' => $report->getReason(),
                        'expired' => $report->isSubscriptionExpired(),
                    ]);
                }
            }

            return $results;

        } catch (\Throwable $e) {
            Log::error('WebPushProvider exception.', ['error' => $e->getMessage()]);

            return [
                [
                    'success' => false,
                    'expired' => false,
                    'reason' => $e->getMessage(),
                ],
            ];
        }
    }
}
