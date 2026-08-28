<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Shared\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Recent Transactions card fills the height it is given.
 *
 * The desktop card stretches to match the left column, so how many rows fit
 * is a layout answer that changes with the viewport - but the query fetched a
 * flat 8, which left a tall card half empty however much room it had. It
 * draws on a pool now and shows what fits, scrolling the rest.
 *
 * The mobile card is the opposite case: it has no height to fill and grows
 * with its content, so it keeps its screenful.
 */
class RecentTransactionsFillTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTransactions(int $count): User
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        foreach (range(1, $count) as $i) {
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransactionType::Credit,
                'currency' => 'USD',
                'amount' => 10,
                'balance_before' => 0,
                'balance_after' => 10,
                'transaction_category' => TransactionCategory::Refund,
                'reference' => 'REF-'.$i,
                'description' => 'Transaction '.$i,
                'created_at' => now()->subMinutes($i),
            ]);
        }

        return $user;
    }

    private function overview(): string
    {
        return File::get(resource_path('views/livewire/dashboard/overview.blade.php'));
    }

    /** The pool is bigger than the old flat 8, or a tall card still runs dry. */
    public function test_the_card_draws_on_a_pool_rather_than_a_screenful(): void
    {
        $overview = $this->overview();

        $this->assertSame(1, preg_match('/recentTransactionPool = (\d+);/', $overview, $m));
        $this->assertGreaterThan(8, (int) $m[1], 'the pool must exceed the screenful it replaced');

        // Both source queries take the pool - either could supply the whole
        // merged list, so capping one at 8 would cap the merge at 8.
        $this->assertSame(2, substr_count($overview, 'limit($recentTransactionPool)'));
        $this->assertStringContainsString('take($recentTransactionPool)', $overview);
    }

    /**
     * min-h-0 is load-bearing. A flex child will not shrink below its content
     * without it, so a long list stretches the card instead of scrolling
     * inside it - the card grows and the page scrolls, which is the bug this
     * would look like.
     */
    public function test_the_desktop_list_scrolls_inside_the_card(): void
    {
        $this->assertStringContainsString(
            'relative z-10 mt-4 min-h-0 flex-1 space-y-3 overflow-y-auto',
            $this->overview()
        );
    }

    /** Mobile grows with its content, so it keeps a screenful. */
    public function test_the_mobile_card_still_shows_a_screenful(): void
    {
        $this->assertStringContainsString('@forelse ($recentTransactions->take(8) as $txn)', $this->overview());
    }

    /**
     * End to end: more than a screenful actually reaches the card.
     *
     * #[Lazy] on the component means a plain GET renders the skeleton
     * placeholder and nothing else, so the data has to be booted through
     * $refresh - the same way DashboardPopularFollowsStorefrontTest does it.
     */
    public function test_more_than_a_screenful_reaches_the_card(): void
    {
        $this->actingAs($this->userWithTransactions(14));

        Volt::test('dashboard.overview')
            ->call('$refresh')
            ->assertViewHas('recentTransactions', function ($rows) {
                // All 14 survive the merge; the old flat cap stopped at 8.
                return $rows->count() === 14
                    && $rows->contains(fn ($row) => $row->title === 'Transaction 14');
            });
    }
}
