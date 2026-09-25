<?php

namespace Tests\Feature\Kyc;

use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The count that has to be known before kyc_enforce is switched on: which
 * unverified customers already sit at or over the cumulative dial and will be
 * asked to verify on their very next order or funding.
 */
class KycBlastRadiusCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fund(User $user, float $usd): void
    {
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id, 'currency' => 'USD'], ['balance' => 0, 'is_active' => true]);

        WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
            'amount' => $usd,
            'settled_amount_usd' => $usd,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    private function order(User $user, float $usd, string $paymentMethod): void
    {
        Order::factory()->create([
            'user_id' => $user->id,
            'payment_method' => $paymentMethod,
            'total_amount' => $usd,
            'display_currency' => 'USD',
        ]);
    }

    public function test_it_counts_the_unverified_customers_already_over_the_cumulative_dial(): void
    {
        $over = User::factory()->create();          // $2,400 in, unverified: asked next time
        $this->fund($over, 1900);
        $this->order($over, 500, 'flutterwave');

        $verified = User::factory()->create(['kyc_status' => 'verified']); // over, but already verified
        $this->fund($verified, 4000);

        $under = User::factory()->create();         // $600 in, unverified: not yet
        $this->fund($under, 600);

        $guest = User::factory()->create(['is_guest' => true]); // guests are not customers to gate
        $this->fund($guest, 5000);

        $walletOnly = User::factory()->create();    // spent balance, brought nothing in
        $this->order($walletOnly, 900, 'wallet');

        $this->artisan('kyc:blast-radius')
            ->expectsOutputToContain('3 customer(s) brought money in; 2 of them are not identity-verified.')
            ->expectsOutputToContain('1 unverified customer(s) are already at or over $2,000.00')
            ->expectsTable(
                ['User', 'Money in (USD)', 'Share of dial', 'Next action'],
                [
                    [$over->id, '2,400.00', '120%', 'verify'],
                    [$under->id, '600.00', '30%', '$1,400.00 more'],
                ],
            )
            ->assertSuccessful();
    }

    public function test_an_unset_cumulative_dial_gates_nobody_on_volume(): void
    {
        SiteSetting::put('features.kyc_cumulative_threshold_usd', '0', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $this->fund($user, 5000);

        $this->artisan('kyc:blast-radius')
            ->expectsOutputToContain('The cumulative dial is unset (0), so nobody is gated on volume.')
            ->assertSuccessful();
    }

    public function test_it_says_so_when_nobody_brought_money_in(): void
    {
        $this->artisan('kyc:blast-radius')
            ->expectsOutputToContain('No customer brought money in during the window.')
            ->assertSuccessful();
    }
}
