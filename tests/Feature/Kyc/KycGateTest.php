<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycGate;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Models\KycVerification;
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

    private function enforce(bool $on, bool $verificationAvailable = true): void
    {
        SiteSetting::put('features.kyc_enforce_enabled', $on ? 'on' : 'off', 'features');
        SiteSetting::put('features.kyc_verification_enabled', $verificationAvailable ? 'on' : 'off', 'features');
        Cache::flush();
    }

    /**
     * Demanding a verification nobody can complete would only block the
     * payment, so while verification is switched off an enforced step-up is
     * logged and let through, and the provider is never called.
     */
    public function test_an_enforced_step_up_lets_the_action_through_while_verification_is_off(): void
    {
        $this->enforce(true, verificationAvailable: false);
        $this->mock(KycProviderFactory::class, function ($mock) {
            $mock->shouldReceive('forUser')->never();
        });
        $user = User::factory()->create();

        // No exception: the payment goes through, and no attempt was started.
        app(KycGate::class)->guard($user, 250, 'order');

        $this->assertSame(0, KycVerification::count());
    }

    /**
     * A customer whose verification is already with the provider is not sent
     * round the flow again on their next payment: no new session (and no second
     * paid check), the attempt under review stays pending, and the modal is told
     * to wait for the verdict.
     */
    public function test_a_customer_already_in_review_waits_for_the_verdict_instead_of_a_new_session(): void
    {
        $this->enforce(true);
        $this->mock(KycProviderFactory::class, function ($mock) {
            $mock->shouldReceive('forUser')->never();
        });
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $underReview = KycVerification::factory()->create(['user_id' => $user->id]);

        try {
            app(KycGate::class)->guard($user, 250, 'order');
            $this->fail('Expected KycStepUpRequiredException.');
        } catch (KycStepUpRequiredException $e) {
            $this->assertTrue($e->toResponse()['smile']['in_review']);
            $this->assertNull($e->session->token);
            $this->assertStringContainsString('being reviewed', $e->customerMessage());
        }

        $this->assertSame('pending', $underReview->fresh()->status);
        $this->assertSame(1, KycVerification::count());
    }

    /**
     * A provider refusal during enforcement still holds the payment, but hands
     * the modal a session with no token so the customer is told verification
     * is not available, rather than the payment failing with a server error.
     */
    public function test_a_refused_session_still_holds_the_payment_without_a_token(): void
    {
        $this->enforce(true);
        $refusing = new class extends SmileKycProvider
        {
            protected function mintWebToken(string $userId, string $jobId, string $product): ?string
            {
                return $this->tokenFromResponse(['code' => '2204', 'error' => 'Unauthorized']);
            }
        };
        $this->mock(KycProviderFactory::class, function ($mock) use ($refusing) {
            $mock->shouldReceive('forUser')->andReturn($refusing);
        });
        $user = User::factory()->create();

        try {
            app(KycGate::class)->guard($user, 250, 'order');
            $this->fail('Expected KycStepUpRequiredException.');
        } catch (KycStepUpRequiredException $e) {
            $this->assertNull($e->session->token);
        }

        $this->assertSame(0, KycVerification::count());
    }

    public function test_an_already_enrolled_customer_with_a_pass_is_verified_and_let_through(): void
    {
        $this->enforce(true);
        $enrolled = new class extends SmileKycProvider
        {
            protected function mintWebToken(string $userId, string $jobId, string $product): ?string
            {
                return $this->tokenFromResponse(['code' => '2209', 'error' => 'Wrong job type. This user is already enrolled.']);
            }

            public function queryJobStatus(KycVerification $verification): array
            {
                return ['job_complete' => true, 'result' => [
                    'ResultCode' => '0810',
                    'PartnerParams' => ['job_id' => $verification->reference, 'job_type' => '6'],
                ]];
            }
        };
        $this->mock(KycProviderFactory::class, function ($mock) use ($enrolled) {
            $mock->shouldReceive('forUser')->andReturn($enrolled);
        });
        $user = User::factory()->create(['kyc_status' => 'rejected']);
        KycVerification::factory()->rejected()->create(['user_id' => $user->id]);

        app(KycGate::class)->guard($user, 250, 'order');

        $this->assertSame('verified', $user->fresh()->kyc_status);
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
