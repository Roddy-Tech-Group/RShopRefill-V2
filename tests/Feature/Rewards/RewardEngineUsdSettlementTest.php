<?php

namespace Tests\Feature\Rewards;

use App\Domain\Communication\Mail\Rewards\RcoinEarnedMail;
use App\Domain\Rewards\Services\RewardEngine;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rcoin cashback must be computed from the order's settlement USD, never from
 * the display-currency total. A 4000 XAF order is ~$6.67, not $4000 - the bug
 * this guards against credited 4000 Rcoin for it.
 */
class RewardEngineUsdSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('rcoin_enabled', true);
        Setting::set('cashback_percentage', 1.0);
        Setting::set('rcoin_usd_rate', 0.01);
        Setting::set('referral_enabled', false);
    }

    private function makeCompletedOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'RSR-TEST-'.fake()->unique()->numerify('######'),
            'cart_id' => null,
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10.00,
            'markup_amount' => 0,
            'total_amount' => 10.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'completed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => 10.00, 'settlement_subtotal_usd' => 10.00],
        ], $overrides));
    }

    public function test_xaf_order_earns_rcoin_from_settlement_usd_not_display_total(): void
    {
        $user = User::factory()->create();

        // 4000 XAF at 600 XAF/USD settles at $6.67. 1% cashback = $0.0667
        // = 6 Rcoin at the $0.01 rate. The display figure must play no part.
        $order = $this->makeCompletedOrder($user, [
            'settlement_currency' => 'XAF',
            'display_currency' => 'XAF',
            'subtotal_amount' => 4000,
            'total_amount' => 4000,
            'metadata' => ['exchange_rate' => 600.0, 'settlement_total_usd' => 6.6667, 'settlement_subtotal_usd' => 6.6667],
        ]);

        app(RewardEngine::class)->processOrderRewards($order);

        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::RCOIN);
        $this->assertSame(6.0, (float) $wallet->refresh()->balance);
    }

    public function test_usd_order_cashback_is_unchanged(): void
    {
        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user);

        app(RewardEngine::class)->processOrderRewards($order);

        // $10 x 1% = $0.10 = 10 Rcoin at the $0.01 rate.
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::RCOIN);
        $this->assertSame(10.0, (float) $wallet->refresh()->balance);
    }

    public function test_cashback_no_longer_sends_a_dedicated_rcoin_email(): void
    {
        // The earning is shown on the order-delivery email instead.
        Mail::fake();

        $user = User::factory()->create();
        $order = $this->makeCompletedOrder($user);

        app(RewardEngine::class)->processOrderRewards($order);

        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::RCOIN);
        $this->assertSame(10.0, (float) $wallet->refresh()->balance);
        Mail::assertNotQueued(RcoinEarnedMail::class);
    }
}
