<?php

namespace App\Domain\PaymentInsights\Jobs;

use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Enums\ProductLine;
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

        // Extract reason from metadata if not explicitly passed
        $reasonText = $this->reason ?? ($session->metadata['failure_reason'] ?? 'unknown');

        $classification = $classifier->classify($session->provider ?? 'unknown', $reasonText, $session->status);

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
                'consecutive_failure_count' => $consecutiveFailures,
                'session_duration_seconds' => $duration,
                'device_uuid' => $session->device_uuid,
                'customer_ip' => $session->customer_ip,
                'correlation_id' => $session->correlation_id,
                'metadata' => [
                    'raw_reason' => $reasonText,
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

        // A confirmed payment moots any still-pending recovery invitations for
        // this customer — cancel them so no stale "complete your payment"
        // email journey stays open.
        if ($this->outcomeType === OutcomeType::Confirmed && $session->customer_email) {
            TransactionRecovery::where('status', 'pending')
                ->where(function ($query) use ($session, $outcome) {
                    $query->where('customer_email', $session->customer_email);
                    if ($outcome->user_id) {
                        $query->orWhere('user_id', $outcome->user_id);
                    }
                })
                ->update(['status' => 'cancelled']);
        }
    }
}
