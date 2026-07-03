<?php

namespace Tests\Feature\GiftCardTrading;

use App\Domain\GiftCardTrading\Services\PayoutOrchestrator;
use App\Enums\TradeStatus;
use App\Models\BankAccount;
use App\Models\GiftCardBrand;
use App\Models\GiftCardRate;
use App\Models\GiftCardTrade;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Money-safety guards on gift-card trade payouts. A payout must only fire for an
 * Approved trade, never twice, and a bank transfer must never go to an account the
 * trade owner doesn't own.
 */
class PayoutGuardTest extends TestCase
{
    use RefreshDatabase;

    private function trade(array $overrides = []): GiftCardTrade
    {
        $user = User::factory()->create();
        $brand = GiftCardBrand::create(['name' => 'Amazon', 'currency' => 'USD', 'is_active' => true]);
        $rate = GiftCardRate::create([
            'brand_id' => $brand->id,
            'country_code' => 'US',
            'currency' => 'USD',
            'min_value' => 1,
            'max_value' => 1000,
            'rate' => 1,
            'is_active' => true,
        ]);

        return GiftCardTrade::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'rate_id' => $rate->id,
            'payout_method' => 'wallet',
            'declared_value' => 100,
            'calculated_payout' => 100,
            'payout_currency' => 'USD',
            'status' => TradeStatus::Approved,
        ], $overrides));
    }

    public function test_refuses_to_pay_a_trade_that_is_not_approved(): void
    {
        $trade = $this->trade(['status' => TradeStatus::PendingReview]);

        $this->expectException(\RuntimeException::class);

        app(PayoutOrchestrator::class)->dispatchPayout($trade);
    }

    public function test_refuses_to_pay_a_trade_that_already_has_a_live_payout(): void
    {
        $trade = $this->trade();
        Payout::create([
            'trade_id' => $trade->id,
            'reference' => 'GC-PAYOUT-'.$trade->uuid,
            'amount' => 100,
            'status' => 'successful',
        ]);

        $this->expectException(\RuntimeException::class);

        app(PayoutOrchestrator::class)->dispatchPayout($trade->fresh());
    }

    public function test_refuses_bank_payout_to_an_account_not_owned_by_the_trade_user(): void
    {
        $trade = $this->trade(['payout_method' => 'bank']);
        $stranger = User::factory()->create();
        $strangerAccount = BankAccount::create([
            'user_id' => $stranger->id,
            'bank_name' => 'Some Bank',
            'bank_code' => '000',
            'account_number' => '1234567890',
            'account_name' => 'Stranger',
        ]);
        $trade->update(['bank_account_id' => $strangerAccount->id]);

        try {
            app(PayoutOrchestrator::class)->dispatchPayout($trade->fresh());
            $this->fail('Expected the payout to be refused for a non-owned bank account.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not owned', $e->getMessage());
        }

        // The guarding transaction rolled back, so no payout row was created.
        $this->assertDatabaseMissing('payouts', ['trade_id' => $trade->id]);
    }
}
