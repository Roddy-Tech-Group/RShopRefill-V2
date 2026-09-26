<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The ledger has no page numbers, the same as the order history and the
 * notifications. Twelve rows render, and the sentinel under them asks for
 * twelve more each time it scrolls into view.
 *
 * The feed is two queries merged and sorted in PHP rather than one paginated
 * query, so the slice is taken on the assembled rows: they already exist, and
 * the only question is how many of them to draw.
 *
 * The CSV is untouched by any of this. It streams the whole ledger in chunks
 * straight from the query, so an export is the complete history whether one
 * chunk has been loaded on screen or twenty.
 */
class TransactionsLoadMoreTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(int $count): User
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 500]);

        // No factory for the ledger: rows are written the way the rest of the
        // suite writes them, with the balances the column requires.
        for ($i = 1; $i <= $count; $i++) {
            WalletTransaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => $i % 2 === 0 ? WalletTransactionType::Credit : WalletTransactionType::Debit,
                'currency' => 'USD',
                'amount' => $i,
                'balance_before' => 0,
                'balance_after' => $i,
                'transaction_category' => TransactionCategory::Refund,
                'reference' => 'LEDGER-'.$i,
                'description' => 'Ledger row '.$i,
                'created_at' => now()->subMinutes($count - $i),
            ]);
        }

        $this->actingAs($user);

        return $user;
    }

    public function test_the_first_chunk_shows_twelve_rows_and_a_scroll_sentinel(): void
    {
        $this->ledger(30);

        Volt::test('dashboard.transactions')
            ->assertViewHas('transactions', fn ($rows) => $rows->count() === 12)
            ->assertViewHas('hasMoreTransactions', true)
            ->assertSeeHtml('wire:intersect.margin.400px="loadMore"')
            ->assertDontSeeHtml('wire:click="nextPage"')
            ->assertDontSeeHtml('wire:click="gotoPage(');
    }

    public function test_reaching_the_bottom_loads_twelve_more_until_the_last_row(): void
    {
        $this->ledger(30);

        $page = Volt::test('dashboard.transactions')->call('loadMore');

        $page->assertSet('visible', 24)
            ->assertViewHas('transactions', fn ($rows) => $rows->count() === 24)
            ->assertViewHas('hasMoreTransactions', true);

        $page->call('loadMore')
            ->assertViewHas('transactions', fn ($rows) => $rows->count() === 30)
            ->assertViewHas('hasMoreTransactions', false)
            ->assertDontSeeHtml('wire:intersect');
    }

    /** A new search or filter starts from the first chunk again. */
    public function test_narrowing_the_ledger_starts_over(): void
    {
        $this->ledger(30);

        Volt::test('dashboard.transactions')
            ->call('loadMore')
            ->assertSet('visible', 24)
            ->set('search', 'Ledger')
            ->assertSet('visible', 12)
            ->call('loadMore')
            ->assertSet('visible', 24)
            ->call('setFilter', 'credit')
            ->assertSet('visible', 12);
    }

    /** A short ledger has no sentinel at all. */
    public function test_a_short_ledger_has_no_sentinel(): void
    {
        $this->ledger(5);

        Volt::test('dashboard.transactions')
            ->assertViewHas('transactions', fn ($rows) => $rows->count() === 5)
            ->assertViewHas('hasMoreTransactions', false)
            ->assertDontSeeHtml('wire:intersect');
    }

    public function test_the_browser_cannot_set_the_loaded_count_itself(): void
    {
        $this->ledger(3);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Volt::test('dashboard.transactions')->set('visible', 100000);
    }

    /** Nothing paginates any more. */
    public function test_the_page_carries_no_paginator(): void
    {
        $source = file_get_contents(resource_path('views/livewire/dashboard/transactions.blade.php'));

        foreach (['WithPagination', 'LengthAwarePaginator', 'resetPage()', "links('vendor.pagination"] as $gone) {
            $this->assertStringNotContainsString($gone, $source, $gone.' is still there');
        }
    }

    /**
     * The export was never bound to what is drawn, and still is not: it chunks
     * the query itself, so it cannot be cut short by the number of rows a
     * customer happens to have scrolled past.
     */
    public function test_the_csv_still_streams_the_whole_ledger(): void
    {
        $source = file_get_contents(resource_path('views/livewire/dashboard/transactions.blade.php'));

        $this->assertStringContainsString('$query->chunkById(500, function ($rows) use ($out) {', $source);
        $this->assertStringNotContainsString('->take($this->visible)->map(fn ($txn) => $this->walletRow', $source);

        $this->ledger(30);

        // It downloads with only the first chunk drawn, which is the state the
        // old paginator would have exported from.
        Volt::test('dashboard.transactions')
            ->assertSet('visible', 12)
            ->call('downloadCsv')
            ->assertFileDownloaded('transactions-'.now()->format('Y-m-d').'.csv');

        // The rows themselves come from the query the export builds, so they
        // are counted there rather than through the download harness, which
        // hands back a streamed response it will not replay.
        $rows = User::find(auth()->id())->walletTransactions()->count();
        $this->assertSame(30, $rows, 'the ledger the export walks is not the whole ledger');
    }
}
