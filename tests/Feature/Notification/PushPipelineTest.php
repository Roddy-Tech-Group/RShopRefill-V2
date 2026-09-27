<?php

namespace Tests\Feature\Notification;

use App\Domain\Communication\Channels\WebPushChannel;
use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\DeliveryStatus;
use App\Domain\Communication\Providers\WebPushProvider;
use App\Domain\Communication\Services\DeviceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Four things that were wrong with the push pipeline at once.
 *
 * The pipeline itself worked: keys configured, subscriptions stored, endpoints
 * pruned when a push service rejected them. What was broken sat at both ends of
 * it - what the notification looked like when it arrived, and whether a customer
 * ever got the chance to receive one.
 */
class PushPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function worker(): string
    {
        return File::get(public_path('sw.js'));
    }

    private function subscribeHelper(): string
    {
        return File::get(resource_path('js/push-manager.js'));
    }

    private function promptSource(): string
    {
        return File::get(resource_path('views/components/push-prompt.blade.php'));
    }

    /**
     * The icon the service worker falls back to has to be a file that exists.
     *
     * It was /icon-192x192.png with a /badge-72x72.png beside it, and neither
     * has ever been in this repository. Nothing on the send path set an icon
     * either, so the fallback was not an edge case, it was every notification
     * we have ever sent.
     */
    public function test_the_fallback_icon_is_a_file_that_exists(): void
    {
        $js = $this->worker();

        $this->assertStringContainsString("const NOTIFICATION_ICON = '/assets/icon-192.png';", $js);
        $this->assertFileExists(public_path('assets/icon-192.png'));

        // Quoted, because the comment above the handler names both files while
        // explaining why they are gone. What must not survive is a reference.
        foreach (['/icon-192x192.png', '/badge-72x72.png'] as $ghost) {
            $this->assertStringNotContainsString("'".$ghost."'", $js, $ghost.' does not exist and is still referenced');
        }
    }

    /**
     * No badge is better than a broken one. Left out, the browser picks its own
     * mark for the status bar; pointed at a missing file, it gets nothing. We
     * have no monochrome silhouette drawn, so the worker only sets a badge when
     * the payload actually carries one.
     */
    public function test_the_badge_is_omitted_rather_than_guessed(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(payload\.badge\) \{\s*options\.badge = payload\.badge;\s*\}/',
            $this->worker()
        );

        $this->assertNull(config('webpush.notification.badge'), 'no badge asset exists to point at yet');
    }

    /** A push we cannot parse still has to show something: browsers require it. */
    public function test_an_unreadable_payload_still_shows_a_notification(): void
    {
        $js = $this->worker();

        $this->assertStringContainsString('payload = event.data.json();', $js);
        $this->assertStringContainsString("payload = { title: 'RshopRefill', body: event.data.text() };", $js);
    }

    /**
     * And the icon rides in the payload, so a client on an older worker still
     * gets the mark rather than the worker's own fallback.
     */
    public function test_the_icon_travels_with_the_payload(): void
    {
        $source = File::get(app_path('Domain/Communication/Providers/WebPushProvider.php'));

        $this->assertStringContainsString("'icon' => \$icon ?: config('webpush.notification.icon'),", $source);
        $this->assertStringContainsString("'badge' => config('webpush.notification.badge'),", $source);

        // array_filter is what drops the null badge, so it must still be there.
        $this->assertStringContainsString('json_encode(array_filter([', $source);
        $this->assertSame('/assets/icon-192.png', config('webpush.notification.icon'));
    }

    /**
     * The ordering that matters most in the whole feature.
     *
     * A browser asks for notification permission once. There is no second
     * prompt. This used to ask first and only afterwards look for the VAPID key
     * and the service worker, so a misconfigured deploy spent every visitor's
     * one chance on a flow that could not have succeeded, silently and
     * permanently.
     */
    public function test_nothing_is_asked_of_the_customer_before_we_know_we_can_deliver(): void
    {
        $js = $this->subscribeHelper();

        $keyCheck = strpos($js, 'vapid-public-key');
        $workerCheck = strpos($js, 'const registration = await this.readyWorker();');
        $theAsk = strpos($js, 'await Notification.requestPermission()');

        $this->assertNotFalse($keyCheck);
        $this->assertNotFalse($workerCheck);
        $this->assertNotFalse($theAsk);

        $this->assertLessThan($theAsk, $keyCheck, 'the VAPID key must be checked before the prompt');
        $this->assertLessThan($theAsk, $workerCheck, 'the service worker must be ready before the prompt');
    }

    /**
     * Waiting on the worker cannot become the new way to hang: ready() never
     * resolves until something is registered, so a registration that failed
     * would leave the call pending forever.
     */
    public function test_waiting_for_the_worker_gives_up(): void
    {
        $js = $this->subscribeHelper();

        $this->assertStringContainsString('const WORKER_TIMEOUT_MS = 10000;', $js);
        $this->assertStringContainsString('Promise.race([', $js);
        $this->assertStringContainsString('navigator.serviceWorker.ready,', $js);
    }

    /**
     * Each way out is named, because the caller has to tell a decision from a
     * failure. Only a decision closes the question.
     */
    public function test_every_outcome_says_which_kind_it_was(): void
    {
        $js = $this->subscribeHelper();

        foreach (["'unsupported'", "'no-key'", "'no-worker'", "'denied'"] as $outcome) {
            $this->assertStringContainsString('return '.$outcome.';', $js, $outcome.' is not returned anywhere');
        }

        // The last two are the same return: whether the server took it decides
        // between a subscription that exists and one that has to be retried.
        $this->assertStringContainsString(
            "return (await this.sync(subscription)) ? 'ok' : 'failed';",
            $js
        );
        $this->assertStringContainsString("return 'failed';", $js, 'a thrown error must report failure, not denial');
    }

    /**
     * The one-shot flag is written from the OUTCOME, never before the attempt.
     * 'ok' and 'denied' mean the browser put the question to them; anything else
     * is our side failing, and leaves the flag clear so a later visit retries.
     */
    public function test_the_one_shot_flag_is_only_spent_when_the_browser_actually_asked(): void
    {
        $blade = $this->promptSource();

        $this->assertMatchesRegularExpression(
            "/if \(result === 'ok' \|\| result === 'denied'\) \{\s*localStorage\.setItem\('pushAsked', '1'\);/",
            $blade
        );

        // The old shape set it up front, before subscribe() was even called.
        $this->assertDoesNotMatchRegularExpression(
            "/localStorage\.setItem\('pushAsked', '1'\);\s*window\.RshopPush\.subscribe\(\);/",
            $blade
        );

        // Same rule for the per-session sync marker.
        $this->assertStringContainsString("if (result === 'ok') sessionStorage.setItem('pushSynced', '1');", $blade);
        $this->assertDoesNotMatchRegularExpression(
            "/sessionStorage\.setItem\('pushSynced', '1'\);\s*window\.RshopPush\.subscribe\(\);/",
            $blade
        );
    }

    /**
     * An endpoint we already know is dead should not cost a request on every
     * notification. The channel read the relation straight off the recipient,
     * which includes expired rows; they were only pruned once a push service
     * bothered to reject them.
     */
    public function test_an_expired_subscription_is_not_sent_to(): void
    {
        $user = User::factory()->create();

        $user->pushSubscriptions()->create([
            'endpoint' => 'https://push.example/live',
            'p256dh_key' => 'live-key',
            'auth_token' => 'live-token',
            'expires_at' => null,
        ]);

        $user->pushSubscriptions()->create([
            'endpoint' => 'https://push.example/expired',
            'p256dh_key' => 'dead-key',
            'auth_token' => 'dead-token',
            'expires_at' => now()->subDay(),
        ]);

        $seen = null;

        $provider = $this->mock(WebPushProvider::class);
        $provider->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (Collection $subscriptions) use (&$seen) {
                $seen = $subscriptions->pluck('endpoint')->all();

                return ['success' => true, 'expired_endpoints' => [], 'failed_count' => 0, 'last_error' => null];
            });

        $result = (new WebPushChannel($provider, app(DeviceManager::class)))->send($this->payloadFor($user));

        $this->assertSame(DeliveryStatus::Sent, $result->status);
        $this->assertSame(['https://push.example/live'], $seen);
    }

    /** A recipient with only expired rows is suppressed, not failed. */
    public function test_a_recipient_whose_only_endpoint_expired_is_suppressed(): void
    {
        $user = User::factory()->create();

        $user->pushSubscriptions()->create([
            'endpoint' => 'https://push.example/expired-only',
            'p256dh_key' => 'dead-key',
            'auth_token' => 'dead-token',
            'expires_at' => now()->subDay(),
        ]);

        $provider = $this->mock(WebPushProvider::class);
        $provider->shouldNotReceive('send');

        $result = (new WebPushChannel($provider, app(DeviceManager::class)))->send($this->payloadFor($user));

        $this->assertSame(DeliveryStatus::Suppressed, $result->status);
    }

    private function payloadFor(User $user): CommunicationPayload
    {
        return new CommunicationPayload(
            recipient: $user,
            category: CommunicationCategory::OrderFulfilled,
            title: 'Order delivered',
            message: 'Your codes are ready.',
        );
    }
}
