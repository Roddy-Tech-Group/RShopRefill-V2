<?php

namespace Tests\Feature\Kyc;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Models\Admin;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SmileIdentity\Signature;
use Tests\TestCase;

/**
 * The persisted provider-attempt trail: recording at session mint, resolution
 * by the webhook (including the never-demote-verified guard), the job-status
 * verdict normalisation, the admin "check with provider" reconciliation, and
 * the admin customer page's provider panel.
 */
class KycVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $partnerId = 'partner-test';

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = base64_encode('smile-secret-key');

        config([
            'services.smileid.partner_id' => $this->partnerId,
            'services.smileid.api_key' => $this->apiKey,
            'services.smileid.server' => 0,
            'services.smileid.callback_url' => 'https://example.test/api/webhooks/smileid',
            'services.smileid.approved_result_codes' => ['1012'],
            'services.smileid.biometric_countries' => [],
        ]);
    }

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

    /**
     * The real provider with only the live HTTP token mint stubbed out, so the
     * attempt-recording path runs for real.
     */
    private function offlineProvider(): SmileKycProvider
    {
        return new class extends SmileKycProvider
        {
            protected function mintWebToken(string $userId, string $jobId, string $product): ?string
            {
                return 'tok-offline';
            }
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function signedPayload(array $extra): array
    {
        $generated = (new Signature($this->partnerId, $this->apiKey))->generate_signature();

        return array_merge([
            'timestamp' => $generated['timestamp'],
            'signature' => $generated['signature'],
        ], $extra);
    }

    // --- Recording at session mint -------------------------------------------

    public function test_starting_a_verification_records_a_pending_attempt(): void
    {
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $session = $this->offlineProvider()->startVerification($user);

        $this->assertDatabaseHas('kyc_verifications', [
            'user_id' => $user->id,
            'provider' => 'smile',
            'reference' => $session->reference,
            'product' => 'doc_verification',
            'status' => 'pending',
        ]);
    }

    // --- Webhook resolution ---------------------------------------------------

    public function test_an_approved_webhook_resolves_the_attempt_and_verifies_the_user(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $payload = $this->signedPayload([
            'ResultCode' => '1012',
            'PartnerParams' => ['job_id' => $verification->reference, 'user_id' => 'user_'.$user->id],
        ]);

        $this->postJson('/api/webhooks/smileid', $payload)->assertOk();

        $verification->refresh();
        $this->assertSame('approved', $verification->status);
        $this->assertSame('1012', $verification->result_code);
        $this->assertNotNull($verification->decided_at);
        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    public function test_a_rejected_webhook_resolves_the_attempt_with_the_reason(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $payload = $this->signedPayload([
            'ResultCode' => '1013',
            'ResultText' => 'Unable to validate ID - Not Found',
            'PartnerParams' => ['job_id' => $verification->reference, 'user_id' => 'user_'.$user->id],
        ]);

        $this->postJson('/api/webhooks/smileid', $payload)->assertOk();

        $verification->refresh();
        $this->assertSame('rejected', $verification->status);
        $this->assertSame('Unable to validate ID - Not Found', $verification->result_text);
        $this->assertSame('rejected', $user->fresh()->kyc_status);
    }

    public function test_a_rejected_webhook_never_demotes_an_already_verified_user(): void
    {
        $user = User::factory()->create(['kyc_status' => 'verified']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $payload = $this->signedPayload([
            'ResultCode' => '1013',
            'PartnerParams' => ['job_id' => $verification->reference, 'user_id' => 'user_'.$user->id],
        ]);

        $this->postJson('/api/webhooks/smileid', $payload)->assertOk();

        // The attempt records the failure, but the customer stays verified.
        $this->assertSame('rejected', $verification->fresh()->status);
        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    public function test_a_webhook_for_an_unrecorded_job_still_flips_the_user_via_fallback(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);

        // A job minted before attempts were persisted: no row, only PartnerParams.
        $payload = $this->signedPayload([
            'ResultCode' => '1012',
            'PartnerParams' => ['job_id' => 'kyc_'.$user->id.'_legacy', 'user_id' => 'user_'.$user->id],
        ]);

        $this->postJson('/api/webhooks/smileid', $payload)->assertOk();

        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    // --- Job-status verdict normalisation ------------------------------------

    public function test_an_incomplete_job_status_yields_no_verdict(): void
    {
        $verdict = app(SmileKycProvider::class)->verdictFromJobStatus([
            'job_complete' => false,
            'job_success' => false,
        ]);

        $this->assertNull($verdict);
    }

    public function test_a_complete_successful_job_status_yields_an_approved_verdict(): void
    {
        $verdict = app(SmileKycProvider::class)->verdictFromJobStatus([
            'job_complete' => true,
            'job_success' => true,
            'result' => [
                'ResultCode' => '1012',
                'PartnerParams' => ['job_id' => 'kyc_9_abc', 'user_id' => 'user_9'],
            ],
        ]);

        $this->assertNotNull($verdict);
        $this->assertTrue($verdict->approved);
        $this->assertSame('kyc_9_abc', $verdict->reference);
    }

    public function test_a_complete_failed_job_status_yields_a_rejected_verdict(): void
    {
        $verdict = app(SmileKycProvider::class)->verdictFromJobStatus([
            'job_complete' => true,
            'job_success' => false,
            'result' => [
                'ResultCode' => '1013',
                'ResultText' => 'No match',
                'PartnerParams' => ['job_id' => 'kyc_9_abc', 'user_id' => 'user_9'],
            ],
        ]);

        $this->assertNotNull($verdict);
        $this->assertFalse($verdict->approved);
        $this->assertSame('No match', $verdict->reason);
    }

    // --- Admin "check with provider" sync ------------------------------------

    public function test_admin_sync_applies_a_finished_job_verdict(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $this->partialMock(SmileKycProvider::class, function ($mock) use ($verification) {
            $mock->shouldReceive('queryJobStatus')->once()->andReturn([
                'job_complete' => true,
                'job_success' => true,
                'result' => [
                    'ResultCode' => '1012',
                    'PartnerParams' => ['job_id' => $verification->reference, 'user_id' => 'user_'.$verification->user_id],
                ],
            ]);
        });

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.kyc.sync', $verification))
            ->assertRedirect();

        $this->assertSame('approved', $verification->fresh()->status);
        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    public function test_admin_sync_leaves_a_still_processing_job_untouched(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $this->partialMock(SmileKycProvider::class, function ($mock) {
            $mock->shouldReceive('queryJobStatus')->once()->andReturn([
                'job_complete' => false,
                'job_success' => false,
            ]);
        });

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.kyc.sync', $verification))
            ->assertRedirect()
            ->assertSessionHas('status', 'Smile ID is still processing this verification. Check back shortly.');

        $this->assertSame('pending', $verification->fresh()->status);
        $this->assertSame('pending', $user->fresh()->kyc_status);
    }

    public function test_admin_sync_refuses_an_already_decided_attempt(): void
    {
        $verification = KycVerification::factory()->approved()->create();

        $this->partialMock(SmileKycProvider::class, function ($mock) {
            $mock->shouldReceive('queryJobStatus')->never();
        });

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.kyc.sync', $verification))
            ->assertRedirect()
            ->assertSessionHas('status', 'This verification already has a verdict.');
    }

    public function test_admin_sync_survives_a_provider_outage(): void
    {
        $verification = KycVerification::factory()->create();

        $this->partialMock(SmileKycProvider::class, function ($mock) {
            $mock->shouldReceive('queryJobStatus')->once()->andThrow(new \RuntimeException('smile down'));
        });

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.kyc.sync', $verification))
            ->assertRedirect()
            ->assertSessionHas('status', 'Could not reach Smile ID. Please try again shortly.');

        $this->assertSame('pending', $verification->fresh()->status);
    }

    public function test_sync_requires_an_authenticated_admin(): void
    {
        $verification = KycVerification::factory()->create();

        // Unauthenticated admin traffic gets a 404, hiding the admin surface
        // entirely (app-wide convention).
        $this->post(route('admin.kyc.sync', $verification))->assertNotFound();
        $this->assertSame('pending', $verification->fresh()->status);
    }

    // --- Admin customer page panel -------------------------------------------

    public function test_the_admin_customer_page_shows_provider_attempts(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $user))
            ->assertOk()
            ->assertSee('Document Verification')
            ->assertSee('Check with provider')
            ->assertSee($verification->reference);
    }

    public function test_the_admin_customer_page_shows_a_rejection_reason(): void
    {
        $user = User::factory()->create(['kyc_status' => 'rejected']);
        $verification = KycVerification::factory()->rejected('Document image too blurry')->create(['user_id' => $user->id]);

        // The panel's explainer prose mentions "Check with provider", so assert
        // on the sync form's URL: a decided attempt must not render the button.
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $user))
            ->assertOk()
            ->assertSee('Document image too blurry')
            ->assertDontSee('kyc/verifications/'.$verification->id.'/sync', false);
    }

    public function test_the_admin_customer_page_shows_an_empty_state_without_attempts(): void
    {
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $user))
            ->assertOk()
            ->assertSee('No verification attempts yet');
    }
}
