<?php

namespace App\Domain\Card\Jobs;

use App\Domain\Shared\Services\ProviderManager;
use App\Domain\Wallet\Services\FinancialWalletService;
use App\Models\VirtualCard;
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

class ProcessPendingCardJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = [60, 300, 1800, 3600]; // Exponential backoff: 1m, 5m, 30m, 1h

    public function __construct(
        public VirtualCard $card,
        public int $fundingAmount,
        public string $idempotencyKey,
        public ?string $correlationId = null
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('payments');
    }

    public function uniqueId(): string
    {
        return (string) $this->card->id;
    }

    public function handle(ProviderManager $providerManager, FinancialWalletService $walletService): void
    {
        if ($this->correlationId) {
            Context::add('correlation_id', $this->correlationId);
        }

        if ($this->card->status !== VirtualCard::STATUS_PENDING) {
            return;
        }

        try {
            $providerCard = $providerManager->card($this->card->provider)->createVirtualCard([
                'customer_id' => (string) $this->card->user_id,
                'currency' => $this->card->currency,
                'amount' => $this->fundingAmount,
                'name' => $this->card->name_on_card,
            ]);

            $this->card->update([
                'provider_card_id' => $providerCard['id'],
                'masked_pan' => $providerCard['masked_pan'],
                'expiry_month' => $providerCard['expiry_month'],
                'expiry_year' => $providerCard['expiry_year'],
                'brand' => $providerCard['brand'],
                'status' => VirtualCard::STATUS_ACTIVE,
            ]);

        } catch (Exception $e) {
            Log::error('Pending Card Creation Failed.', ['card_id' => $this->card->id, 'error' => $e->getMessage()]);
            throw $e; // Triggers retry, and eventually DLQ
        }
    }

    /**
     * Handle a job failure (Dead Letter Queue).
     */
    public function failed(Exception $exception): void
    {
        Log::critical('Card Creation entered DLQ! Manual reconciliation required.', [
            'card_id' => $this->card->id,
            'amount' => $this->fundingAmount,
            'user_id' => $this->card->user_id,
        ]);

        $this->card->update(['status' => 'manual_review']);
    }
}
