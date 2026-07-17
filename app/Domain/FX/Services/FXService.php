<?php

namespace App\Domain\FX\Services;

use App\Domain\Shared\Services\ProviderManager;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Wallet\Services\FinancialWalletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Str;

class FXService
{
    public function __construct(
        private ProviderManager $providerManager,
        private LedgerService $ledgerService,
        private FinancialWalletService $walletService
    ) {}

    /**
     * Get a cached quote for a currency pair, including system markup.
     * 
     * @return array Contains rate, source amount, destination amount, and quote ID.
     */
    public function getQuote(string $sourceCurrency, string $destinationCurrency, int $amount): array
    {
        // Prevent generating duplicate quotes for the same parameters within the validity window
        $existingQuote = \App\Models\FXQuote::where('source_currency', $sourceCurrency)
            ->where('destination_currency', $destinationCurrency)
            ->where('source_amount', $amount)
            ->valid()
            ->first();

        if ($existingQuote) {
            return $existingQuote->toArray();
        }

        // Fetch live rate from default FX provider (e.g. Maplerad)
        // Note: Provider APIs expect minor units for amounts.
        $providerQuote = $this->providerManager->fx()->getQuote($sourceCurrency, $destinationCurrency, $amount);

        // Calculate internal markup (e.g. 2% spread)
        $markupPercentage = 0.02;
        
        // Assume the provider returns how much destination currency we get.
        $providerReceives = $providerQuote['destination_amount'] ?? (int)($amount * $providerQuote['rate']);
        
        // The customer receives 2% less. The rest is our revenue.
        $customerReceives = (int) ($providerReceives * (1 - $markupPercentage));
        $systemRevenue = $providerReceives - $customerReceives;

        $quote = \App\Models\FXQuote::create([
            'id' => Str::uuid()->toString(),
            'provider_reference' => $providerQuote['reference'] ?? 'test_ref',
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'source_amount' => $amount,
            'destination_amount' => $customerReceives,
            'provider_destination_amount' => $providerReceives,
            'system_revenue' => $systemRevenue,
            'rate' => $customerReceives / $amount,
            'status' => \App\Models\FXQuote::STATUS_CREATED,
            'expires_at' => now()->addSeconds(60)
        ]);

        return $quote->toArray();
    }

    /**
     * Execute the FX conversion on the local double-entry ledger.
     * Uses a cross-currency clearing account pattern to ensure balanced debits and credits per currency.
     */
    public function executeExchange(int $userId, string $quoteId, string $idempotencyKey): void
    {
        // 1. Fetch the quote from Cache
        // In a real system, we'd search redis keys or require the full quote array to be passed,
        // but for this architectural blueprint we will assume the quote array is passed or fetched.
        throw new Exception("Implementation requires passing the full quote array to ensure atomic integrity.");
    }

    /**
     * Execute the FX conversion on the local double-entry ledger.
     */
    public function executeExchangeWithQuote(int $userId, array $quote, string $idempotencyKey): void
    {
        $sourceCurrency = $quote['source_currency'];
        $destCurrency = $quote['destination_currency'];

        // Get User Accounts
        $userSourceWallet = $this->walletService->getWalletAccount($userId, $sourceCurrency);
        $userDestWallet = $this->walletService->getWalletAccount($userId, $destCurrency);
        
        // Get System Clearing Accounts (To balance cross-currency ledgers)
        $clearingSource = $this->walletService->getSystemAccount($sourceCurrency, 'FX_Clearing');
        $clearingDest = $this->walletService->getSystemAccount($destCurrency, 'FX_Clearing');
        $revenueDest = $this->walletService->getSystemAccount($destCurrency, 'FX_Revenue');

        DB::transaction(function () use ($userSourceWallet, $userDestWallet, $clearingSource, $clearingDest, $revenueDest, $quote, $idempotencyKey, $sourceCurrency, $destCurrency) {
            
            // 0. Atomically lock and consume the quote to prevent Replay Attacks
            $lockedQuote = \App\Models\FXQuote::where('id', $quote['id'])->lockForUpdate()->firstOrFail();

            if ($lockedQuote->status !== \App\Models\FXQuote::STATUS_CREATED) {
                throw new Exception("FX Quote has already been consumed or is invalid.");
            }

            if ($lockedQuote->expires_at < now()) {
                $lockedQuote->update(['status' => \App\Models\FXQuote::STATUS_EXPIRED]);
                throw new Exception("FX Quote has expired.");
            }

            $lockedQuote->update([
                'status' => \App\Models\FXQuote::STATUS_CONSUMED,
                'consumed_at' => now(),
            ]);

            // 1. Leg A: Source Currency Movement
            // User pays NGN -> FX Clearing receives NGN
            $this->ledgerService->recordTransaction(
                description: "FX Sale: {$sourceCurrency} to {$destCurrency} (Leg A)",
                currency: $sourceCurrency,
                entries: [
                    ['account_id' => $userSourceWallet->id, 'amount' => $quote['source_amount'], 'direction' => 'debit'], // Decrease User Liability
                    ['account_id' => $clearingSource->id, 'amount' => $quote['source_amount'], 'direction' => 'credit'], // Increase Clearing Equity
                ],
                idempotencyKey: $idempotencyKey . '_leg_a'
            );

            // 2. Leg B: Destination Currency Movement
            // FX Clearing pays USD -> User receives USD
            $this->ledgerService->recordTransaction(
                description: "FX Purchase: {$sourceCurrency} to {$destCurrency} (Leg B)",
                currency: $destCurrency,
                entries: [
                    ['account_id' => $clearingDest->id, 'amount' => $quote['destination_amount'], 'direction' => 'debit'], // Decrease Clearing Equity
                    ['account_id' => $userDestWallet->id, 'amount' => $quote['destination_amount'], 'direction' => 'credit'], // Increase User Liability
                ],
                idempotencyKey: $idempotencyKey . '_leg_b'
            );

            // 3. Leg C: System Revenue Movement (The Spread)
            if ($quote['system_revenue'] > 0) {
                $this->ledgerService->recordTransaction(
                    description: "FX Revenue: {$sourceCurrency} to {$destCurrency} (Leg C)",
                    currency: $destCurrency,
                    entries: [
                        ['account_id' => $clearingDest->id, 'amount' => $quote['system_revenue'], 'direction' => 'debit'], // Decrease Clearing Equity
                        ['account_id' => $revenueDest->id, 'amount' => $quote['system_revenue'], 'direction' => 'credit'], // Increase Revenue
                    ],
                    idempotencyKey: $idempotencyKey . '_leg_c'
                );
            }

            // Execute the provider API call asynchronously in the background.
            // The local ledger is the source of truth, but we must hedge the position via the provider.
            // dispatch(new ExecuteProviderFXJob($quote));
        });
    }
}
