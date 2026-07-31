<?php

namespace App\Domain\Admin\Queries;

use App\Models\CurrencyRate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * User leaderboards for the Management → Leaderboards page.
 *
 * Eleven ranked boards over the existing order / payment / wallet / referral
 * data. Guests (guest checkout accounts) are excluded everywhere — they are
 * order vehicles, not customers. Spend and margin figures convert to USD via
 * the same snapshot rules as the dashboard so no board can disagree with the
 * revenue pages.
 *
 * Period filtering ($since) applies to activity-based boards; identity boards
 * (Newest Users, Highest Wallet Balance, Dormant Users) ignore it because
 * "newest", "current balance" and "gone quiet" are not window questions.
 */
class LeaderboardQuery
{
    /** Order states that represent a customer who actually bought something. */
    private const PURCHASED_STATUSES = ['completed', 'partially_completed'];

    /** A previously-active customer with no order for this long is dormant. */
    private const DORMANT_AFTER_DAYS = 60;

    public function __construct(private DashboardMetricsQuery $metrics) {}

    /**
     * Every board, hydrated with user identities, ready for the page.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function boards(?Carbon $since, int $limit = 20): array
    {
        $boards = [
            'top_customers' => $this->topCustomers($since, $limit),
            'newest_users' => $this->newestUsers($limit),
            'returning_users' => $this->returningUsers($since, $limit),
            'dormant_users' => $this->dormantUsers($limit),
            'highest_margin' => $this->highestMargin($since, $limit),
            'most_failed_transactions' => $this->mostFailedTransactions($since, $limit),
            'highest_wallet_balance' => $this->highestWalletBalance($limit),
            'most_referrals' => $this->mostReferrals($since, $limit),
            'most_gift_cards' => $this->categoryBoard(['gift-cards'], $since, $limit),
            'most_airtime' => $this->categoryBoard(['mobile-airtime', 'topups'], $since, $limit),
            'most_esims' => $this->categoryBoard(['esims'], $since, $limit),
        ];

        return $this->hydrateUsers($boards);
    }

    /** @return array<int, array<string, mixed>> */
    private function topCustomers(?Carbon $since, int $limit): array
    {
        return $this->purchasedOrdersQuery($since)
            ->select(
                'orders.user_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM('.$this->orderUsdSql().') as spend_usd'),
            )
            ->groupBy('orders.user_id')
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'orders' => (int) $row->orders_count,
                'spend_usd' => round((float) $row->spend_usd, 2),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function newestUsers(int $limit): array
    {
        return User::query()
            ->where(fn ($q) => $q->where('is_guest', false)->orWhereNull('is_guest'))
            ->latest()
            ->limit($limit)
            ->get(['id', 'created_at', 'country'])
            ->map(fn (User $user) => [
                'user_id' => (int) $user->id,
                'registered_at' => $user->created_at?->toDateTimeString(),
                'country' => $user->country,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function returningUsers(?Carbon $since, int $limit): array
    {
        return $this->purchasedOrdersQuery($since)
            ->select(
                'orders.user_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('MAX(orders.created_at) as last_order_at'),
            )
            ->groupBy('orders.user_id')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('last_order_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'orders' => (int) $row->orders_count,
                'last_order_at' => (string) $row->last_order_at,
            ])
            ->all();
    }

    /**
     * Customers who bought before but have gone quiet — ranked by what they
     * used to spend, so the most valuable lapsed customers surface first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dormantUsers(int $limit): array
    {
        $cutoff = now()->subDays(self::DORMANT_AFTER_DAYS);

        return $this->purchasedOrdersQuery(null)
            ->select(
                'orders.user_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('MAX(orders.created_at) as last_order_at'),
                DB::raw('SUM('.$this->orderUsdSql().') as spend_usd'),
            )
            ->groupBy('orders.user_id')
            ->havingRaw('MAX(orders.created_at) < ?', [$cutoff])
            ->orderByDesc('spend_usd')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'orders' => (int) $row->orders_count,
                'last_order_at' => (string) $row->last_order_at,
                'spend_usd' => round((float) $row->spend_usd, 2),
            ])
            ->all();
    }

    /**
     * Who makes the company the most money: item revenue (USD) minus the
     * supplier cost snapshot, per customer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function highestMargin(?Carbon $since, int $limit): array
    {
        return $this->purchasedItemsQuery($since)
            ->select(
                'orders.user_id',
                DB::raw('SUM('.$this->metrics->itemUsdSql().') as revenue_usd'),
                DB::raw('SUM(order_items.provider_cost_usd * order_items.quantity) as cost_usd'),
            )
            ->groupBy('orders.user_id')
            ->orderByDesc(DB::raw('SUM('.$this->metrics->itemUsdSql().') - SUM(order_items.provider_cost_usd * order_items.quantity)'))
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'revenue_usd' => round((float) $row->revenue_usd, 2),
                'margin_usd' => round((float) $row->revenue_usd - (float) $row->cost_usd, 2),
            ])
            ->all();
    }

    /**
     * Users with the most failed payment attempts — half support radar
     * (a customer who cannot pay), half fraud radar (card testing).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mostFailedTransactions(?Carbon $since, int $limit): array
    {
        return DB::table('payment_attempts')
            ->join('users', 'payment_attempts.user_id', '=', 'users.id')
            ->tap(fn ($q) => $this->excludeGuests($q))
            ->where('payment_attempts.payment_status', 'failed')
            ->when($since, fn ($q) => $q->where('payment_attempts.created_at', '>=', $since))
            ->select(
                'payment_attempts.user_id',
                DB::raw('COUNT(*) as failures'),
                DB::raw('MAX(payment_attempts.created_at) as last_failed_at'),
            )
            ->groupBy('payment_attempts.user_id')
            ->orderByDesc('failures')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'failures' => (int) $row->failures,
                'last_failed_at' => (string) $row->last_failed_at,
            ])
            ->all();
    }

    /**
     * Current wallet balances summed per user across currencies, converted to
     * USD in PHP (multi-currency balances cannot rank honestly in SQL).
     *
     * @return array<int, array<string, mixed>>
     */
    private function highestWalletBalance(int $limit): array
    {
        $rows = DB::table('wallets')
            ->join('users', 'wallets.user_id', '=', 'users.id')
            ->tap(fn ($q) => $this->excludeGuests($q))
            ->where('wallets.balance', '>', 0)
            ->select('wallets.user_id', 'wallets.currency', DB::raw('SUM(wallets.balance) as balance'))
            ->groupBy('wallets.user_id', 'wallets.currency')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ratesPerUsd = CurrencyRate::query()->where('is_active', true)->pluck('rate_per_usd', 'code');
        $rcoinUsdRate = (float) Setting::rcoinUsdRate();

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row->user_id] = ($byUser[(int) $row->user_id] ?? 0.0)
                + $this->metrics->balanceToUsd((string) $row->currency, (float) $row->balance, $ratesPerUsd, $rcoinUsdRate);
        }

        arsort($byUser);

        return collect(array_slice($byUser, 0, $limit, true))
            ->map(fn (float $balance, int $userId) => [
                'user_id' => $userId,
                'balance_usd' => round($balance, 2),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function mostReferrals(?Carbon $since, int $limit): array
    {
        return DB::table('referrals')
            ->join('users', 'referrals.referrer_id', '=', 'users.id')
            ->tap(fn ($q) => $this->excludeGuests($q))
            ->when($since, fn ($q) => $q->where('referrals.created_at', '>=', $since))
            ->select(
                'referrals.referrer_id as user_id',
                DB::raw('COUNT(*) as referrals'),
                DB::raw('SUM(referrals.total_orders_completed) as referred_orders'),
            )
            ->groupBy('referrals.referrer_id')
            ->orderByDesc('referrals')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'referrals' => (int) $row->referrals,
                'referred_orders' => (int) $row->referred_orders,
            ])
            ->all();
    }

    /**
     * Units + revenue per user for a set of category slugs (gift cards,
     * airtime, eSIMs).
     *
     * @param  array<int, string>  $slugs
     * @return array<int, array<string, mixed>>
     */
    private function categoryBoard(array $slugs, ?Carbon $since, int $limit): array
    {
        return $this->purchasedItemsQuery($since)
            ->join('categories', 'order_items.category_id', '=', 'categories.id')
            ->whereIn('categories.slug', $slugs)
            ->select(
                'orders.user_id',
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM('.$this->metrics->itemUsdSql().') as revenue_usd'),
            )
            ->groupBy('orders.user_id')
            ->orderByDesc('units')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'units' => (int) $row->units,
                'revenue_usd' => round((float) $row->revenue_usd, 2),
            ])
            ->all();
    }

    /**
     * Base query: purchased orders (completed or partially completed) by real
     * (non-guest) users, optionally windowed on the order date.
     */
    private function purchasedOrdersQuery(?Carbon $since): Builder
    {
        return DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->tap(fn ($q) => $this->excludeGuests($q))
            ->whereIn('orders.order_status', self::PURCHASED_STATUSES)
            ->when($since, fn ($q) => $q->where('orders.created_at', '>=', $since));
    }

    /** Same base, one row per order item (for category / margin boards). */
    private function purchasedItemsQuery(?Carbon $since): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->tap(fn ($q) => $this->excludeGuests($q))
            ->whereIn('orders.order_status', self::PURCHASED_STATUSES)
            ->when($since, fn ($q) => $q->where('orders.created_at', '>=', $since));
    }

    /**
     * Guest checkout accounts are order vehicles, not customers — keep them
     * off every board. NULL-safe because rows predating the column keep NULL.
     */
    private function excludeGuests(Builder $query): void
    {
        $query->where(fn ($q) => $q->where('users.is_guest', false)->orWhereNull('users.is_guest'));
    }

    /**
     * Order-level USD conversion mirroring Order::usdTotal(): prefer the
     * settlement snapshot, fall back to total ÷ exchange-rate snapshot.
     */
    private function orderUsdSql(): string
    {
        $settlement = $this->metrics->jsonDecimalSql('orders.metadata', '$.settlement_total_usd');
        $rate = $this->metrics->jsonDecimalSql('orders.metadata', '$.exchange_rate');

        return "COALESCE(NULLIF({$settlement}, 0), orders.total_amount / COALESCE(NULLIF({$rate}, 0), 1))";
    }

    /**
     * Attach user identity (name, email, avatar) to every board row in one
     * query across all boards.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $boards
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function hydrateUsers(array $boards): array
    {
        $userIds = collect($boards)
            ->flatMap(fn (array $rows) => array_column($rows, 'user_id'))
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return $boards;
        }

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'avatar_url', 'created_at'])
            ->keyBy('id');

        foreach ($boards as $key => $rows) {
            $boards[$key] = array_values(array_filter(array_map(function (array $row) use ($users) {
                $user = $users->get($row['user_id']);
                if (! $user) {
                    return null;
                }

                $row['user'] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url ?: $user->initialsAvatar(),
                ];

                return $row;
            }, $rows)));
        }

        return $boards;
    }
}
