<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Models\Admin;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Circulating supply / Rcoin in customer wallets right now" must equal the actual
 * sum of Rcoin wallet balances. The old ledger formula (minted - redeemed - reversed)
 * drifted because it ignores reward_withdrawal and adjustment.
 */
class RcoinCirculatingSupplyTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): self
    {
        $admin = Admin::firstOrCreate(
            ['email' => 'rcoin-admin@example.test'],
            ['name' => 'Rcoin Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    private function rcoinTx(Wallet $wallet, TransactionCategory $category, WalletTransactionType $type, float $amount): void
    {
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'type' => $type,
            'currency' => Currency::RCOIN->value,
            'amount' => $amount,
            'balance_before' => 0,
            'balance_after' => 0,
            'transaction_category' => $category->value,
            'description' => 'test',
        ]);
    }

    public function test_circulating_supply_matches_actual_rcoin_wallet_balances(): void
    {
        $walletA = Wallet::factory()->for(User::factory())->create(['currency' => Currency::RCOIN->value, 'balance' => 300]);
        $walletB = Wallet::factory()->for(User::factory())->create(['currency' => Currency::RCOIN->value, 'balance' => 200]);
        // Actually held across wallets = 500.

        // A ledger the OLD formula would read as 800 - 100 - 0 = 700, because it
        // never subtracts the 200 reward_withdrawal.
        $this->rcoinTx($walletA, TransactionCategory::RewardCashback, WalletTransactionType::Credit, 800);
        $this->rcoinTx($walletA, TransactionCategory::RewardRedemption, WalletTransactionType::Debit, 100);
        $this->rcoinTx($walletB, TransactionCategory::RewardWithdrawal, WalletTransactionType::Debit, 200);

        $data = $this->asAdmin()
            ->getJson(route('admin.api.rewards.analytics.metrics'))
            ->assertOk()
            ->json();

        // The truth: 500 held, NOT the 700 the ledger formula would derive.
        $this->assertEqualsWithDelta(500, $data['metrics']['circulating_supply'], 0.001);
        $this->assertSame(800, $data['metrics']['total_minted']);
        $this->assertSame(100, $data['metrics']['total_redeemed']);
    }
}
