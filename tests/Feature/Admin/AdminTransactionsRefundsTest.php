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

    /**
     * wallet_transactions only ever grows, so the Refunded tab must paginate:
     * with a hard 50-row cap the 51st refund was unreachable, and the
     * subheading counted payment_attempts (a different table) instead.
     */
    public function test_refunded_tab_paginates_and_counts_the_rows_it_shows(): void
    {
        $user = User::factory()->create(['name' => 'Paged Refunder']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        // Spaced timestamps keep latest() deterministic: REF-PAGE-1 is newest,
        // REF-PAGE-51 is oldest and therefore the only row on page 2.
        //
        // The spread has to be forced in afterwards. created_at is not fillable,
        // so passing it to create() drops it silently and stamps all 51 rows with
        // the same now(); latest() then has 51 ties and the database decides which
        // row falls off page 1.
        foreach (range(1, 51) as $index) {
            $txn = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Credit,
                'currency' => 'USD',
                'amount' => 1.00,
                'balance_before' => 0,
                'balance_after' => 1.00,
                'transaction_category' => TransactionCategory::Refund,
                'reference' => 'REF-PAGE-'.$index,
                'description' => 'Refund '.$index,
            ]);

            $txn->forceFill([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ])->save();
        }

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['status' => 'refunded']))
            ->assertOk()
            ->assertSee('51 wallet refunds matching this filter')
            ->assertDontSee('payment attempts')
            ->assertDontSee('REF-PAGE-51');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['status' => 'refunded', 'page' => 2]))
            ->assertOk()
            ->assertSee('REF-PAGE-51');
    }

    /**
     * Refunds sharing one timestamp must still split across pages deterministically.
     *
     * wallet_transactions.created_at is a second-granularity column, so a batch of
     * refunds routinely writes many rows carrying the same value. latest() on its
     * own leaves those rows unordered relative to each other, and because every
     * page is a separate query a tied row is free to render on two pages, or on
     * none. orderByDesc('id') pins the order: newest id first, so the row inserted
     * FIRST is the one pushed onto the last page.
     */
    public function test_refunds_sharing_one_timestamp_page_deterministically(): void
    {
        $user = User::factory()->create(['name' => 'Tied Refunder']);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        // One timestamp for all 51 rows - the exact condition the tie-break exists for.
        $stamp = now()->subHour();

        foreach (range(1, 51) as $index) {
            $txn = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Credit,
                'currency' => 'USD',
                'amount' => 1.00,
                'balance_before' => 0,
                'balance_after' => 1.00,
                'transaction_category' => TransactionCategory::Refund,
                'reference' => 'REF-TIE-'.$index,
                'description' => 'Tied refund '.$index,
            ]);

            $txn->forceFill(['created_at' => $stamp, 'updated_at' => $stamp])->save();
        }

        $firstPage = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['status' => 'refunded']))
            ->assertOk()
            ->getContent();

        $secondPage = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['status' => 'refunded', 'page' => 2]))
            ->assertOk()
            ->getContent();

        $onPage1 = $this->tiedReferencesIn($firstPage);
        $onPage2 = $this->tiedReferencesIn($secondPage);

        // The pages must partition the 51 rows: nothing duplicated, nothing lost.
        $this->assertCount(50, $onPage1);
        $this->assertCount(1, $onPage2);
        $this->assertEmpty(array_intersect($onPage1, $onPage2), 'A tied refund rendered on both pages');
        $this->assertCount(51, array_unique(array_merge($onPage1, $onPage2)), 'A tied refund is unreachable from either page');

        // And the split is defined rather than incidental: id DESC means the row
        // written first is the one that falls to the last page.
        $this->assertSame(['REF-TIE-1'], $onPage2);
    }

    /**
     * Every distinct REF-TIE-n reference rendered on a page, in render order.
     * Each row prints its reference twice (clipboard payload plus visible label),
     * so the list is deduplicated.
     *
     * @return list<string>
     */
    private function tiedReferencesIn(string $html): array
    {
        preg_match_all('/REF-TIE-\d+/', $html, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function test_default_tab_still_renders(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions'))
            ->assertOk();
    }
}
