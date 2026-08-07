<?php

namespace App\Domain\PaymentInsights\Jobs;

use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Models\TransactionRecovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps pending transaction recoveries whose links have expired into the
 * terminal `abandoned` state. Read paths already refuse expired tokens; this
 * keeps the stored statuses truthful so recovery analytics don't count dead
 * links as still-open opportunities.
 */
class ExpireStaleRecoveriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('intelligence');
    }

    public $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        // Every open state, not just pending: a customer who clicked or
        // started a retry and then never paid has abandoned it just as surely
        // as one who ignored the email, and leaving those rows open would
        // report dead links as live opportunities forever.
        $expired = TransactionRecovery::whereIn('status', RecoveryStatus::openValues())
            ->where('expires_at', '<', now())
            ->update(['status' => RecoveryStatus::Abandoned->value]);

        if ($expired > 0) {
            Log::info("Expired {$expired} stale transaction recoveries to abandoned.");
        }
    }
}
