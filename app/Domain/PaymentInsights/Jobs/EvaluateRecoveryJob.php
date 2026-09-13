<?php

namespace App\Domain\PaymentInsights\Jobs;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Transactions\PaymentRecoveryMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Domain\PaymentInsights\Services\RecoveryEligibilityService;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
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

    /** How long a recovery link stays usable. Must outlive the reminder. */
    public const LINK_LIFETIME_HOURS = 48;

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
            'payment_outcome_id' => $outcome->id,
            'user_id' => $outcome->user_id,
            'customer_email' => $session->customer_email,
            // Snapshotted so the email copy and later analytics agree even if
            // the outcome is reclassified afterwards.
            'failure_reason_code' => $outcome->failure_reason_code,
            'token' => Str::random(40),
            'status' => RecoveryStatus::Pending,
            // 48h, not 24h: the reminder lands around the 20-hour mark and a
            // link that dies before its own reminder arrives is a dead end.
            'expires_at' => now()->addHours(self::LINK_LIFETIME_HOURS),
        ]);

        /** @var User $user */
        $user = User::findOrFail($outcome->user_id);

        // The email is redirected to the checkout email when it differs from
        // the account email (e.g. an order placed with an alternate address).
        $copy = RecoveryCopy::for($recovery->failure_reason_code, $recovery->productLine());
        $mailable = new PaymentRecoveryMail($recovery);

        $communications->send(
            recipient: $user,
            category: CommunicationCategory::PaymentRecovery,
            // EmailChannel uses `title` as the subject line, so the subject
            // must be passed here; a Mailable envelope subject is ignored on
            // this path. The headline still opens the email body.
            title: $copy['subject'],
            message: $copy['guidance'],
            mailable: $mailable,
            correlationId: 'payment_recovery_'.$recovery->id,
            overrideEmail: strtolower($session->customer_email) !== strtolower((string) $user->email)
                ? $session->customer_email
                : null,
            metadata: [
                'payment_session_id' => $session->id,
                'recovery_id' => $recovery->id,
                'payment_outcome_id' => $outcome->id,
                'failure_reason_code' => $outcome->failure_reason_code,
                'failure_reason' => $outcome->metadata['raw_reason'] ?? null,
                // Optional mail: the API provider sets List-Unsubscribe from this.
                'list_unsubscribe' => $mailable->unsubscribeUrl(),
            ]
        );
    }
}
