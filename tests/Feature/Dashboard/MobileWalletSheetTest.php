<?php

namespace Tests\Feature\Dashboard;

use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The mobile overview's wallet hero and PURCHASE cards (2026-09-23).
 *
 * The per-wallet swipe carousel became one figure: a TOTAL BALANCE pill (the
 * wallets' flags stacked) over the USD-equivalent total. Tapping the pill
 * slides a sheet up with a ring (one arc per funded wallet, sized by its USD
 * share) and a per-wallet breakdown, each row with its own Top Up. Below the
 * hero, the Quick Actions tile grid became four PURCHASE cards.
 */
class MobileWalletSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The overview body is a #[Lazy] component; the PURCHASE cards live in
        // it, so render lazily-loaded components eagerly here.
        Livewire::withoutLazyLoading();
        // The card figures are cached for an hour; each test seeds its own catalogue.
        Cache::flush();
    }

    /** $75 in USD and 30,000 XAF at 600 per dollar ($50): $125 total, 60/40. */
    private function fundedUser(): User
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 75, 'is_active' => true]);
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'XAF', 'balance' => 30000, 'is_active' => true]);
        CurrencyRate::create(['code' => 'XAF', 'name' => 'Central African CFA Franc', 'type' => 'fiat', 'rate_per_usd' => 600, 'is_active' => true]);

        return $user;
    }

    public function test_the_hero_shows_the_usd_total_behind_a_total_balance_pill(): void
    {
        $this->withoutVite();

        $html = $this->actingAs($this->fundedUser())->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Show wallet balances"', $html);
        $this->assertStringContainsString('Total balance', $html);
        $this->assertStringContainsString('$125<span class="text-2xl">.00</span>', $html);
        $this->assertStringContainsString('2 wallets · USD equivalent', $html);

        // The carousel is gone: no per-wallet snap slides, no swipe dots.
        $this->assertStringNotContainsString('snap-center', $html);
        $this->assertStringNotContainsString('fund-mobile-', $html);
    }

    public function test_the_sheet_carries_a_ring_and_a_breakdown_with_a_top_up_per_wallet(): void
    {
        $this->withoutVite();

        $html = $this->actingAs($this->fundedUser())->get('/dashboard')->assertOk()->getContent();
        $sheet = substr($html, strpos($html, 'class="wallet-sheet'));

        // One arc per funded wallet, the USD wallet (the larger, hence the
        // default) first: 60% blue, 40% green, on the track circle.
        $this->assertSame(2, preg_match_all('/<circle cx="60" cy="60" r="48" fill="none" stroke-width="12" stroke="#/', $sheet));
        $this->assertMatchesRegularExpression('/stroke="#2563eb" stroke-dasharray="[0-9.]+ 301\.593"/', $sheet);
        $this->assertMatchesRegularExpression('/stroke="#10b981" stroke-dasharray="[0-9.]+ 301\.593"/', $sheet);

        $this->assertStringContainsString('Balance breakdown', $sheet);
        $this->assertStringContainsString('$125.00', $sheet);
        $this->assertStringContainsString('60%', $sheet);
        $this->assertStringContainsString('40%', $sheet);
        $this->assertStringContainsString('≈ $50.00', $sheet);

        // Each row's Top Up is the fund-wallet component set to that wallet.
        $this->assertStringContainsString('wire:key="fund-sheet-USD"', $html);
        $this->assertStringContainsString('wire:key="fund-sheet-XAF"', $html);

        // The $ / % switch is one thumb sliding between two equal cells, not
        // two buttons swapping fills.
        $this->assertStringContainsString('relative grid grid-cols-2 rounded-[10px] bg-[#f4f6f5] p-1', $sheet);
        $this->assertStringContainsString(':class="mode === \'share\' ? \'translate-x-full\' : \'translate-x-0\'" class="wallet-mode-thumb pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-[8px] bg-[#ffffff] shadow-sm transition-transform duration-300', $sheet);
        $this->assertSame(1, substr_count($sheet, 'wallet-mode-thumb'));
        $this->assertStringNotContainsString("mode === 'amount' ? 'bg-[#ffffff]", $sheet);
    }

    public function test_an_unfunded_customer_gets_an_empty_ring_not_a_broken_one(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'currency' => 'USD', 'balance' => 0, 'is_active' => true]);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $sheet = substr($html, strpos($html, 'class="wallet-sheet'));

        $this->assertStringContainsString('$0<span class="text-2xl">.00</span>', $html);
        $this->assertSame(0, preg_match_all('/stroke="#[0-9a-f]{6}" stroke-dasharray/', $sheet), 'no arc for a zero balance');
        $this->assertStringContainsString('0%', $sheet);
    }

    public function test_purchase_cards_replace_the_mobile_quick_actions(): void
    {
        $this->withoutVite();

        $html = $this->actingAs($this->fundedUser())->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('id="purchase-heading"', $html);
        foreach (['Bills', 'Airtime &amp; data', 'eSIM', 'Gift Cards'] as $card) {
            $this->assertStringContainsString($card, $html);
        }
        foreach (['dashboard.shop.bills', 'dashboard.shop.topups', 'dashboard.shop.esims', 'dashboard.shop.gift-cards'] as $route) {
            $this->assertStringContainsString(route($route), $html);
        }

        // The phone block no longer carries the tile grid; the desktop keeps its own.
        $overview = file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));
        $mobile = substr($overview, strpos($overview, 'MOBILE VIEW'), strpos($overview, 'lg:grid-cols-12') - strpos($overview, 'MOBILE VIEW'));
        $this->assertStringNotContainsString('Quick Actions', $mobile);
        $this->assertStringContainsString('mt-3 grid grid-cols-2 gap-4', $mobile);
        // Popular Gift Cards left the phone overview with it.
        $this->assertStringNotContainsString('Popular Gift Cards', $mobile);
    }

    /**
     * Every line under a card name is the catalogue's own figure, not copy
     * written into the template: countries and networks / brands / billers
     * counted from the active products of that store.
     */
    public function test_purchase_card_copy_states_the_live_catalogue_figures(): void
    {
        $this->withoutVite();

        $category = fn (string $slug) => Category::factory()->create(['slug' => $slug, 'name' => ucfirst($slug), 'is_active' => true]);
        $product = fn (Category $c, string $country, string $brand) => Product::factory()->create(['category_id' => $c->id, 'country_code' => $country, 'brand_key' => $brand, 'is_active' => true]);

        $esims = $category('esims');
        foreach (['US', 'FR', 'JP'] as $cc) {
            $product($esims, $cc, 'esim-'.$cc);
        }
        $airtime = $category('mobile-airtime');
        $product($airtime, 'NG', 'mtn');
        $product($airtime, 'GH', 'vodafone');
        $bills = $category('bill-payments');
        $product($bills, 'NG', 'ikeja-electric');
        $gift = $category('gift-cards');
        $product($gift, 'US', 'steam');
        $product($gift, 'US', 'netflix');
        // Inactive products never count.
        Product::factory()->create(['category_id' => $gift->id, 'country_code' => 'US', 'brand_key' => 'amazon', 'is_active' => false]);

        $html = $this->actingAs($this->fundedUser())->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Data, Calls for 3 countries', $html);
        $this->assertStringContainsString('2 networks in 2 countries', $html);
        $this->assertStringContainsString('1 biller in 1 country', $html);
        $this->assertStringContainsString('2 brands to choose from', $html);
        // The borrowed reference copy is gone.
        $this->assertStringNotContainsString('100+ countries', $html);
        $this->assertStringNotContainsString('favourite brands', $html);
    }

    /** An empty catalogue falls back to plain descriptions, never a zero figure. */
    public function test_purchase_cards_fall_back_to_plain_copy_on_an_empty_catalogue(): void
    {
        $this->withoutVite();

        $html = $this->actingAs($this->fundedUser())->get('/dashboard')->assertOk()->getContent();

        foreach (['Pay your bills', 'Top up airtime &amp; data', 'Travel data plans', 'Shop gift cards'] as $copy) {
            $this->assertStringContainsString($copy, $html);
        }
        $this->assertStringNotContainsString('0 countries', $html);
        $this->assertStringNotContainsString('0 brands', $html);
    }
}
