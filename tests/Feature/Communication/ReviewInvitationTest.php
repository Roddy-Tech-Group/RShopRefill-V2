<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Providers\ResendProvider;
use App\Domain\Communication\Services\ChannelResolver;
use App\Domain\Communication\Services\PreferenceManager;
use App\Domain\Communication\Services\ResendApiThrottle;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Reviews\Services\ReviewInvitation;
use App\Domain\Rewards\Services\RewardEngine;
use App\Models\CommunicationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Automated review invitations by blind copy: when an order delivers in full,
 * a short order-complete email goes to the customer with the review
 * platform's address in BCC. The copy goes to a third party, so it may never
 * ride on anything that carries a code.
 */
class ReviewInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const INVITE_ADDRESS = 'reviews-inbox@invites.example.com';

    private const SECRET_CODE = 'GIFT-SECRET-7Q2X-9LMN';

    private const SECRET_PIN = '884213';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.resend.key' => 'test-key']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'resend-ok'], 200)]);
    }

    public function test_a_fully_delivered_order_sends_the_note_with_the_invitation_in_bcc_and_no_codes(): void
    {
        SiteSetting::put(ReviewInvitation::SETTING_KEY, self::INVITE_ADDRESS, 'reviews');
        $order = $this->paidOrderWithDeliveredCode();

        app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::Fulfilled);

        $sent = $this->orderCompleteRequests();
        $this->assertCount(1, $sent, 'exactly one order-complete email goes out');

        $payload = $sent[0]->data();
        $this->assertSame($order->user->email, $payload['to']);
        $this->assertSame([self::INVITE_ADDRESS], $payload['bcc']);
        $this->assertTrue(str_contains($payload['html'], $order->order_number), 'the note names the order');
        $this->assertFalse(str_contains($payload['html'], self::SECRET_CODE), 'a code reached the blind-copied email');
        $this->assertFalse(str_contains($payload['html'], self::SECRET_PIN), 'a PIN reached the blind-copied email');

        // Email and nothing else: a bell entry or push would only repeat the
        // delivery notification.
        $channels = CommunicationLog::query()
            ->where('category', CommunicationCategory::OrderComplete->value)
            ->pluck('channel')
            ->map(fn ($channel) => $channel instanceof CommunicationChannel ? $channel->value : $channel)
            ->all();
        $this->assertSame([CommunicationChannel::Email->value], $channels);
    }

    public function test_a_partly_delivered_order_sends_no_invitation(): void
    {
        SiteSetting::put(ReviewInvitation::SETTING_KEY, self::INVITE_ADDRESS, 'reviews');
        $order = $this->paidOrderWithDeliveredCode();

        app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::PartiallyFulfilled);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertCount(0, $this->orderCompleteRequests());
    }

    public function test_nothing_is_sent_while_the_address_is_empty_or_not_an_email(): void
    {
        foreach (['', '   ', 'not-an-address'] as $value) {
            SiteSetting::put(ReviewInvitation::SETTING_KEY, $value, 'reviews');
            $order = $this->paidOrderWithDeliveredCode();

            app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::Fulfilled);

            $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        }

        $this->assertCount(0, $this->orderCompleteRequests());
        $this->assertSame(0, CommunicationLog::query()->where('category', CommunicationCategory::OrderComplete->value)->count());
    }

    public function test_a_completion_that_rolls_back_sends_nothing(): void
    {
        SiteSetting::put(ReviewInvitation::SETTING_KEY, self::INVITE_ADDRESS, 'reviews');
        $order = $this->paidOrderWithDeliveredCode();

        DB::beginTransaction();
        app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::Fulfilled);
        DB::rollBack();

        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertCount(0, $this->orderCompleteRequests());
    }

    public function test_a_setting_that_is_not_one_address_cannot_cost_the_customer_their_rewards(): void
    {
        // The settings page stores whatever JSON-decodes, so a pasted list
        // lands as an array. The note must quietly stay off, and the rewards
        // queued after the same commit must still go out.
        SiteSetting::put(ReviewInvitation::SETTING_KEY, [self::INVITE_ADDRESS, 'second@example.com'], 'reviews');
        $rewards = $this->spy(RewardEngine::class);
        $order = $this->paidOrderWithDeliveredCode();

        app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::Fulfilled);

        $this->assertNull(ReviewInvitation::bccAddress());
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $rewards->shouldHaveReceived('processOrderRewards');
        $this->assertCount(0, $this->orderCompleteRequests());
    }

    public function test_an_order_finished_by_hand_after_a_partial_delivery_is_invited_once(): void
    {
        SiteSetting::put(ReviewInvitation::SETTING_KEY, self::INVITE_ADDRESS, 'reviews');
        $order = $this->paidOrderWithDeliveredCode();

        app(OrderService::class)->transitionFulfillmentStatus($order, FulfillmentStatus::PartiallyFulfilled);
        $this->assertCount(0, $this->orderCompleteRequests());

        // The missing item is delivered later: the order was already
        // Completed, so only the move to Fulfilled marks it whole.
        app(OrderService::class)->transitionFulfillmentStatus($order->fresh(), FulfillmentStatus::Fulfilled);
        app(OrderService::class)->transitionFulfillmentStatus($order->fresh(), FulfillmentStatus::Fulfilled);

        $sent = $this->orderCompleteRequests();
        $this->assertCount(1, $sent);
        $this->assertSame([self::INVITE_ADDRESS], $sent[0]->data()['bcc']);
    }

    public function test_no_mail_but_the_order_complete_note_declares_a_copy_recipient(): void
    {
        // BCC forwarding in the provider is generic. What keeps codes away
        // from the review platform is that no code-carrying mail ever
        // declares a copy recipient; this pins that for every mailable.
        $declaring = [];

        foreach (File::allFiles(app_path()) as $file) {
            $source = $file->getContents();

            if (! str_contains($source, 'extends Mailable')) {
                continue;
            }

            if (preg_match('/\bbcc\s*:|->bcc\(|\bcc\s*:|->cc\(/i', $source)) {
                $declaring[] = $file->getFilename();
            }
        }

        $this->assertSame(['OrderCompleteMail.php'], $declaring);
    }

    public function test_the_provider_forwards_a_blind_copy_only_when_the_mail_declares_one(): void
    {
        $user = User::factory()->create();
        $provider = new ResendProvider(new ResendApiThrottle(maxPerSecond: 100, maxWaitMilliseconds: 0));

        $provider->send(
            to: $user->email,
            from: 'Orders <orders@rshoprefill.com>',
            replyTo: 'support@rshoprefill.com',
            subject: 'Welcome',
            mailable: new WelcomeMail($user),
        );

        Http::assertSent(fn (Request $request) => ! array_key_exists('bcc', $request->data()));
    }

    public function test_the_order_complete_category_resolves_to_email_only(): void
    {
        $user = User::factory()->create();

        $channels = app(ChannelResolver::class)->resolve(
            recipient: $user,
            category: CommunicationCategory::OrderComplete,
            hasMailable: true,
            hasOverrideEmail: false,
            preferenceManager: app(PreferenceManager::class),
        );

        $this->assertSame([CommunicationChannel::Email], $channels);
    }

    public function test_the_address_setting_is_seeded_empty_under_reviews(): void
    {
        $setting = SiteSetting::query()->where('key', ReviewInvitation::SETTING_KEY)->first();

        $this->assertNotNull($setting, 'the setting row must exist so admins can fill it in');
        $this->assertSame('reviews', $setting->group);
        $this->assertSame('', SiteSetting::get(ReviewInvitation::SETTING_KEY));
        $this->assertNull(ReviewInvitation::bccAddress());
    }

    private function paidOrderWithDeliveredCode(): Order
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 25.00,
            'markup_amount' => 0,
            'total_amount' => 25.00,
            'payment_method' => 'card',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::NotStarted,
            'order_status' => OrderStatus::Processing,
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => 25.00],
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'subtotal_amount' => 25.00,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => ['code' => self::SECRET_CODE, 'pin' => self::SECRET_PIN],
        ]);

        return $order->fresh();
    }

    /**
     * @return list<Request>
     */
    private function orderCompleteRequests(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains((string) ($request->data()['subject'] ?? ''), 'is complete'))
            ->values()
            ->all();
    }
}
