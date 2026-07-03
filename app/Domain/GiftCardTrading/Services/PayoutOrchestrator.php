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
use Illuminate\Support\Facades\Log;

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
     * Lock the trade and assert it is safe to pay out. The trade must be Approved
     * and have no in-flight (pending) or completed (successful) payout. The lock is
     * held for the duration of the caller's transaction so two concurrent clicks /
     * retries can't both pass and double-pay. A previously FAILED payout does not
     * block a genuine retry.
     */
    protected function lockApprovedTrade(GiftCardTrade $trade): GiftCardTrade
    {
        $locked = GiftCardTrade::whereKey($trade->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== TradeStatus::Approved) {
            throw new \RuntimeException("Trade {$locked->uuid} is not approved for payout (status: {$locked->status->value}).");
        }

        $hasLivePayout = Payout::where('trade_id', $locked->id)
            ->whereIn('status', ['pending', 'successful'])
            ->exists();

        if ($hasLivePayout) {
            throw new \RuntimeException("Trade {$locked->uuid} already has an in-flight or completed payout; refusing to pay again.");
        }

        return $locked;
    }

    /**
     * Process wallet payout synchronously.
     */
    protected function payoutToWallet(GiftCardTrade $trade): void
    {
        DB::transaction(function () use ($trade) {
            $trade = $this->lockApprovedTrade($trade);

            $currency = Currency::from($trade->payout_currency);
            $wallet = $this->walletService->getOrCreateWallet($trade->user, $currency);

            $this->walletService->credit(
                wallet: $wallet,
                amount: $trade->calculated_payout,
                category: TransactionCategory::Funding,
                description: "Gift Card Trade Payout ({$trade->rate->brand->name})",
                reference: 'GC-PAYOUT-'.$trade->uuid,
                idempotencyKey: 'gc-payout-'.$trade->uuid,
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
            $trade = $this->lockApprovedTrade($trade);

            // Never transfer to a missing account or one that isn't owned by the
            // trade owner - a tampered bank_account_id must not redirect the money.
            $bankAccount = $trade->bankAccount;
            if (! $bankAccount || (int) $bankAccount->user_id !== (int) $trade->user_id) {
                throw new \RuntimeException("Trade {$trade->uuid} bank account is missing or not owned by the trade owner.");
            }

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

            // Fire the transfer AFTER COMMIT only - so a rolled-back transaction can
            // never leave money sent with no payout record (which would guarantee a
            // re-pay). Status is finalised by the signed Flutterwave webhook.
            dispatch(function () use ($trade, $reference) {
                $bankAccount = $trade->bankAccount;

                try {
                    $response = Http::timeout(30)->withToken(config('services.flutterwave.secret_key'))
                        ->post('https://api.flutterwave.com/v3/transfers', [
                            'account_bank' => $bankAccount->bank_code,
                            'account_number' => $bankAccount->account_number,
                            'amount' => $trade->calculated_payout,
                            'currency' => $trade->payout_currency,
                            'narration' => 'Gift Card Trade Payout',
                            'reference' => $reference,
                        ]);

                    if ($response->clientError()) {
                        // 4xx: gateway rejected outright, no transfer was created. Safe to
                        // mark failed and reopen the trade for a retry.
                        Payout::where('reference', $reference)->update([
                            'status' => 'failed',
                            'gateway_response' => $response->json(),
                        ]);
                        app(TradeReviewService::class)->updateStatus(
                            $trade->fresh(),
                            TradeStatus::Approved,
                            Admin::first(),
                            null,
                            'Bank transfer rejected by gateway; reopened for retry.'
                        );
                        Log::error("Flutterwave transfer rejected for {$reference}", [
                            'status' => $response->status(),
                            'body' => $response->json(),
                        ]);
                    } elseif (! $response->successful()) {
                        // 5xx / ambiguous: the transfer may have gone through. Leave it
                        // pending for the signed webhook or the stuck-payout reconciler -
                        // never mark failed here, or we risk re-paying a real transfer.
                        Log::warning("Flutterwave transfer unconfirmed for {$reference}", [
                            'status' => $response->status(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Network/timeout is ambiguous - do NOT fail the payout here.
                    Log::error("Flutterwave transfer error for {$reference}: ".$e->getMessage());
                }
            })->afterCommit()->catch(function (\Throwable $e) use ($reference) {
                Log::error("Flutterwave transfer job failed for {$reference}: ".$e->getMessage());
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

        // Idempotent: a payout that already reached a terminal state ignores
        // repeat/duplicate webhooks so it can't flip an already-paid trade.
        if (in_array($payout->status, ['successful', 'failed'], true)) {
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
