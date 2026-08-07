<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Models\Admin;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Transactions page takes a `?q=` reference search. The header command bar
 * sends wallet-funding attempts here (they have no order page of their own to
 * land on), so a hit in the bar has to resolve to a visible row rather than an
 * unfiltered list the admin then has to scroll.
 */
class AdminTransactionsSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::firstOrCreate(
            ['email' => 'txn-search-admin@example.test'],
            ['name' => 'Txn Search Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
    }

    private function attempt(User $user, string $reference, ?Order $order = null): PaymentAttempt
    {
        return PaymentAttempt::create([
            'order_id' => $order?->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'gateway_reference' => $reference,
            'idempotency_key' => 'idem-'.$reference,
            'currency' => 'USD',
            'amount' => 15.00,
            'payment_status' => 'paid',
        ]);
    }

    public function test_search_narrows_the_list_to_the_matching_reference(): void
    {
        $wanted = User::factory()->create(['name' => 'Wanted Payer']);
        $other = User::factory()->create(['name' => 'Other Payer']);

        $this->attempt($wanted, 'FLW-WANTED-1');
        $this->attempt($other, 'FLW-OTHER-2');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['q' => 'FLW-WANTED-1']))
            ->assertOk()
            ->assertSee('FLW-WANTED-1')
            ->assertDontSee('FLW-OTHER-2');
    }

    public function test_search_matches_the_order_number_and_the_customer(): void
    {
        $user = User::factory()->create(['name' => 'Findable Payer', 'email' => 'findable@example.test']);
        $order = Order::factory()->for($user)->create(['order_number' => 'ORD-FINDME9']);
        $this->attempt($user, 'FLW-VIA-ORDER');

        $noise = User::factory()->create(['name' => 'Unrelated Payer']);
        $this->attempt($noise, 'FLW-NOISE-9');

        // Reached through the order number...
        $this->attempt($user, 'FLW-ON-ORDER', $order);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['q' => 'ORD-FINDME9']))
            ->assertOk()
            ->assertSee('FLW-ON-ORDER')
            ->assertDontSee('FLW-NOISE-9');

        // ...and through the customer's email.
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['q' => 'findable@example.test']))
            ->assertOk()
            ->assertSee('FLW-VIA-ORDER')
            ->assertDontSee('FLW-NOISE-9');
    }

    public function test_search_applies_to_the_refunded_tab_too(): void
    {
        $user = User::factory()->create(['name' => 'Refund Searcher']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance' => 10,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        foreach (['REF-KEEP-1', 'REF-DROP-2'] as $reference) {
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Credit,
                'currency' => 'USD',
                'amount' => 5.00,
                'balance_before' => 0,
                'balance_after' => 5.00,
                'transaction_category' => TransactionCategory::Refund,
                'reference' => $reference,
                'description' => 'Refund '.$reference,
            ]);
        }

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['status' => 'refunded', 'q' => 'REF-KEEP-1']))
            ->assertOk()
            ->assertSee('REF-KEEP-1')
            ->assertDontSee('REF-DROP-2');
    }

    /**
     * Pressing a status chip while a search is active must keep the search -
     * otherwise the chips silently widen the list back to the whole table.
     */
    public function test_status_chips_preserve_an_active_search(): void
    {
        $user = User::factory()->create();
        $this->attempt($user, 'FLW-CHIP-1');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['q' => 'FLW-CHIP-1']))
            ->assertOk()
            // Escaped, not raw: the href renders the query separator as &amp;.
            ->assertSee(route('admin.transactions', ['status' => 'failed', 'q' => 'FLW-CHIP-1']));
    }

    public function test_csv_export_filters_by_the_same_search(): void
    {
        $wanted = User::factory()->create(['name' => 'Export Wanted']);
        $other = User::factory()->create(['name' => 'Export Other']);

        $this->attempt($wanted, 'FLW-EXPORT-KEEP');
        $this->attempt($other, 'FLW-EXPORT-DROP');

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions.export', ['q' => 'FLW-EXPORT-KEEP']))
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('FLW-EXPORT-KEEP', $csv);
        $this->assertStringNotContainsString('FLW-EXPORT-DROP', $csv);
    }
}
