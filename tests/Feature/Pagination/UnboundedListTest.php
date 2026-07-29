<?php

namespace Tests\Feature\Pagination;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pages that render a list of rows must stay flat in cost as the table behind
 * them grows. Two specific regressions are pinned here.
 *
 * 1. The admin wallets page used `Wallet::with('transactions')`, which pulled
 *    every wallet_transaction row for all 50 listed wallets into memory purely
 *    to print one integer per row. wallet_transactions is the fastest-growing
 *    table in the schema, so that page got heavier with every top-up, purchase
 *    and refund forever. It must use withCount (a SQL COUNT), never the relation.
 *
 * 2. The gift-card listing rendered one card for every brand (~688 in
 *    production) on a single uncached, anonymous page, eager-loading variants
 *    out of an 18k-row table for each. It must paginate.
 */
class UnboundedListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Ops',
            'email' => 'ops@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_admin_wallets_does_not_load_the_transaction_rows_it_only_counts(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        foreach (range(1, 5) as $i) {
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => 10,
                'balance_before' => 10 * ($i - 1),
                'balance_after' => 10 * $i,
                'description' => "tx {$i}",
                'reference' => 'TEST-'.$i,
            ]);
        }

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/wallets')
            ->assertOk()
            // The count still renders correctly, just via SQL.
            ->assertSee('5', false);

        // The giveaway for the old eager load: a bare SELECT of the transaction
        // rows themselves. A COUNT aggregate is fine; hydrating the rows is not.
        $hydratedRows = collect($queries)->filter(
            fn ($sql) => str_contains($sql, 'wallet_transactions')
                && ! str_contains(strtolower($sql), 'count(')
                && ! str_contains(strtolower($sql), 'sum(')
        );

        $this->assertTrue(
            $hydratedRows->isEmpty(),
            "The wallets list must COUNT transactions, not load them. Offending SQL:\n".$hydratedRows->implode("\n")
        );
    }

    public function test_the_gift_card_listing_paginates_instead_of_rendering_every_brand(): void
    {
        $this->withoutVite();

        $category = Category::factory()->create(['slug' => 'gift-cards', 'name' => 'Gift Cards']);

        // More brands than fit on one page, so the cap has to bite.
        foreach (range(1, 70) as $i) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'brand_key' => 'Brand'.$i,
                'name' => 'Brand '.$i,
                'country_code' => 'US',
                'is_active' => true,
            ]);

            ProductVariant::factory()->create([
                'product_id' => $product->id,
                'is_available' => true,
                'retail_price' => 10,
                'face_value' => 10,
            ]);
        }

        $html = $this->get('/gift-cards')->assertOk()->getContent();

        // Page 2 must exist, which only happens if the listing is paginated.
        $this->assertStringContainsString('page=2', $html, 'the gift-card listing must paginate');

        // And the last brand must NOT be on page one.
        $this->assertStringNotContainsString('Brand 70', $html);
    }
}
