<?php

namespace App\Domain\PaymentInsights\Jobs;

use App\Domain\PaymentInsights\Services\RefusedCardRecorder;
use App\Models\PaymentSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After a Flutterwave session confirms, ask the gateway whether other cards
 * were refused under the same reference first.
 *
 * The three testers who got money in during the July wave each ran nine to
 * fifteen cards before one worked. Their refusals were the most valuable
 * signal on the platform and the confirmed payment was the one path that
 * never looked back at them. Queued because a confirmation must never wait
 * on a second gateway round trip.
 */
class ReconcileRefusedCardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly string $sessionId)
    {
        $this->onQueue('intelligence');
    }

    public function handle(RefusedCardRecorder $recorder): void
    {
        $session = PaymentSession::with('paymentAttempt')->find($this->sessionId);

        if ($session === null) {
            return;
        }

        $declines = $recorder->reconcile($session);

        // The refusals are worth recording whatever happened next, but the
        // "paid after refused cards" alert is only true of a confirmed payment.
        if ($declines->isEmpty() || $session->status !== 'confirmed') {
            return;
        }

        $recorder->alertIfPaidAfterRefusals($session, $declines);
    }
}
