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
