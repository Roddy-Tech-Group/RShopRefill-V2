<?php

namespace App\Http\Resources\V2;

use App\Domain\Shared\Enums\Currency;
use App\Models\Wallet;
use App\Support\ApiMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the customer's wallets. They may hold several, one per currency.
 *
 * Balances are NOT converted into the display currency, and that is the honest
 * choice: a naira wallet holds naira. Showing it as dollars would suggest the
 * customer can spend dollars from it, and the figure would drift with the rate
 * while the actual balance sat still.
 *
 * Three figures rather than one, because they answer different questions:
 *   balance      everything in the wallet
 *   locked       held against a payment in flight, so not spendable right now
 *   available    what can actually be spent, which is what a checkout screen wants
 *
 * @property Wallet $resource
 */
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->currency instanceof Currency
            ? $this->currency->value
            : (string) $this->currency;

        return [
            'id' => $this->id,
            'currency' => $currency,
            // Rcoin is a points balance that happens to live in the wallets table.
            // Named so the app does not add it into a cash total, which is exactly
            // what the website's own totals go out of their way not to do.
            'kind' => $currency === Currency::RCOIN->value ? 'points' : 'cash',
            'balance' => ApiMoney::native((float) $this->balance, $currency),
            'locked_balance' => ApiMoney::native((float) $this->locked_balance, $currency),
            'available_balance' => ApiMoney::native((float) $this->availableBalance(), $currency),
            'is_active' => (bool) $this->is_active,
            'last_activity_at' => $this->last_activity_at?->toIso8601ZuluString(),
        ];
    }
}
