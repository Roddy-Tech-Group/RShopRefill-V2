<?php

namespace Tests\Feature\Wallet;

use App\Domain\Auth\Exceptions\EmailVerificationRequiredException;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Domain\Wallet\Services\WalletService;
use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Email verification is mandatory for money-in on EVERY path. The API route is
 * covered by the verified-email middleware; this pins the service-level gate
 * that also covers the dashboard's Livewire funding component, which used to
 * bypass the middleware entirely.
 */
class FundingEmailGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unverified_email_refuses_funding_and_resends_the_link(): void
    {
        $user = User::factory()->unverified()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);

        try {
            app(WalletFundingService::class)->initializeFunding($user, $wallet, 100, Currency::USD);
            $this->fail('Expected EmailVerificationRequiredException.');
        } catch (EmailVerificationRequiredException $e) {
            $this->assertStringContainsString('verify your email', $e->customerMessage());
            $this->assertTrue($e->sent);
            $this->assertSame(30, $e->retryAfter); // first send -> 30s cooldown
        }

        // Nothing was written...
        $this->assertDatabaseCount('wallet_fundings', 0);

        // ...and a fresh verification link went out.
        $this->assertTrue(
            CommunicationLog::query()
                ->where('user_id', $user->id)
                ->where('category', CommunicationCategory::EmailVerification->value)
                ->exists(),
        );
    }

    public function test_the_funding_component_shows_the_verify_email_notice(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        Volt::test('dashboard.fund-wallet', ['currency' => 'USD'])
            ->set('amount', '100')
            ->call('fund')
            ->assertDispatched('email-verification-required');
    }

    public function test_a_verified_email_passes_the_gate(): void
    {
        config(['services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);

        $result = app(WalletFundingService::class)->initializeFunding($user, $wallet, 100, Currency::USD);

        $this->assertNotNull($result['funding']);
        $this->assertDatabaseCount('wallet_fundings', 1);
    }
}
