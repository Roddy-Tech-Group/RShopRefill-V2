<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycVerdictRecorder;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The lost-callback rescue. Smile resolves a verification by posting to our
 * webhook; when that post never lands the attempt sits pending forever, the
 * customer reads "in review" with nothing coming, and under step-up
 * enforcement their payment is held behind it. kyc:reconcile asks Smile
 * directly and applies the verdict through the same recorder the callback
 * uses.
 */
class KycReconcileTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.smileid.partner_id' => 'partner-1',
            'services.smileid.api_key' => 'key-1',
        ]);
        Cache::flush();

        $alerts = &$this->alerts;
        $this->app->instance(OperationalAlerter::class, new class($alerts) extends OperationalAlerter
        {
            public function __construct(private array &$sent) {}

            public function send(AlertSeverity $severity, string $key, string $title, string $message, array $context = [], ?string $runbook = null): bool
            {
                $this->sent[] = compact('severity', 'key', 'title', 'message', 'context');

                return true;
            }
        });
    }

    private function waitingAttempt(string $status = 'pending', int $minutesAgo = 60, ?User $customer = null): KycVerification
    {
        $user = $customer ?? User::factory()->create(['kyc_status' => 'pending']);

        $attempt = KycVerification::factory()->create(['user_id' => $user->id, 'status' => $status]);
        $attempt->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $attempt->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $result  null = Smile is still working on it
     */
    private function smileAnswers(?array $result, int $times = 1): void
    {
        $this->partialMock(SmileKycProvider::class, function ($mock) use ($result, $times) {
            $mock->shouldReceive('queryJobStatus')->times($times)->andReturn(
                $result === null ? ['job_complete' => false] : ['job_complete' => true, 'result' => $result],
            );
        });
    }

    public function test_a_pass_that_never_reached_the_webhook_verifies_the_customer(): void
    {
        $attempt = $this->waitingAttempt();
        $this->smileAnswers([
            'ResultCode' => '0810',
            'PartnerParams' => ['job_id' => $attempt->reference, 'user_id' => 'user_'.$attempt->user_id],
        ]);

        $this->assertSame(0, Artisan::call('kyc:reconcile'));

        $this->assertSame('approved', $attempt->fresh()->status);
        $this->assertSame('verified', $attempt->user->fresh()->kyc_status);
        $this->assertStringContainsString('1 verified', Artisan::output());
    }

    public function test_a_refusal_that_never_reached_the_webhook_is_recorded_too(): void
    {
        $attempt = $this->waitingAttempt();
        $this->smileAnswers([
            'ResultCode' => '0811',
            'ResultText' => 'Unable to validate ID',
            'PartnerParams' => ['job_id' => $attempt->reference, 'user_id' => 'user_'.$attempt->user_id],
        ]);

        Artisan::call('kyc:reconcile');

        $this->assertSame('rejected', $attempt->fresh()->status);
        $this->assertSame('rejected', $attempt->user->fresh()->kyc_status);
        $this->assertSame('Unable to validate ID', $attempt->fresh()->result_text);
    }

    /**
     * Smile usually answers within minutes, so a fresh attempt is left alone:
     * polling it would only cost a request and tell us nothing.
     */
    public function test_an_attempt_inside_the_grace_period_is_left_alone(): void
    {
        $this->waitingAttempt(minutesAgo: 2);
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        Artisan::call('kyc:reconcile', ['--minutes' => 10]);

        $this->assertStringContainsString('No verifications are waiting', Artisan::output());
    }

    /**
     * An abandoned attempt was replaced by a newer session. Re-reading its
     * old verdict could walk the customer back from a later decision; a late
     * callback still lands on it through the webhook.
     */
    public function test_an_abandoned_attempt_is_never_polled(): void
    {
        $this->waitingAttempt(status: 'abandoned');
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        Artisan::call('kyc:reconcile');
    }

    public function test_a_job_smile_is_still_working_on_changes_nothing(): void
    {
        $attempt = $this->waitingAttempt();
        $this->smileAnswers(null);

        Artisan::call('kyc:reconcile');

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertSame('pending', $attempt->user->fresh()->kyc_status);
        $this->assertStringContainsString('1 still processing', Artisan::output());
    }

    /**
     * One unreachable attempt must not abort the sweep, and must not decide
     * anything about the customer.
     */
    public function test_a_provider_error_is_survived_and_decides_nothing(): void
    {
        $attempt = $this->waitingAttempt();
        $this->partialMock(SmileKycProvider::class, function ($mock) {
            $mock->shouldReceive('queryJobStatus')->once()->andThrow(new \RuntimeException('connection reset'));
        });

        $this->assertSame(0, Artisan::call('kyc:reconcile'));

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertStringContainsString('1 could not be reached', Artisan::output());

        $alert = collect($this->alerts)->firstWhere('key', 'kyc:reconcile:provider-unreachable');
        $this->assertNotNull($alert, 'every poll failing is an outage, not bad luck');
        $this->assertSame(AlertSeverity::Sev2, $alert['severity']);
    }

    /**
     * Polled for the whole window and never answered, the capture was never
     * submitted. It is closed rather than reported: reporting it sent a SEV-2
     * every hour for as long as the row existed.
     */
    public function test_an_attempt_pending_past_the_window_is_closed_not_polled(): void
    {
        $attempt = $this->waitingAttempt(minutesAgo: 60 * 24 * 9);
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        $this->assertSame(0, Artisan::call('kyc:reconcile', ['--max-age-days' => 7]));

        $this->assertSame('abandoned', $attempt->fresh()->status);
        $this->assertStringContainsString('Closed 1 verification(s) pending over 7 day(s)', Artisan::output());
        $this->assertSame([], $this->alerts, 'a closed capture is routine, not an incident');
    }

    public function test_a_closed_attempt_does_not_come_back_on_the_next_run(): void
    {
        $this->waitingAttempt(minutesAgo: 60 * 24 * 9);
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        Artisan::call('kyc:reconcile');
        Artisan::call('kyc:reconcile');

        $this->assertStringContainsString('No verifications are waiting', Artisan::output());
        $this->assertSame([], $this->alerts);
    }

    /**
     * An abandoned attempt is already closed. However old, it is not stuck,
     * and it is never polled.
     */
    public function test_an_old_abandoned_attempt_is_not_stuck(): void
    {
        $attempt = $this->waitingAttempt(status: 'abandoned', minutesAgo: 60 * 24 * 30);
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        Artisan::call('kyc:reconcile');

        $this->assertSame('abandoned', $attempt->fresh()->status);
        $this->assertStringContainsString('No verifications are waiting', Artisan::output());
        $this->assertSame([], $this->alerts);
    }

    /**
     * While the account reads "in review", Verify now and the checkout
     * step-up both refuse to start a new session. Closing the only attempt
     * behind that status gives the customer their way back.
     */
    public function test_a_customer_held_in_review_by_the_closed_attempt_can_start_again(): void
    {
        $attempt = $this->waitingAttempt(minutesAgo: 60 * 24 * 9);

        Artisan::call('kyc:reconcile');

        $this->assertSame('unsubmitted', $attempt->user->fresh()->kyc_status);
        $this->assertStringContainsString('1 customer(s) can start verification again', Artisan::output());
    }

    public function test_a_customer_rejected_before_the_closed_attempt_goes_back_to_rejected(): void
    {
        $customer = User::factory()->create(['kyc_status' => 'pending']);
        KycVerification::factory()->rejected()->create(['user_id' => $customer->id, 'decided_at' => now()->subDays(12)]);
        $this->waitingAttempt(minutesAgo: 60 * 24 * 9, customer: $customer);

        Artisan::call('kyc:reconcile');

        $this->assertSame('rejected', $customer->fresh()->kyc_status);
    }

    public function test_verified_and_rejected_customers_are_never_touched(): void
    {
        $verified = User::factory()->create(['kyc_status' => 'verified']);
        $rejected = User::factory()->create(['kyc_status' => 'rejected']);
        $verifiedAttempt = $this->waitingAttempt(minutesAgo: 60 * 24 * 9, customer: $verified);
        $rejectedAttempt = $this->waitingAttempt(minutesAgo: 60 * 24 * 9, customer: $rejected);

        Artisan::call('kyc:reconcile');

        $this->assertSame('abandoned', $verifiedAttempt->fresh()->status);
        $this->assertSame('abandoned', $rejectedAttempt->fresh()->status);
        $this->assertSame('verified', $verified->fresh()->kyc_status);
        $this->assertSame('rejected', $rejected->fresh()->kyc_status);
    }

    /**
     * Another attempt still in flight is a real reason to read "in review".
     */
    public function test_a_customer_with_another_live_attempt_stays_in_review(): void
    {
        $customer = User::factory()->create(['kyc_status' => 'pending']);
        $old = $this->waitingAttempt(minutesAgo: 60 * 24 * 9, customer: $customer);
        $live = $this->waitingAttempt(minutesAgo: 2, customer: $customer);

        Artisan::call('kyc:reconcile');

        $this->assertSame('abandoned', $old->fresh()->status);
        $this->assertSame('pending', $live->fresh()->status);
        $this->assertSame('pending', $customer->fresh()->kyc_status);
    }

    /**
     * A verdict that arrives after the attempt was closed still lands on it.
     */
    public function test_a_late_verdict_still_lands_on_a_closed_attempt(): void
    {
        $attempt = $this->waitingAttempt(minutesAgo: 60 * 24 * 9);
        Artisan::call('kyc:reconcile');

        app(KycVerdictRecorder::class)->apply(KycVerdict::approved($attempt->reference, KycTier::IdentityVerified, ['ResultCode' => '0810']));

        $this->assertSame('approved', $attempt->fresh()->status);
        $this->assertSame('verified', $attempt->user->fresh()->kyc_status);
    }

    public function test_a_dry_run_sends_nothing_and_writes_nothing(): void
    {
        $expired = $this->waitingAttempt(minutesAgo: 60 * 24 * 9);
        $due = $this->waitingAttempt();
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        Artisan::call('kyc:reconcile', ['--dry-run' => true]);

        $this->assertSame('pending', $expired->fresh()->status);
        $this->assertSame('pending', $expired->user->fresh()->kyc_status);
        $this->assertSame('pending', $due->fresh()->status);
        $this->assertSame([], $this->alerts);

        $output = Artisan::output();
        $this->assertStringContainsString('would be closed as abandoned', $output);
        $this->assertStringContainsString($expired->reference, $output);
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $attempt = $this->waitingAttempt();
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        Artisan::call('kyc:reconcile', ['--dry-run' => true]);

        $this->assertSame('pending', $attempt->fresh()->status);
        $output = Artisan::output();
        $this->assertStringContainsString('Dry run', $output);
        $this->assertStringContainsString($attempt->reference, $output);
    }

    public function test_it_does_nothing_without_provider_credentials(): void
    {
        config(['services.smileid.partner_id' => '', 'services.smileid.api_key' => '']);
        $this->waitingAttempt();
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        $this->assertSame(0, Artisan::call('kyc:reconcile'));
        $this->assertStringContainsString('not configured', Artisan::output());
    }
}
