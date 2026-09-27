<?php

namespace App\Console\Commands;

use App\Domain\Security\Services\IpLocator;
use App\Domain\Security\Services\LoginCountryGuard;
use App\Models\GeoVelocityEvent;
use App\Models\TrustedDevice;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the login country check is doing.
 *
 * The rule it replaced scored a number into a session row and left no record
 * of why, so nobody could say whether it had ever been right. This answers the
 * questions that decide whether the new one earns its place: how many customers
 * were asked for a code because of a country change, how many were waved
 * through on a country they had already proved, and which hops we are seeing.
 *
 * Also reports the health of the thing underneath it, because a rule reading a
 * missing GeoIP database is a rule that silently never fires.
 */
class GeoVelocityReportCommand extends Command
{
    protected $signature = 'geo:report
        {--days=30 : How far back to look}
        {--customers=15 : How many of the most affected customers to list}
        {--json : Emit the raw result for dashboards and probes}';

    protected $description = 'Report who the login country check challenged, and whether the location data behind it is healthy.';

    public function handle(IpLocator $locator, LoginCountryGuard $guard): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $events = GeoVelocityEvent::query()
            ->with('user:id,name,email')
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        $enabled = FeatureFlag::on('login_country_check', true);
        $coverage = $this->coverage();

        if ($this->option('json')) {
            return $this->emitJson($events, $since, $enabled, $coverage, $locator, $guard);
        }

        $this->components->info($enabled
            ? 'Login country check is ON: a country change inside the window asks for an email code.'
            : 'Login country check is OFF: country changes are not being noticed at all.');

        $this->line('  Window: '.$guard->windowHours().' hours. Proof of a country lasts '.$guard->proofExpiryDays().' days.');
        $this->newLine();

        $this->reportHealth($locator, $coverage);

        if ($events->isEmpty()) {
            $this->components->info("No country change was seen in the last {$days} day(s).");

            return self::SUCCESS;
        }

        $challenged = $events->where('challenged', true);

        $this->line(sprintf(
            '  <options=bold>%d country change(s) across %d customer(s)</> in the last %d day(s): %d asked for a code, %d already proved.',
            $events->count(),
            $events->pluck('user_id')->filter()->unique()->count(),
            $days,
            $challenged->count(),
            $events->count() - $challenged->count(),
        ));
        $this->newLine();

        $this->reportHops($events);
        $this->reportCustomers($events, max(1, (int) $this->option('customers')));

        return self::SUCCESS;
    }

    /**
     * A rule is only as good as the data under it, so the report leads with
     * whether that data is actually there.
     */
    private function reportHealth(IpLocator $locator, array $coverage): void
    {
        $this->line('  <options=bold>Location data</>');

        if (! $locator->databaseAvailable()) {
            $this->components->error('GeoIP database MISSING. Every lookup resolves to unknown, so this check cannot fire.');
            $this->line('  Expected at: '.config('location.maxmind.local.path'));
            $this->line('  Fix: run `php artisan location:update` on the server.');
            $this->newLine();

            return;
        }

        $updatedAt = $locator->databaseUpdatedAt();
        $ageDays = $updatedAt ? (int) $updatedAt->diffInDays(now()) : null;

        $this->line('  Database:   present, updated '.($updatedAt?->diffForHumans() ?? 'unknown'));

        if ($ageDays !== null && $ageDays > 60) {
            $this->comment('  The database is over two months old. IP ranges get reassigned, so accuracy drifts. Check the weekly location:update run.');
        }

        $this->line(sprintf(
            '  Devices:    %d of %d carry a country code (%s)',
            $coverage['with_code'],
            $coverage['total'],
            $coverage['total'] > 0 ? round($coverage['with_code'] / $coverage['total'] * 100).'%' : 'n/a',
        ));

        if ($coverage['total'] > 0 && $coverage['with_code'] < $coverage['total']) {
            $this->comment('  Devices without a code are invisible to the money-path geo signal. Run `php artisan devices:backfill-countries`.');
        }

        $this->newLine();
    }

    /**
     * @param  Collection<int, GeoVelocityEvent>  $events
     */
    private function reportHops(Collection $events): void
    {
        $rows = $events
            ->groupBy(fn (GeoVelocityEvent $e) => $e->from_country_code.' -> '.$e->to_country_code)
            ->map(fn (Collection $group, string $hop) => [
                $hop,
                $group->count(),
                $group->pluck('user_id')->filter()->unique()->count(),
                $group->where('challenged', true)->count(),
                $group->min('minutes_apart').' min',
            ])
            ->sortByDesc(fn (array $row) => $row[1])
            ->values();

        $this->line('  <options=bold>Hops seen</>');
        $this->table(['Hop', 'Times', 'Customers', 'Challenged', 'Fastest'], $rows->all());
    }

    /**
     * @param  Collection<int, GeoVelocityEvent>  $events
     */
    private function reportCustomers(Collection $events, int $limit): void
    {
        $rows = $events
            ->filter(fn (GeoVelocityEvent $e) => $e->user_id !== null)
            ->groupBy('user_id')
            ->map(function (Collection $forUser) {
                /** @var GeoVelocityEvent $fastest */
                $fastest = $forUser->sortBy('minutes_apart')->first();

                return [
                    'customer' => $fastest->user?->email ?? ('user '.$fastest->user_id),
                    'changes' => $forUser->count(),
                    'challenged' => $forUser->where('challenged', true)->count(),
                    'fastest' => $fastest->describeHop(),
                    'last' => $forUser->max('created_at')?->diffForHumans(),
                    '_sort' => $fastest->minutes_apart,
                ];
            })
            ->sortBy('_sort')
            ->take($limit)
            ->map(fn (array $row) => array_values(array_diff_key($row, ['_sort' => true])))
            ->values();

        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('  <options=bold>Customers, fastest hop first</>');
        $this->table(['Customer', 'Changes', 'Challenged', 'Fastest hop', 'Last'], $rows->all());
        $this->newLine();
        $this->comment('A customer appearing here repeatedly on the same hop is almost always a VPN, not a traveller.');
        $this->comment('They are asked once per country and then left alone, so repeats mean their exit country keeps moving.');
    }

    /**
     * @return array{total: int, with_code: int}
     */
    private function coverage(): array
    {
        return [
            'total' => TrustedDevice::query()->count(),
            'with_code' => TrustedDevice::query()->whereNotNull('country_code')->count(),
        ];
    }

    /**
     * @param  Collection<int, GeoVelocityEvent>  $events
     * @param  array{total: int, with_code: int}  $coverage
     */
    private function emitJson(
        Collection $events,
        Carbon $since,
        bool $enabled,
        array $coverage,
        IpLocator $locator,
        LoginCountryGuard $guard,
    ): int {
        $this->line((string) json_encode([
            'since' => $since->toIso8601String(),
            'enabled' => $enabled,
            'window_hours' => $guard->windowHours(),
            'proof_days' => $guard->proofExpiryDays(),
            'database_available' => $locator->databaseAvailable(),
            'database_updated_at' => $locator->databaseUpdatedAt()?->toIso8601String(),
            'devices_total' => $coverage['total'],
            'devices_with_country_code' => $coverage['with_code'],
            'events' => $events->count(),
            'customers' => $events->pluck('user_id')->filter()->unique()->count(),
            'challenged' => $events->where('challenged', true)->count(),
            'already_proven' => $events->where('challenged', false)->count(),
            'by_hop' => $events
                ->groupBy(fn (GeoVelocityEvent $e) => $e->from_country_code.'_'.$e->to_country_code)
                ->map->count(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
