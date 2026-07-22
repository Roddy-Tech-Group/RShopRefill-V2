<?php

namespace App\Domain\PaymentInsights\Providers;

use App\Domain\Payment\Events\PaymentSessionConfirmed;
use App\Domain\Payment\Events\PaymentSessionExpired;
use App\Domain\Payment\Events\PaymentSessionFailed;
use App\Domain\PaymentInsights\Listeners\RecordPaymentOutcomeListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PaymentInsightsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every terminal session state funnels through the same listener; it
        // records the outcome and, for failures, schedules the delayed
        // recovery evaluation (EvaluateRecoveryJob) — recovery email dispatch
        // is owned by that job, not by a listener, so eligibility (cooldowns,
        // retries, opt-outs) is always consulted first.
        Event::listen(PaymentSessionFailed::class, RecordPaymentOutcomeListener::class);
        Event::listen(PaymentSessionExpired::class, RecordPaymentOutcomeListener::class);
        Event::listen(PaymentSessionConfirmed::class, RecordPaymentOutcomeListener::class);
    }
}
