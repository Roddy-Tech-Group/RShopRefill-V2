<?php

namespace App\Domain\PaymentInsights\Jobs;

use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\TransactionRecovery;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;

class RecordPaymentOutcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $deleteWhenMissingModels = true;

    public function __construct(
        public readonly string $paymentSessionId,
        public readonly OutcomeType $outcomeType,
        public readonly ?string $reason = null,
        public readonly ?string $correlationId = null,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(FailureReasonClassifier $classifier): void
    {
        if ($this->correlationId) {
            Context::add('correlation_id', $this->correlationId);
        }

        $session = PaymentSession::with('paymentAttempt.order')->find($this->paymentSessionId);

        if (! $session) {
            return;
        }

        // Compute session duration
        $duration = (int) abs($session->created_at->diffInSeconds($session->updated_at ?? now()));

        // Product line comes from WHAT is being paid for (the attempt's target),
        // not the session_type: a wallet deposit paid by card has
        // session_type 'card' but is still wallet_funding.
        $attempt = $session->paymentAttempt;
        $productLine = $attempt?->order_id ? ProductLine::Checkout : ProductLine::WalletFunding;

        // The diagnostic evidence, best first: the structured gateway verdict
        // captured at failure time, then whatever reason text reached us.
        // Expired and cancelled sessions get honest default codes instead of
        // dissolving into the unknown bucket.
        $failureDetails = $session->metadata['failure_details'] ?? null;
        $reasonText = $this->reason
            ?? ($session->metadata['failure_reason'] ?? null)
            ?? match ($this->outcomeType) {
                OutcomeType::Expired => 'session_expired',
                OutcomeType::Cancelled => 'user cancelled',
                default => 'unknown',
            };

        // The status the customer actually reached, not the terminal one. By
        // the time this job runs the session has already been driven to
        // failed/expired/cancelled, and failSession routes through 'processing'
        // on the way, so reading $session->status filed every single outcome as
        // a processing-stage failure. Falls back to the live status for older
        // sessions recorded before this was captured.
        $statusAtFailure = $session->metadata['status_at_failure'] ?? $session->status;

        $classification = $classifier->classify(
            $session->provider ?? 'unknown',
            $reasonText,
            $statusAtFailure,
            is_array($failureDetails) ? $failureDetails : null,
        );

        // Compute consecutive failures for this user on this product line
        $consecutiveFailures = 1;
        if ($session->customer_email) {
            $user = User::where('email', $session->customer_email)->first();
            if ($user) {
                // Determine failures since last success for this product line
                $lastSuccess = PaymentOutcome::where('user_id', $user->id)
                    ->where('product_line', $productLine->value)
                    ->where('outcome', OutcomeType::Confirmed->value)
                    ->orderByDesc('created_at')
                    ->first();

                $query = PaymentOutcome::where('user_id', $user->id)
                    ->where('product_line', $productLine->value)
                    ->whereIn('outcome', [OutcomeType::Failed->value, OutcomeType::Expired->value, OutcomeType::Cancelled->value]);

                if ($lastSuccess) {
                    $query->where('created_at', '>', $lastSuccess->created_at);
                }

                $consecutiveFailures = $query->count() + 1;
            }
        }

        $outcome = PaymentOutcome::updateOrCreate(
            [
                'payment_session_id' => $session->id,
                'outcome' => $this->outcomeType->value,
            ],
            [
                'user_id' => $attempt?->user_id ?? $attempt?->order?->user_id ?? $session->walletFunding?->user_id,
                'product_line' => $productLine->value,
                'provider' => $session->provider,
                'payment_method' => $session->payment_payload['payment_method'] ?? null,
                'failure_stage' => $classification['stage']->value,
                'failure_category' => $classification['category']->value,
                'failure_reason_code' => $classification['code'],
                'provider_code' => $classification['provider_code'],
                'retryable' => $classification['retryable'],
                'consecutive_failure_count' => $consecutiveFailures,
                'session_duration_seconds' => $duration,
                'device_uuid' => $session->device_uuid,
                'customer_ip' => $session->customer_ip,
                'correlation_id' => $session->correlation_id,
                'metadata' => [
                    'raw_reason' => $reasonText,
                    'failure_details' => $failureDetails,
                    'provider_payload' => $session->metadata['failure_payload'] ?? null,
                ],
            ]
        );

        // Failed/cancelled/expired outcomes are evaluated for transaction
        // recovery after a 20-minute grace window (customers often retry on
        // their own; the eligibility service also cancels out anyone who did).
        if (in_array($this->outcomeType, [OutcomeType::Failed, OutcomeType::Cancelled, OutcomeType::Expired])) {
            EvaluateRecoveryJob::dispatch($outcome->id, $this->correlationId)
                ->onQueue('intelligence')
                ->delay(now()->addMinutes(20));
        }

        // A confirmed payment closes any open recovery for this customer, but
        // HOW it closes is the difference between measuring revenue and
        // measuring clicks. A recovery is only "recovered" when the payment
        // that confirmed is the one it was chasing; anything else is closed
        // as superseded so it cannot inflate the recovery numbers.
        if ($this->outcomeType === OutcomeType::Confirmed && $session->customer_email) {
            $this->closeOpenRecoveries($session, $outcome);
        }
    }

    /**
     * Resolve every open recovery for this customer against the payment that
     * just confirmed.
     */
    private function closeOpenRecoveries(PaymentSession $session, PaymentOutcome $outcome): void
    {
        $openRecoveries = TransactionRecovery::query()
            ->whereIn('status', RecoveryStatus::openValues())
            ->where(function ($query) use ($session, $outcome) {
                $query->where('customer_email', $session->customer_email);
                if ($outcome->user_id) {
                    $query->orWhere('user_id', $outcome->user_id);
                }
            })
            ->with('paymentSession')
            ->get();

        foreach ($openRecoveries as $recovery) {
            $attributable = $this->isAttributable($recovery, $session, $outcome);

            $recovery->update([
                'status' => $attributable ? RecoveryStatus::Recovered : RecoveryStatus::Cancelled,
                'recovered_at' => $attributable ? now() : null,
                'recovered_payment_session_id' => $attributable ? $session->id : null,
            ]);
        }
    }

    /**
     * Whether this confirmed payment is the one the recovery was chasing.
     *
     * Checkout: the same order paid. Wallet funding: the same customer topping
     * up again while the recovery was live. Anything else (a different order,
     * a payment that predates the email) is real revenue but not recovered
     * revenue, and claiming it would make the recovery ROI a flattering lie.
     */
    private function isAttributable(TransactionRecovery $recovery, PaymentSession $session, PaymentOutcome $outcome): bool
    {
        // The confirming payment must come after the recovery was created,
        // otherwise the customer had already paid by another route.
        if ($session->created_at && $recovery->created_at && $session->created_at->lt($recovery->created_at)) {
            return false;
        }

        $recoveredOrderId = $recovery->paymentSession?->paymentAttempt?->order_id;
        $confirmedOrderId = $session->paymentAttempt?->order_id;

        if ($recoveredOrderId && $confirmedOrderId) {
            return $recoveredOrderId === $confirmedOrderId;
        }

        // Wallet funding has no order to match on: same customer, still-open
        // recovery, payment after the email. That is the whole signal there is.
        return $recoveredOrderId === null && $confirmedOrderId === null;
    }
}
