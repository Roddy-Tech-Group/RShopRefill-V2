<?php

namespace App\Console\Commands;

use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Support\PaymentStripBadges;
use App\Models\CurrencyRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes each crypto currency's gateway-side facts: whether our merchant
 * account can actually accept it, and what the minimum payment is.
 *
 * Minimums are not static. They float with network fee conditions, which is how
 * USDT on Tron ended up at roughly twelve dollars while USDC on Base sits near two
 * cents. Hardcoding them would guarantee a stale table and a customer discovering
 * the real floor only at the moment they try to pay.
 *
 * Fixed-rate minimums run materially higher than floating ones, so the provider
 * queries with the same flags it actually sends when creating a payment. Asking
 * with the wrong flags would cache a floor we then breach.
 */
class SyncCryptoCurrenciesCommand extends Command
{
    protected $signature = 'crypto:sync-currencies
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Sync crypto coin availability and minimum payment amounts from NOWPayments.';

    public function handle(NowPaymentsProvider $provider): int
    {
        $coins = CurrencyRate::query()->crypto()->active()->orderBy('sort_order')->get();

        if ($coins->isEmpty()) {
            $this->warn('No active crypto currencies to sync.');

            return self::SUCCESS;
        }

        $merchantCoins = $provider->getMerchantCurrencies();
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $unavailable = 0;

        foreach ($coins as $coin) {
            $ticker = strtolower($coin->code);

            // An empty roster means the lookup failed, not that every coin is
            // disabled. Treating a failed API call as "nothing is payable" would
            // empty the checkout, so availability is left untouched in that case.
            $payable = $merchantCoins === [] ? (bool) $coin->is_payable : in_array($ticker, $merchantCoins, true);

            $minimum = $payable ? $provider->getMinimumAmount($coin->code, 'usd') : null;
            $minUsd = $minimum['fiat_equivalent'] ?? null;

            if (! $payable) {
                $unavailable++;
            }

            $rows[] = [
                $coin->code,
                $coin->network ?? '-',
                $payable ? 'yes' : 'NO',
                $minUsd !== null ? '$'.number_format($minUsd, 2) : ($payable ? 'unknown' : '-'),
                $coin->min_amount_usd !== null ? '$'.number_format((float) $coin->min_amount_usd, 2) : '-',
            ];

            if ($dryRun) {
                continue;
            }

            $coin->is_payable = $payable;
            // Only overwrite a known minimum with another known minimum. A failed
            // lookup must not wipe a good value and silently reopen a coin we had
            // correctly gated.
            if ($minUsd !== null) {
                $coin->min_amount_usd = round((float) $minUsd, 4);
            }
            $coin->limits_synced_at = now();
            $coin->save();
        }

        $this->table(['Coin', 'Network', 'Payable', 'New min (USD)', 'Previous min'], $rows);

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        // The storefront "Pay your way" strip caches the payable lineup for 30
        // minutes. Without this, a coin the gateway just stopped accepting keeps
        // being advertised on the homepage for the rest of the window.
        PaymentStripBadges::forgetCryptoCache();

        if ($unavailable > 0) {
            Log::warning('Crypto sync: coins unavailable at the gateway', ['count' => $unavailable]);
            $this->warn("{$unavailable} coin(s) are enabled here but not accepted by the gateway — they are now hidden from checkout.");
        }

        $this->info('Crypto currency limits synced.');

        return self::SUCCESS;
    }
}
