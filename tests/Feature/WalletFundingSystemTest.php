<?php

namespace Tests\Feature;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Events\FundingCompleted;
use App\Domain\Wallet\Jobs\ProcessFundingWebhookJob;
use App\Domain\Wallet\Jobs\SyncExchangeRatesJob;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\CurrencyRate;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WalletFundingSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default CurrencyRate
        CurrencyRate::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'type' => 'fiat',
            'rate_per_usd' => 1.0,
            'is_active' => true,
        ]);

        CurrencyRate::create([
            'code' => 'NGN',
            'name' => 'Nigerian Naira',
            'type' => 'fiat',
            'rate_per_usd' => 1400.0,
            'is_active' => true,
        ]);
    }

    /**
     * Test initiating wallet funding through storefront APIs.
     */
    public function test_user_can_initiate_wallet_funding(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => 100.0,
        ]);

        $response = $this->actingAs($user)->postJson(route('api.wallets.fund.initiate'), [
            'currency' => 'USD',
            'amount' => 50.0,
            'display_currency' => 'NGN', // Option to pay in alternate currency
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'payment_link',
                'reference',
                'requested_amount',
                'display_currency',
                'exchange_rate',
            ]);

        $this->assertDatabaseHas('wallet_fundings', [
            'user_id' => $user->id,
            'currency' => 'USD',
            'display_currency' => 'NGN',
            'amount' => 50.0,
            'requested_amount' => 70000.0, // 50 * 1400 NGN
            'exchange_rate_snapshot' => 1400.0,
            'status' => 'pending',
        ]);

        $funding = WalletFunding::where('user_id', $user->id)->first();

        $this->assertDatabaseHas('payment_attempts', [
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'currency' => 'NGN',
            'amount' => 70000.0,
            'exchange_rate_snapshot' => 1400.0,
            'payment_status' => 'pending',
        ]);
    }

    /**
     * Test webhook signature validation and asynchronous job dispatch.
     */
    public function test_incoming_webhook_saves_and_queues_processing(): void
    {
        Queue::fake();
        config(['services.flutterwave.webhook_hash' => 'FLW_SECURE_HASH']);

        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => 0.0,
        ]);

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-USD-12345',
            'currency' => 'USD',
            'amount' => 50.0,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);

        PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'FUND-USD-12345',
            'currency' => 'USD',
            'amount' => 50.0,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $payload = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 123456,
                'tx_ref' => 'FUND-USD-12345',
                'amount' => 50.0,
                'currency' => 'USD',
                'status' => 'successful',
            ],
        ];

        // Send with CORRECT signature hash
        $response = $this->withHeaders([
            'verif-hash' => 'FLW_SECURE_HASH',
        ])->postJson(route('api.webhooks.flutterwave'), $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        // Verify webhook immediately saved in raw registry
        $this->assertDatabaseHas('payment_webhooks', [
            'gateway' => 'flutterwave',
            'event_type' => 'charge.completed',
            'reference' => 'FUND-USD-12345',
            'processed' => false,
            'processing_status' => 'pending',
        ]);

        $webhook = PaymentWebhook::where('reference', 'FUND-USD-12345')->first();

        // Verify job was queued
        Queue::assertPushed(ProcessFundingWebhookJob::class, function ($job) use ($webhook) {
            $reflector = new \ReflectionProperty(ProcessFundingWebhookJob::class, 'paymentWebhookId');
            $reflector->setAccessible(true);

            return $reflector->getValue($job) === $webhook->id;
        });
    }

    /**
     * Test that signature verification failure logs error and responds with 401.
     */
    public function test_webhook_with_bad_signature_gets_rejected(): void
    {
        Queue::fake();
        config(['services.flutterwave.webhook_hash' => 'FLW_SECURE_HASH']);

        $payload = [
            'event' => 'charge.completed',
            'data' => [
                'id' => 123456,
                'tx_ref' => 'FUND-USD-54321',
                'amount' => 50.0,
                'status' => 'successful',
            ],
        ];

        // Send with WRONG signature hash
        $response = $this->withHeaders([
            'verif-hash' => 'BAD_HASH',
        ])->postJson(route('api.webhooks.flutterwave'), $payload);

        $response->assertStatus(401);

        $this->assertDatabaseMissing('payment_webhooks', [
            'reference' => 'FUND-USD-54321',
        ]);

        Queue::assertNotPushed(ProcessFundingWebhookJob::class);
    }

    /**
     * Test successful verification and crediting via ProcessFundingWebhookJob.
     */
    public function test_webhook_job_credits_wallet_idempotently(): void
    {
        Event::fake([
            FundingCompleted::class,
        ]);

        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => 10.0,
        ]);

        // Initiate a pending funding
        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-USD-777',
            'currency' => 'USD',
            'amount' => 50.0,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'FUND-USD-777',
            'currency' => 'USD',
            'amount' => 50.0,
            'payment_status' => PaymentStatus::Pending,
        ]);

        // Prepare raw webhook log
        $webhook = PaymentWebhook::create([
            'gateway' => 'flutterwave',
            'event_type' => 'charge.completed',
            'reference' => 'FUND-USD-777',
            'payload' => [
                'event' => 'charge.completed',
                'data' => [
                    'id' => 99999,
                    'tx_ref' => 'FUND-USD-777',
                    'amount' => 50.0,
                    'currency' => 'USD',
                    'status' => 'successful',
                ],
            ],
            'processed' => false,
            'processing_status' => 'pending',
        ]);

        // Execute processing job
        $job = new ProcessFundingWebhookJob($webhook->id);
        $job->handle(app(WalletFundingService::class));

        // 1. Verify webhook status updated
        $this->assertTrue($webhook->refresh()->processed);
        $this->assertEquals('completed', $webhook->processing_status);

        // 2. Verify funding state updated
        $this->assertEquals(FundingStatus::Completed, $funding->refresh()->status);
        $this->assertEquals(99999, $funding->gateway_reference);

        // 3. Verify wallet was credited
        $this->assertEquals(60.0, $wallet->refresh()->balance);

        // 4. Verify PaymentAttempt confirmed
        $this->assertEquals(PaymentStatus::Paid, $attempt->refresh()->payment_status);

        // 5. Verify Event Fired
        Event::assertDispatched(FundingCompleted::class);
    }

    /**
     * Test amount/currency mismatch verification checks.
     */
    public function test_tampered_payload_causes_verification_failure(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => 10.0,
        ]);

        // Initiate a pending funding for $500.00 (triggers mock verified lower amount check)
        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-USD-888',
            'currency' => 'USD',
            'amount' => 500.0,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);

        // Webhook fires with altered/lower amount ($10.00)
        $webhook = PaymentWebhook::create([
            'gateway' => 'flutterwave',
            'event_type' => 'charge.completed',
            'reference' => 'FUND-USD-888',
            'payload' => [
                'event' => 'charge.completed',
                'data' => [
                    'id' => 99999,
                    'tx_ref' => 'FUND-USD-888',
                    'amount' => 10.0, // TAMPERED!
                    'currency' => 'USD',
                    'status' => 'successful',
                ],
            ],
            'processed' => false,
            'processing_status' => 'pending',
        ]);

        // Execute processing job
        try {
            $job = new ProcessFundingWebhookJob($webhook->id);
            $job->handle(app(WalletFundingService::class));
        } catch (\Throwable $e) {
            // Expect verification exception
        }

        // Verify webhook status failed
        $this->assertEquals('failed', $webhook->refresh()->processing_status);

        // Verify funding state marked failed due to tampering
        $this->assertEquals(FundingStatus::Failed, $funding->refresh()->status);
        $this->assertStringContainsString('tamper', strtolower($funding->failed_reason));

        // Wallet must not be credited!
        $this->assertEquals(10.0, $wallet->refresh()->balance);
    }

    /**
     * Test scheduling exchange rate sync registry.
     */
    public function test_exchange_rate_sync_job_populates_registry(): void
    {
        $job = new SyncExchangeRatesJob;
        $job->handle();

        $this->assertDatabaseHas('exchange_rates', [
            'base_currency' => 'USD',
            'target_currency' => 'NGN',
            'rate' => 1400.0,
            'provider' => 'system_sync',
        ]);

        $this->assertDatabaseHas('exchange_rates', [
            'base_currency' => 'NGN',
            'target_currency' => 'USD',
            'provider' => 'system_sync',
        ]);
    }
}
