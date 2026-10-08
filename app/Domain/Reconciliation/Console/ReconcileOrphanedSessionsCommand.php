<?php

namespace App\Domain\Reconciliation\Console;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Reconciliation\Models\ReconciliationReport;
use App\Models\PaymentSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReconcileOrphanedSessionsCommand extends Command
{
    protected $signature = 'reconcile:orphaned-sessions';

    protected $description = 'Detect and fail abandoned payment sessions';

    public function handle(PaymentSessionService $paymentSessionService)
    {
        $this->info('Starting orphaned sessions reconciliation...');

        $report = ReconciliationReport::create([
            'type' => 'orphaned_sessions',
            'status' => 'processing',
            'started_at' => now(),
        ]);

        // Only sessions that never reached a gateway. A session awaiting the
        // customer's action (a 3-D Secure page, a bank's app) has a charge
        // the gateway may still complete; ending it here by the clock, with
        // no gateway check, told the customer it failed, sent them to pay
        // again, and let the first payment land afterwards: two charges.
        // Those sessions belong to the expiry sweeper, which asks the gateway
        // first and honours the window the customer was promised.
        $threshold = Carbon::now()->subMinutes(10);
        $orphanedSessions = PaymentSession::where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->get();

        $anomalies = [];

        foreach ($orphanedSessions as $session) {
            $anomalies[] = [
                'session_id' => $session->id,
                'order_id' => $session->order_id,
                'status' => $session->status,
                'created_at' => $session->created_at->toDateTimeString(),
            ];

            // Auto-fail the abandoned session via the state machine
            $paymentSessionService->failSession($session, 'orphaned_timeout');

        }

        $report->update([
            'status' => count($anomalies) > 0 ? 'anomalies_found' : 'clean',
            'anomalies_found' => count($anomalies) > 0 ? $anomalies : null,
            'completed_at' => now(),
        ]);

        $this->info('Reconciliation finished. '.count($anomalies).' orphaned sessions auto-failed.');
    }
}
