<?php

namespace Tests\Feature\Payments;

use App\Domain\Communication\Mail\Transactions\PaymentRecoveryMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Communication\Services\PreferenceManager;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Domain\PaymentInsights\Jobs\EvaluateRecoveryJob;
use App\Domain\PaymentInsights\Jobs\ExpireStaleRecoveriesJob;
use App\Domain\PaymentInsights\Jobs\RecordPaymentOutcomeJob;
use App\Domain\PaymentInsights\Jobs\SendRecoveryRemindersJob;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\PaymentInsights\Services\PaymentInsightsMetrics;
use App\Domain\PaymentInsights\Services\RecoveryEligibilityService;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\CommunicationLog;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\TransactionRecovery;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The recovery programme's intelligence layer: writing to the reason the
 * payment failed, nudging once, and only claiming revenue that arrived.
 */
class RecoveryIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user, ?Order $order = null): PaymentSession
    {
        $order ??= Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'test_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'pending',
        ]);

        return app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
    }

    /** Fail a session and run the outcome + evaluation as the workers would. */
    private function failAndEvaluate(PaymentSession $session, array $failure): TransactionRecovery
    {
        app(PaymentSessionService::class)->failSession($session, 'Transaction could not be completed', $failure);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        (new EvaluateRecoveryJob($outcome->id))->handle(
            app(RecoveryEligibilityService::class),
            app(CommunicationManager::class),
        );

        return TransactionRecovery::where('payment_session_id', $session->id)->firstOrFail();
    }

    public function test_failed_wallet_funding_also_gets_a_reason_aware_recovery_email(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => 'USD'],
            ['balance' => 0, 'locked_balance' => 0],
        );

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'fund_'.Str::random(10),
            'currency' => 'USD',
            'amount' => 50.00,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'idempotency_key' => 'fund_attempt_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 50.00,
            'payment_status' => 'pending',
        ]);

        $session = app(PaymentSessionService::class)->createForWalletFunding($funding, $attempt, []);

        // Go through the real funding failure path, handed the raw gateway
        // payload exactly as the webhook delivers it.
        app(WalletFundingService::class)->failFunding($funding, 'Gateway verification failed', [
            'data' => ['status' => 'failed', 'processor_response' => 'Insufficient Funds'],
        ]);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('wallet_funding', $outcome->product_line->value);
        // The issuer's verdict survives the funding path, not just checkout.
        $this->assertSame('insufficient_funds', $outcome->failure_reason_code);

        (new EvaluateRecoveryJob($outcome->id))->handle(
            app(RecoveryEligibilityService::class),
            app(CommunicationManager::class),
        );

        $recovery = TransactionRecovery::where('payment_session_id', $session->id)->first();
        $this->assertNotNull($recovery, 'wallet funding failure produced no recovery email');
        $this->assertSame('insufficient_funds', $recovery->failure_reason_code);

        // The email renders without an order attached, and still says what
        // went wrong and what to do instead.
        $rendered = (new PaymentRecoveryMail($recovery))->render();
        $this->assertStringContainsString('insufficient funds', strtolower($rendered));
    }

    public function test_recovery_snapshots_the_failure_reason_and_outlives_its_reminder(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        $recovery = $this->failAndEvaluate($session, [
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds'],
        ]);

        $this->assertSame('insufficient_funds', $recovery->failure_reason_code);
        $this->assertNotNull($recovery->payment_outcome_id);
        // The link must still be alive when the ~20h reminder lands.
        $this->assertTrue($recovery->expires_at->gt(now()->addHours(SendRecoveryRemindersJob::REMIND_AFTER_HOURS)));
    }

    public function test_email_copy_is_written_to_the_failure_reason(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        $recovery = $this->failAndEvaluate($session, [
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds'],
        ]);

        $rendered = (new PaymentRecoveryMail($recovery))->render();

        // The customer is told what actually happened and what helps, not a
        // generic "payment not completed".
        $this->assertStringContainsString('insufficient funds', strtolower($rendered));
        $this->assertStringContainsString('wallet', strtolower($rendered));
        // Gateway internals never reach a customer.
        $this->assertStringNotContainsString('processor_response', $rendered);
    }

    public function test_the_subject_line_reaches_the_email_channel(): void
    {
        $user = User::factory()->create();
        $recovery = $this->failAndEvaluate($this->makeSession($user), [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);

        // EmailChannel takes the subject from send()'s `title`, so a Mailable
        // envelope subject never reaches the inbox on this path. Without this
        // assertion both touches silently ship the same subject line.
        $log = CommunicationLog::where('channel', 'email')
            ->where('correlation_id', 'payment_recovery_'.$recovery->id)
            ->firstOrFail();

        $this->assertSame(RecoveryCopy::for('insufficient_funds')['subject'], $log->subject);
        $this->assertNotSame(RecoveryCopy::for('insufficient_funds')['headline'], $log->subject);
    }

    public function test_copy_differs_between_failure_families(): void
    {
        $bank = RecoveryCopy::for('insufficient_funds');
        $browser = RecoveryCopy::for('popup_blocked');
        $partner = RecoveryCopy::for('provider_timeout');

        $this->assertNotSame($bank['headline'], $browser['headline']);
        $this->assertNotSame($browser['headline'], $partner['headline']);
        // A partner outage must never read as the customer's fault.
        $this->assertStringContainsString('not your fault', strtolower($partner['guidance']));
        // Unknown codes still produce sane copy rather than an empty email.
        $this->assertNotEmpty(RecoveryCopy::for('something_new_entirely')['headline']);
    }

    public function test_reminder_goes_only_to_silent_customers_and_only_once(): void
    {
        Mail::fake();

        $silent = User::factory()->create();
        $silentRecovery = $this->failAndEvaluate($this->makeSession($silent), [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);
        // created_at is not fillable, so age the row explicitly.
        $silentRecovery->forceFill(['created_at' => now()->subHours(SendRecoveryRemindersJob::REMIND_AFTER_HOURS + 1)])->save();

        $engaged = User::factory()->create();
        $engagedRecovery = $this->failAndEvaluate($this->makeSession($engaged), [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);
        $engagedRecovery->forceFill([
            'created_at' => now()->subHours(SendRecoveryRemindersJob::REMIND_AFTER_HOURS + 1),
            'status' => RecoveryStatus::Clicked,
            'clicked_at' => now()->subHour(),
        ])->save();

        (new SendRecoveryRemindersJob)->handle(
            app(CommunicationManager::class),
            app(PreferenceManager::class),
        );

        $this->assertNotNull($silentRecovery->fresh()->reminder_sent_at);
        // Someone who already engaged is not chased again.
        $this->assertNull($engagedRecovery->fresh()->reminder_sent_at);

        // Second sweep must not double-send.
        $firstStamp = $silentRecovery->fresh()->reminder_sent_at;
        (new SendRecoveryRemindersJob)->handle(
            app(CommunicationManager::class),
            app(PreferenceManager::class),
        );
        $this->assertEquals($firstStamp, $silentRecovery->fresh()->reminder_sent_at);
    }

    public function test_reminder_is_skipped_when_a_fresher_failure_already_emailed_them(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $older = $this->failAndEvaluate($this->makeSession($user), [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);
        $older->forceFill(['created_at' => now()->subHours(SendRecoveryRemindersJob::REMIND_AFTER_HOURS + 1)])->save();

        // A second, fresher failure produced its own recovery email.
        $newer = TransactionRecovery::create([
            'payment_session_id' => $this->makeSession($user)->id,
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'token' => Str::random(40),
            'status' => RecoveryStatus::Pending,
            'expires_at' => now()->addHours(48),
        ]);

        (new SendRecoveryRemindersJob)->handle(
            app(CommunicationManager::class),
            app(PreferenceManager::class),
        );

        // The stale nudge is stood down rather than stacked on top.
        $this->assertNotNull($older->fresh()->reminder_sent_at);
        Mail::assertNothingOutgoing();
        // The fresher one is simply not due yet.
        $this->assertNull($newer->fresh()->reminder_sent_at);
    }

    public function test_reminder_respects_suppression_at_send_time(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $recovery = $this->failAndEvaluate($this->makeSession($user), [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);
        $recovery->forceFill(['created_at' => now()->subHours(SendRecoveryRemindersJob::REMIND_AFTER_HOURS + 1)])->save();

        // Suppression added AFTER the first email: consent is re-checked at
        // send time, never inherited from the first touch.
        EmailSuppression::create([
            'email' => strtolower($user->email),
            'reason' => 'bounce',
            'provider' => 'resend',
            'suppressed_at' => now(),
        ]);

        (new SendRecoveryRemindersJob)->handle(
            app(CommunicationManager::class),
            app(PreferenceManager::class),
        );

        // Stamped so it is not reconsidered every hour, but nothing was sent.
        $this->assertNotNull($recovery->fresh()->reminder_sent_at);
        Mail::assertNothingOutgoing();
    }

    public function test_payment_on_the_same_order_marks_the_recovery_recovered(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $session = $this->makeSession($user, $order);

        $recovery = $this->failAndEvaluate($session, [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);
        $this->assertSame(RecoveryStatus::Pending, $recovery->status);

        // The customer comes back and pays for the same order.
        $paying = $this->makeSession($user, $order);
        app(PaymentSessionService::class)->confirmSession($paying);

        $fresh = $recovery->fresh();
        $this->assertSame(RecoveryStatus::Recovered, $fresh->status);
        $this->assertNotNull($fresh->recovered_at);
        $this->assertSame($paying->id, $fresh->recovered_payment_session_id);
    }

    public function test_payment_on_a_different_order_supersedes_without_claiming_credit(): void
    {
        $user = User::factory()->create();
        $recovery = $this->failAndEvaluate($this->makeSession($user), [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);

        // A completely unrelated purchase: real revenue, but not recovered
        // revenue. Counting it would inflate the programme's ROI.
        $unrelated = $this->makeSession($user);
        app(PaymentSessionService::class)->confirmSession($unrelated);

        $fresh = $recovery->fresh();
        $this->assertSame(RecoveryStatus::Cancelled, $fresh->status);
        $this->assertNull($fresh->recovered_at);
    }

    public function test_recovery_metrics_count_payments_not_clicks(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $session = $this->makeSession($user, $order);

        $recovery = $this->failAndEvaluate($session, [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);

        // Click and retry only: intent, no money.
        $this->withoutVite()->get(route('recovery.show', $recovery->token))->assertOk();
        $this->post(route('recovery.retry', $recovery->token));

        $metrics = app(PaymentInsightsMetrics::class)->recovery();
        $this->assertSame(1, $metrics['clicked']);
        $this->assertSame(0, $metrics['recovered'], 'a click must never be counted as recovered revenue');
        $this->assertSame(0.0, $metrics['conversionRate']);

        // Now the payment actually lands.
        $paying = $this->makeSession($user, $order);
        app(PaymentSessionService::class)->confirmSession($paying);

        $metrics = app(PaymentInsightsMetrics::class)->recovery();
        $this->assertSame(1, $metrics['recovered']);
        $this->assertGreaterThan(0, $metrics['conversionRate']);
    }

    public function test_the_link_survives_a_retry_so_a_second_attempt_is_possible(): void
    {
        $user = User::factory()->create();
        $recovery = $this->failAndEvaluate($this->makeSession($user), [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);

        $this->post(route('recovery.retry', $recovery->token));

        // The old code marked this "recovered" and the link 404'd, stranding
        // anyone whose second attempt also failed.
        $this->withoutVite()->get(route('recovery.show', $recovery->token))->assertOk();
        $this->assertSame(RecoveryStatus::Retrying, $recovery->fresh()->status);
    }

    public function test_expired_recoveries_are_swept_from_every_open_state(): void
    {
        $user = User::factory()->create();

        foreach ([RecoveryStatus::Pending, RecoveryStatus::Clicked, RecoveryStatus::Retrying] as $status) {
            TransactionRecovery::create([
                'payment_session_id' => $this->makeSession($user)->id,
                'user_id' => $user->id,
                'customer_email' => $user->email,
                'token' => Str::random(40),
                'status' => $status,
                'expires_at' => now()->subHour(),
            ]);
        }

        (new ExpireStaleRecoveriesJob)->handle();

        $this->assertSame(3, TransactionRecovery::where('status', RecoveryStatus::Abandoned->value)->count());
    }

    public function test_confirmed_outcome_does_not_resurrect_a_resolved_recovery(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $session = $this->makeSession($user, $order);

        $recovery = $this->failAndEvaluate($session, [
            'failure' => ['provider_code' => 'Insufficient Funds'],
        ]);
        $recovery->update(['status' => RecoveryStatus::FeedbackReceived]);

        $paying = $this->makeSession($user, $order);
        app(PaymentSessionService::class)->confirmSession($paying);
        (new RecordPaymentOutcomeJob($paying->id, OutcomeType::Confirmed))->handle(app(FailureReasonClassifier::class));

        // Terminal states are terminal; the customer already told us why.
        $this->assertSame(RecoveryStatus::FeedbackReceived, $recovery->fresh()->status);
    }
}
