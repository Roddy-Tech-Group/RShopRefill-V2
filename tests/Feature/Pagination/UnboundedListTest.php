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
 * 2. The gift-card listing renders one card for every brand on a single
 *    uncached, anonymous page - a deliberate product decision, so the guard is
 *    not a page size. It is that the page's cost stays FLAT in the number of
 *    brands: variants arrive in one eager-load and ratings in one rollup, so
 *    doubling the catalogue must not double the queries. A per-card lookup
 *    creeping back in is the regression that matters now.
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

    /** Seeds $count gift-card brands, one available variant each. */
    private function seedBrands(int $count): void
    {
        $category = Category::firstOrCreate(
            ['slug' => 'gift-cards'],
            ['name' => 'Gift Cards']
        );

        foreach (range(1, $count) as $i) {
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
    }

    /** Every brand, in one scroll - no page links, nothing withheld. */
    public function test_the_gift_card_listing_renders_every_brand(): void
    {
        $this->withoutVite();
        $this->seedBrands(70);

        $html = $this->get('/gift-cards')->assertOk()->getContent();

        // Tiles print Product::brandDisplayName($brand_key), so the label is
        // the KEY ("Brand70"), not the product's name column ("Brand 70").
        // The old assertion looked for the spaced form, which never appears -
        // it passed against any page at all.
        $this->assertStringContainsString('Brand70', $html);
        $this->assertStringNotContainsString('page=2', $html);
    }

    /**
     * The real guard: doubling the catalogue must not double the queries.
     *
     * A per-card lookup - for variants, or for a brand's rating - is what made
     * this the heaviest page in the app before. Both come back keyed by the
     * whole result set, so the count holds flat.
     */
    public function test_the_listing_cost_does_not_scale_with_the_number_of_brands(): void
    {
        $this->withoutVite();

        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            $this->get('/gift-cards')->assertOk();

            return $queries;
        };

        $this->seedBrands(10);
        $small = $count();

        $this->seedBrands(60);
        $large = $count();

        // Some slack for query-log noise, but nothing resembling one per brand:
        // 50 extra brands must not mean anywhere near 50 extra queries.
        $this->assertLessThan(
            $small + 10,
            $large,
            "Query count scaled with the catalogue: {$small} queries for 10 brands, {$large} for 70. ".
            'Something is looking up per card instead of per page.'
        );
    }
}
