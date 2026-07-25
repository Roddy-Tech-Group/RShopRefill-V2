<?php

namespace App\Domain\Rewards\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Rewards\RcoinEarnedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Fraud\Services\FraudDetectionService;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Order;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class RewardEngine
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly FraudDetectionService $fraudDetectionService
    ) {}

    /**
     * Entry point called when an order is successfully completed.
     */
    public function processOrderRewards(Order $order): void
    {
        if (! Setting::get('rcoin_enabled', true)) {
            return;
        }

        DB::transaction(function () use ($order) {
            // Lock the order to prevent duplicate processing
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            if (! $lockedOrder) {
                return;
            }

            // Ensure this order hasn't already awarded cashback (idempotency check)
            $alreadyAwarded = WalletTransaction::where('idempotency_key', "reward-cashback-{$order->id}")->exists();

            if ($alreadyAwarded) {
                return;
            }

            $this->awardCashback($lockedOrder);
            $this->awardReferralBonus($lockedOrder);
        });
    }

    /**
     * The Rcoin cashback an order earns (before velocity caps): settlement
     * USD x cashback percentage, through the buyer's multiplier. Public so
     * the delivery email can print the same figure the credit will use -
     * there is no separate "you earned Rcoin" email.
     */
    public function cashbackPreviewFor(Order $order): int
    {
        if (! Setting::get('rcoin_enabled', true)) {
            return 0;
        }

        $percentage = (float) Setting::get('cashback_percentage', 0.5);
        if ($percentage <= 0) {
            return 0;
        }

        // Reward is based on total USD spent. total_amount is stored in the
        // customer's display currency (4000 XAF, 12000 NGN, ...), so it must
        // never feed the Rcoin formula directly - usdTotal() resolves the true
        // settlement USD from the order's rate snapshot.
        $rcoinAmount = $this->usdToRcoin($order->usdTotal() * ($percentage / 100));

        // Per-user multiplier - power users / influencers earn proportionally
        // more for the same activity. Defaults to 1.00 (no change) for the
        // vast majority of accounts. Admin edits this from the customer page.
        $multiplier = (float) ($order->user?->rcoin_multiplier ?? 1.00);
        if ($multiplier > 0 && $multiplier !== 1.0) {
            $rcoinAmount = (int) floor($rcoinAmount * $multiplier);
        }

        return max(0, $rcoinAmount);
    }

    /**
     * Award buyer cashback for their purchase. The figure is surfaced on the
     * order-delivery email instead of a dedicated cashback email.
     */
    private function awardCashback(Order $order): void
    {
        $rcoinAmount = $this->cashbackPreviewFor($order);

        if ($rcoinAmount <= 0) {
            return;
        }

        $user = $order->user;

        // Enforce daily/monthly max limits
        if (! $this->canEarnRcoin($user, $rcoinAmount)) {
            return; // Or cap the amount, but skipping is safer for now
        }

        $wallet = $this->walletService->getOrCreateWallet($user, Currency::RCOIN);

        $description = "Cashback reward for Order #{$order->order_number}";

        $this->walletService->credit(
            wallet: $wallet,
            amount: $rcoinAmount,
            category: TransactionCategory::RewardCashback,
            description: $description,
            idempotencyKey: "reward-cashback-{$order->id}",
            metadata: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'cashback_percentage' => (float) Setting::get('cashback_percentage', 0.5),
            ]
        );
    }

    /**
     * Award recurring referral bonus if the user was referred.
     */
    private function awardReferralBonus(Order $order): void
    {
        if (! Setting::get('referral_enabled', true)) {
            return;
        }
        if (! Setting::get('recurring_referral_rewards_enabled', true)) {
            // If recurring is disabled, only award on first order.
            // Check if this is the user's first order
            $isFirstOrder = Order::where('user_id', $order->user_id)
                ->where('id', '!=', $order->id)
                ->where('order_status', 'completed')
                ->doesntExist();

            if (! $isFirstOrder) {
                return;
            }
        }

        $percentage = (float) Setting::get('referral_reward_percentage', 0.5);
        if ($percentage <= 0) {
            return;
        }

        $referral = Referral::where('referred_user_id', $order->user_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (! $referral) {
            return;
        }

        // Apply max referral rewards per user cap
        $maxPerUser = (int) Setting::get('max_referral_rewards_per_user', 0);
        if ($maxPerUser > 0 && $referral->total_orders_completed >= $maxPerUser) {
            return;
        }

        $referrer = $referral->referrer;

        // Calculate referral reward from the true settlement USD (total_amount
        // is the display-currency figure - see awardCashback).
        $usdAmount = $order->usdTotal();
        $rcoinAmount = $this->usdToRcoin($usdAmount * ($percentage / 100));

        if ($rcoinAmount <= 0) {
            return;
        }

        // Per-user multiplier applies to the REFERRER (they're the one
        // being incentivised). A 2× influencer earns 2× the referral
        // bonus on every order their referrees place.
        $multiplier = (float) ($referrer->rcoin_multiplier ?? 1.00);
        if ($multiplier > 0 && $multiplier !== 1.0) {
            $rcoinAmount = (int) floor($rcoinAmount * $multiplier);
        }

        // Apply daily/monthly limits for referrer
        if (! $this->canEarnRcoin($referrer, $rcoinAmount, 'referral')) {
            return;
        }

        $wallet = $this->walletService->getOrCreateWallet($referrer, Currency::RCOIN);

        $description = "Referral bonus for Order #{$order->order_number}";

        $this->walletService->credit(
            wallet: $wallet,
            amount: $rcoinAmount,
            category: TransactionCategory::RewardReferral,
            description: $description,
            idempotencyKey: "reward-referral-{$order->id}",
            metadata: [
                'order_id' => $order->id,
                'referred_user_id' => $order->user_id,
                'referral_percentage' => $percentage,
            ]
        );

        // Update referral stats
        $referral->total_rewards_generated += $rcoinAmount;
        $referral->total_orders_completed += 1;
        $referral->last_rewarded_at = now();
        $referral->save();

        // Notify the referrer their bonus landed. Includes the referred
        // user's name for context ("Maria just completed an order").
        try {
            $referredUser = User::find($order->user_id);

            app(CommunicationManager::class)->send(
                recipient: $referrer,
                category: CommunicationCategory::RcoinReward,
                title: 'You Earned RCoins!',
                message: "You just earned {$rcoinAmount} RCoins because {$referredUser?->name} completed an order.",
                mailable: new RcoinEarnedMail(
                    recipient: $referrer,
                    rcoinAmount: $rcoinAmount,
                    newBalance: (int) $wallet->refresh()->balance,
                    kind: 'referral',
                    orderNumber: $order->order_number,
                    referredName: $referredUser?->name,
                ),
                correlationId: 'rcoin_referral_'.$order->id
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Safely reverse rewards associated with an order (e.g. refund/chargeback).
     */
    public function reverseOrderRewards(Order $order): void
    {
        if (! Setting::get('reward_reversal_enabled', true)) {
            return;
        }

        DB::transaction(function () use ($order) {
            $transactions = WalletTransaction::where('currency', Currency::RCOIN->value)
                ->whereIn('transaction_category', [TransactionCategory::RewardCashback->value, TransactionCategory::RewardReferral->value])
                ->where('metadata->order_id', $order->id)
                ->get();

            foreach ($transactions as $tx) {
                // Prevent double reversal
                $alreadyReversed = WalletTransaction::where('currency', Currency::RCOIN->value)
                    ->where('transaction_category', TransactionCategory::RewardReversal->value)
                    ->where('metadata->reversed_transaction_id', $tx->id)
                    ->exists();

                if ($alreadyReversed) {
                    continue;
                }

                $wallet = $tx->wallet;

                // If wallet balance is sufficient, deduct it safely.
                // Note: The prompt states "MUST NOT allow negative balances improperly".
                // We will attempt debit. If debit fails due to InsufficientBalanceException,
                // we might need to handle it. The WalletService::debit throws an exception.
                try {
                    $this->walletService->debit(
                        wallet: $wallet,
                        amount: (float) $tx->amount,
                        category: TransactionCategory::RewardReversal,
                        description: "Reversal of reward due to Order #{$order->order_number} refund",
                        metadata: [
                            'order_id' => $order->id,
                            'reversed_transaction_id' => $tx->id,
                        ]
                    );

                    // Revert referral stats if it was a referral reward
                    if ($tx->transaction_category === TransactionCategory::RewardReferral) {
                        $referredUserId = $tx->metadata['referred_user_id'] ?? null;
                        if ($referredUserId) {
                            $referral = Referral::where('referrer_id', $wallet->user_id)
                                ->where('referred_user_id', $referredUserId)
                                ->first();

                            if ($referral) {
                                $referral->total_rewards_generated -= $tx->amount;
                                $referral->save();
                            }
                        }
                    }
                } catch (InsufficientBalanceException $e) {
                    // Log the failure to reverse because user spent the RCOINs.
                    // Depending on policy, we might create a negative ledger adjustment,
                    // but the directive specifically says "MUST NOT allow negative balances improperly".
                    // So we gracefully catch and perhaps notify admin.
                    report($e);
                }
            }
        });
    }

    /**
     * Convert USD amount to Rcoin (rounding down to nearest whole coin).
     */
    public function usdToRcoin(float $usdAmount): int
    {
        $rate = (float) Setting::rcoinUsdRate();
        if ($rate <= 0) {
            return 0;
        }

        return (int) floor($usdAmount / $rate);
    }

    /**
     * Convert Rcoin to USD.
     */
    public function rcoinToUsd(int $rcoinAmount): float
    {
        $rate = (float) Setting::rcoinUsdRate();

        return round($rcoinAmount * $rate, 4);
    }

    /**
     * Check if user is eligible to earn this amount based on fraud/velocity caps.
     */
    private function canEarnRcoin(User $user, int $amount, string $type = 'cashback'): bool
    {
        // For phase 10: daily/monthly limits.
        // We can query wallet_transactions for the sum of rewards today/this month.
        $dailyKey = $type === 'referral' ? 'max_referral_rewards_daily' : 'max_daily_reward_per_user';
        $monthlyKey = $type === 'referral' ? 'max_referral_rewards_monthly' : 'max_monthly_reward_per_user';

        $dailyLimit = (int) Setting::get($dailyKey, 5000);
        $monthlyLimit = (int) Setting::get($monthlyKey, 50000);

        $categories = $type === 'referral'
            ? [TransactionCategory::RewardReferral->value]
            : [TransactionCategory::RewardCashback->value, TransactionCategory::RewardReferral->value];

        $rcoinWallet = $this->walletService->getOrCreateWallet($user, Currency::RCOIN);

        if ($dailyLimit > 0) {
            $earnedToday = WalletTransaction::where('wallet_id', $rcoinWallet->id)
                ->whereIn('transaction_category', $categories)
                ->whereDate('created_at', today())
                ->sum('amount');

            if (($earnedToday + $amount) > $dailyLimit) {
                return false;
            }
        }

        if ($monthlyLimit > 0) {
            $earnedThisMonth = WalletTransaction::where('wallet_id', $rcoinWallet->id)
                ->whereIn('transaction_category', $categories)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount');

            if (($earnedThisMonth + $amount) > $monthlyLimit) {
                return false;
            }
        }

        return true;
    }
}
