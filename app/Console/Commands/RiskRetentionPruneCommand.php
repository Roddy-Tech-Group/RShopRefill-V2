<?php

namespace App\Console\Commands;

use App\Models\RiskAssessment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Prunes risk assessments (and their signal rows, via FK cascade) older than the
 * configured retention window. Reviews and labels are deliberately kept: they are
 * the audit trail and the feedback ground truth. Runs daily off the scheduler.
 */
class RiskRetentionPruneCommand extends Command
{
    protected $signature = 'risk:prune';

    protected $description = 'Delete risk assessments older than the configured retention window.';

    public function handle(): int
    {
        $months = (int) config('risk.retention_months', 24);
        $cutoff = now()->subMonths($months);

        $total = RiskAssessment::query()->where('created_at', '<', $cutoff)->count();

        if ($total === 0) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $deleted = 0;

        // Chunk the delete so a large backlog never loads into memory at once;
        // the FK cascade removes each assessment's risk_signals rows.
        do {
            $batch = RiskAssessment::query()
                ->where('created_at', '<', $cutoff)
                ->limit(1000)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += RiskAssessment::query()->whereIn('id', $batch)->delete();
        } while ($batch->count() === 1000);

        // No silent caps: report exactly what was removed vs. what was eligible.
        Log::info('risk.retention_pruned', [
            'retention_months' => $months,
            'eligible' => $total,
            'deleted' => $deleted,
        ]);

        $this->info("Pruned {$deleted} of {$total} eligible risk assessments (retention {$months} months).");

        return self::SUCCESS;
    }
}
