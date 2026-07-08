<?php

use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminCommerceController;
use App\Http\Controllers\Admin\AdminCustomerController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFintechController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\AdminReportExportController;
use App\Http\Controllers\Admin\AdminRewardAnalyticsController;
use App\Http\Controllers\Admin\AdminRewardSettingsController;
use App\Http\Controllers\Admin\AdminSreController;
use App\Http\Controllers\Admin\AdminTransactionExportController;
use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\Auth\AdminTwoFactorController;
use App\Http\Controllers\Admin\NotificationAdminApiController;
use App\Http\Controllers\ThemeController;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::prefix('admin')->name('admin.')->group(function () {
    // Guest admin routes (login). The login URI is a non-obvious slug
    // (admin/rshopadmins) to keep bots off the obvious /admin/login path;
    // the route NAME stays `admin.login` so every route('admin.login') still
    // resolves. This is obscurity on top of - not instead of - auth + 2FA.
    Route::middleware('guest:admin')->group(function () {
        Route::get('rshopadmins', [AdminLoginController::class, 'create'])->name('login');
        Route::post('rshopadmins', [AdminLoginController::class, 'store']);

        Route::get('2fa', [AdminTwoFactorController::class, 'create'])->name('2fa.challenge');
        Route::post('2fa', [AdminTwoFactorController::class, 'store'])->name('2fa.verify');
        Route::post('2fa/resend', [AdminTwoFactorController::class, 'resend'])->name('2fa.resend');
    });

    // Authenticated admin routes
    Route::middleware('admin')->group(function () {
        Route::view('dashboard', 'admin.dashboard')->name('dashboard');
        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');

        // Persists the admin's own light/dark/system preference, kept separate
        // from the customer side (different table + guard).
        Route::post('theme', [ThemeController::class, 'updateAdmin'])->name('theme');

        // Admin content views - read-only Blade pages backed by shipped models.
        // Replace with controllers when CRUD/actions ship.
        Route::view('products', 'admin.products')->name('products');
        // Live-search suggestions for the products page. Returns up to 8
        // variants matching the query (name + SKU + country) as JSON.
        Route::get('products/search-suggest', function (Request $request) {
            $q = trim((string) $request->query('q', ''));
            if (mb_strlen($q) < 2) {
                return response()->json([]);
            }

            // Include product_id in the select - without it Eloquent can't
            // load the `product` relation and every row comes back with null
            // brand / name / country.
            $variants = ProductVariant::query()
                ->select('product_variants.id', 'product_variants.product_id', 'product_variants.sku', 'product_variants.cost_price', 'product_variants.retail_price', 'product_variants.face_value', 'product_variants.currency')
                ->with(['product:id,name,brand_key,country_code,category_id,provider_name,logo_url', 'product.category:id,name'])
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where(function ($qq) use ($q) {
                    $qq->where('products.name', 'like', "%{$q}%")
                        ->orWhere('products.brand_key', 'like', "%{$q}%")
                        ->orWhere('products.country_code', 'like', strtoupper($q).'%')
                        ->orWhere('product_variants.sku', 'like', "%{$q}%");
                })
                ->orderByDesc('product_variants.id')
                ->limit(8)
                ->get();

            return response()->json($variants->map(function ($v) {
                $product = $v->product;
                $brand = $product?->brand_key
                    ? Product::brandDisplayName($product->brand_key)
                    : ($product?->name ?? 'Unknown');

                return [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'name' => $product?->name,
                    'brand' => $brand,
                    'logo' => $product ? Product::brandLogoUrl($product->brand_key, $product->logo_url) : null,
                    'country' => $product?->country_code,
                    'category' => $product?->category?->name,
                    'provider' => $product?->provider_name,
                    'cost' => (float) $v->cost_price,
                    'retail' => (float) $v->retail_price,
                ];
            }));
        })->name('products.search-suggest');

        // Global command-bar search for the admin header. Unlike products/search-
        // suggest (catalogue only), this powers the bar's "products, orders,
        // customers" promise: it returns TYPED rows (customer + product), each
        // with a ready-made `url`, so a customer hit links straight to that
        // customer's page instead of dead-ending on the products list.
        Route::get('search-suggest', function (Request $request) {
            $q = trim((string) $request->query('q', ''));
            if (mb_strlen($q) < 2) {
                return response()->json([]);
            }

            // Customers by name or email - the most common admin lookup, shown first.
            $customers = User::query()
                ->where(function ($qq) use ($q) {
                    $qq->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                })
                ->orderByDesc('id')
                ->limit(6)
                ->get(['id', 'name', 'email', 'avatar_url'])
                ->map(fn (User $user) => [
                    'type' => 'customer',
                    'id' => 'c'.$user->id, // prefixed so it never collides with a product id (Alpine :key)
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url ?: $user->initialsAvatar(),
                    'url' => route('admin.customer', $user),
                ]);

            // Products - same matching as products/search-suggest so the bar keeps
            // its existing catalogue search alongside customers.
            $products = ProductVariant::query()
                ->select('product_variants.id', 'product_variants.product_id', 'product_variants.sku', 'product_variants.cost_price')
                ->with(['product:id,name,brand_key,country_code,category_id,logo_url', 'product.category:id,name'])
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where(function ($qq) use ($q) {
                    $qq->where('products.name', 'like', "%{$q}%")
                        ->orWhere('products.brand_key', 'like', "%{$q}%")
                        ->orWhere('products.country_code', 'like', strtoupper($q).'%')
                        ->orWhere('product_variants.sku', 'like', "%{$q}%");
                })
                ->orderByDesc('product_variants.id')
                ->limit(6)
                ->get()
                ->map(function ($variant) {
                    $product = $variant->product;
                    $brand = $product?->brand_key
                        ? Product::brandDisplayName($product->brand_key)
                        : ($product?->name ?? 'Unknown');

                    return [
                        'type' => 'product',
                        'id' => 'p'.$variant->id,
                        'name' => $product?->name,
                        'brand' => $brand,
                        'logo' => $product ? Product::brandLogoUrl($product->brand_key, $product->logo_url) : null,
                        'country' => $product?->country_code,
                        'category' => $product?->category?->name,
                        'sku' => $variant->sku,
                        'cost' => (float) $variant->cost_price,
                        'url' => route('admin.products').'?q='.urlencode($product?->name ?? (string) $variant->sku),
                    ];
                });

            return response()->json($customers->concat($products)->values());
        })->name('search-suggest');

        // Gift Card Trading Routes
        Volt::route('gift-cards/trades', 'admin.gift-cards.trades.index')->name('gift-cards.trades.index');
        Volt::route('gift-cards/trades/{trade}', 'admin.gift-cards.trades.show')->name('gift-cards.trades.show');
        Volt::route('gift-cards/trades/{trade}/receipt', 'admin.gift-cards.trades.receipt')->name('gift-cards.trades.receipt');
        Volt::route('gift-cards/brands', 'admin.gift-cards.brands')->name('gift-cards.brands');
        Volt::route('gift-cards/rates', 'admin.gift-cards.rates')->name('gift-cards.rates');

        Route::view('orders', 'admin.orders')->name('orders');
        Route::get('orders/{order}', function (Order $order) {
            return view('admin.order', [
                'order' => $order->load(['user', 'items', 'paymentAttempts']),
            ]);
        })->name('order');
        Route::view('customers', 'admin.customers')->name('customers');
        Route::get('customers/{user}', function (User $user) {
            $user->load([
                'wallets',
                'orders' => fn ($query) => $query->latest()->limit(10),
                'walletTransactions' => fn ($query) => $query->latest()->limit(10),
            ]);

            // Total spent must be the USD settlement sum, NOT raw total_amount:
            // total_amount is stored in each order's display currency, so summing
            // it directly and labelling it USD mixes currencies (a XAF order's
            // 4000 would read as $4000). usdTotal() reads the settlement figure.
            $totalSpentUsd = $user->orders()
                ->whereIn('order_status', ['completed', 'partially_completed'])
                ->get(['id', 'total_amount', 'metadata'])
                ->sum(fn ($order) => $order->usdTotal());

            return view('admin.customer', [
                'user' => $user,
                'ordersCount' => $user->orders()->count(),
                'totalSpent' => (float) $totalSpentUsd,
                'unreadNotifications' => $user->notifications()->whereNull('read_at')->count(),
                'kyc' => $user->kycSubmissions()->first(),
            ]);
        })->name('customer');

        // KYC review - serve documents from the private disk + approve / reject.
        Route::get('kyc/{submission}/document/{type}', [AdminKycController::class, 'document'])->name('kyc.document');
        Route::post('kyc/{submission}/approve', [AdminKycController::class, 'approve'])->name('kyc.approve');
        Route::post('kyc/{submission}/reject', [AdminKycController::class, 'reject'])->name('kyc.reject');

        // Customer admin actions: edit profile, ban/unban, hold/release funds,
        // and send a direct (email + dashboard) message.
        Route::patch('customers/{user}', [AdminCustomerController::class, 'update'])->name('customer.update');
        Route::post('customers/{user}/ban', [AdminCustomerController::class, 'toggleBan'])->name('customer.ban');
        Route::post('customers/{user}/suspend', [AdminCustomerController::class, 'toggleSuspend'])->name('customer.suspend');
        Route::post('customers/{user}/funds', [AdminCustomerController::class, 'toggleFunds'])->name('customer.funds');
        Route::post('customers/{user}/verify-email', [AdminCustomerController::class, 'toggleEmailVerification'])->name('customer.verify-email');
        Route::post('customers/{user}/kyc-status', [AdminCustomerController::class, 'setKycStatus'])->name('customer.kyc-status');
        Route::post('customers/{user}/rcoin-multiplier', [AdminCustomerController::class, 'setRcoinMultiplier'])->name('customer.rcoin-multiplier');
        Route::post('customers/{user}/wallet-adjust', [AdminCustomerController::class, 'adjustWalletBalance'])->name('customer.wallet-adjust');
        Route::post('customers/{user}/message', [AdminCustomerController::class, 'message'])->name('customer.message');
        Route::post('customers/{user}/reset-pin', [AdminCustomerController::class, 'resetTransactionPin'])->name('customer.reset-pin');
        Route::post('customers/{user}/password-reset', [AdminCustomerController::class, 'sendPasswordReset'])->name('customer.password-reset');
        Route::post('customers/{user}/login-as', [AdminCustomerController::class, 'loginAsCustomer'])->name('customer.login-as');
        // Friendly fallback: a GET on the same path (URL bar, back-button, link
        // prefetch) redirects to the customer page instead of 405ing.
        Route::get('customers/{user}/message', fn (User $user) => redirect()->route('admin.customer', $user));
        Route::view('transactions', 'admin.transactions')->name('transactions');
        Route::get('transactions/export.csv', [AdminTransactionExportController::class, 'csv'])->name('transactions.export');
        Route::view('wallets', 'admin.wallets')->name('wallets');
        Volt::route('rates', 'admin.rates')->name('rates');
        Volt::route('account', 'admin.account')->name('account');
        Volt::route('notifications', 'admin.notifications')->name('notifications');
        Volt::route('reports', 'admin.reports')->name('reports');
        Route::get('reports/export.csv', [AdminReportExportController::class, 'csv'])->name('reports.export');

        Volt::route('support-tickets', 'admin.support-tickets')->name('support-tickets');
        Volt::route('admins', 'admin.admins')->name('admins');
        Volt::route('pricing-rules', 'admin.pricing-rules')->name('pricing-rules');
        Volt::route('newsletter', 'admin.newsletter')->name('newsletter');
        Volt::route('campaigns', 'admin.campaigns')->name('campaigns');
        Volt::route('campaigns/create', 'admin.campaign-editor')->name('campaign-editor');
        Volt::route('campaigns/{id}/edit', 'admin.campaign-editor')->name('campaign-editor.edit');
        Volt::route('settings', 'admin.system-settings')->name('settings');

        // API & Integrations
        Volt::route('api-settings', 'admin.api-settings')->name('api-settings');

        // Crypto Fees
        Volt::route('crypto-fees', 'admin.crypto-fees')->name('crypto-fees');

        Volt::route('account-activity', 'admin.account-activity')->name('account-activity');

        // Content (CMS) - stub Volt pages that list what's in the DB today.
        // CRUD UI lands in a follow-up; for now editors confirm data is wired.
        Route::prefix('content')->name('content.')->group(function () {
            Volt::route('hero-sections', 'admin.content.hero-sections')->name('hero-sections');
            Volt::route('hero-cards', 'admin.content.hero-cards')->name('hero-cards');
            Volt::route('blog', 'admin.content.blog')->name('blog');
            Volt::route('press', 'admin.content.press')->name('press');
            Volt::route('reviews', 'admin.content.reviews')->name('reviews');
            Volt::route('faqs', 'admin.content.faqs')->name('faqs');
            Volt::route('rewards', 'admin.content.rewards')->name('rewards');
            Volt::route('rewards/analytics', 'admin.content.rewards-analytics')->name('rewards.analytics');
            Volt::route('rewards/withdrawals', 'admin.content.rewards-withdrawals')->name('rewards.withdrawals');
        });

        // Admin Dashboard API
        Route::prefix('api/dashboard')->name('api.dashboard.')->group(function () {
            Route::get('overview', [AdminDashboardController::class, 'overview'])->name('overview');
            Route::get('revenue-chart', [AdminDashboardController::class, 'revenueChart'])->name('revenue-chart');
            Route::get('latest-users', [AdminDashboardController::class, 'latestUsers'])->name('latest-users');
            Route::get('latest-transactions', [AdminDashboardController::class, 'latestTransactions'])->name('latest-transactions');
        });

        // Admin Fintech Monitoring API
        Route::prefix('api/monitoring')->name('api.monitoring.')->group(function () {
            Route::get('transactions', [AdminFintechController::class, 'transactions'])->name('transactions');
            Route::get('fundings', [AdminFintechController::class, 'fundings'])->name('fundings');
            Route::get('wallets', [AdminFintechController::class, 'wallets'])->name('wallets');

            // Hardened Wallet Funding & Financial Operations Extensions
            Route::get('payment-attempts', [AdminFintechController::class, 'paymentAttempts'])->name('payment-attempts');
            Route::get('payment-webhooks', [AdminFintechController::class, 'paymentWebhooks'])->name('payment-webhooks');
            Route::get('reconciliation/pending', [AdminFintechController::class, 'pendingReconciliations'])->name('reconciliation.pending');
            Route::post('reconciliation/{id}/retry', [AdminFintechController::class, 'retryReconciliation'])->name('reconciliation.retry');
            Route::get('metrics', [AdminFintechController::class, 'metrics'])->name('metrics');
        });

        // Admin SRE & Enterprise Operations API
        Route::prefix('api/sre')->name('api.sre.')->group(function () {
            Route::get('audit-logs', [AdminSreController::class, 'auditLogs'])->name('audit-logs');
            Route::get('ledger-events', [AdminSreController::class, 'ledgerEvents'])->name('ledger-events');
            Route::get('reconciliation-reports', [AdminSreController::class, 'reconciliationReports'])->name('reconciliation-reports');
            Route::get('system-metrics', [AdminSreController::class, 'systemMetrics'])->name('system-metrics');
        });

        // Admin Commerce Monitoring & Actions API
        Route::prefix('api/commerce')->name('api.commerce.')->group(function () {
            Route::get('orders', [AdminCommerceController::class, 'listOrders'])->name('orders');
            Route::get('orders/export', [AdminCommerceController::class, 'exportOrders'])->name('orders.export');
            Route::get('payments', [AdminCommerceController::class, 'listPayments'])->name('payments');
            Route::get('fulfillments', [AdminCommerceController::class, 'listFulfillmentLogs'])->name('fulfillments');
            Route::post('orders/{itemId}/retry-fulfillment', [AdminCommerceController::class, 'retryFulfillment'])->name('retry-fulfillment');
            Route::post('orders/{itemId}/mark-manually-fulfilled', [AdminCommerceController::class, 'markItemManuallyFulfilled'])->name('mark-manually-fulfilled');
            Route::post('orders/{orderId}/refund', [AdminCommerceController::class, 'refundOrder'])->name('refund');
        });

        // Admin Catalog API
        Route::prefix('api/catalog')->name('api.catalog.')->group(function () {
            Route::post('sync/zendit', [AdminCatalogController::class, 'syncZendit'])->name('sync.zendit');
            Route::post('sync/zendit-esims', [AdminCatalogController::class, 'syncZenditEsims'])->name('sync.zendit-esims');
            Route::post('sync/zendit-topups', [AdminCatalogController::class, 'syncZenditTopups'])->name('sync.zendit-topups');
            Route::get('products', [AdminCatalogController::class, 'products'])->name('products');
            Route::patch('products/{product}/toggle-active', [AdminCatalogController::class, 'toggleActive']);
            Route::patch('products/{product}/out-of-stock', [AdminCatalogController::class, 'setOutOfStock'])->name('products.out-of-stock');
            Route::patch('products/{product}/toggle-featured', [AdminCatalogController::class, 'toggleFeatured']);
            Route::patch('products/{product}/toggle-popular', [AdminCatalogController::class, 'togglePopular']);

            // Per-variant sales-price override + coupon management. All keyed
            // on a ProductVariant - the drawer is variant-level.
            Route::patch('variants/{variant}/price', [AdminCatalogController::class, 'setVariantPrice'])->name('variants.price.set');
            Route::delete('variants/{variant}/price', [AdminCatalogController::class, 'clearVariantPrice'])->name('variants.price.clear');
            Route::patch('variants/{variant}/availability', [AdminCatalogController::class, 'setVariantAvailability'])->name('variants.availability');
            Route::patch('products/{product}/markup', [AdminCatalogController::class, 'setProductMarkup'])->name('products.markup.set');
            Route::delete('products/{product}/markup', [AdminCatalogController::class, 'clearProductMarkup'])->name('products.markup.clear');
            Route::get('variants/{variant}/coupons', [AdminCatalogController::class, 'listCoupons'])->name('variants.coupons.index');
            Route::post('variants/{variant}/coupons', [AdminCatalogController::class, 'createCoupon'])->name('variants.coupons.create');
            Route::delete('coupons/{coupon}', [AdminCatalogController::class, 'deleteCoupon'])->name('coupons.delete');
        });

        // Admin Notifications & Compliance API
        Route::prefix('api/notifications')->name('api.notifications.')->group(function () {
            Route::get('/', [NotificationAdminApiController::class, 'index'])->name('index');
            Route::get('deliveries', [NotificationAdminApiController::class, 'deliveries'])->name('deliveries');
            Route::get('metrics', [NotificationAdminApiController::class, 'metrics'])->name('metrics');
            Route::post('{id}/retry', [NotificationAdminApiController::class, 'retry'])->name('retry');
        });
        // Admin Rewards API
        Route::prefix('api/rewards')->name('api.rewards.')->group(function () {
            Route::get('settings', [AdminRewardSettingsController::class, 'index'])->name('settings.index');
            Route::put('settings', [AdminRewardSettingsController::class, 'update'])->name('settings.update');
            Route::get('analytics/metrics', [AdminRewardAnalyticsController::class, 'metrics'])->name('analytics.metrics');
        });
    });
});
