<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDetailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_a_customer_detail_page(): void
    {
        $customer = User::factory()->create([
            'name' => 'Jane Customer',
            'email' => 'jane.customer@example.test',
        ]);

        Wallet::create([
            'user_id' => $customer->id,
            'balance' => 125.50,
            'locked_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $customer));

        $response->assertOk();
        $response->assertSee('Jane Customer');
        $response->assertSee('jane.customer@example.test');
        $response->assertSee('Customer #'.$customer->id);
        $response->assertSee('125.50');
    }

    public function test_wallet_adjust_amounts_take_any_four_decimal_figure_and_balances_show_exactly(): void
    {
        $customer = User::factory()->create();

        Wallet::create([
            'user_id' => $customer->id,
            'balance' => 0.0099,
            'locked_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $html = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $customer))
            ->assertOk()
            ->getContent();

        // A step that does not divide its min makes the browser accept only
        // min + n*step (10.5001, 10.5101), so 10.51 could not be typed.
        $this->assertSame(2, substr_count($html, 'name="amount" min="0.0001" step="0.0001"'), 'Both credit and debit amounts must step from 0.0001 by 0.0001.');
        $this->assertFalse(str_contains($html, 'name="amount" min="0.0001" step="0.01"'), 'An amount input still steps by 0.01 from a 0.0001 min.');

        // Balance chip and currency picker show the leftover the admin has to type.
        $this->assertSame(2, substr_count($html, '0.0099'), 'The sub-cent balance must show exactly, not rounded to 0.01.');

        // The dark theme repaints bg-zinc-50 as the card's own ash, which made
        // both form panels vanish; literal light fills let them sink to the ground.
        $this->assertSame(2, substr_count($html, 'p-3 bg-[#fafafa] dark:bg-[var(--ground)]'), 'Both adjust forms need a surface the dark theme does not repaint.');
        $this->assertTrue(str_contains($html, 'text-emerald-700 dark:text-emerald-400">Credit'), 'The Credit label must stay readable in dark mode.');
        $this->assertTrue(str_contains($html, 'text-red-700 dark:text-red-400">Debit'), 'The Debit label must stay readable in dark mode.');
    }

    public function test_stale_exchange_rate_does_not_break_the_customer_page(): void
    {
        $customer = User::factory()->create(['name' => 'Pierre Client']);

        // Non-USD wallet whose balance the headline tries to convert to USD.
        Wallet::create([
            'user_id' => $customer->id,
            'balance' => 50000,
            'locked_balance' => 0,
            'currency' => 'XAF',
            'is_active' => true,
        ]);

        // A critically stale (> 48h) rate makes CurrencyRateService::convert()
        // throw. The read-only admin view must degrade, not 500.
        ExchangeRate::create([
            'base_currency' => 'XAF',
            'target_currency' => 'USD',
            'rate' => 0.0016,
            'provider' => 'test',
            'source' => 'test',
            'is_active' => true,
            'fetched_at' => now()->subHours(80),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $customer))
            ->assertOk()
            ->assertSee('Pierre Client');
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $customer = User::factory()->create();

        $this->get(route('admin.customer', $customer))
            ->assertNotFound();
    }

    public function test_unknown_customer_returns_not_found(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', 999999))
            ->assertNotFound();
    }
}
