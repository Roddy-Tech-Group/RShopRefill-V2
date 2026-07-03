<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class FundWalletCryptoMinimumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_crypto_floor_is_thirteen_usd_for_a_usd_wallet(): void
    {
        $component = Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])->instance();

        $this->assertSame(13.0, $component->cryptoMinimum());
        $this->assertSame('$13.00', $component->cryptoMinimumLabel());
    }

    public function test_meets_crypto_minimum_gates_on_the_entered_amount(): void
    {
        $component = Volt::test('dashboard.fund-wallet', ['currency' => 'USD']);

        $this->assertFalse($component->set('amount', '5')->instance()->meetsCryptoMinimum());
        $this->assertTrue($component->set('amount', '13')->instance()->meetsCryptoMinimum());
        $this->assertTrue($component->set('amount', '15')->instance()->meetsCryptoMinimum());
    }

    public function test_crypto_step_shows_the_translated_minimum_message(): void
    {
        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->assertSee('Add money to pay instantly from $13.00 and above');
    }

    public function test_amount_below_the_floor_shows_the_change_amount_prompt(): void
    {
        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '5')
            ->assertSee('Crypto top-ups start at $13.00')
            ->assertSee('Change amount');
    }

    public function test_amount_at_or_above_the_floor_hides_the_prompt(): void
    {
        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '20')
            ->assertDontSee('Crypto top-ups start at');
    }

    public function test_non_usd_floor_is_rounded_up_with_no_minor_units(): void
    {
        $component = Volt::test('dashboard.fund-wallet', ['currency' => 'NGN'])->instance();

        $minimum = $component->cryptoMinimum();

        $this->assertSame((float) ceil($minimum), $minimum, 'The translated floor must be rounded up to a whole unit.');
        $this->assertStringStartsWith('₦', $component->cryptoMinimumLabel());
        $this->assertStringNotContainsString('.', $component->cryptoMinimumLabel());
    }
}
