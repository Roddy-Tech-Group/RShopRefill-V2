<?php

namespace Tests\Feature\Kyc;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Auth\Services\DataExportService;
use App\Domain\Kyc\DTOs\VerifiedIdentity;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Order\Services\OrderReportService;
use App\Domain\Payment\Services\TransactionReportService;
use App\Models\Admin;
use App\Models\ClosedAccountIdentity;
use App\Models\KycVerification;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use SmileIdentity\Signature;
use Tests\TestCase;

/**
 * What the document said is kept from a pass: legal name, date of birth,
 * document type, issuing country and expiry, encrypted where personal. The
 * customer's display name stays their own (the blue tick follows whichever
 * name they choose); admins see the legal details beside it; the export
 * carries them and closure moves them into the compliance drawer.
 */
class VerifiedIdentityTest extends TestCase
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
            'services.smileid.approved_result_codes' => [
                'doc_verification' => ['0810'],
                'biometric_kyc' => ['1020'],
            ],
            'services.smileid.biometric_countries' => [],
        ]);
    }

    /**
     * A Document Verification result shaped exactly like the sandbox job read
     * back on 2026-10-12: Smile writes "Not Available" for an empty field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function smileResult(string $jobId, array $overrides = []): array
    {
        return array_merge([
            'DOB' => '1990-04-17',
            'Gender' => 'F',
            'IDType' => 'PASSPORT',
            'Actions' => ['Verify_Document' => 'Passed', 'Return_Personal_Info' => 'Returned'],
            'Country' => 'NG',
            'Document' => 'Not Available',
            'FullName' => 'ADAEZE NGOZI OKAFOR',
            'IDNumber' => 'A01234567',
            'LastName' => 'OKAFOR',
            'FirstName' => 'ADAEZE',
            'OtherName' => 'Not Available',
            'ResultCode' => '0810',
            'ResultText' => 'Document Verified',
            'ResultType' => 'Document Verification',
            'SmileJobID' => '0000012345',
            'IssuanceDate' => '2021-03-02',
            'PartnerParams' => ['job_id' => $jobId, 'user_id' => 'user_1', 'job_type' => 6],
            'ExpirationDate' => '2031-03-01',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $result */
    private function signed(array $result): array
    {
        $generated = (new Signature($this->partnerId, $this->apiKey))->generate_signature();

        return ['timestamp' => $generated['timestamp'], 'signature' => $generated['signature']] + $result;
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

    private function verifiedCustomer(string $displayName = 'Ada'): array
    {
        $user = User::factory()->create(['name' => $displayName, 'kyc_status' => 'verified']);
        $verification = KycVerification::factory()->approved()->create([
            'user_id' => $user->id,
            'legal_name' => 'ADAEZE NGOZI OKAFOR',
            'date_of_birth' => '1990-04-17',
            'id_type' => 'PASSPORT',
            'id_country' => 'NG',
            'id_expires_on' => '2031-03-01',
        ]);

        return [$user, $verification];
    }

    // --- Reading Smile's result ---------------------------------------------

    public function test_a_smile_result_is_read_into_the_legal_identity(): void
    {
        $identity = VerifiedIdentity::fromSmile($this->smileResult('kyc_1_a'));

        $this->assertSame('ADAEZE NGOZI OKAFOR', $identity->legalName);
        $this->assertSame('1990-04-17', $identity->dateOfBirth);
        $this->assertSame('PASSPORT', $identity->idType);
        $this->assertSame('NG', $identity->idCountry);
        $this->assertSame('2031-03-01', $identity->idExpiresOn);
        $this->assertArrayNotHasKey('id_number', $identity->attributes(), 'the document number is never kept');
    }

    public function test_without_a_full_name_the_parts_are_joined_and_placeholders_skipped(): void
    {
        $identity = VerifiedIdentity::fromSmile($this->smileResult('kyc_1_a', ['FullName' => 'Not Available']));

        $this->assertSame('ADAEZE OKAFOR', $identity->legalName);
    }

    public function test_placeholders_and_malformed_values_are_read_as_empty(): void
    {
        $identity = VerifiedIdentity::fromSmile($this->smileResult('kyc_1_a', [
            'DOB' => '1990-02-31',
            'Country' => 'Nigeria',
            'IDType' => 'Not Available',
            'ExpirationDate' => 'None',
        ]));

        $this->assertNull($identity->dateOfBirth);
        $this->assertNull($identity->idCountry);
        $this->assertNull($identity->idType);
        $this->assertNull($identity->idExpiresOn);
    }

    public function test_a_result_that_names_nobody_is_no_identity(): void
    {
        $this->assertNull(VerifiedIdentity::fromSmile([
            'FullName' => 'Not Available', 'FirstName' => '', 'LastName' => 'None', 'DOB' => 'Not Available', 'Country' => 'NG',
        ]));
    }

    // --- Storing it ------------------------------------------------------------

    public function test_an_approved_callback_keeps_the_legal_identity_encrypted(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/webhooks/smileid', $this->signed($this->smileResult($verification->reference)))->assertOk();

        $verification->refresh();
        $this->assertSame('approved', $verification->status);
        $this->assertSame('ADAEZE NGOZI OKAFOR', $verification->legal_name);
        $this->assertSame('1990-04-17', $verification->date_of_birth);
        $this->assertSame('PASSPORT', $verification->id_type);
        $this->assertSame('NG', $verification->id_country);
        $this->assertSame('2031-03-01', $verification->id_expires_on->toDateString());

        $raw = DB::table('kyc_verifications')->where('id', $verification->id)->first();
        $this->assertNotSame('ADAEZE NGOZI OKAFOR', $raw->legal_name, 'legal name is encrypted at rest');
        $this->assertNotSame('1990-04-17', $raw->date_of_birth, 'date of birth is encrypted at rest');

        // The display name is the customer's own and is not touched.
        $this->assertSame('Ada', $user->fresh()->name);
        $this->assertSame('verified', $user->fresh()->kyc_status);
    }

    public function test_a_rejected_callback_keeps_no_identity(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/webhooks/smileid', $this->signed($this->smileResult($verification->reference, [
            'ResultCode' => '0811', 'ResultText' => 'Document not verified',
        ])))->assertOk();

        $verification->refresh();
        $this->assertSame('rejected', $verification->status);
        $this->assertFalse($verification->hasLegalIdentity());
    }

    public function test_the_admin_sync_path_keeps_it_too(): void
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);
        $verification = KycVerification::factory()->create(['user_id' => $user->id]);

        $this->partialMock(SmileKycProvider::class, function ($mock) use ($verification) {
            $mock->shouldReceive('queryJobStatus')->once()->andReturn([
                'job_complete' => true,
                'job_success' => true,
                'result' => $this->smileResult($verification->reference),
            ]);
        });

        $this->actingAs($this->admin(), 'admin')->post(route('admin.kyc.sync', $verification))->assertRedirect();

        $this->assertSame('ADAEZE NGOZI OKAFOR', $verification->fresh()->legal_name);
        $this->assertSame($verification->id, $user->verifiedIdentity()?->id);
    }

    // --- Admin --------------------------------------------------------------------

    public function test_admins_see_the_legal_details_and_a_differing_display_name(): void
    {
        [$user] = $this->verifiedCustomer('Ada');

        $this->withoutVite()->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $user))
            ->assertOk()
            ->assertSee('data-legal-identity', false)
            ->assertSeeInOrder(['Legal name', 'ADAEZE NGOZI OKAFOR', 'Date of birth', '17 Apr 1990', 'Document', 'Passport', 'Issued by', 'Nigeria', 'Expires', '1 Mar 2031'])
            ->assertSee('The customer goes by "Ada" on the platform, which is not their legal name.', false);
    }

    public function test_no_legal_details_without_a_pass_that_kept_them(): void
    {
        $user = User::factory()->create(['kyc_status' => 'verified']);
        KycVerification::factory()->approved()->create(['user_id' => $user->id]);

        $this->withoutVite()->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $user))
            ->assertOk()
            ->assertDontSee('data-legal-identity', false);
    }

    // --- Customer ----------------------------------------------------------------

    public function test_the_customer_is_offered_their_verified_name_and_keeps_the_tick_either_way(): void
    {
        [$user] = $this->verifiedCustomer('Ada');
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->assertSee('data-verified-name-offer', false)
            ->assertSee('Your ID says Adaeze Ngozi Okafor.')
            ->assertSee('Identity verified')
            ->call('useVerifiedName')
            ->assertSet('name', 'Adaeze Ngozi Okafor')
            ->assertDispatched('profile-updated');

        $this->assertSame('Adaeze Ngozi Okafor', $user->fresh()->name);

        // Once they use it, there is nothing left to offer.
        Volt::test('settings.profile')->assertDontSee('data-verified-name-offer', false);
    }

    /**
     * Keeping their own name is remembered: the offer goes, and the legal name
     * stays on show as a read-only row, since reports carry it regardless.
     */
    public function test_keeping_their_own_name_shows_the_legal_name_read_only(): void
    {
        [$user, $verification] = $this->verifiedCustomer('Ada');
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->assertSee('Keep mine')
            ->assertDontSee('data-legal-name-row', false)
            ->call('keepDisplayName');

        $this->assertNotNull($verification->fresh()->name_offer_declined_at);
        $this->assertSame('Ada', $user->fresh()->name);

        Volt::test('settings.profile')
            ->assertDontSee('data-verified-name-offer', false)
            ->assertSee('data-legal-name-row', false)
            ->assertSeeInOrder(['Legal name', 'Adaeze Ngozi Okafor'])
            ->assertDontSee('From your ID')
            ->assertSee('Identity verified');
    }

    public function test_a_customer_with_no_kept_identity_is_offered_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'kyc_status' => 'verified']);
        KycVerification::factory()->approved()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->assertDontSee('data-verified-name-offer', false)
            ->call('useVerifiedName');

        $this->assertSame('Ada', $user->fresh()->name);
    }

    // --- Order and transaction reports --------------------------------------------

    public function test_reports_name_a_verified_customer_by_their_legal_name(): void
    {
        [$user] = $this->verifiedCustomer('Ada');
        $order = Order::factory()->create(['user_id' => $user->id]);
        $attempt = $this->attemptFor($user, $order);

        $orderData = app(OrderReportService::class)->assemble($order, 'admin@example.test');
        $transactionData = app(TransactionReportService::class)->assemble($attempt, 'admin@example.test');

        foreach ([['admin.reports.order-report-pdf', $orderData], ['admin.reports.transaction-report-pdf', $transactionData]] as [$view, $data]) {
            $this->assertSame('ADAEZE NGOZI OKAFOR', $data['customer']['legal_name']);
            $this->assertTrue($data['customer']['goes_by_another_name']);

            $html = view($view, ['data' => $data])->render();
            $this->assertStringContainsString('<td class="k">Legal name</td><td class="v">ADAEZE NGOZI OKAFOR</td>', $html, $view);
            $this->assertStringContainsString('<td class="k">Name</td><td class="v">Ada</td>', $html, $view);
        }
    }

    public function test_reports_keep_the_display_name_when_there_is_no_verified_identity(): void
    {
        // Verified once, then set back by an admin: the legal name no longer speaks for them.
        [$user] = $this->verifiedCustomer('Ada');
        $user->update(['kyc_status' => 'rejected']);
        $order = Order::factory()->create(['user_id' => $user->id]);

        $data = app(OrderReportService::class)->assemble($order, 'admin@example.test');
        $html = view('admin.reports.order-report-pdf', ['data' => $data])->render();

        $this->assertNull($data['customer']['legal_name']);
        $this->assertStringContainsString('<td class="k">Customer</td><td class="v">Ada', $html);
        $this->assertStringNotContainsString('<td class="k">Legal name</td>', $html);
        $this->assertStringNotContainsString('<td class="k">Name</td>', $html);
    }

    private function attemptFor(User $user, Order $order): PaymentAttempt
    {
        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'gateway_reference' => $ref = 'PAY-'.Str::lower(Str::random(12)),
            'idempotency_key' => $ref,
            'currency' => 'USD',
            'amount' => 10,
        ]);
    }

    // --- Export and closure -------------------------------------------------------

    public function test_the_data_export_carries_the_legal_identity(): void
    {
        [$user] = $this->verifiedCustomer();

        $exported = app(DataExportService::class)->build($user)['identity_verifications'][0];

        $this->assertSame('ADAEZE NGOZI OKAFOR', $exported['legal_name']);
        $this->assertSame('1990-04-17', $exported['date_of_birth']);
        $this->assertSame('PASSPORT', $exported['id_type']);
        $this->assertSame('NG', $exported['id_issuing_country']);
        $this->assertSame('2031-03-01', $exported['id_expires_on']);
    }

    public function test_closure_moves_the_legal_identity_into_the_drawer(): void
    {
        [$user, $verification] = $this->verifiedCustomer();
        $user->update(['closure_requested_at' => now()->subDays(31)]);
        Order::factory()->create(['user_id' => $user->id, 'order_status' => 'completed']);
        // A later failed attempt must not hide the pass that proved who they are.
        KycVerification::factory()->rejected()->create(['user_id' => $user->id, 'created_at' => now()->addMinute()]);

        $this->artisan('retention:close-accounts')->assertExitCode(0);

        $drawer = ClosedAccountIdentity::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('ADAEZE NGOZI OKAFOR', $drawer->legal_name);
        $this->assertSame('1990-04-17', $drawer->date_of_birth);
        $this->assertSame($verification->reference, $drawer->kyc_reference);
        $this->assertNotSame('ADAEZE NGOZI OKAFOR', DB::table('closed_account_identities')->where('user_id', $user->id)->value('legal_name'));

        $this->assertFalse($verification->fresh()->hasLegalIdentity(), 'the verification rows no longer hold it');
        $this->assertSame('approved', $verification->fresh()->status);
    }

    public function test_closure_without_money_moved_keeps_the_legal_identity_nowhere(): void
    {
        [$user, $verification] = $this->verifiedCustomer();
        $user->update(['closure_requested_at' => now()->subDays(31)]);

        $this->artisan('retention:close-accounts')->assertExitCode(0);

        $this->assertDatabaseMissing('closed_account_identities', ['user_id' => $user->id]);
        $this->assertFalse($verification->fresh()->hasLegalIdentity());
        $this->assertNull($verification->fresh()->id_type);
    }
}
