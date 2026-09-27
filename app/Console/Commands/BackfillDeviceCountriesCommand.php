<?php

namespace App\Console\Commands;

use App\Domain\Security\Services\IpLocator;
use App\Models\TrustedDevice;
use Illuminate\Console\Command;

/**
 * Give every existing device an ISO country code.
 *
 * The registry has stored country NAMES since July, resolved from ip-api. The
 * rules that compare two logins need codes, because names are provider-specific
 * and a swap would read as a customer changing country. Rather than translate
 * the stored names, which would bake one provider's spellings in forever, this
 * re-resolves each device's stored IP address against the local database: the
 * same source the live path now uses, so history and new traffic agree.
 *
 * Read-only against the outside world (the lookup is a local file), and safe to
 * re-run: a device that already has a code is skipped unless --force.
 */
class BackfillDeviceCountriesCommand extends Command
{
    protected $signature = 'devices:backfill-countries
        {--force : Re-resolve devices that already have a country code}
        {--limit=0 : Stop after this many devices, 0 for all}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Resolve an ISO country code for existing trusted devices from their stored IP address.';

    public function handle(IpLocator $locator): int
    {
        if (! $locator->databaseAvailable()) {
            $this->components->error('No GeoIP database on disk. Run `php artisan location:update` first.');
            $this->line('  Expected at: '.config('location.maxmind.local.path'));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $query = TrustedDevice::query()->whereNotNull('ip_address');

        if (! $this->option('force')) {
            $query->whereNull('country_code');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->info('Every device with an IP address already has a country code.');

            return self::SUCCESS;
        }

        $this->components->info(($dryRun ? 'Would resolve ' : 'Resolving ').$total.' device(s).');

        $resolved = 0;
        $unresolved = 0;
        $renamed = 0;

        $query->orderBy('id')->chunkById(200, function ($devices) use ($locator, $dryRun, $limit, &$resolved, &$unresolved, &$renamed) {
            foreach ($devices as $device) {
                $location = $locator->locate($device->ip_address);

                if ($location === null) {
                    $unresolved++;

                    continue;
                }

                // Worth noticing: the new source disagrees with what ip-api
                // said about the same address. Usually only spelling.
                if ($device->country !== null && $device->country !== $location->countryName) {
                    $renamed++;
                }

                if (! $dryRun) {
                    $device->forceFill([
                        'country_code' => $location->countryCode,
                        'country' => $location->countryName ?? $device->country,
                        'city' => $location->city ?? $device->city,
                        'timezone' => $location->timezone ?? $device->timezone,
                    ])->saveQuietly();
                }

                $resolved++;

                if ($limit > 0 && $resolved >= $limit) {
                    return false;
                }
            }

            return true;
        });

        $this->newLine();
        $this->line("  Resolved:   {$resolved}");
        $this->line("  No country: {$unresolved}");
        $this->line("  Renamed:    {$renamed}  (the local database spells the country differently to the old provider)");

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run, nothing was written.');
        }

        return self::SUCCESS;
    }
}
