<?php

namespace App\Domain\Admin\Services;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\GiftCardTrade;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WalletFunding;
use App\Models\WalletTransaction;
use App\Support\FeatureFlag;
use Illuminate\Support\Str;

/**
 * Backs the admin header command bar. One query string is fanned out across
 * every admin-reachable record type (customers, orders, eSIMs, transactions,
 * wallet money, gift-card trades, catalogue, coupons) plus a static index of
 * admin pages, so the bar is a real "search the whole back office" surface
 * rather than the customers-and-products lookup it started as.
 *
 * Two rules hold every branch together:
 *
 * 1. Every branch is gated on {@see Admin::canAccessAdminRoute()} for the page
 *    it links to. Search must never surface a record an admin cannot open -
 *    otherwise the bar becomes a permission bypass that leaks customer names
 *    and order references to a Moderator who is blocked from those pages.
 * 2. Every row is normalised to the same shape (title / subtitle / meta /
 *    badge / url), so the dropdown renders one template instead of one per
 *    type. Type-specific keys are kept alongside it for existing consumers.
 */
class AdminGlobalSearchService
{
    /** Below this length the query matches too much to be useful. */
    private const MIN_QUERY_LENGTH = 2;

    /** Rows per record type, so no single type can crowd out the rest. */
    private const PER_GROUP_LIMIT = 5;

    /** Total rows returned across every type. */
    private const TOTAL_LIMIT = 24;

    /**
     * Admin pages the bar can jump to, so "settings" or "runbooks" navigates
     * instead of returning nothing. Keywords carry the synonyms an admin is
     * likely to type; the route name is also what the permission check uses.
     *
     * An optional `flag` key gates the entry behind a feature flag, so a page
     * hidden from the sidebar is not quietly reachable through search instead.
     *
     * @var array<int, array{label: string, route: string, params?: array<string, string>, group: string, keywords: string, flag?: string}>
     */
    private const PAGES = [
        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'group' => 'Overview', 'keywords' => 'home overview metrics revenue stats'],
        ['label' => 'Operations', 'route' => 'admin.operations', 'group' => 'Overview', 'keywords' => 'ops queue health status'],

        ['label' => 'All Products', 'route' => 'admin.products', 'group' => 'Catalogue', 'keywords' => 'catalogue catalog inventory skus variants'],
        ['label' => 'Products - Gift Cards', 'route' => 'admin.products', 'params' => ['category' => 'gift-cards'], 'group' => 'Catalogue', 'keywords' => 'giftcards vouchers'],
        ['label' => 'Products - eSIMs', 'route' => 'admin.products', 'params' => ['category' => 'esims'], 'group' => 'Catalogue', 'keywords' => 'esim sim data plans'],
        ['label' => 'Products - Mobile Top-ups', 'route' => 'admin.products', 'params' => ['category' => 'mobile-airtime'], 'group' => 'Catalogue', 'keywords' => 'airtime topup recharge'],
        ['label' => 'Products - Bill Payments', 'route' => 'admin.products', 'params' => ['category' => 'bill-payments'], 'group' => 'Catalogue', 'keywords' => 'bills utilities'],
        ['label' => 'Products - Flights', 'route' => 'admin.products', 'params' => ['category' => 'flights'], 'group' => 'Catalogue', 'keywords' => 'flight travel air', 'flag' => 'flights'],
        ['label' => 'Products - Stays', 'route' => 'admin.products', 'params' => ['category' => 'stays'], 'group' => 'Catalogue', 'keywords' => 'hotels accommodation booking', 'flag' => 'stays'],
        ['label' => 'Pricing Rules', 'route' => 'admin.pricing-rules', 'group' => 'Catalogue', 'keywords' => 'markup margin pricing'],
        ['label' => 'Rate Management', 'route' => 'admin.rates', 'group' => 'Catalogue', 'keywords' => 'fx exchange currency rates'],

        ['label' => 'Orders', 'route' => 'admin.orders', 'group' => 'Commerce', 'keywords' => 'purchases sales fulfillment'],
        ['label' => 'Customers', 'route' => 'admin.customers', 'group' => 'Commerce', 'keywords' => 'users buyers accounts kyc'],
        ['label' => 'Transactions', 'route' => 'admin.transactions', 'group' => 'Commerce', 'keywords' => 'payments attempts gateway charges'],
        ['label' => 'Wallets', 'route' => 'admin.wallets', 'group' => 'Commerce', 'keywords' => 'balances funding topup ledger'],
        ['label' => 'Payment Insights', 'route' => 'admin.payment-insights', 'group' => 'Commerce', 'keywords' => 'failures conversion gateway analytics'],

        ['label' => 'Gift Card Trades Queue', 'route' => 'admin.gift-cards.trades.index', 'group' => 'Gift Cards', 'keywords' => 'trades queue redemption sell'],
        ['label' => 'Gift Card Brands', 'route' => 'admin.gift-cards.brands', 'group' => 'Gift Cards', 'keywords' => 'brands amazon steam itunes'],
        ['label' => 'Gift Card Rates', 'route' => 'admin.gift-cards.rates', 'group' => 'Gift Cards', 'keywords' => 'rates payout percentage'],

        ['label' => 'Monitoring', 'route' => 'admin.management.monitoring', 'group' => 'Management', 'keywords' => 'health uptime queues horizon providers'],
        ['label' => 'Finances', 'route' => 'admin.management.finances', 'group' => 'Management', 'keywords' => 'profit revenue margin accounting'],
        ['label' => 'Leaderboards', 'route' => 'admin.management.leaderboards', 'group' => 'Management', 'keywords' => 'top customers ranking'],
        ['label' => 'Runbooks', 'route' => 'admin.management.runbooks', 'group' => 'Management', 'keywords' => 'incident playbook procedures'],
        ['label' => 'Reports', 'route' => 'admin.reports', 'group' => 'Management', 'keywords' => 'export csv analytics'],

        ['label' => 'Newsletter', 'route' => 'admin.newsletter', 'group' => 'Comms', 'keywords' => 'subscribers mailing email list'],
        ['label' => 'Push Campaigns', 'route' => 'admin.campaigns', 'group' => 'Comms', 'keywords' => 'push notifications broadcast'],
        ['label' => 'Notifications', 'route' => 'admin.notifications', 'group' => 'Comms', 'keywords' => 'alerts inbox deliveries'],
        ['label' => 'Support Tickets', 'route' => 'admin.support-tickets', 'group' => 'Comms', 'keywords' => 'help desk contact messages'],

        ['label' => 'Hero Sections', 'route' => 'admin.content.hero-sections', 'group' => 'Content', 'keywords' => 'homepage banner cms'],
        ['label' => 'Hero Cards', 'route' => 'admin.content.hero-cards', 'group' => 'Content', 'keywords' => 'homepage cards cms'],
        ['label' => 'Blog Posts', 'route' => 'admin.content.blog', 'group' => 'Content', 'keywords' => 'articles posts cms'],
        ['label' => 'Press Articles', 'route' => 'admin.content.press', 'group' => 'Content', 'keywords' => 'media news cms'],
        ['label' => 'Reviews', 'route' => 'admin.content.reviews', 'group' => 'Content', 'keywords' => 'testimonials ratings cms'],
        ['label' => 'FAQs', 'route' => 'admin.content.faqs', 'group' => 'Content', 'keywords' => 'questions help cms'],
        ['label' => 'Rcoin Rewards', 'route' => 'admin.content.rewards', 'group' => 'Content', 'keywords' => 'rcoin loyalty points'],
        ['label' => 'Rcoin Analytics', 'route' => 'admin.content.rewards.analytics', 'group' => 'Content', 'keywords' => 'rcoin analytics supply'],
        ['label' => 'Rcoin Withdrawals', 'route' => 'admin.content.rewards.withdrawals', 'group' => 'Content', 'keywords' => 'rcoin cashout withdrawals'],

        ['label' => 'Admins', 'route' => 'admin.admins', 'group' => 'System', 'keywords' => 'staff roles permissions team'],
        ['label' => 'System Settings', 'route' => 'admin.settings', 'group' => 'System', 'keywords' => 'config preferences maintenance'],
        ['label' => 'API & Integrations', 'route' => 'admin.api-settings', 'group' => 'System', 'keywords' => 'keys providers zendit airalo webhooks'],
        ['label' => 'My Account', 'route' => 'admin.account', 'group' => 'System', 'keywords' => 'profile password 2fa security'],
        ['label' => 'Account Activity', 'route' => 'admin.account-activity', 'group' => 'System', 'keywords' => 'audit log sessions history'],
    ];

    /**
     * Search every record type this admin may reach.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, Admin $admin): array
    {
        $q = trim($query);
        $isShort = mb_strlen($q) < self::MIN_QUERY_LENGTH;

        if ($isShort) {
            // A bare number is a precise customer-ID lookup, so a single digit
            // still means something - customers 1 through 9 are real accounts.
            // It is far too short to fan out as a LIKE across every table
            // though, so only the indexed ID branch runs for it.
            return $this->asNumericId($q) === null
                ? []
                : $this->rank($this->customers($q, $admin, idOnly: true));
        }

        $rows = array_merge(
            $this->customers($q, $admin),
            $this->orders($q, $admin),
            $this->esims($q, $admin),
            $this->transactions($q, $admin),
            $this->walletMoney($q, $admin),
            $this->trades($q, $admin),
            $this->products($q, $admin),
            $this->coupons($q, $admin),
            $this->pages($q, $admin),
        );

        return $this->rank($rows);
    }

    // ────────────────────────────────────────────────────────────
    //  Record branches
    // ────────────────────────────────────────────────────────────

    /**
     * Customers by name, email, phone, referral code, or their numeric ID -
     * "customer ID" in support conversations means the users.id the customer
     * page is keyed by, so a bare number has to resolve to that account.
     *
     * @param  bool  $idOnly  Match the numeric ID alone, skipping the fuzzy
     *                        LIKE clauses - used for queries too short to fan out.
     * @return array<int, array<string, mixed>>
     */
    private function customers(string $q, Admin $admin, bool $idOnly = false): array
    {
        if (! $admin->canAccessAdminRoute('admin.customer')) {
            return [];
        }

        $numericId = $this->asNumericId($q);

        return User::query()
            ->where(function ($builder) use ($q, $numericId, $idOnly) {
                if ($idOnly) {
                    $builder->where('id', $numericId);

                    return;
                }

                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('referral_code', 'like', "{$q}%");

                if ($numericId !== null) {
                    $builder->orWhere('id', $numericId);
                }
            })
            ->orderByDesc('id')
            ->limit(self::PER_GROUP_LIMIT)
            ->get(['id', 'name', 'email', 'phone', 'referral_code', 'avatar_url', 'is_guest'])
            ->map(function (User $user) use ($q, $numericId) {
                // An exact ID hit is a deliberate lookup, not a fuzzy name
                // brush - it outranks everything else the query touched.
                $score = $numericId !== null && (int) $user->id === $numericId
                    ? 130
                    : $this->score($q, [$user->name, $user->email, $user->phone, $user->referral_code], 10);

                return [
                    'type' => 'customer',
                    'group' => 'Customers',
                    'id' => 'c'.$user->id,
                    'title' => $user->name,
                    'subtitle' => trim($user->email.($user->phone ? ' · '.$user->phone : '')),
                    'meta' => 'ID '.$user->id,
                    'badge' => $user->is_guest ? 'Guest' : 'Customer',
                    'avatar' => $user->avatar_url ?: $user->initialsAvatar(),
                    'initials' => $this->initials($user->name),
                    'url' => route('admin.customer', $user),
                    'score' => $score,
                    // Retained for existing consumers of this endpoint.
                    'name' => $user->name,
                    'email' => $user->email,
                ];
            })
            ->all();
    }

    /**
     * Orders by order number, provider reference, or UUID prefix.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orders(string $q, Admin $admin): array
    {
        if (! $admin->canAccessAdminRoute('admin.order')) {
            return [];
        }

        return Order::query()
            ->with('user:id,name,email')
            ->where(function ($builder) use ($q) {
                $builder->where('order_number', 'like', "%{$q}%")
                    ->orWhere('provider_reference', 'like', "%{$q}%")
                    ->orWhere('id', 'like', "{$q}%");
            })
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->map(fn (Order $order) => [
                'type' => 'order',
                'group' => 'Orders',
                'id' => 'o'.$order->id,
                'title' => $order->order_number,
                'subtitle' => trim(($order->user?->name ?? 'Guest').' · '.str_replace('_', ' ', (string) ($order->order_status?->value ?? ''))),
                'meta' => $order->display_currency.' '.number_format((float) $order->total_amount, 2),
                'badge' => 'Order',
                'avatar' => null,
                'initials' => 'OR',
                'url' => route('admin.order', $order),
                'score' => $this->score($q, [$order->order_number, $order->provider_reference, $order->id], 20),
            ])
            ->all();
    }

    /**
     * eSIMs by ICCID. There is no eSIM table - the ICCID is written into the
     * order item's fulfillment_payload, under three different keys depending on
     * which provider delivered it, so all three paths are searched and the hit
     * links to the order the eSIM was sold on.
     *
     * @return array<int, array<string, mixed>>
     */
    private function esims(string $q, Admin $admin): array
    {
        if (! $admin->canAccessAdminRoute('admin.order')) {
            return [];
        }

        // ICCIDs are 19-20 digits; a two-character query would scan the whole
        // JSON column to match every eSIM ever sold. Require enough of the
        // number that the admin is genuinely looking one up.
        if (mb_strlen($q) < 4) {
            return [];
        }

        return OrderItem::query()
            ->with(['order:id,order_number,user_id,display_currency,total_amount', 'order.user:id,name'])
            ->where(function ($builder) use ($q) {
                $builder->where('fulfillment_payload->iccid', 'like', "%{$q}%")
                    ->orWhere('fulfillment_payload->esim->iccid', 'like', "%{$q}%")
                    ->orWhere('fulfillment_payload->esim_iccid', 'like', "%{$q}%");
            })
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->filter(fn (OrderItem $item) => $item->order !== null)
            ->map(function (OrderItem $item) use ($q) {
                $iccid = $this->iccidOf($item);

                return [
                    'type' => 'esim',
                    'group' => 'eSIMs',
                    'id' => 'e'.$item->id,
                    'title' => $iccid ?? 'eSIM',
                    'subtitle' => trim($item->order->order_number.' · '.($item->order->user?->name ?? 'Guest')),
                    'meta' => strtoupper((string) $item->provider_name),
                    'badge' => 'eSIM',
                    'avatar' => null,
                    'initials' => 'SM',
                    'url' => route('admin.order', $item->order),
                    'score' => $this->score($q, [$iccid], 40),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Payment attempts - the real transaction ledger - by any of the references
     * an admin might be handed while chasing one down: the gateway reference,
     * the idempotency key, the crypto purchase ID or pay address, or the
     * attempt's own UUID.
     *
     * payment_attempts.correlation_id is deliberately NOT searched: the column
     * exists and is indexed but nothing in the application ever writes it, so
     * including it would only add a clause that can never match.
     *
     * An attempt tied to an order links to that order (the richest view of it);
     * a wallet-funding attempt has no order page, so it falls back to the
     * transactions list pre-filtered to that reference.
     *
     * @return array<int, array<string, mixed>>
     */
    private function transactions(string $q, Admin $admin): array
    {
        if (! $admin->canAccessAdminRoute('admin.transactions')) {
            return [];
        }

        $canOpenOrders = $admin->canAccessAdminRoute('admin.order');

        return PaymentAttempt::query()
            ->with(['user:id,name,email', 'order:id,order_number'])
            ->where(function ($builder) use ($q) {
                $builder->where('gateway_reference', 'like', "%{$q}%")
                    ->orWhere('idempotency_key', 'like', "%{$q}%")
                    ->orWhere('purchase_id', 'like', "%{$q}%")
                    ->orWhere('pay_address', 'like', "%{$q}%")
                    ->orWhere('id', 'like', "{$q}%");
            })
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->map(function (PaymentAttempt $attempt) use ($q, $canOpenOrders) {
                $reference = $attempt->gateway_reference ?: ($attempt->idempotency_key ?: $attempt->id);
                $status = $attempt->payment_status?->value ?? 'pending';

                $url = $attempt->order && $canOpenOrders
                    ? route('admin.order', $attempt->order)
                    : route('admin.transactions', ['q' => $reference]);

                return [
                    'type' => 'transaction',
                    'group' => 'Transactions',
                    'id' => 't'.$attempt->id,
                    'title' => $reference,
                    'subtitle' => trim(($attempt->user?->name ?? 'Unknown customer').' · '.$attempt->gateway.' · '.$status),
                    'meta' => $attempt->currency.' '.number_format((float) $attempt->amount, 2),
                    'badge' => 'Transaction',
                    'avatar' => null,
                    'initials' => 'TX',
                    'url' => $url,
                    'score' => $this->score($q, [
                        $attempt->gateway_reference,
                        $attempt->idempotency_key,
                        $attempt->purchase_id,
                        $attempt->pay_address,
                        $attempt->id,
                    ], 30),
                ];
            })
            ->all();
    }

    /**
     * Wallet fundings (top-up attempts) and wallet ledger movements. Neither
     * has a detail page, so both land on the owning customer, whose page shows
     * the wallet balance and recent movements.
     *
     * @return array<int, array<string, mixed>>
     */
    private function walletMoney(string $q, Admin $admin): array
    {
        if (! $admin->canAccessAdminRoute('admin.wallets')) {
            return [];
        }

        $canOpenCustomers = $admin->canAccessAdminRoute('admin.customer');
        $walletsUrl = route('admin.wallets');

        $fundings = WalletFunding::query()
            ->with('user:id,name,email')
            ->where(function ($builder) use ($q) {
                $builder->where('reference', 'like', "%{$q}%")
                    ->orWhere('gateway_reference', 'like', "%{$q}%")
                    ->orWhere('idempotency_key', 'like', "%{$q}%");
            })
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->map(fn (WalletFunding $funding) => [
                'type' => 'funding',
                'group' => 'Wallet Fundings',
                'id' => 'f'.$funding->id,
                'title' => $funding->reference ?: ('Funding #'.$funding->id),
                'subtitle' => trim(($funding->user?->name ?? 'Unknown customer').' · '.$funding->gateway.' · '.($funding->status?->value ?? '')),
                'meta' => ($funding->currency?->value ?? '').' '.number_format((float) $funding->amount, 2),
                'badge' => 'Funding',
                'avatar' => null,
                'initials' => 'WF',
                'url' => $canOpenCustomers && $funding->user
                    ? route('admin.customer', $funding->user)
                    : $walletsUrl,
                'score' => $this->score($q, [$funding->reference, $funding->gateway_reference, $funding->idempotency_key], 25),
            ])
            ->all();

        $movements = WalletTransaction::query()
            ->with('user:id,name,email')
            ->where(function ($builder) use ($q) {
                $builder->where('reference', 'like', "%{$q}%")
                    ->orWhere('idempotency_key', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            })
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->map(function (WalletTransaction $movement) use ($q, $canOpenCustomers, $walletsUrl) {
                $category = $movement->transaction_category?->value ?? (string) $movement->transaction_category;

                return [
                    'type' => 'wallet_transaction',
                    'group' => 'Wallet Ledger',
                    'id' => 'w'.$movement->id,
                    'title' => $movement->reference ?: ($movement->description ?: 'Wallet movement #'.$movement->id),
                    'subtitle' => trim(($movement->user?->name ?? 'Unknown customer').' · '.$category),
                    'meta' => ($movement->currency?->value ?? $movement->currency).' '.number_format((float) $movement->amount, 2),
                    'badge' => 'Wallet',
                    'avatar' => null,
                    'initials' => 'WL',
                    'url' => $canOpenCustomers && $movement->user
                        ? route('admin.customer', $movement->user)
                        : $walletsUrl,
                    'score' => $this->score($q, [$movement->reference, $movement->idempotency_key, $movement->description], 20),
                ];
            })
            ->all();

        return array_merge($fundings, $movements);
    }

    /**
     * Gift-card trades by UUID, WhatsApp number, or the trader's name / email.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trades(string $q, Admin $admin): array
    {
        if (! $admin->canAccessAdminRoute('admin.gift-cards.trades.show')) {
            return [];
        }

        return GiftCardTrade::query()
            ->with('user:id,name,email')
            ->where(function ($builder) use ($q) {
                $builder->where('uuid', 'like', "{$q}%")
                    ->orWhere('whatsapp_number', 'like', "%{$q}%")
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            })
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->map(fn (GiftCardTrade $trade) => [
                'type' => 'trade',
                'group' => 'Gift Card Trades',
                'id' => 'g'.$trade->id,
                'title' => 'Trade #'.$trade->id,
                'subtitle' => trim(($trade->user?->name ?? 'Unknown trader').' · '.str_replace('_', ' ', (string) ($trade->status?->value ?? ''))),
                'meta' => $trade->payout_currency.' '.number_format((float) $trade->calculated_payout, 2),
                'badge' => 'Trade',
                'avatar' => null,
                'initials' => 'GC',
                'url' => route('admin.gift-cards.trades.show', $trade),
                'score' => $this->score($q, [$trade->uuid, $trade->whatsapp_number, $trade->user?->name, $trade->user?->email], 15),
            ])
            ->all();
    }

    /**
     * Catalogue variants - the bar's original behaviour, kept intact.
     *
     * @return array<int, array<string, mixed>>
     */
    private function products(string $q, Admin $admin): array
    {
        if (! $admin->canAccessAdminRoute('admin.products')) {
            return [];
        }

        return ProductVariant::query()
            ->select('product_variants.id', 'product_variants.product_id', 'product_variants.sku', 'product_variants.cost_price')
            ->with(['product:id,name,brand_key,country_code,category_id,logo_url', 'product.category:id,name'])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where(function ($builder) use ($q) {
                $builder->where('products.name', 'like', "%{$q}%")
                    ->orWhere('products.brand_key', 'like', "%{$q}%")
                    ->orWhere('products.country_code', 'like', strtoupper($q).'%')
                    ->orWhere('product_variants.sku', 'like', "%{$q}%");
            })
            ->orderByDesc('product_variants.id')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->map(function (ProductVariant $variant) use ($q) {
                $product = $variant->product;
                $brand = $product?->brand_key
                    ? Product::brandDisplayName($product->brand_key)
                    : ($product?->name ?? 'Unknown');

                $subtitle = collect([$product?->category?->name, $product?->country_code, $variant->sku])
                    ->filter()
                    ->implode(' · ');

                return [
                    'type' => 'product',
                    'group' => 'Products',
                    'id' => 'p'.$variant->id,
                    'title' => $brand ?: ($product?->name ?? $variant->sku),
                    'subtitle' => $subtitle,
                    'meta' => 'Cost USD '.number_format((float) $variant->cost_price, 2),
                    'badge' => 'Product',
                    'avatar' => $product ? Product::brandLogoUrl($product->brand_key, $product->logo_url) : null,
                    'initials' => $this->initials($brand),
                    'url' => route('admin.products').'?q='.urlencode($product?->name ?? (string) $variant->sku),
                    'score' => $this->score($q, [$product?->name, $product?->brand_key, $variant->sku], 0),
                    // Retained for existing consumers of this endpoint.
                    'name' => $product?->name,
                    'brand' => $brand,
                    'logo' => $product ? Product::brandLogoUrl($product->brand_key, $product->logo_url) : null,
                    'country' => $product?->country_code,
                    'category' => $product?->category?->name,
                    'sku' => $variant->sku,
                    'cost' => (float) $variant->cost_price,
                ];
            })
            ->all();
    }

    /**
     * Coupons by code. There is no coupon page of its own - they are managed
     * from the variant they belong to, so the hit opens the products list
     * filtered to that variant's product.
     *
     * @return array<int, array<string, mixed>>
     */
    private function coupons(string $q, Admin $admin): array
    {
        if (! $admin->canAccessAdminRoute('admin.products')) {
            return [];
        }

        return Coupon::query()
            ->with('variant.product:id,name')
            ->where('code', 'like', "%{$q}%")
            ->orderByDesc('id')
            ->limit(self::PER_GROUP_LIMIT)
            ->get()
            ->map(function (Coupon $coupon) use ($q) {
                $productName = $coupon->variant?->product?->name;
                $discount = $coupon->discount_type === 'percent'
                    ? rtrim(rtrim(number_format((float) $coupon->discount_value, 2), '0'), '.').'%'
                    : 'USD '.number_format((float) $coupon->discount_value, 2);

                return [
                    'type' => 'coupon',
                    'group' => 'Coupons',
                    'id' => 'k'.$coupon->id,
                    'title' => $coupon->code,
                    'subtitle' => trim(($productName ?? 'Unlinked variant').' · '.$coupon->used_count.'/'.($coupon->max_uses ?: '∞').' used'),
                    'meta' => $discount.' off',
                    'badge' => $coupon->is_active ? 'Coupon' : 'Inactive',
                    'avatar' => null,
                    'initials' => 'CP',
                    'url' => route('admin.products').'?q='.urlencode($productName ?? $coupon->code),
                    'score' => $this->score($q, [$coupon->code], 25),
                ];
            })
            ->all();
    }

    /**
     * Static admin pages, filtered to the ones this admin may open.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pages(string $q, Admin $admin): array
    {
        $rows = [];

        foreach (self::PAGES as $index => $page) {
            if (! $admin->canAccessAdminRoute($page['route'])) {
                continue;
            }

            // Fail closed, matching the sidebar: a missing flag row hides the
            // entry rather than exposing a page nobody has switched on yet.
            if (isset($page['flag']) && FeatureFlag::off($page['flag'], false)) {
                continue;
            }

            // Keywords only ever confirm a page the label already hints at, so
            // they score lower than a label hit and never outrank a record.
            $labelScore = $this->score($q, [$page['label']], 0);
            $keywordScore = $this->keywordScore($q, $page['keywords']);
            $score = max($labelScore, $keywordScore);

            if ($score === 0) {
                continue;
            }

            $rows[] = [
                'type' => 'page',
                'group' => 'Pages',
                'id' => 'n'.$index,
                'title' => $page['label'],
                'subtitle' => $page['group'],
                'meta' => null,
                'badge' => 'Page',
                'avatar' => null,
                'initials' => $this->initials($page['label']),
                'url' => route($page['route'], $page['params'] ?? []),
                'score' => $score + 5,
            ];
        }

        return array_slice($rows, 0, self::PER_GROUP_LIMIT + 3);
    }

    // ────────────────────────────────────────────────────────────
    //  Ranking helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Order rows so the strongest match leads, keeping each type's rows
     * together: groups are sorted by their best row, rows sorted within.
     * The dropdown then renders contiguous, self-labelling sections.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $rows): array
    {
        $grouped = collect($rows)
            ->filter(fn (array $row) => ($row['score'] ?? 0) > 0)
            ->groupBy('group')
            ->map(fn ($group) => $group->sortByDesc('score')->values())
            ->sortByDesc(fn ($group) => $group->max('score'));

        return $grouped
            ->flatten(1)
            ->take(self::TOTAL_LIMIT)
            ->map(function (array $row) {
                unset($row['score']);

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Best match strength of the query against any of the given values. An
     * exact hit beats a prefix, which beats a substring; $base lifts a whole
     * record type so an identifier match sorts above a fuzzy name match.
     *
     * @param  array<int, mixed>  $values
     */
    private function score(string $query, array $values, int $base = 0): int
    {
        $needle = mb_strtolower($query);
        $best = 0;

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $haystack = mb_strtolower((string) $value);

            $best = max($best, match (true) {
                $haystack === $needle => 100,
                str_starts_with($haystack, $needle) => 70,
                str_contains($haystack, $needle) => 40,
                default => 0,
            });
        }

        return $best === 0 ? 0 : $best + $base;
    }

    /**
     * Match the query against a page's keyword list, where each keyword is a
     * whole word: "sim" should reach the eSIM page without "settings" matching
     * every query that happens to contain "set".
     */
    private function keywordScore(string $query, string $keywords): int
    {
        $needle = mb_strtolower(trim($query));

        foreach (explode(' ', mb_strtolower($keywords)) as $keyword) {
            if ($keyword === '') {
                continue;
            }

            if ($keyword === $needle) {
                return 60;
            }

            if (str_starts_with($keyword, $needle)) {
                return 35;
            }
        }

        return 0;
    }

    /**
     * A bare number is only treated as a customer ID when it is short enough to
     * plausibly be one - a long digit run is an ICCID or a phone number.
     */
    private function asNumericId(string $query): ?int
    {
        return ctype_digit($query) && mb_strlen($query) <= 12 ? (int) $query : null;
    }

    /**
     * Pull the ICCID out of an order item, covering the three payload shapes
     * the fulfillment providers write.
     */
    private function iccidOf(OrderItem $item): ?string
    {
        $payload = $item->fulfillment_payload ?? [];

        $iccid = $payload['iccid']
            ?? $payload['esim']['iccid']
            ?? $payload['esim_iccid']
            ?? null;

        return $iccid !== null ? (string) $iccid : null;
    }

    /**
     * Two-character fallback chip for rows with no image.
     */
    private function initials(?string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', (string) $value);

        return $clean === '' ? '?' : Str::upper(Str::substr($clean, 0, 2));
    }
}
