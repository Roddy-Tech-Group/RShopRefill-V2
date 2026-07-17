<?php

namespace App\Domain\Card\Services;

use App\Models\VirtualCard;
use App\Domain\Shared\Services\ProviderManager;
use App\Domain\Wallet\Services\FinancialWalletService;
use Exception;
use Illuminate\Support\Str;

class CardService
{
    public function __construct(
        private ProviderManager $providerManager,
        private FinancialWalletService $walletService
    ) {}

    /**
     * Create a virtual card and fund it initially.
     * Uses atomic idempotency and the ledger wallet service to ensure funds exist.
     */
    public function createVirtualCard(int $userId, string $nameOnCard, int $fundingAmount, string $currency = 'USD', string $providerName = 'maplerad'): VirtualCard
    {
        $idempotencyKey = Str::uuid()->toString();

        // 1. Deduct funds from the user's ledger wallet first
        // If this throws an InsufficientFundsException, the process stops here.
        // We include a fixed $2 card creation fee for this example.
        $cardCreationFee = 200; // 2 USD in cents
        $totalDeduction = $fundingAmount + $cardCreationFee;

        $this->walletService->deductWallet(
            userId: $userId,
            amount: $totalDeduction,
            currency: $currency,
            providerName: $providerName,
            idempotencyKey: $idempotencyKey,
            description: "Virtual Card Creation & Funding"
        );

        // 2. Create the pending local card record
        $card = VirtualCard::create([
            'user_id' => $userId,
            'provider' => $providerName,
            'currency' => $currency,
            'status' => VirtualCard::STATUS_PENDING,
            'name_on_card' => $nameOnCard,
        ]);

        try {
            // 3. Call the Provider (e.g. Maplerad)
            $providerCard = $this->providerManager->card($providerName)->createVirtualCard([
                'customer_id' => (string) $userId, // Assuming mapped ID
                'currency' => $currency,
                'amount' => $fundingAmount, // Funds the card immediately
                'name' => $nameOnCard
            ]);

            // 4. Update the card with active provider details
            $card->update([
                'provider_card_id' => $providerCard['id'],
                'masked_pan' => $providerCard['masked_pan'],
                'expiry_month' => $providerCard['expiry_month'],
                'expiry_year' => $providerCard['expiry_year'],
                'brand' => $providerCard['brand'],
                'status' => VirtualCard::STATUS_ACTIVE,
            ]);

        } catch (Exception $e) {
            // 5. Provider failed or timed out.
            // DO NOT INSTANTLY REFUND. The provider might have received the payload and created the card.
            // Send to Dead Letter Queue architecture via an encrypted background job.
            \App\Domain\Card\Jobs\ProcessPendingCardJob::dispatch(
                $card, 
                $fundingAmount, 
                $idempotencyKey,
                \Illuminate\Support\Facades\Context::get('correlation_id')
            );
            
            // Note: If it eventually fails the DLQ, manual intervention or a specialized rollback job is required.
        }

        return $card;
    }

    /**
     * Top-up an existing virtual card.
     */
    public function fundCard(VirtualCard $card, int $amount): void
    {
        if ($card->status !== VirtualCard::STATUS_ACTIVE) {
            throw new Exception("Cannot fund a card that is not ACTIVE.");
        }

        $idempotencyKey = Str::uuid()->toString();

        // 1. Deduct wallet
        $this->walletService->deductWallet(
            userId: $card->user_id,
            amount: $amount,
            currency: $card->currency,
            providerName: $card->provider,
            idempotencyKey: $idempotencyKey,
            description: "Top-up Virtual Card: " . $card->masked_pan
        );

        try {
            // 2. Call provider
            $this->providerManager->card($card->provider)->fundCard($card->provider_card_id, $amount, $card->currency);
        } catch (Exception $e) {
            // DO NOT INSTANTLY REFUND.
            // Queue for async verification of provider state.
            \Illuminate\Support\Facades\Log::critical("Card Topup entered DLQ! Manual reconciliation required.", [
                'card_id' => $card->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception("Card Funding pending verification. Provider unreachable.");
        }
    }

    /**
     * Temporarily freeze the card.
     */
    public function freezeCard(VirtualCard $card): void
    {
        $this->providerManager->card($card->provider)->freezeCard($card->provider_card_id);
        $card->update(['status' => VirtualCard::STATUS_FROZEN]);
    }

    /**
     * Unfreeze the card.
     */
    public function unfreezeCard(VirtualCard $card): void
    {
        $this->providerManager->card($card->provider)->unfreezeCard($card->provider_card_id);
        $card->update(['status' => VirtualCard::STATUS_ACTIVE]);
    }
}
