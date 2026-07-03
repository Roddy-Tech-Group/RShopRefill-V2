<?php

namespace App\Console\Commands;

use App\Domain\Notification\Providers\WebPushProvider;
use App\Models\PushSubscription;
use Illuminate\Console\Command;

class TestWebPushCommand extends Command
{
    protected $signature = 'webpush:test {--user= : User ID to send to (defaults to first subscribed user)} {--admin : Send to admin subscriptions instead}';

    protected $description = 'Send a test Web Push notification to verify the push pipeline works';

    public function handle(WebPushProvider $provider): int
    {
        $query = PushSubscription::query();

        if ($userId = $this->option('user')) {
            $query->where('subscribable_id', $userId)
                ->where('subscribable_type', $this->option('admin') ? 'App\\Models\\Admin' : 'App\\Models\\User');
        }

        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            $this->warn('No push subscriptions found. Please subscribe from the browser first.');
            $this->line('');
            $this->line('Steps to subscribe:');
            $this->line('  1. Open your site in Chrome (localhost or HTTPS)');
            $this->line('  2. Go to Settings → Notifications');
            $this->line('  3. Enable the "Web Push" toggle');
            $this->line('  4. Accept the browser permission prompt');
            $this->line('  5. Re-run this command');

            return 1;
        }

        $this->info("Found {$subscriptions->count()} subscription(s). Sending test push...");

        $payload = [
            'title' => '🎉 RshopRefills Push Test',
            'body' => 'If you see this, Web Push is working! '.now()->format('H:i:s'),
            'icon' => '/assets/icon-512.png',
            'badge' => '/assets/icon-192.png',
            'url' => '/dashboard',
            'data' => [
                'url' => '/dashboard',
                'test' => true,
            ],
        ];

        $successCount = 0;
        $failCount = 0;

        foreach ($subscriptions as $sub) {
            $subscriptionData = [
                'endpoint' => $sub->endpoint,
                'keys' => [
                    'p256dh' => $sub->p256dh,
                    'auth' => $sub->auth,
                ],
            ];

            $results = $provider->send($subscriptionData, $payload);

            foreach ($results as $result) {
                if ($result['success']) {
                    $successCount++;
                    $this->line("  ✓ Sent to {$sub->subscribable_type}#{$sub->subscribable_id}");
                } else {
                    $failCount++;
                    $this->error('  ✗ Failed: '.($result['reason'] ?? 'Unknown'));
                    if (! empty($result['expired'])) {
                        $this->warn('    Subscription expired — cleaning up.');
                        $sub->delete();
                    }
                }
            }
        }

        $this->newLine();
        $this->info("Done! Sent: {$successCount}, Failed: {$failCount}");

        return $failCount > 0 ? 1 : 0;
    }
}
