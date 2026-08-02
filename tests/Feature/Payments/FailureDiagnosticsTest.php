<?php

namespace Tests\Feature\Payments;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\IneligibilityReason;
use App\Domain\PaymentInsights\Services\PaymentInsightsMetrics;
use App\Domain\PaymentInsights\Services\RecoveryEligibilityService;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dual-track failure pipeline end to end: the customer keeps the polite
 * sentence, the outcome row keeps the gateway's verdict, and history can be
 * re-graded from stored evidence.
 */
class FailureDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user): PaymentSession
    {
        $order = Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'diag_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'pending',
        ]);

        return app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
    }

    public function test_gateway_verdict_reaches_the_outcome_while_customer_sees_polite_copy(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        // What PaymentSessionController does on a failed charge: customer copy
        // as the reason, the provider result (with its failure block) as payload.
        app(PaymentSessionService::class)->failSession($session, 'Card charge could not be processed', [
            'status' => 'failed',
            'message' => 'Card charge could not be processed',
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null],
        ]);

        $session->refresh();

        // Customer track: polite copy only, no gateway internals.
        $this->assertSame('Card charge could not be processed', $session->payment_payload['failure_reason']);

        // Operator track: the verdict, graded and verbatim.
        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('insufficient_funds', $outcome->failure_reason_code);
        $this->assertSame('customer', $outcome->failure_category->value);
        $this->assertSame('Insufficient Funds', $outcome->provider_code);
        $this->assertTrue($outcome->retryable);
        $this->assertSame('Insufficient Funds', $outcome->metadata['failure_details']['provider_message']);
    }

    public function test_non_retryable_verdicts_are_flagged_for_recovery_logic(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        app(PaymentSessionService::class)->failSession($session, 'Transaction could not be completed', [
            'failure' => ['provider_code' => 'Stolen card, pick up', 'provider_message' => 'Stolen card, pick up', 'http_status' => null],
        ]);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('card_reported_lost', $outcome->failure_reason_code);
        $this->assertFalse($outcome->retryable);
    }

    public function test_expired_sessions_grade_as_abandonment_not_unknown(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        app(PaymentSessionService::class)->expireSession($session);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)
            ->where('outcome', 'expired')
            ->firstOrFail();

        $this->assertSame('session_expired', $outcome->failure_reason_code);
        $this->assertSame('customer', $outcome->failure_category->value);
    }

    public function test_insights_metrics_expose_labeled_reasons_and_recent_failures(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        app(PaymentSessionService::class)->failSession($session, 'Transaction could not be completed', [
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null],
        ]);

        $outcomes = app(PaymentInsightsMetrics::class)->outcomes();

        $reason = collect($outcomes['topReasons'])->firstWhere('failure_reason_code', 'insufficient_funds');
        $this->assertNotNull($reason);
        $this->assertSame('Insufficient funds', $reason->label);
        $this->assertTrue($reason->retryable);

        $recent = collect($outcomes['recentFailures'])->first();
        $this->assertSame('insufficient_funds', $recent['code']);
        $this->assertSame('Insufficient Funds', $recent['provider_message']);
    }

    public function test_reclassify_regrades_history_from_stored_evidence(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        // A row as the OLD classifier would have written it: unknown_error,
        // no provider_code, but the raw evidence preserved in metadata.
        $outcome = PaymentOutcome::create([
            'payment_session_id' => $session->id,
            'user_id' => $user->id,
            'outcome' => 'failed',
            'product_line' => 'checkout',
            'provider' => 'flutterwave',
            'failure_stage' => 'confirmation',
            'failure_category' => 'system',
            'failure_reason_code' => 'unknown_error',
            'consecutive_failure_count' => 1,
            'metadata' => ['raw_reason' => 'Insufficient funds - do not honor'],
        ]);

        $this->artisan('insights:reclassify', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame('unknown_error', $outcome->fresh()->failure_reason_code);

        $this->artisan('insights:reclassify')->assertExitCode(0);

        $outcome->refresh();
        $this->assertSame('insufficient_funds', $outcome->failure_reason_code);
        $this->assertSame('customer', $outcome->failure_category->value);
        $this->assertTrue($outcome->retryable);
        // Stage is historical truth and must survive re-grading untouched.
        $this->assertSame('confirmation', $outcome->failure_stage->value);
    }

    public function test_reclassify_salvages_verdicts_from_stored_webhook_payloads(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        // Pre-upgrade row: raw_reason is only a summary sentence, but the
        // webhook payload was archived and carries the issuer's verdict.
        $outcome = PaymentOutcome::create([
            'payment_session_id' => $session->id,
            'user_id' => $user->id,
            'outcome' => 'failed',
            'product_line' => 'wallet_funding',
            'provider' => 'flutterwave',
            'failure_stage' => 'confirmation',
            'failure_category' => 'system',
            'failure_reason_code' => 'unknown_error',
            'consecutive_failure_count' => 1,
            'metadata' => [
                'raw_reason' => 'Gateway verification failed or payload tampered.',
                'provider_payload' => [
                    'event' => 'charge.completed',
                    'data' => ['status' => 'failed', 'processor_response' => 'Insufficient Funds'],
                ],
            ],
        ]);

        $this->artisan('insights:reclassify')->assertExitCode(0);

        $outcome->refresh();
        $this->assertSame('insufficient_funds', $outcome->failure_reason_code);
        $this->assertSame('Insufficient Funds', $outcome->provider_code);
        $this->assertSame('customer', $outcome->failure_category->value);
    }

    public function test_non_retryable_failures_are_excluded_from_recovery(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        app(PaymentSessionService::class)->failSession($session, 'Transaction could not be completed', [
            'failure' => ['provider_code' => 'Stolen card, pick up', 'provider_message' => 'Stolen card, pick up', 'http_status' => null],
        ]);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertFalse($outcome->retryable);

        $verdict = app(RecoveryEligibilityService::class)->evaluate($outcome);

        $this->assertFalse($verdict['eligible']);
        $this->assertSame(IneligibilityReason::NonRetryableFailure, $verdict['reason']);
    }

    public function test_retryable_failures_remain_recovery_eligible(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        app(PaymentSessionService::class)->failSession($session, 'Transaction could not be completed', [
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null],
        ]);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertTrue($outcome->retryable);

        $verdict = app(RecoveryEligibilityService::class)->evaluate($outcome);

        // The retryable gate must not be the blocker; any remaining
        // ineligibility must come from other rules, never this one.
        $this->assertNotSame(IneligibilityReason::NonRetryableFailure, $verdict['reason']);
    }

    public function test_reclassify_is_idempotent(): void
    {
        $user = User::factory()->create();
        $session = $this->makeSession($user);

        app(PaymentSessionService::class)->failSession($session, 'Transaction could not be completed', [
            'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null],
        ]);

        $before = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail()->toArray();

        $this->artisan('insights:reclassify')->assertExitCode(0);
        $this->artisan('insights:reclassify')->assertExitCode(0);

        $after = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail()->toArray();
        $this->assertSame($before['failure_reason_code'], $after['failure_reason_code']);
        $this->assertSame($before['provider_code'], $after['provider_code']);
    }
}
