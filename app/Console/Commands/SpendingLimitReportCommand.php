<?php

namespace App\Console\Commands;

use App\Domain\Kyc\Services\SpendingLimitService;
use App\Models\SpendingLimitEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * What the spending caps are doing, or would do.
 *
 * The caps have run in shadow since they shipped, which is the right way
 * round: measure first, enforce on a deliberate flip. But shadow is only
 * worth anything if somebody reads it, and until now the only trace was a
 * log line. This reads spending_limit_events and answers the question that
 * decides the flip: who reached a cap, on which window, and by how much.
 *
 * After enforcement it keeps its job, reporting who was actually refused.
 */
class SpendingLimitReportCommand extends Command
{
    protected $signature = 'limits:report
        {--days=30 : How far back to look}
        {--customers=10 : How many of the most affected customers to list}
        {--json : Emit the raw result for dashboards and probes}';

    protected $description = 'Report who reached a spending cap, and whether they were refused or only observed.';

    public function handle(SpendingLimitService $limits): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $events = SpendingLimitEvent::query()
            ->with('user:id,name,email')
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        $enforcing = $limits->enforcementEnabled();
        $sets = $limits->sets();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'since' => $since->toIso8601String(),
                'enforcing' => $enforcing,
                'events' => $events->count(),
                'customers' => $events->pluck('user_id')->filter()->unique()->count(),
                'refused' => $events->where('enforced', true)->count(),
                'observed' => $events->where('enforced', false)->count(),
                'by_window' => $events->groupBy('limit_window')->map->count(),
                'by_context' => $events->groupBy('context')->map->count(),
                'limits' => $sets,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info($enforcing
            ? 'Spending caps are ENFORCED: customers reaching one are refused.'
            : 'Spending caps are in SHADOW: customers reaching one are let through, and recorded here.');

        $this->line('  Unverified: $'.number_format($sets['standard']['day'], 2).' a day, $'.number_format($sets['standard']['week'], 2).' a week, $'.number_format($sets['standard']['month'], 2).' a month.');
        $this->line('  Verified:   $'.number_format($sets['verified']['day'], 2).' a day, $'.number_format($sets['verified']['week'], 2).' a week, $'.number_format($sets['verified']['month'], 2).' a month.');
        $this->newLine();

        if ($events->isEmpty()) {
            $this->info("No customer has reached a spending cap in the last {$days} day(s).");

            return self::SUCCESS;
        }

        $customers = $events->pluck('user_id')->filter()->unique()->count();
        $refused = $events->where('enforced', true)->count();

        $this->line(sprintf(
            '  <options=bold>%d event(s) across %d customer(s)</> in the last %d day(s): %d refused, %d let through.',
            $events->count(),
            $customers,
            $days,
            $refused,
            $events->count() - $refused,
        ));
        $this->newLine();

        $this->table(
            ['Window', 'Events', 'Customers', 'Unverified', 'Verified'],
            collect(['daily', 'weekly', 'monthly'])
                ->map(function (string $window) use ($events) {
                    $slice = $events->where('limit_window', $window);

                    return [
                        $window,
                        $slice->count(),
                        $slice->pluck('user_id')->filter()->unique()->count(),
                        $slice->where('kyc_verified', false)->count(),
                        $slice->where('kyc_verified', true)->count(),
                    ];
                })
                ->filter(fn (array $row) => $row[1] > 0)
                ->all(),
        );

        $this->listCustomers($events, max(1, (int) $this->option('customers')));

        if (! $enforcing) {
            $this->newLine();
            $this->comment('These customers were NOT refused. Enforcing would have stopped each of them at the');
            $this->comment('amount shown, and told them verification lifts the cap. Read the list before flipping');
            $this->comment('features.spending_limits_enforce_enabled, and never flip it before verification is on.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, SpendingLimitEvent>  $events
     */
    private function listCustomers(Collection $events, int $limit): void
    {
        $rows = $events
            ->filter(fn (SpendingLimitEvent $event) => $event->user_id !== null)
            ->groupBy('user_id')
            ->map(function (Collection $forUser) {
                /** @var SpendingLimitEvent $worst */
                $worst = $forUser->sortByDesc(fn (SpendingLimitEvent $e) => $e->overshootUsd())->first();

                return [
                    'customer' => $worst->user?->email ?? ('user '.$worst->user_id),
                    'events' => $forUser->count(),
                    'window' => $worst->limit_window,
                    'cap' => '$'.number_format((float) $worst->limit_usd, 2),
                    'reached' => '$'.number_format((float) $worst->money_in_usd + (float) $worst->amount_usd, 2),
                    'over_by' => '$'.number_format($worst->overshootUsd(), 2),
                    'verified' => $worst->kyc_verified ? 'yes' : 'no',
                    'last_seen' => $forUser->max('created_at')?->diffForHumans(),
                    '_sort' => $worst->overshootUsd(),
                ];
            })
            ->sortByDesc('_sort')
            ->take($limit)
            ->map(fn (array $row) => array_values(array_diff_key($row, ['_sort' => true])))
            ->values();

        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('  <options=bold>Customers who reached a cap</>');
        $this->table(
            ['Customer', 'Events', 'Window', 'Cap', 'Reached', 'Over by', 'Verified', 'Last'],
            $rows->all(),
        );
    }
}
