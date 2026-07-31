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

class RetryFailedFundingVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $fundingId
    ) {
        $this->onQueue('payments');
    }

    /**
     * Execute the job.
     */
    public function handle(WalletFundingService $fundingService): void
    {
        $funding = WalletFunding::find($this->fundingId);

        if (! $funding) {
            Log::error("WalletFunding ID {$this->fundingId} not found for manual retry verification.");

            return;
        }

        Log::info("Manually retrying verification for funding ID: {$this->fundingId}, reference: {$funding->reference}");

        try {
            // Re-set status to pending so it can be verified
            $funding->update([
                'status' => FundingStatus::Pending,
                'failed_reason' => null,
            ]);

            $fundingService->processSuccessfulFunding($funding->reference, $funding->gateway_reference ?: 'RETRY-'.uniqid(), [
                'source' => 'manual_admin_retry',
                'retried_at' => now()->toIso8601String(),
            ]);

            Log::info("Successfully retried and completed funding ref: {$funding->reference}");

        } catch (\Throwable $e) {
            Log::error("Manual retry verification failed for funding Ref {$funding->reference}: ".$e->getMessage());

            // Re-set to failed
            $fundingService->failFunding($funding, 'Retry failed: '.$e->getMessage(), [
                'source' => 'manual_admin_retry',
                'failed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }
}
