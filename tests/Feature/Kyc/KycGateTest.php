<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Services\KycGate;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The enforcement seam. With KYC enforcement on, a money action at or above the
 * threshold throws with a minted verification session; in shadow mode it is
 * inert; a verified user is never stepped up. The provider is stubbed so no
 * live Smile HTTP call is made.
 */
class KycGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kyc.tier2_threshold_usd' => 200,
            'kyc.tier1_trigger_usd' => 0,
        ]);

        // The threshold lives in the admin-editable site_settings row (seeded
        // at $500), which outranks the config pin - so move the row itself.
        SiteSetting::put('features.kyc_tier2_threshold_usd', '200', 'features');

        $fake = new class implements KycProvider
        {
            public function key(): string
            {
                return 'fake';
            }

            public function startVerification(User $user, ?string $issuingCountry = null): KycSession
            {
                return new KycSession('ref-1', token: 'tok-1', metadata: ['partner_id' => 'p', 'product' => 'biometric_kyc', 'environment' => 'sandbox']);
            }

            public function verdictFromWebhook(array $payload): ?KycVerdict
            {
                return null;
            }
        };

        $this->mock(KycProviderFactory::class, function ($mock) use ($fake) {
            $mock->shouldReceive('forUser')->andReturn($fake);
        });
    }

    private function enforce(bool $on): void
    {
        SiteSetting::put('features.kyc_enforce_enabled', $on ? 'on' : 'off', 'features');
        Cache::flush();
    }

    public function test_enforcing_a_high_value_action_throws_with_a_smile_session(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(); // identity-unverified

        try {
            app(KycGate::class)->guard($user, 250, 'order');
            $this->fail('Expected KycStepUpRequiredException.');
        } catch (KycStepUpRequiredException $e) {
            $this->assertSame('tok-1', $e->session->token);
            $this->assertTrue($e->toResponse()['kyc_required']);
        }
    }

    public function test_shadow_mode_lets_the_action_through(): void
    {
        $this->enforce(false);
        $user = User::factory()->create();

        app(KycGate::class)->guard($user, 5000, 'order');

        $this->assertTrue(true); // no exception
    }

    public function test_a_verified_user_is_not_stepped_up(): void
    {
        $this->enforce(true);
        $user = User::factory()->create(['kyc_status' => 'verified']);

        app(KycGate::class)->guard($user, 5000, 'order');

        $this->assertTrue(true); // already IdentityVerified
    }
}
