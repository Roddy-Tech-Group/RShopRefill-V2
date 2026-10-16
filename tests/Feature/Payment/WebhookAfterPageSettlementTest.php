<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A gateway's success webhook usually lands after the page has already
 * verified and settled the payment (every popup and inline flow verifies by
 * our reference first). The verify run the webhook starts finds nothing to
 * do, and used to return before marking the webhook row processed, so the
 * admin's "Webhook deliveries" showed a pending delivery on every such
 * payment. A delivery that was received and understood reads as processed.
 */
class WebhookAfterPageSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_webhook_for_a_payment_the_page_already_settled_is_marked_processed(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'payment_status' => PaymentStatus::Paid, 'payment_method' => 'korapay']);
        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'korapay',
            'payment_method' => 'mobile_money',
            'gateway_reference' => $ref = 'PAY-'.Str::lower(Str::random(12)),
            'idempotency_key' => $ref,
            'currency' => 'XAF',
            'amount' => 6560,
            'payment_status' => PaymentStatus::Paid,
            'confirmed_at' => now()->subSeconds(20),
        ]);
        $webhook = PaymentWebhook::create([
            'gateway' => 'korapay',
            'event_type' => 'charge.success',
            'reference' => $ref,
            'payload' => ['event' => 'charge.success', 'data' => ['reference' => $ref, 'status' => 'success']],
            'headers' => [],
            'processed' => false,
            'processing_status' => 'pending',
            'processing_attempts' => 0,
        ]);

        VerifyPaymentJob::dispatchSync($attempt);

        $webhook->refresh();
        $this->assertTrue((bool) $webhook->processed);
        $this->assertSame('processed', $webhook->processing_status);
        $this->assertNotNull($webhook->processed_at);
        // Nothing was re-verified or re-settled: the gateway was not asked.
        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->payment_status);
    }
}
