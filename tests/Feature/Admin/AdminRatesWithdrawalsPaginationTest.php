<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\CurrencyRate;
use App\Models\ExchangeRate;
use App\Models\RcoinWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Both of these admin queues used to render every matching row on one request:
 * rates loaded the whole currency_rates table (a flag image per row) and the
 * withdrawal queue used a hard ->limit(50), which silently hid older rows on
 * the append-only paid/rejected tabs. These tests pin the bounded page size and
 * prove the filters/tabs still behave.
 */
class AdminRatesWithdrawalsPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // currency_rates is NOT empty at the start of a test: the crypto launch
        // migrations seed a fixed coin lineup, and RefreshDatabase runs migrations
        // for every case. These tests assert exact totals over a dataset they build
        // themselves, so the seeded coins would be counted alongside it - and every
        // figure here would drift again the next time the lineup gains a coin.
        CurrencyRate::query()->delete();
    }

    private function asAdmin(): self
    {
        $admin = Admin::firstOrCreate(
            ['email' => 'pagination-admin@example.test'],
            ['name' => 'Pagination Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );
        $this->actingAs($admin, 'admin');

        return $this;
    }

    private function makeRates(int $count, string $type = 'fiat'): void
    {
        foreach (range(1, $count) as $i) {
            CurrencyRate::create([
                'code' => strtoupper(substr($type, 0, 1)).str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => ucfirst($type).' currency '.$i,
                'type' => $type,
                'rate_per_usd' => 1 + $i,
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }
    }

    private function makeWithdrawals(int $count, string $status): void
    {
        $user = User::factory()->create();

        foreach (range(1, $count) as $i) {
            $withdrawal = RcoinWithdrawal::create([
                'user_id' => $user->id,
                'rcoin_amount' => 100 * $i,
                'usd_value' => 1.0 * $i,
                'fee_usd' => 0,
                'payout_usd' => 1.0 * $i,
                'method' => 'wallet',
                'rate_snapshot' => 0.01,
                'status' => $status,
            ]);

            // The queue is ordered by latest(); spread created_at so "oldest
            // row" is a stable target rather than a tie-break coin flip.
            $withdrawal->forceFill(['created_at' => now()->subMinutes($count - $i)])->save();
        }
    }

    public function test_rates_table_renders_at_most_fifty_rows_per_page(): void
    {
        $this->withoutVite()->asAdmin();
        $this->makeRates(60);

        $component = Volt::test('admin.rates');

        $this->assertSame(50, $component->instance()->rates->count());
        $this->assertSame(60, $component->instance()->rates->total());

        // Row 60 is unreachable on page 1 but present on page 2.
        $component->assertDontSee('F060')
            ->set('paginators.page', 2)
            ->assertSee('F060');
    }

    public function test_rates_kpi_totals_count_every_row_not_just_the_current_page(): void
    {
        $this->withoutVite()->asAdmin();
        $this->makeRates(60, 'fiat');
        $this->makeRates(5, 'crypto');

        $summary = Volt::test('admin.rates')->instance()->summary;

        $this->assertSame(60, $summary['fiat']);
        $this->assertSame(5, $summary['crypto']);
    }

    public function test_rates_filters_and_search_still_narrow_the_result_set(): void
    {
        $this->withoutVite()->asAdmin();
        $this->makeRates(3, 'fiat');
        $this->makeRates(2, 'crypto');
        CurrencyRate::where('code', 'F003')->update(['is_active' => false]);

        $component = Volt::test('admin.rates');

        $this->assertSame(3, $component->set('typeFilter', 'fiat')->instance()->rates->total());
        $this->assertSame(2, $component->set('typeFilter', 'crypto')->instance()->rates->total());
        $this->assertSame(1, $component->set('typeFilter', 'inactive')->instance()->rates->total());

        $this->assertSame(1, $component->set('typeFilter', 'all')->set('search', 'f002')->instance()->rates->total());
        $this->assertSame(2, $component->set('search', 'Crypto currency')->instance()->rates->total());
    }

    public function test_rates_stale_filter_matches_rows_with_no_live_rate_or_a_rate_over_24h_old(): void
    {
        $this->withoutVite()->asAdmin();
        $this->makeRates(3);

        // F001 is fresh, F002 is 30h old, F003 has no exchange_rates row at all.
        ExchangeRate::create([
            'base_currency' => 'USD', 'target_currency' => 'F001', 'provider' => 'system_sync',
            'rate' => 2, 'source' => 'currency_rates_db', 'is_active' => true,
            'fetched_at' => now()->subHour(), 'expires_at' => now()->addDay(),
        ]);
        ExchangeRate::create([
            'base_currency' => 'USD', 'target_currency' => 'F002', 'provider' => 'system_sync',
            'rate' => 3, 'source' => 'currency_rates_db', 'is_active' => true,
            'fetched_at' => now()->subHours(30), 'expires_at' => now()->addDay(),
        ]);

        $stale = Volt::test('admin.rates')->set('typeFilter', 'stale')->instance()->rates;

        $this->assertSame(2, $stale->total());
        $this->assertEqualsCanonicalizing(['F002', 'F003'], $stale->pluck('code')->all());
    }

    public function test_changing_the_rates_filter_returns_to_page_one(): void
    {
        $this->withoutVite()->asAdmin();
        $this->makeRates(60);

        Volt::test('admin.rates')
            ->set('paginators.page', 2)
            ->set('typeFilter', 'fiat')
            ->assertSet('paginators.page', 1);
    }

    public function test_withdrawal_queue_pages_at_twenty_five_and_reaches_rows_past_the_old_fifty_limit(): void
    {
        $this->withoutVite()->asAdmin();
        $this->makeWithdrawals(55, 'paid');

        $component = Volt::test('admin.content.rewards-withdrawals')->call('setTab', 'paid');

        $this->assertSame(25, $component->instance()->withdrawals->count());
        $this->assertSame(55, $component->instance()->withdrawals->total());

        // The 5 oldest rows sat past the old ->limit(50) with no way to reach
        // them; they are now the last page.
        $oldest = RcoinWithdrawal::orderBy('created_at')->first();
        $component->set('paginators.page', 3)
            ->assertSee('#W'.str_pad((string) $oldest->id, 6, '0', STR_PAD_LEFT));

        $this->assertSame(5, $component->instance()->withdrawals->count());
        $this->assertSame(3, $component->instance()->withdrawals->lastPage());
    }

    public function test_withdrawal_tab_counts_stay_whole_table_totals_and_switching_tab_resets_the_page(): void
    {
        $this->withoutVite()->asAdmin();
        $this->makeWithdrawals(30, 'paid');
        $this->makeWithdrawals(2, 'pending');

        $component = Volt::test('admin.content.rewards-withdrawals')->call('setTab', 'paid');

        $this->assertSame(['paid' => 30, 'pending' => 2], $component->instance()->counts);

        $component->set('paginators.page', 2)
            ->call('setTab', 'pending')
            ->assertSet('paginators.page', 1);
    }
}
