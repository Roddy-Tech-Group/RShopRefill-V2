<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletFunding;

/**
 * Who may press "Verify now" on the KYC page.
 *
 * Every verification we start costs money at the provider, and a brand-new
 * signup pressing the button is usually a tyre-kicker. The CEO's rule
 * (2026-09-22): a customer must have transacted with us before they can
 * start verification on their own. A qualifying transaction is a completed
 * wallet top-up or a paid order, however it was paid (card, mobile money,
 * crypto or wallet balance), worth at least the floor. Pending, failed and
 * expired attempts do not count, and neither does an order that was fully
 * refunded, or one could buy, refund, and verify for free.
 *
 * This gate guards ONLY the self-serve door (POST /api/kyc/start). The
 * step-up door (KycGate, reached from checkout and wallet funding) must
 * never read it: a first order of $500 trips Tier 2 on the spot, and a
 * customer blocked from paying until verified and from verifying until they
 * pay is a customer lost. At that door they are already committing money,
 * which is the commitment the rule asks for. The admin's Set KYC override
 * is not subject to it either.
 *
 * Both numbers are admin settings (Settings > Verification & Limits) so
 * "one" can become "two" without a deploy; a count of 0 switches the rule
 * off.
 */
class KycSelfServeEligibility
{
    public const MIN_TRANSACTIONS_KEY = 'features.kyc_self_serve_min_transactions';

    public const MIN_TRANSACTION_USD_KEY = 'features.kyc_self_serve_min_transaction_usd';

    public function isEligible(User $user): bool
    {
        $required = $this->minTransactions();

        return $required <= 0 || $this->qualifyingTransactions($user, $required) >= $required;
    }

    /**
     * The sentence the button shows when the customer is not yet eligible.
     */
    public function refusalMessage(): string
    {
        $floor = $this->minTransactionUsd();
        $amount = '$'.($floor == floor($floor) ? number_format($floor, 0) : number_format($floor, 2));

        if ($this->minTransactions() > 1) {
            return sprintf(
                'Verification opens after your first %d purchases or wallet top-ups of at least %s each. Shop or fund your wallet, then come back.',
                $this->minTransactions(),
                $amount,
            );
        }

        return "Verification opens after your first purchase or wallet top-up of at least {$amount}. Shop or fund your wallet, then come back.";
    }

    /**
     * How many qualifying transactions the customer has, counting no further
     * than $upTo (the gate only needs to know whether the bar is met).
     */
    public function qualifyingTransactions(User $user, ?int $upTo = null): int
    {
        $floor = $this->minTransactionUsd();
        $upTo ??= PHP_INT_MAX;

        $fundings = WalletFunding::query()
            ->where('user_id', $user->getKey())
            ->where('status', FundingStatus::Completed)
            ->when($floor > 0, fn ($query) => $query->where('settled_amount_usd', '>=', $floor))
            ->limit($upTo)
            ->count();

        if ($fundings >= $upTo) {
            return $fundings;
        }

        // Paid, however it was paid. Refunded and partially refunded orders
        // are excluded on payment_status; usdTotal() needs the row, so the
        // amount floor is applied in PHP over the candidates.
        $orders = $user->orders()
            ->where('payment_status', PaymentStatus::Paid)
            ->orderByDesc('id')
            ->get(['id', 'total_amount', 'display_currency', 'metadata'])
            ->filter(fn (Order $order) => $floor <= 0 || $order->usdTotal() >= $floor)
            ->take($upTo - $fundings)
            ->count();

        return $fundings + $orders;
    }

    public function minTransactions(): int
    {
        return (int) $this->dial(self::MIN_TRANSACTIONS_KEY, 1.0);
    }

    public function minTransactionUsd(): float
    {
        return $this->dial(self::MIN_TRANSACTION_USD_KEY, 1.0);
    }

    /**
     * A dial's value, never below 0. Both dials once rendered as an on/off
     * switch on the Settings page, which showed 0 as Off and 1 as On and
     * saved the word. A word left behind reads the way the switch showed it,
     * so a dial switched Off really is off. Anything else unreadable falls
     * back to the default.
     */
    private function dial(string $key, float $default): float
    {
        $value = SiteSetting::get($key);

        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (! is_string($value)) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            'off', 'false' => 0.0,
            'on', 'true' => 1.0,
            default => $default,
        };
    }
}
