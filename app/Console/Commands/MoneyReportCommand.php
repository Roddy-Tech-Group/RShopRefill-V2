<?php

namespace App\Console\Commands;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\CurrencyRate;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only money reconciliation. Separates the two pools that must never be
 * confused:
 *
 *   - Customer float  Every wallet balance, converted to USD. A LIABILITY: money
 *                     customers funded that the business must keep available to
 *                     honour their spends and withdrawals. NOT yours to spend.
 *   - Company profit  Markup actually earned = completed-order sales minus the
 *                     supplier cost paid. THIS is the business's money.
 *
 * It also shows which gateway the cash came in through, so the owner knows which
 * account to log in to, and how much is safe to withdraw without dipping into
 * customer funds. Pass --mine to carve your own test/personal customer accounts
 * out of the float so the pure-customer figure is honest.
 *
 * Run on production for live figures:  php artisan money:report
 * With your own accounts marked:        php artisan money:report --mine=you@example.com,alt@example.com
 */
class MoneyReportCommand extends Command
{
    protected $signature = 'money:report {--mine= : Comma-separated emails of YOUR own customer accounts to separate out of customer float}';

    protected $description = 'Separate customer float (a liability) from company earnings, and show which account the cash came in through.';

    public function handle(DashboardMetricsQuery $metrics): int
    {
        $ratesPerUsd = CurrencyRate::query()->where('is_active', true)->pluck('rate_per_usd', 'code');
        $rcoinUsdRate = (float) Setting::rcoinUsdRate();

        $mineEmails = collect(explode(',', (string) $this->option('mine')))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter()
            ->values();

        $mineUserIds = $mineEmails->isNotEmpty()
            ? User::whereIn(DB::raw('LOWER(email)'), $mineEmails->all())->pluck('id')->all()
            : [];

        // ── Customer float: every non-empty wallet, converted to USD ─────────
        $wallets = Wallet::query()
            ->with('user:id,name,email')
            ->where('balance', '>', 0)
            ->get(['id', 'user_id', 'currency', 'balance']);

        $customerFloatUsd = 0.0;   // real customers only
        $myWalletUsd = 0.0;        // wallets flagged via --mine
        $byCurrency = [];          // code => ['native' => x, 'usd' => y]
        $walletRows = [];          // ownership eyeball list

        foreach ($wallets as $wallet) {
            $code = strtoupper($wallet->currency->value);
            $native = (float) $wallet->balance;
            $usd = $metrics->balanceToUsd($code, $native, $ratesPerUsd, $rcoinUsdRate);
            $isMine = in_array($wallet->user_id, $mineUserIds, true);

            $byCurrency[$code] ??= ['native' => 0.0, 'usd' => 0.0];
            $byCurrency[$code]['native'] += $native;
            $byCurrency[$code]['usd'] += $usd;

            if ($isMine) {
                $myWalletUsd += $usd;
            } else {
                $customerFloatUsd += $usd;
            }

            $walletRows[] = [
                'email' => $wallet->user?->email ?? '(no user)',
                'code' => $code,
                'native' => $native,
                'usd' => $usd,
                'mine' => $isMine,
            ];
        }

        $totalFloatUsd = $customerFloatUsd + $myWalletUsd;

        // ── Company earnings (shares the dashboard's exact math) ─────────────
        $split = $metrics->getMoneySplit();

        // ── Where the cash came in (completed top-ups by gateway) ────────────
        $inflows = WalletFunding::query()
            ->where('status', FundingStatus::Completed->value)
            ->selectRaw('gateway, COUNT(*) as n, SUM(COALESCE(settled_amount_usd, 0)) as usd')
            ->groupBy('gateway')
            ->orderByDesc('usd')
            ->get();

        // ── Render ───────────────────────────────────────────────────────────
        $this->newLine();
        $this->info('MONEY REPORT   (every figure in USD, all-time)');
        $this->line('Connection: '.config('database.default').' / '.DB::connection()->getDatabaseName());

        $this->newLine();
        $this->info('1) HELD FOR CUSTOMERS  (a liability - keep this much reserved)');
        $currencyRows = [];
        foreach ($byCurrency as $code => $sums) {
            $currencyRows[] = [$code, number_format($sums['native'], 2), '$'.number_format($sums['usd'], 2)];
        }
        if ($currencyRows) {
            $this->table(['Currency', 'Held (native)', 'Held (USD)'], $currencyRows);
        } else {
            $this->line('   No funded wallets.');
        }
        $this->line('   Real customers:      $'.number_format($customerFloatUsd, 2));
        if (! empty($mineUserIds)) {
            $this->line('   Your own accounts:   $'.number_format($myWalletUsd, 2).'   (from --mine)');
        }
        $this->line('   Total wallet float:  $'.number_format($totalFloatUsd, 2));

        $this->newLine();
        $this->info('2) COMPANY EARNINGS  (your profit - yours to keep or reinvest)');
        $this->line('   Gross sales:         $'.number_format($split['gross_sales_usd'], 2));
        $this->line('   Supplier cost:      -$'.number_format($split['supplier_cost_usd'], 2));
        $this->line('   Profit:              $'.number_format($split['company_profit_usd'], 2));

        $this->newLine();
        $this->info('3) WHERE THE CASH CAME IN  (completed top-ups by gateway)');
        if ($inflows->isNotEmpty()) {
            $this->table(
                ['Gateway', 'Top-ups', 'Gross in (USD)'],
                $inflows->map(fn ($row) => [
                    $row->gateway,
                    (int) $row->n,
                    '$'.number_format((float) $row->usd, 2),
                ])->all(),
            );
            $this->line('   Note: gross money in, not the live balance. Log in to each gateway for');
            $this->line('   the current balance (payouts to suppliers and your withdrawals reduce it).');
        } else {
            $this->line('   No completed top-ups.');
        }

        // Top wallets so the owner can eyeball which accounts hold money.
        $top = collect($walletRows)->sortByDesc('usd')->take(15);
        if ($top->isNotEmpty()) {
            $this->newLine();
            $this->info('4) BIGGEST WALLETS  (spot your own accounts here)');
            $this->table(
                ['Account', 'Cur', 'Native', 'USD', 'Yours?'],
                $top->map(fn ($row) => [
                    Str::limit($row['email'], 34),
                    $row['code'],
                    number_format($row['native'], 2),
                    '$'.number_format($row['usd'], 2),
                    $row['mine'] ? 'yes' : '',
                ])->all(),
            );
            if (empty($mineUserIds)) {
                $this->line('   Re-run with --mine=your@email to move your own accounts out of the');
                $this->line('   customer column above.');
            }
        }

        // ── The bottom line ──────────────────────────────────────────────────
        $this->newLine();
        $this->info('THE BOTTOM LINE');
        $this->line('Your gateway/bank accounts must always hold at least $'.number_format($customerFloatUsd, 2));
        $this->line('to cover what customers can spend or withdraw. Everything above that line is');
        $this->line('yours: the first $'.number_format($split['company_profit_usd'], 2).' is earned profit, and any remainder is');
        $this->line('capital you put in and can take back.');
        if (! empty($mineUserIds)) {
            $this->newLine();
            $this->line('To remove YOUR OWN money:');
            $this->line('  - $'.number_format($myWalletUsd, 2).' sits in your own wallet(s): spend it or refund it to yourself.');
            $this->line('  - From the bank, withdraw only down to the $'.number_format($customerFloatUsd, 2).' customer-float line.');
        }
        $this->newLine();

        return self::SUCCESS;
    }
}
