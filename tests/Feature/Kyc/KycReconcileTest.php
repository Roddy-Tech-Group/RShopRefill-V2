<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Providers\SmileKycProvider;
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

    private function waitingAttempt(string $status = 'pending', int $minutesAgo = 60): KycVerification
    {
        $user = User::factory()->create(['kyc_status' => 'pending']);

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
     * Past the window Smile will answer for, the attempt needs a person.
     */
    public function test_an_attempt_stuck_past_the_window_is_reported_not_polled(): void
    {
        $attempt = $this->waitingAttempt(minutesAgo: 60 * 24 * 9);
        $this->partialMock(SmileKycProvider::class, fn ($mock) => $mock->shouldReceive('queryJobStatus')->never());

        Artisan::call('kyc:reconcile', ['--max-age-days' => 7]);

        $this->assertSame('pending', $attempt->fresh()->status);

        $alert = collect($this->alerts)->firstWhere('key', 'kyc:reconcile:stuck');
        $this->assertNotNull($alert);
        $this->assertStringContainsString('1 identity verification(s) have waited over 7 days', $alert['message']);
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
