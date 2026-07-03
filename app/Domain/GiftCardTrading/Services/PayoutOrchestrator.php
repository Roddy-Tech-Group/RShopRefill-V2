<?php

namespace App\Domain\GiftCardTrading\Services;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Enums\TradeStatus;
use App\Models\Admin;
use App\Models\GiftCardTrade;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PayoutOrchestrator
{
    public function __construct(
        protected WalletService $walletService
    ) {}

    /**
     * Trigger payout for an approved trade.
     */
    public function dispatchPayout(GiftCardTrade $trade): void
    {
        if ($trade->payout_method === 'wallet') {
            $this->payoutToWallet($trade);
        } elseif ($trade->payout_method === 'bank') {
            $this->payoutToBank($trade);
        } else {
            throw new \Exception("Unknown payout method: {$trade->payout_method}");
        }
    }

    /**
     * Process wallet payout synchronously.
     */
    protected function payoutToWallet(GiftCardTrade $trade): void
    {
        DB::transaction(function () use ($trade) {
            $currency = Currency::from($trade->payout_currency);
            $wallet = $this->walletService->getOrCreateWallet($trade->user, $currency);

            $this->walletService->credit(
                wallet: $wallet,
                amount: $trade->calculated_payout,
                category: TransactionCategory::Funding,
                description: "Gift Card Trade Payout ({$trade->rate->brand->name})",
                reference: 'GC-PAYOUT-'.$trade->uuid,
                sourceType: get_class($trade),
                sourceId: $trade->id
            );

            Payout::create([
                'trade_id' => $trade->id,
                'reference' => 'GC-PAYOUT-'.$trade->uuid,
                'amount' => $trade->calculated_payout,
                'status' => 'successful',
            ]);

            app(TradeReviewService::class)->updateStatus(
                $trade,
                TradeStatus::Paid,
                Admin::first(), // Or System actor if supported
                null,
                'Wallet credited automatically.'
            );
        });
    }

    /**
     * Process bank payout via Flutterwave.
     */
    protected function payoutToBank(GiftCardTrade $trade): void
    {
        DB::transaction(function () use ($trade) {
            $reference = 'FW-PAYOUT-'.$trade->uuid;

            Payout::create([
                'trade_id' => $trade->id,
                'reference' => $reference,
                'amount' => $trade->calculated_payout,
                'status' => 'pending',
            ]);

            app(TradeReviewService::class)->updateStatus(
                $trade,
                TradeStatus::PayingOut,
                Admin::first(),
                null,
                'Initiated Flutterwave transfer.'
            );

            // Dispatch a job to actually call Flutterwave API to prevent blocking
            dispatch(function () use ($trade, $reference) {
                $bankAccount = $trade->bankAccount;

                $response = Http::timeout(30)->withToken(config('services.flutterwave.secret_key'))
                    ->post('https://api.flutterwave.com/v3/transfers', [
                        'account_bank' => $bankAccount->bank_code,
                        'account_number' => $bankAccount->account_number,
                        'amount' => $trade->calculated_payout,
                        'currency' => $trade->payout_currency,
                        'narration' => 'Gift Card Trade Payout',
                        'reference' => $reference,
                    ]);

                if (! $response->successful()) {
                    // Log failure or trigger alert
                    // The webhook will handle the final status anyway if it was queued by FW
                }
            })->catch(function (\Throwable $e) {
                // Handle job failure
            });
        });
    }

    /**
     * Handle incoming Flutterwave webhook for transfers.
     */
    public function handleWebhook(array $payload): void
    {
        $reference = $payload['data']['reference'] ?? null;
        $status = $payload['data']['status'] ?? null;

        if (! $reference || ! $status) {
            return;
        }

        $payout = Payout::where('reference', $reference)->first();

        if (! $payout) {
            return;
        }

        if ($status === 'SUCCESSFUL') {
            $payout->update([
                'status' => 'successful',
                'gateway_response' => $payload,
            ]);

            app(TradeReviewService::class)->updateStatus(
                $payout->trade,
                TradeStatus::Paid,
                Admin::first(),
                null,
                'Bank transfer completed successfully.'
            );

        } elseif ($status === 'FAILED') {
            $payout->update([
                'status' => 'failed',
                'gateway_response' => $payload,
            ]);

            app(TradeReviewService::class)->updateStatus(
                $payout->trade,
                TradeStatus::Approved, // Revert to approved so it can be retried or changed to wallet
                Admin::first(),
                null,
                'Bank transfer failed. Please check bank details or switch payout method.'
            );
        }
    }
}
