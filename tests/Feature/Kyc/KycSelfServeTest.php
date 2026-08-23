<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The self-serve KYC flow behind the dashboard page's "Verify now" button:
 * minting a Smile session, recording the capture as in-review, and the page's
 * status-driven states. The provider is stubbed so no live Smile call is made.
 */
class KycSelfServeTest extends TestCase
{
    use RefreshDatabase;

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
