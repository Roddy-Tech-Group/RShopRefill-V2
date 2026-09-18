<?php

namespace App\Console\Commands;

use App\Models\ClosedAccountIdentity;
use App\Models\KycVerification;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Enforces the retention windows the privacy policy publishes, from the
 * numbers in config/retention.php, so the promise and the behaviour are the
 * same thing. Runs daily. Every rule is idempotent and reports exactly what it
 * removed against what was eligible.
 *
 * Fraud signals (risk:prune) and audit logs (audit:prune) keep their own
 * commands; this one covers the customer-facing windows they do not.
 */
class RetentionEnforceCommand extends Command
{
    protected $signature = 'retention:enforce
        {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Delete or anonymise data whose published retention window has passed.';

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->comment('Dry run - nothing will be changed.');
        }

        $summary = [
            'closed_account_identities' => $this->purgeClosedAccountIdentities(),
            'closed_account_financial_records' => $this->purgeClosedAccountFinancialRecords(),
            'communication_logs' => $this->purgeCommunicationLogs(),
            'contact_messages' => $this->purgeContactMessages(),
        ];

        Log::info('retention.enforced', ['dry_run' => $this->dryRun] + $summary);

        $this->newLine();
        $this->table(['Rule', 'Eligible', 'Removed'], collect($summary)->map(fn (array $r, string $k) => [$k, $r['eligible'], $r['removed']])->values()->all());

        return self::SUCCESS;
    }

    /**
     * The compliance drawer: who a closed account belonged to, kept for the
     * statutory period after closure and not a day longer.
     *
     * @return array{eligible: int, removed: int}
     */
    private function purgeClosedAccountIdentities(): array
    {
        $query = ClosedAccountIdentity::query()->where('retain_until', '<', now());
        $eligible = $query->count();

        $removed = $this->dryRun || $eligible === 0 ? 0 : $query->delete();

        $this->info("Closed-account identities past retention: {$eligible} eligible, {$removed} removed.");

        return ['eligible' => $eligible, 'removed' => $removed];
    }

    /**
     * Transaction records of a closed account, once the years financial and
     * anti-money-laundering law require have passed since closure. The user
     * row itself stays as an empty tombstone; everything financial hanging off
     * it goes. Active customers are never touched: their relationship has not
     * ended, so their window has not started.
     *
     * @return array{eligible: int, removed: int}
     */
    private function purgeClosedAccountFinancialRecords(): array
    {
        $years = max(1, (int) config('retention.closed_account_financial_years', 7));
        $cutoff = now()->subYears($years);

        $due = User::query()
            ->whereNotNull('anonymised_at')
            ->where('anonymised_at', '<', $cutoff)
            ->whereNull('financial_records_purged_at')
            ->orderBy('id')
            ->get();

        $eligible = $due->count();
        $removed = 0;

        foreach ($due as $user) {
            if ($this->dryRun) {
                $this->line("  would purge financial records of closed user {$user->id}");

                continue;
            }

            try {
                DB::transaction(function () use ($user): void {
                    // Children before parents; each FK below cascades to its
                    // own children (order items, payments, ledger events).
                    Order::query()->where('user_id', $user->id)->get()->each->delete();
                    PaymentAttempt::query()->where('user_id', $user->id)->delete();
                    WalletFunding::query()->where('user_id', $user->id)->delete();
                    WalletTransaction::query()->where('user_id', $user->id)->delete();
                    Wallet::query()->where('user_id', $user->id)->delete();
                    KycVerification::query()->where('user_id', $user->id)->delete();

                    foreach (['rcoin_withdrawals', 'referrals'] as $table) {
                        if (! Schema::hasTable($table)) {
                            continue;
                        }

                        $table === 'referrals'
                            ? DB::table($table)->where('referrer_id', $user->id)->orWhere('referred_user_id', $user->id)->delete()
                            : DB::table($table)->where('user_id', $user->id)->delete();
                    }

                    $user->forceFill(['financial_records_purged_at' => now()])->save();
                });

                $removed++;
                $this->line("  <info>purged</info> financial records of closed user {$user->id}");
            } catch (Throwable $e) {
                Log::error('retention.financial_purge_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                $this->line("  <error>failed</error> user {$user->id}: {$e->getMessage()}");
            }
        }

        $this->info("Closed accounts past the {$years}-year financial window: {$eligible} eligible, {$removed} purged.");

        return ['eligible' => $eligible, 'removed' => $removed];
    }

    /**
     * Delivery logs carry the recipient address and subject line: personal
     * data with an operational shelf life, not a financial record.
     *
     * @return array{eligible: int, removed: int}
     */
    private function purgeCommunicationLogs(): array
    {
        $days = max(1, (int) config('retention.communication_logs_days', 730));

        return $this->purgeTableOlderThan('communication_logs', 'created_at', now()->subDays($days), "Communication logs older than {$days} days");
    }

    /**
     * @return array{eligible: int, removed: int}
     */
    private function purgeContactMessages(): array
    {
        $days = max(1, (int) config('retention.contact_messages_days', 730));

        return $this->purgeTableOlderThan('contact_messages', 'created_at', now()->subDays($days), "Contact messages older than {$days} days");
    }

    /**
     * @return array{eligible: int, removed: int}
     */
    private function purgeTableOlderThan(string $table, string $column, \DateTimeInterface $cutoff, string $label): array
    {
        if (! Schema::hasTable($table)) {
            return ['eligible' => 0, 'removed' => 0];
        }

        $eligible = DB::table($table)->where($column, '<', $cutoff)->count();
        $removed = 0;

        if (! $this->dryRun && $eligible > 0) {
            // Chunked so a large backlog never locks the table in one statement.
            do {
                $batch = DB::table($table)->where($column, '<', $cutoff)->limit(1000)->pluck('id');

                if ($batch->isEmpty()) {
                    break;
                }

                $removed += DB::table($table)->whereIn('id', $batch)->delete();
            } while ($batch->count() === 1000);
        }

        $this->info("{$label}: {$eligible} eligible, {$removed} removed.");

        return ['eligible' => $eligible, 'removed' => $removed];
    }
}
