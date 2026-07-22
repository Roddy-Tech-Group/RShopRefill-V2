<?php

namespace App\Domain\PaymentInsights\Listeners;

use App\Domain\Payment\Events\PaymentSessionConfirmed;
use App\Domain\Payment\Events\PaymentSessionExpired;
use App\Domain\Payment\Events\PaymentSessionFailed;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Jobs\RecordPaymentOutcomeJob;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Context;

class RecordPaymentOutcomeListener
{
    public function handle(PaymentSessionFailed|PaymentSessionExpired|PaymentSessionConfirmed $event): void
    {
        // Kill switch for the whole insights pipeline (outcomes, backoff data,
        // recovery, analytics). Payments themselves are unaffected.
        if (FeatureFlag::off('payment_insights')) {
            return;
        }

        $outcomeType = match (true) {
            $event instanceof PaymentSessionFailed => $this->resolveFailedType($event),
            $event instanceof PaymentSessionExpired => OutcomeType::Expired,
            $event instanceof PaymentSessionConfirmed => OutcomeType::Confirmed,
        };

        $reason = $event instanceof PaymentSessionFailed ? $event->reason : null;

        RecordPaymentOutcomeJob::dispatch(
            $event->session->id,
            $outcomeType,
            $reason,
            Context::get('correlation_id')
        )->onQueue('intelligence');
    }

    private function resolveFailedType(PaymentSessionFailed $event): OutcomeType
    {
        $reason = strtolower($event->reason ?? '');

        // Enumerated client codes for a customer walking away.
        if (in_array($reason, ['popup_closed', 'page_abandoned'], true)) {
            return OutcomeType::Cancelled;
        }

        if (str_contains($reason, 'user cancelled') || str_contains($reason, 'closed modal') || str_contains($reason, 'abandoned')) {
            return OutcomeType::Cancelled;
        }

        return OutcomeType::Failed;
    }
}
