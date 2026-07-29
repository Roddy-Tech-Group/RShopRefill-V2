<?php

namespace App\Domain\Transaction\Jobs;

use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Shared\Services\ProviderManager;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class ProcessPendingWithdrawalJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [300, 1800]; // 5m, 30m

    public function __construct(
        public int $userId,
        public int $amount,
        public string $currency,
        public array $bankDetails,
        public string $providerName,
        public string $idempotencyKey,
        public ?string $correlationId = null
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('payments');
    }

    public function uniqueId(): string
    {
        return $this->idempotencyKey;
    }

    public function handle(ProviderManager $providerManager, LedgerService $ledgerService): void
    {
        if ($this->correlationId) {
            Context::add('correlation_id', $this->correlationId);
        }

        try {
            $providerManager->wallet($this->providerName)->withdraw($this->amount, $this->currency, $this->bankDetails);
        } catch (Exception $e) {
            Log::error('Pending Withdrawal Failed.', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
            throw $e; // Triggers retry
        }
    }

    public function failed(Exception $exception): void
    {
        Log::critical('Withdrawal entered DLQ! Manual reconciliation required.', [
            'user_id' => $this->userId,
            'amount' => $this->amount,
            'bank_code' => $this->bankDetails['bank_code'] ?? 'N/A',
        ]);
    }
}
