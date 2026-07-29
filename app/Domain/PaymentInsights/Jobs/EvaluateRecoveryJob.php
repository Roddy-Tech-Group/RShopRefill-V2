<?php

namespace App\Domain\PaymentInsights\Jobs;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Transactions\PaymentRecoveryMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\PaymentInsights\Services\RecoveryEligibilityService;
use App\Models\PaymentOutcome;
use App\Models\TransactionRecovery;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Evaluates a failed payment outcome for transaction recovery, ~20 minutes
 * after the failure (dispatched with a delay by RecordPaymentOutcomeJob).
 * The delay gives the customer room to retry on their own; the eligibility
 * service then decides whether a recovery email is warranted, and every skip
 * reason is recorded on the outcome for analytics.
 */
class EvaluateRecoveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $deleteWhenMissingModels = true;

    public function __construct(
        public readonly int $paymentOutcomeId,
        public readonly ?string $correlationId = null,
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('intelligence');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(RecoveryEligibilityService $eligibility, CommunicationManager $communications): void
    {
        if ($this->correlationId) {
            Context::add('correlation_id', $this->correlationId);
        }

        $outcome = PaymentOutcome::with('paymentSession')->find($this->paymentOutcomeId);

        if (! $outcome || ! $outcome->paymentSession) {
            return;
        }

        // Idempotency: one recovery per payment session, ever. A retried job
        // or a duplicate outcome event must not send a second email.
        if (TransactionRecovery::where('payment_session_id', $outcome->payment_session_id)->exists()) {
            return;
        }

        $verdict = $eligibility->evaluate($outcome);

        $outcome->update([
            'metadata' => array_merge($outcome->metadata ?? [], [
                'recovery_evaluation' => [
                    'evaluated_at' => now()->toIso8601String(),
                    'eligible' => $verdict['eligible'],
                    'reason' => $verdict['reason']?->value,
                ],
            ]),
        ]);

        if (! $verdict['eligible']) {
            Log::info('Transaction recovery skipped.', [
                'payment_outcome_id' => $outcome->id,
                'payment_session_id' => $outcome->payment_session_id,
                'reason' => $verdict['reason']?->value,
                'correlation_id' => $this->correlationId,
            ]);

            return;
        }

        $session = $outcome->paymentSession;

        $recovery = TransactionRecovery::create([
            'payment_session_id' => $session->id,
            'user_id' => $outcome->user_id,
            'customer_email' => $session->customer_email,
            'token' => Str::random(40),
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);

        /** @var User $user */
        $user = User::findOrFail($outcome->user_id);

        // The email is redirected to the checkout email when it differs from
        // the account email (e.g. an order placed with an alternate address).
        $communications->send(
            recipient: $user,
            category: CommunicationCategory::PaymentRecovery,
            title: 'Complete your Payment',
            message: 'Your recent payment attempt was not completed. You can securely resume it from your email.',
            mailable: new PaymentRecoveryMail($recovery),
            correlationId: 'payment_recovery_'.$recovery->id,
            overrideEmail: strtolower($session->customer_email) !== strtolower((string) $user->email)
                ? $session->customer_email
                : null,
            metadata: [
                'payment_session_id' => $session->id,
                'recovery_id' => $recovery->id,
                'payment_outcome_id' => $outcome->id,
                'failure_reason' => $outcome->metadata['raw_reason'] ?? null,
            ]
        );
    }
}
