<?php

namespace App\Console\Commands;

use App\Domain\Fulfillment\Enums\EsimStatus;
use App\Domain\Fulfillment\Services\EsimUsageSync;
use App\Models\Esim;
use Illuminate\Console\Command;

/**
 * Refreshes eSIM status and data from the supplier, least recently checked
 * first, so every eSIM gets its turn however many there are.
 *
 * Expired, recycled and refunded eSIMs are settled and skipped. A used-up
 * eSIM is still checked: a top-up brings it back to life.
 */
class SyncEsimStatusCommand extends Command
{
    protected $signature = 'esims:sync-status
        {--limit=200 : How many eSIMs to refresh this run}
        {--iccid= : Refresh this one eSIM only}';

    protected $description = 'Refresh eSIM status and remaining data from the supplier.';

    /** Airalo allows 5 calls a second per company; stay under it. */
    private const PAUSE_MICROSECONDS = 250_000;

    public function handle(EsimUsageSync $sync): int
    {
        $esims = Esim::query()
            ->where('provider', 'airalo')
            ->when(
                $this->option('iccid'),
                fn ($query, $iccid) => $query->where('iccid', trim((string) $iccid)),
                fn ($query) => $query
                    ->whereNull('refunded_at')
                    ->whereNotIn('status', [EsimStatus::Expired->value, EsimStatus::Recycled->value, EsimStatus::Refunded->value]),
            )
            ->orderByRaw('last_synced_at IS NOT NULL')
            ->orderBy('last_synced_at')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $refreshed = 0;
        $failed = 0;

        foreach ($esims as $index => $esim) {
            if ($index > 0) {
                usleep(self::PAUSE_MICROSECONDS);
            }

            $sync->sync($esim) ? $refreshed++ : $failed++;
        }

        $this->info("{$refreshed} eSIMs refreshed; {$failed} the supplier did not answer for.");

        return self::SUCCESS;
    }
}
