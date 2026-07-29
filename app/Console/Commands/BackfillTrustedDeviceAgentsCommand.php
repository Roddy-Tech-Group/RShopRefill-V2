<?php

namespace App\Console\Commands;

use App\Models\TrustedDevice;
use Illuminate\Console\Command;
use Jenssegers\Agent\Agent;

/**
 * Re-derives os / browser / device_type from the stored user agent for trusted
 * devices recorded before the Agent package was installed.
 *
 * This repair used to run inside the security centre's render path, so a plain
 * GET of that page issued an UPDATE per stale device - writes on a read request,
 * and only ever for the handful of devices the page happened to list. Running it
 * here repairs every row once, off the request path.
 */
class BackfillTrustedDeviceAgentsCommand extends Command
{
    protected $signature = 'devices:backfill-agents {--dry-run : Report what would be repaired without saving}';

    protected $description = 'Re-derive os, browser and device type from the stored user agent for trusted devices missing them.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $repaired = 0;
        $skipped = 0;

        TrustedDevice::query()
            ->chunkById(200, function ($devices) use ($dryRun, &$repaired, &$skipped) {
                foreach ($devices as $device) {
                    if (! $this->needsRepair($device)) {
                        continue;
                    }

                    if (blank($device->user_agent)) {
                        $skipped++;

                        continue;
                    }

                    $agent = new Agent;
                    $agent->setUserAgent($device->user_agent);

                    $device->os = $agent->platform() ?: 'Unknown OS';
                    $device->browser = $agent->browser() ?: 'Unknown Browser';
                    $device->device_type = $agent->isDesktop() ? 'desktop' : ($agent->isTablet() ? 'tablet' : 'mobile');

                    if (! $dryRun) {
                        $device->save();
                    }

                    $repaired++;
                }
            });

        $this->info(sprintf(
            '%s: %d trusted device(s) repaired, %d skipped for a missing user agent.',
            $dryRun ? 'DRY RUN' : 'Backfilled',
            $repaired,
            $skipped
        ));

        return self::SUCCESS;
    }

    /**
     * A row still carrying the pre-Agent placeholders is the only thing this
     * command may rewrite; anything already resolved is left alone so a re-run
     * is a no-op instead of a second pass over good data.
     */
    private function needsRepair(TrustedDevice $device): bool
    {
        return empty($device->os)
            || str_contains($device->os, 'Unknown')
            || $device->os === '0';
    }
}
