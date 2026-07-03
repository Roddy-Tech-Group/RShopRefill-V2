<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Models\Admin;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-refunds are wallet credits (wallet_transactions, category 'refund'), not
 * payment_attempts, so the admin Transactions "Refunded" tab now reads those
 * records - previously it queried payment_attempts only and showed nothing.
 */
class AdminTransactionsRefundsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::firstOrCreate(
            ['email' => 'txn-admin@example.test'],
            ['name' => 'Txn Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
    }

    public function test_refunded_tab_shows_wallet_refund_transactions(): void
    {
        $user = User::factory()->create(['name' => 'Refund Tester']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance' => 5,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransactionType::Credit,
            'currency' => 'USD',
            'amount' => 5.00,
            'balance_before' => 0,
            'balance_after' => 5.00,
            'transaction_category' => TransactionCategory::Refund,
            'reference' => 'REF-ORD-TEST',
            'description' => 'Refund for Order ORD-TEST',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['status' => 'refunded']))
            ->assertOk()
            ->assertSee('Refund Tester')
            ->assertSee('Wallet refund')
            ->assertSee('REF-ORD-TEST');
    }

    public function test_default_tab_still_renders(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions'))
            ->assertOk();
    }
}
