<?php

namespace App\Domain\Wallet\Jobs;

use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Models\WalletFunding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcilePendingFundingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('payments');
    }

    /**
     * Execute the job.
     */
    public function handle(WalletFundingService $fundingService): void
    {
        Log::info('Starting automatic wallet funding reconciliation worker...');

        // Query pending records older than 2 hours, but created in the last 7 days
        $pendingFundings = WalletFunding::where('status', FundingStatus::Pending)
            ->where('created_at', '<', now()->subHours(2))
            ->where('created_at', '>', now()->subDays(7))
            ->get();

        Log::info("Found {$pendingFundings->count()} pending fundings awaiting reconciliation.");

        foreach ($pendingFundings as $funding) {
            try {
                Log::info("Reconciling funding ref: {$funding->reference}...");

                // Trigger verification/processing
                $fundingService->processSuccessfulFunding($funding->reference, 'RECONCILED-'.uniqid(), [
                    'source' => 'reconciliation_worker',
                    'reconciled_at' => now()->toIso8601String(),
                ]);

                Log::info("Successfully reconciled pending funding: {$funding->reference}");
            } catch (\Throwable $e) {
                // If it fails (e.g. gateway transaction not found/successful), it throws exception, which we log
                Log::info("Funding ref {$funding->reference} was not resolved by gateway: ".$e->getMessage());
            }
        }

        Log::info('Automatic wallet funding reconciliation worker finished.');
    }
}
