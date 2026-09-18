<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SmileIdentity\Signature;
use Tests\TestCase;

/**
 * Smile ID provider: product routing by country, and the authentication +
 * normalisation of the result callback. The live token/HTTP path needs real
 * credentials and is exercised in sandbox, not here.
 */
class SmileKycProviderTest extends TestCase
{
    use RefreshDatabase;

    private SmileKycProvider $provider;

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
            'services.smileid.approved_result_codes' => [
                'doc_verification' => ['0810'],
                'biometric_kyc' => ['1020'],
            ],
            'services.smileid.biometric_countries' => ['NG', 'GH', 'KE', 'ZA'],
        ]);

        $this->provider = app(SmileKycProvider::class);
    }

    public function test_product_is_biometric_only_for_gov_database_countries(): void
    {
        $this->assertSame('biometric_kyc', $this->provider->productForCountry('NG'));
        $this->assertSame('biometric_kyc', $this->provider->productForCountry('ke'));
        // Cameroon and Uganda (and the rest of the world) use Global Document
        // Verification: Smile has no Biometric KYC there (Uganda IDs are
        // no-photo, so there is no government photo to match a selfie against).
        $this->assertSame('doc_verification', $this->provider->productForCountry('CM'));
        $this->assertSame('doc_verification', $this->provider->productForCountry('UG'));
        $this->assertSame('doc_verification', $this->provider->productForCountry('US'));
        $this->assertSame('doc_verification', $this->provider->productForCountry(null));
    }

    public function test_a_validly_signed_approved_callback_yields_an_approved_verdict(): void
    {
        $verdict = $this->provider->verdictFromWebhook($this->signedPayload(['ResultCode' => '0810']));

        $this->assertNotNull($verdict);
        $this->assertTrue($verdict->approved);
        $this->assertSame('kyc_42_abc', $verdict->reference);
    }

    public function test_a_validly_signed_but_unsuccessful_callback_yields_a_rejected_verdict(): void
    {
        $verdict = $this->provider->verdictFromWebhook($this->signedPayload([
            'ResultCode' => '1014',
            'ResultText' => 'No match',
        ]));

        $this->assertNotNull($verdict);
        $this->assertFalse($verdict->approved);
    }

    /**
     * Each Smile product reports success with its own code. The KYC 1012 this
     * used to accept everywhere marked a real Document Verification pass
     * (0810) as rejected, and accepting codes across products would let one
     * product's pass approve a job that ran another.
     */
    public function test_another_products_pass_code_never_approves_a_document_job(): void
    {
        foreach (['1012', '1020'] as $foreignCode) {
            $verdict = $this->provider->verdictFromWebhook($this->signedPayload(['ResultCode' => $foreignCode]));

            $this->assertNotNull($verdict);
            $this->assertFalse($verdict->approved, "{$foreignCode} approved a Document Verification job");
        }
    }

    public function test_a_biometric_job_is_approved_only_by_the_biometric_pass_code(): void
    {
        $biometric = ['PartnerParams' => ['job_id' => 'kyc_42_bio', 'user_id' => 'user_42', 'job_type' => '1']];

        $this->assertTrue($this->provider->verdictFromWebhook($this->signedPayload($biometric + ['ResultCode' => '1020']))->approved);
        $this->assertFalse($this->provider->verdictFromWebhook($this->signedPayload($biometric + ['ResultCode' => '0810']))->approved);
    }

    public function test_a_job_whose_product_cannot_be_told_is_never_approved(): void
    {
        $verdict = $this->provider->verdictFromWebhook($this->signedPayload([
            'ResultCode' => '0810',
            'PartnerParams' => ['job_id' => 'kyc_42_unknown', 'user_id' => 'user_42'],
        ]));

        $this->assertNotNull($verdict);
        $this->assertFalse($verdict->approved);
    }

    /**
     * Our own attempt row decides the product, not the job_type echoed back in
     * the callback body.
     */
    public function test_the_recorded_product_outranks_the_echoed_job_type(): void
    {
        $user = User::factory()->create();
        KycVerification::factory()->create([
            'user_id' => $user->id,
            'reference' => 'kyc_42_recorded',
            'product' => 'doc_verification',
        ]);

        $verdict = $this->provider->verdictFromWebhook($this->signedPayload([
            'ResultCode' => '1020',
            'PartnerParams' => ['job_id' => 'kyc_42_recorded', 'user_id' => 'user_42', 'job_type' => '1'],
        ]));

        $this->assertFalse($verdict->approved);
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $payload = $this->signedPayload(['ResultCode' => '0810']);
        $payload['signature'] = 'tampered';

        $this->assertNull($this->provider->verdictFromWebhook($payload));
    }

    public function test_webhook_flips_kyc_status_on_an_approved_callback(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);

        $payload = $this->signedPayload(['ResultCode' => '0810']);
        $payload['PartnerParams'] = ['job_id' => 'kyc_'.$user->id.'_abc', 'user_id' => 'user_'.$user->id, 'job_type' => '6'];

        $this->postJson('/api/webhooks/smileid', $payload)->assertOk();

        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    public function test_webhook_ignores_an_unsigned_callback(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);

        // No valid signature: the status must not change.
        $this->postJson('/api/webhooks/smileid', [
            'ResultCode' => '0810',
            'PartnerParams' => ['job_id' => 'x', 'user_id' => 'user_'.$user->id],
        ])->assertOk();

        $this->assertSame('pending', $user->fresh()->kyc_status);
    }

    /**
     * Build a callback body carrying a Smile-valid signature (timestamp-based,
     * per the SDK) plus our tracking params.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function signedPayload(array $extra): array
    {
        $generated = (new Signature($this->partnerId, $this->apiKey))->generate_signature();

        return array_merge([
            'timestamp' => $generated['timestamp'],
            'signature' => $generated['signature'],
            'PartnerParams' => ['job_id' => 'kyc_42_abc', 'user_id' => 'user_42', 'job_type' => '6'],
        ], $extra);
    }
}
