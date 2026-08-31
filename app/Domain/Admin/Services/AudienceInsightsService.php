<?php

namespace App\Domain\Admin\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\PhoneCountries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Country-level audience aggregates for the admin Audience Insights page and
 * its CSV export. Aggregates only: nothing here ever returns a name, email or
 * id, so the page is safe to screenshot into a deck.
 *
 * "Unknown" is an honest bucket, not noise: it is the pre-completion Google
 * accounts that never gave a country, and watching it shrink is how the
 * backfill campaign will be measured.
 */
class AudienceInsightsService
{
    /**
     * Everything the page needs, for a window of N days (null = all time).
     *
     * @return array{
     *   users_by_country: Collection<int, object>,
     *   orders_by_country: Collection<int, object>,
     *   weekly_signups: array{categories: list<string>, series: list<array{name: string, data: list<int>}>},
     *   table: Collection<int, array<string, mixed>>,
     *   total_users: int,
     *   total_orders: int,
     *   total_revenue_usd: float
     * }
     */
    public function build(?int $windowDays): array
    {
        $since = $windowDays !== null ? now()->subDays($windowDays) : null;

        $usersByCountry = User::query()
            ->where('is_guest', false)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("COALESCE(NULLIF(country, ''), '??') as country, COUNT(*) as users")
            ->groupBy('country')
            ->orderByDesc('users')
            ->get();

        // Revenue must be the USD settlement figure, not raw total_amount
        // (which is stored in each order's display currency). usdTotal() reads
        // it per order, so aggregate in PHP; volumes are modest and the page
        // is admin-only.
        $orders = Order::query()
            ->whereIn('order_status', ['completed', 'partially_completed'])
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->with('user:id,country')
            ->get(['id', 'user_id', 'total_amount', 'metadata']);

        $ordersByCountry = $orders
            ->groupBy(fn ($order) => $order->user?->country ?: '??')
            ->map(fn ($group, $country) => (object) [
                'country' => $country,
                'orders' => $group->count(),
                'revenue_usd' => round($group->sum(fn ($order) => (float) $order->usdTotal()), 2),
            ])
            ->sortByDesc('revenue_usd')
            ->values();

        return [
            'users_by_country' => $usersByCountry,
            'orders_by_country' => $ordersByCountry,
            'weekly_signups' => $this->weeklySignups(),
            'table' => $this->table($usersByCountry, $ordersByCountry, $since),
            'total_users' => (int) $usersByCountry->sum('users'),
            'total_orders' => $orders->count(),
            'total_revenue_usd' => round($orders->sum(fn ($order) => (float) $order->usdTotal()), 2),
        ];
    }

    /**
     * Human name + flag for a stored country value ('??' = unknown).
     */
    public function label(string $country): string
    {
        if ($country === '??') {
            return 'Unknown';
        }

        $flag = PhoneCountries::flag($country);
        $name = collect(config('countries.codes', []))->search($country) ?: $country;

        return trim($flag.' '.$name);
    }

    /**
     * Twelve weeks of signups, stacked by the top five countries plus Rest.
     * Rest always takes the LAST palette slot; country hues follow all-time
     * rank so a window change never repaints a country.
     *
     * @return array{categories: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    private function weeklySignups(): array
    {
        $start = now()->startOfWeek()->subWeeks(11);

        $rows = User::query()
            ->where('is_guest', false)
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'country'])
            ->groupBy(fn (User $user) => Carbon::parse($user->created_at)->startOfWeek()->format('Y-m-d'));

        $topCountries = User::query()
            ->where('is_guest', false)
            ->whereNotNull('country')->where('country', '!=', '')
            ->selectRaw('country, COUNT(*) as users')
            ->groupBy('country')
            ->orderByDesc('users')
            ->limit(5)
            ->pluck('country')
            ->all();

        $categories = [];
        $series = [];

        foreach ($topCountries as $country) {
            $series[$country] = [];
        }
        $series['Rest'] = [];

        for ($week = 0; $week < 12; $week++) {
            $weekStart = $start->copy()->addWeeks($week);
            $categories[] = $weekStart->format('M j');
            $inWeek = $rows->get($weekStart->format('Y-m-d'), collect());

            foreach ($topCountries as $country) {
                $series[$country][] = $inWeek->where('country', $country)->count();
            }

            $series['Rest'][] = $inWeek->filter(fn (User $user) => ! in_array($user->country, $topCountries, true))->count();
        }

        return [
            'categories' => $categories,
            'series' => collect($series)
                ->map(fn (array $data, string $key) => ['name' => $key === 'Rest' ? 'Rest' : $this->label($key), 'data' => $data])
                ->values()
                ->all(),
        ];
    }

    /**
     * One row per country: users, orders, revenue, AOV, verified share.
     *
     * @param  Collection<int, object>  $usersByCountry
     * @param  Collection<int, object>  $ordersByCountry
     * @return Collection<int, array<string, mixed>>
     */
    private function table(Collection $usersByCountry, Collection $ordersByCountry, ?Carbon $since): Collection
    {
        $verified = User::query()
            ->where('is_guest', false)
            ->whereNotNull('email_verified_at')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("COALESCE(NULLIF(country, ''), '??') as country, COUNT(*) as verified")
            ->groupBy('country')
            ->pluck('verified', 'country');

        $orderIndex = $ordersByCountry->keyBy('country');

        return $usersByCountry->map(function (object $row) use ($verified, $orderIndex) {
            $orders = $orderIndex->get($row->country);
            $orderCount = (int) ($orders->orders ?? 0);
            $revenue = (float) ($orders->revenue_usd ?? 0.0);

            return [
                'country' => $row->country,
                'label' => $this->label($row->country),
                'users' => (int) $row->users,
                'orders' => $orderCount,
                'revenue_usd' => $revenue,
                'aov_usd' => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0.0,
                'verified_pct' => $row->users > 0 ? (int) round(100 * (int) $verified->get($row->country, 0) / $row->users) : 0,
            ];
        })->values();
    }
}
