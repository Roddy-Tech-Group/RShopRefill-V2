<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycOutcomeNotifier;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Domain\Kyc\Services\KycSelfServeEligibility;
use App\Models\KycVerification;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The self-serve KYC flow behind the dashboard page's "Verify now" button:
 * minting a Smile session, recording the capture as in-review, and the page's
 * status-driven states. The provider is stubbed so no live Smile call is made.
 */
class KycSelfServeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::put('features.kyc_verification_enabled', 'on', 'features');
        // The transact-first rule has its own suite (KycSelfServeEligibilityTest);
        // switched off here so these tests keep exercising the session flow
        // for customers who have not bought anything yet.
        SiteSetting::put(KycSelfServeEligibility::MIN_TRANSACTIONS_KEY, '0', 'features');
        Cache::flush();
    }

    /**
     * The real Smile provider with its two network calls replaced: the token
     * mint answers with Smile's "already enrolled" refusal, and the job-status
     * lookup answers with the given result for every attempt.
     *
     * @param  array<string, mixed>|null  $jobResult
     */
    private function enrolledSmileProvider(?array $jobResult): SmileKycProvider
    {
        $provider = new class extends SmileKycProvider
        {
            public ?array $jobResult = null;

            protected function mintWebToken(string $userId, string $jobId, string $product): ?string
            {
                return $this->tokenFromResponse(['code' => '2209', 'error' => 'Wrong job type. This user is already enrolled.', 'statusCode' => 400]);
            }

            public function queryJobStatus(KycVerification $verification): array
            {
                return $this->jobResult === null
                    ? ['job_complete' => false]
                    : ['job_complete' => true, 'result' => array_merge($this->jobResult, [
                        'PartnerParams' => ['job_id' => $verification->reference, 'user_id' => 'user_'.$verification->user_id, 'job_type' => '6'],
                    ])];
            }
        };

        $provider->jobResult = $jobResult;

        $this->mock(KycProviderFactory::class, function ($mock) use ($provider) {
            $mock->shouldReceive('forUser')->andReturn($provider);
        });

        return $provider;
    }

    /**
     * Production has no live identity provider yet. A customer who pressed
     * Verify there started a session that could never end verified; with the
     * switch off nothing reaches the provider at all.
     */
    public function test_start_says_verification_is_not_available_while_the_switch_is_off(): void
    {
        SiteSetting::put('features.kyc_verification_enabled', 'off', 'features');
        Cache::flush();

        $this->mock(KycProviderFactory::class, function ($mock) {
            $mock->shouldReceive('forUser')->never();
        });
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Verification is currently unavailable, try again later.');

        $this->assertSame(0, KycVerification::count());
    }

    /**
     * A confirmed email comes before identity verification. The refusal happens
     * before anything reaches the provider, so no session or attempt is started.
     */
    public function test_start_requires_a_verified_email_first(): void
    {
        $this->mock(KycProviderFactory::class, function ($mock) {
            $mock->shouldReceive('forUser')->never();
        });
        $user = User::factory()->unverified()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertStatus(403)
            ->assertJsonPath('email_unverified', true);

        $this->assertSame(0, KycVerification::count());
    }

    /**
     * The button reads the same whether or not the email is confirmed; the
     * refusal (and the fresh verification link) arrive when it is tapped,
     * exactly as they do while the verification switch is off.
     */
    public function test_the_page_still_offers_verify_now_before_the_email_is_confirmed(): void
    {
        $user = User::factory()->unverified()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->get('/dashboard/kyc')
            ->assertOk()
            ->assertSee('Verify now')
            ->assertDontSee('Confirm your email address before verifying your identity.');
    }

    /**
     * A refusal used to come back as a session with no token: the customer
     * saw a generic message and a pending attempt was left that nothing
     * could ever resolve.
     */
    public function test_a_provider_refusal_leaves_no_dead_attempt_behind(): void
    {
        $provider = new class extends SmileKycProvider
        {
            protected function mintWebToken(string $userId, string $jobId, string $product): ?string
            {
                return $this->tokenFromResponse(['code' => '2204', 'error' => 'Unauthorized', 'statusCode' => 401]);
            }
        };
        $this->mock(KycProviderFactory::class, function ($mock) use ($provider) {
            $mock->shouldReceive('forUser')->andReturn($provider);
        });
        $user = User::factory()->create(['kyc_status' => 'rejected']);
        $earlier = KycVerification::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertStatus(502)
            ->assertJsonPath('message', 'We could not start verification right now. Please try again in a moment.');

        $this->assertSame(1, KycVerification::count());
        $this->assertSame('pending', $earlier->fresh()->status);
    }

    /**
     * Smile refuses a new session for someone it already passed. That pass is
     * read back and applied, correcting the attempt that recorded it wrongly,
     * instead of leaving the customer at a dead end.
     */
    public function test_an_already_enrolled_customer_is_verified_from_their_earlier_pass(): void
    {
        $this->mock(KycOutcomeNotifier::class, function ($mock) {
            $mock->shouldReceive('verified')->once();
        });
        $this->enrolledSmileProvider(['ResultCode' => '0810', 'ResultText' => 'Document Verified']);
        $user = User::factory()->create(['kyc_status' => 'rejected']);
        $wronglyRejected = KycVerification::factory()->rejected('Document Verified')->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertOk()
            ->assertJsonPath('already_verified', true);

        $this->assertSame('verified', $user->fresh()->kyc_status);
        $this->assertSame('approved', $wronglyRejected->fresh()->status);
        $this->assertSame(1, KycVerification::count());
    }

    public function test_an_already_enrolled_customer_without_a_readable_pass_is_sent_to_support(): void
    {
        $this->enrolledSmileProvider(null);
        $user = User::factory()->create(['kyc_status' => 'rejected']);
        KycVerification::factory()->rejected()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertStatus(502)
            ->assertJsonPath('message', 'We could not start verification right now. Please contact support.');

        $this->assertSame('rejected', $user->fresh()->kyc_status);
    }

    private function fakeProvider(): KycProvider
    {
        return new class implements KycProvider
        {
            /** Records the issuing-country override the controller passed. */
            public ?string $receivedCountry = null;

            public function key(): string
            {
                return 'fake';
            }

            public function startVerification(User $user, ?string $issuingCountry = null): KycSession
            {
                $this->receivedCountry = $issuingCountry;

                return new KycSession('kyc_ref_1', token: 'tok-self-serve', metadata: [
                    'partner_id' => 'partner-1',
                    'product' => 'doc_verification',
                    'environment' => 'sandbox',
                    'user_id' => 'user_'.$user->getKey(),
                ]);
            }

            public function verdictFromWebhook(array $payload): ?KycVerdict
            {
                return null;
            }
        };
    }

    /** The active fake, kept for asserting what the controller passed it. */
    private ?KycProvider $fake = null;

    private function mockProvider(): void
    {
        $this->fake = $this->fakeProvider();
        $fake = $this->fake;

        $this->mock(KycProviderFactory::class, function ($mock) use ($fake) {
            $mock->shouldReceive('forUser')->andReturn($fake);
        });
    }

    // --- POST /api/kyc/start -------------------------------------------------

    public function test_start_mints_a_smile_session_for_an_unverified_user(): void
    {
        $this->mockProvider();
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertOk()
            ->assertJsonPath('smile.token', 'tok-self-serve')
            ->assertJsonPath('smile.partner_id', 'partner-1')
            ->assertJsonPath('smile.product', 'doc_verification');
    }

    public function test_start_passes_the_chosen_issuing_country_through(): void
    {
        $this->mockProvider();
        $user = User::factory()->create(['kyc_status' => 'unsubmitted', 'country' => 'NG']);

        // A Nigerian-profile customer verifying with a Cameroonian document.
        $this->actingAs($user)->postJson('/api/kyc/start', ['country' => 'CM'])->assertOk();

        $this->assertSame('CM', $this->fake->receivedCountry);
    }

    public function test_start_rejects_an_unknown_issuing_country(): void
    {
        $this->mockProvider();
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->postJson('/api/kyc/start', ['country' => 'ZZ'])->assertStatus(422);
    }

    public function test_start_refuses_an_already_verified_user(): void
    {
        $this->mockProvider();
        $user = User::factory()->create(['kyc_status' => 'verified']);

        $this->actingAs($user)->postJson('/api/kyc/start')->assertStatus(409);
    }

    public function test_start_refuses_while_a_verification_is_in_review(): void
    {
        $this->mockProvider();
        $user = User::factory()->create(['kyc_status' => 'pending']);

        $this->actingAs($user)->postJson('/api/kyc/start')->assertStatus(409);
    }

    public function test_start_refuses_guest_shadow_accounts(): void
    {
        $this->mockProvider();
        $guest = User::factory()->create(['is_guest' => true]);

        $this->actingAs($guest)->postJson('/api/kyc/start')->assertStatus(403);
    }

    public function test_start_fails_closed_with_a_friendly_message_when_the_provider_errors(): void
    {
        $this->mock(KycProviderFactory::class, function ($mock) {
            $mock->shouldReceive('forUser')->andThrow(new \RuntimeException('smile down'));
        });
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->postJson('/api/kyc/start')
            ->assertStatus(502)
            ->assertJsonStructure(['message']);
    }

    /**
     * Identity is only ever verified by the provider. The old document upload
     * and its admin approve/reject review are gone, so no second, unwatched
     * way to become verified remains.
     */
    public function test_there_is_no_manual_document_upload_or_review(): void
    {
        foreach (['kyc.submit', 'admin.kyc.approve', 'admin.kyc.reject', 'admin.kyc.document'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} still exists");
        }

        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->post('/dashboard/kyc', ['kyc_full_name' => 'Jane Doe'])->assertStatus(405);
        $this->assertSame('unsubmitted', $user->fresh()->kyc_status);
    }

    // --- POST /api/kyc/submitted --------------------------------------------

    public function test_submitted_moves_an_unsubmitted_user_to_pending(): void
    {
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->postJson('/api/kyc/submitted')
            ->assertOk()
            ->assertJsonPath('kyc_status', 'pending');

        $this->assertSame('pending', $user->fresh()->kyc_status);
    }

    public function test_submitted_moves_a_rejected_user_back_to_pending(): void
    {
        $user = User::factory()->create(['kyc_status' => 'rejected']);

        $this->actingAs($user)->postJson('/api/kyc/submitted');

        $this->assertSame('pending', $user->fresh()->kyc_status);
    }

    public function test_submitted_never_demotes_a_verified_user(): void
    {
        $user = User::factory()->create(['kyc_status' => 'verified']);

        $this->actingAs($user)->postJson('/api/kyc/submitted');

        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    // --- The page's status-driven states -------------------------------------

    public function test_the_page_shows_verify_now_for_an_unverified_user(): void
    {
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($user)->get('/dashboard/kyc')
            ->assertOk()
            ->assertSee('Verify now')
            ->assertSee('encrypted and used only to verify your identity')
            ->assertDontSee('Upload your documents');
    }

    public function test_the_page_shows_try_again_after_a_rejection(): void
    {
        $user = User::factory()->create(['kyc_status' => 'rejected']);

        $this->actingAs($user)->get('/dashboard/kyc')
            ->assertOk()
            ->assertSee('Try again')
            ->assertSee('could not verify your identity');
    }

    public function test_the_page_shows_the_review_state_while_pending(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);

        $this->actingAs($user)->get('/dashboard/kyc')
            ->assertOk()
            ->assertSee('in review')
            ->assertDontSee('Verify now');
    }

    public function test_the_page_shows_the_verified_state(): void
    {
        $user = User::factory()->create(['kyc_status' => 'verified']);

        $this->actingAs($user)->get('/dashboard/kyc')
            ->assertOk()
            ->assertSee('Your identity is verified')
            ->assertDontSee('Verify now');
    }
}
