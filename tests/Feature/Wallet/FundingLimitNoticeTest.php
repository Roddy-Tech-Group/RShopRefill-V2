<?php

namespace Tests\Feature\Wallet;

use App\Domain\Shared\Enums\FundingStatus;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * What a customer is told when a wallet funding would cross their transaction
 * limit. The caps meter money in, so they apply to fundings too; before that
 * an unverified customer could load the wallet in sub-$500 pieces straight
 * past a $500 daily limit.
 *
 * Nothing is written when the cap refuses: no funding row, no gateway call.
 */
class FundingLimitNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);
        SiteSetting::put('features.spending_limits_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.kyc_verification_enabled', 'on', 'features');
        Cache::flush();
    }

    private function alreadyFunded(User $user, float $usd): void
    {
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id, 'currency' => 'USD'], ['balance' => $usd, 'is_active' => true]);

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

    public function test_the_top_up_sheet_shows_the_limit_notice_and_starts_nothing(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->alreadyFunded($user, 450);
        $this->actingAs($user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '100') // 550 > the $500 standard daily cap
            ->call('fund')
            ->assertDispatched('risk-hold', function (string $event, array $params) {
                return str_contains($params['message'], 'daily transaction limit of $500')
                    && str_contains($params['message'], 'Verify your identity');
            });

        $this->assertSame(1, WalletFunding::query()->where('user_id', $user->id)->count());
    }

    public function test_a_funding_inside_the_limit_is_not_held(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->alreadyFunded($user, 300);
        $this->actingAs($user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '100') // 400, inside $500
            ->call('fund')
            ->assertNotDispatched('risk-hold');
    }

    public function test_the_funding_api_answers_with_the_limit_refusal(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->alreadyFunded($user, 450);

        $this->actingAs($user)
            ->postJson('/api/wallets/fund/initiate', ['currency' => 'USD', 'amount' => 100])
            ->assertStatus(422)
            ->assertJsonFragment(['limit_exceeded' => true, 'window' => 'daily']);

        $this->assertSame(1, WalletFunding::query()->where('user_id', $user->id)->count());
    }

    public function test_shadow_mode_lets_the_funding_start(): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', 'off', 'features');
        Cache::flush();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->alreadyFunded($user, 450);
        $this->actingAs($user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '100')
            ->call('fund')
            ->assertNotDispatched('risk-hold');
    }
}
