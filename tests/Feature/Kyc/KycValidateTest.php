<?php

namespace Tests\Feature\Kyc;

use App\Models\KycVerification;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The pre-flight before identity verification is switched on. It reads
 * everything the flow depends on and refuses (non-zero) when any of it would
 * send customers into a flow that cannot finish, or bill us for checks that
 * would be recorded wrongly.
 */
class KycValidateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Production-shaped URLs so route() yields the real callback: Smile
        // posts a signed verdict, and an http callback is refused.
        URL::forceRootUrl('https://rshoprefill.com');
        URL::forceScheme('https');

        config([
            'services.smileid.partner_id' => 'partner-1',
            'services.smileid.api_key' => 'key-1',
            'services.smileid.server' => 1,
            'services.smileid.approved_result_codes' => ['doc_verification' => ['0810'], 'biometric_kyc' => ['1020']],
            'services.smileid.biometric_countries' => [],
        ]);
        config(['services.smileid.callback_url' => route('api.webhooks.smileid')]);
        Cache::flush();
    }

    public function test_a_healthy_configuration_passes(): void
    {
        $this->assertSame(0, Artisan::call('kyc:validate'));
        $this->assertStringContainsString('Nothing here blocks switching verification on', Artisan::output());
    }

    public function test_missing_credentials_fail_the_pre_flight(): void
    {
        config(['services.smileid.api_key' => '']);

        $this->assertSame(1, Artisan::call('kyc:validate'));
        $this->assertStringContainsString('SMILE_API_KEY is set', Artisan::output());
    }

    /**
     * Smile posts its verdict to the configured URL. Pointed anywhere but the
     * route that handles it, every attempt waits for a callback that lands
     * nowhere.
     */
    public function test_a_callback_pointing_somewhere_else_fails(): void
    {
        config(['services.smileid.callback_url' => 'https://example.test/hooks/smile']);

        $this->assertSame(1, Artisan::call('kyc:validate'));
        $this->assertStringContainsString('Callback points at the route that handles it', Artisan::output());
    }

    public function test_a_non_https_callback_fails(): void
    {
        config(['services.smileid.callback_url' => 'http://rshoprefill.com/api/webhooks/smileid']);

        $this->assertSame(1, Artisan::call('kyc:validate'));
        $this->assertStringContainsString('Callback is https', Artisan::output());
    }

    /**
     * Each Smile product reports success with its own code. With the list
     * empty every genuine pass is recorded as a rejection, which is exactly
     * what happened before 2026-09-15.
     */
    public function test_missing_pass_codes_fail(): void
    {
        config(['services.smileid.approved_result_codes' => ['doc_verification' => []]]);

        $this->assertSame(1, Artisan::call('kyc:validate'));
        $this->assertStringContainsString('Every document pass would be recorded as a rejection', Artisan::output());
    }

    public function test_biometric_routing_without_pass_codes_fails(): void
    {
        config([
            'services.smileid.biometric_countries' => ['NG'],
            'services.smileid.approved_result_codes' => ['doc_verification' => ['0810']],
        ]);

        $this->assertSame(1, Artisan::call('kyc:validate'));
        $this->assertStringContainsString('Biometric KYC has pass codes', Artisan::output());
    }

    /**
     * The go-live order: a cap that refuses a payment and says "verify to
     * raise your limit" is a dead end while verification is switched off.
     */
    public function test_enforcing_spending_limits_before_verification_is_on_fails(): void
    {
        SiteSetting::put('features.spending_limits_enforce_enabled', 'on', 'features');
        SiteSetting::put('features.kyc_verification_enabled', 'off', 'features');
        Cache::flush();

        $this->assertSame(1, Artisan::call('kyc:validate'));
        $this->assertStringContainsString('Spending limits are not enforced ahead of verification', Artisan::output());
    }

    /**
     * An attempt minted a minute ago is in flight, not stuck. It is shown and
     * does not block, or the pre-flight could never pass on a live system.
     */
    public function test_it_reports_verifications_still_waiting_on_a_verdict(): void
    {
        KycVerification::factory()->create(['status' => 'pending']);

        $this->assertSame(0, Artisan::call('kyc:validate'));

        $this->assertStringContainsString('1 verification(s) waiting on a verdict', Artisan::output());
    }

    /**
     * An abandoned attempt is already closed, however old it is.
     */
    public function test_an_abandoned_attempt_does_not_fail_the_pre_flight(): void
    {
        $attempt = KycVerification::factory()->create(['status' => 'abandoned']);
        $attempt->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->assertSame(0, Artisan::call('kyc:validate'));

        $this->assertStringContainsString('1 attempt(s) closed without a verdict', Artisan::output());
    }

    /**
     * kyc:reconcile closes a pending attempt once it passes the polling
     * window. One still pending after that means the reconciler is not doing
     * its job.
     */
    public function test_a_pending_attempt_past_the_window_fails(): void
    {
        $attempt = KycVerification::factory()->create(['status' => 'pending']);
        $attempt->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->assertSame(1, Artisan::call('kyc:validate'));

        $this->assertStringContainsString('1 verification(s) pending over 7 day(s)', Artisan::output());
    }

    /**
     * Accounts an admin set verified with no provider pass behind them are
     * worth knowing about before enforcement starts.
     */
    public function test_it_counts_verified_accounts_without_a_provider_pass(): void
    {
        $handSet = User::factory()->create(['kyc_status' => 'verified']);
        $properly = User::factory()->create(['kyc_status' => 'verified']);
        KycVerification::factory()->approved()->create(['user_id' => $properly->id]);

        Artisan::call('kyc:validate');
        $output = Artisan::output();

        $this->assertStringContainsString('2 account(s) read verified', $output);
        $this->assertStringContainsString('1 verified account(s) have no provider pass', $output);
        $this->assertNotNull($handSet->fresh());
    }

    public function test_the_reconciler_is_scheduled(): void
    {
        Artisan::call('kyc:validate');

        $this->assertStringContainsString('kyc:reconcile is scheduled', Artisan::output());
        $this->assertStringNotContainsString('A lost callback would leave the customer in review forever', Artisan::output());
    }

    public function test_json_mode_is_machine_readable(): void
    {
        config(['services.smileid.api_key' => '']);

        $this->assertSame(1, Artisan::call('kyc:validate', ['--json' => true]));

        $report = json_decode(Artisan::output(), true);
        $this->assertSame('failed', $report['status']);
        $this->assertContains('SMILE_API_KEY is set', $report['failures']);
    }
}
