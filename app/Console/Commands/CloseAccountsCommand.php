<?php

namespace App\Console\Commands;

use App\Domain\Auth\Services\AccountClosureService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finishes account closures whose grace period has run out by anonymising the
 * customer's personal details. Runs daily off the scheduler. Safe to re-run:
 * an account is anonymised once and then skipped.
 */
class CloseAccountsCommand extends Command
{
    protected $signature = 'retention:close-accounts
        {--dry-run : List the accounts that are due without changing anything}';

    protected $description = 'Anonymise the personal details of accounts whose closure grace period has ended.';

    public function handle(AccountClosureService $closures): int
    {
        $due = $closures->due()->get();

        if ($due->isEmpty()) {
            $this->info('No account closures are due.');

            return self::SUCCESS;
        }

        $this->info("{$due->count()} account closure(s) due (grace period {$closures->graceDays()} days).");

        if ($this->option('dry-run')) {
            $this->table(
                ['User', 'Requested'],
                $due->map(fn (User $user) => [$user->id, $user->closure_requested_at?->toDateTimeString()])->all()
            );
            $this->comment('Dry run - nothing was changed.');

            return self::SUCCESS;
        }

        $closed = 0;
        $failed = 0;

        foreach ($due as $user) {
            try {
                $closures->anonymise($user);
                $closed++;
                $this->line("  <info>closed</info> user {$user->id}");
            } catch (Throwable $e) {
                $failed++;
                Log::error('account.anonymise_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                $this->line("  <error>failed</error> user {$user->id}: {$e->getMessage()}");
            }
        }

        Log::info('account.closures_processed', ['due' => $due->count(), 'closed' => $closed, 'failed' => $failed]);

        $this->newLine();
        $this->info("Done. Closed {$closed}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
