<?php

use App\Domain\Admin\Services\AudienceInsightsService;
use App\Http\Controllers\Admin\AdminApiDocsController;
use App\Http\Controllers\Admin\AdminCatalogController;
use App\Http\Controllers\Admin\AdminCommerceController;
use App\Http\Controllers\Admin\AdminCustomerController;
use App\Http\Controllers\Admin\AdminCustomerExportController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFintechController;
use App\Http\Controllers\Admin\AdminKycController;
use App\Http\Controllers\Admin\AdminOrderReportController;
use App\Http\Controllers\Admin\AdminPaymentBackoffController;
use App\Http\Controllers\Admin\AdminReportExportController;
use App\Http\Controllers\Admin\AdminRewardAnalyticsController;
use App\Http\Controllers\Admin\AdminRewardSettingsController;
use App\Http\Controllers\Admin\AdminRiskReviewController;
use App\Http\Controllers\Admin\AdminRunbookDocController;
use App\Http\Controllers\Admin\AdminSearchController;
use App\Http\Controllers\Admin\AdminSreController;
use App\Http\Controllers\Admin\AdminTransactionController;
use App\Http\Controllers\Admin\AdminTransactionExportController;
use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\Auth\AdminSecurityQuestionController;
use App\Http\Controllers\Admin\Auth\AdminTwoFactorController;
use App\Http\Controllers\Admin\NotificationAdminApiController;
use App\Http\Controllers\ThemeController;
use App\Http\Middleware\AdminInactivityTimeout;
use App\Models\Admin;
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

        Route::get('security-question', [AdminSecurityQuestionController::class, 'create'])->name('security-question');
        Route::post('security-question', [AdminSecurityQuestionController::class, 'store'])->name('security-question.verify');
    });

    // Authenticated admin routes
    Route::middleware(['admin', AdminInactivityTimeout::class])->group(function () {
        // Core Dashboard & Operations
        Route::view('dashboard', 'admin.dashboard')->name('dashboard');
        Route::view('operations', 'admin.operations')->name('operations');

        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');

        // Impersonation
        Route::post('revert-admin-impersonation', function () {
            if (! session()->has('impersonator_admin_id')) {
                return redirect()->route('admin.dashboard');
            }
            $superAdmin = Admin::find(session()->pull('impersonator_admin_id'));
            if ($superAdmin && $superAdmin->isSuperAdmin()) {
                auth()->guard('admin')->login($superAdmin);
            }

            return redirect()->route('admin.admins')->with('status', 'Returned to Super Admin account.');
        })->name('revert-impersonation');

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
        // suggest (catalogue only), this searches the whole back office -
        // customers, orders, eSIMs by ICCID, transactions, wallet money,
        // catalogue, coupons, and the admin pages themselves -
        // returning TYPED rows with a ready-made `url` so every hit links
        // straight to the page that shows it. Every branch is permission-gated
        // inside the service, so the bar can never surface a record the signed-in
        // admin is blocked from opening.
        Route::get('search-suggest', [AdminSearchController::class, 'suggest'])->name('search-suggest');

        Route::view('orders', 'admin.orders')->name('orders');
        // Manage eSIMs: every eSIM sold, its live status and data, and the
        // refund for one never activated. Shares the orders permission.
        Volt::route('esims', 'admin.esims')->name('esims');
        Volt::route('esims/{esim}', 'admin.esim')->whereNumber('esim')->name('esims.show');
        // Order report: preview page first (view + Download/Share), PDF behind
        // it. Viewing and downloading are separately audited.
        Route::get('orders/{order}/report', [AdminOrderReportController::class, 'show'])->name('order.report');
        Route::get('orders/{order}/report/pdf', [AdminOrderReportController::class, 'pdf'])->name('order.report.pdf');
        Route::get('orders/{order}', function (Order $order) {
            return view('admin.order', [
                'order' => $order->load(['user', 'items.esims', 'paymentAttempts']),
            ]);
        })->name('order');
        Route::view('customers', 'admin.customers')->name('customers');
        // Registered BEFORE customers/{user}: routes match in order, so the
        // wildcard would otherwise capture "export.csv" as a user key and
        // 404 on the model binding.
        Route::get('customers/export.csv', [AdminCustomerExportController::class, 'csv'])->name('customers.export');
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
                'kycVerifications' => $user->kycVerifications()->limit(5)->get(),
            ]);
        })->name('customer');

        // ── User Management group (shares the `customers` section grant) ──
        Route::view('users/blocked', 'admin.users.blocked')->name('users.blocked');
        Volt::route('users/leaderboards', 'admin.management.leaderboards')->name('users.leaderboards');
        Route::view('users/closures', 'admin.users.closures')->name('users.closures');
        Route::view('users/feedback', 'admin.users.feedback')->name('users.feedback');

        Route::get('users/insights', function () {
            $window = request()->query('window');
            $windowDays = in_array($window, ['30', '90'], true) ? (int) $window : null;

            return view('admin.users.insights', [
                'insights' => app(AudienceInsightsService::class)->build($windowDays),
                'window' => $windowDays,
            ]);
        })->name('users.insights');

        Route::get('users/insights/export.csv', function () {
            $window = request()->query('window');
            $windowDays = in_array($window, ['30', '90'], true) ? (int) $window : null;
            $table = app(AudienceInsightsService::class)->build($windowDays)['table'];

            return response()->streamDownload(function () use ($table) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Country', 'Users', 'Orders', 'Revenue USD', 'Avg order USD', 'Verified %']);
                foreach ($table as $row) {
                    fputcsv($out, [$row['label'], $row['users'], $row['orders'], $row['revenue_usd'], $row['aov_usd'], $row['verified_pct']]);
                }
                fclose($out);
            }, 'audience-insights-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
        })->name('users.insights.export');

        // KYC reconciliation: ask Smile's job-status API about an attempt whose
        // webhook has not arrived. Identity is only ever verified by the
        // provider; there is no manual document review.
        Route::post('kyc/verifications/{verification}/sync', [AdminKycController::class, 'sync'])->name('kyc.sync');

        // Risk engine - metrics dashboard, manual-review queue, and feedback labels.
        Route::get('risk', [AdminRiskReviewController::class, 'dashboard'])->name('risk.dashboard');
        Route::post('risk/label', [AdminRiskReviewController::class, 'label'])->name('risk.label');
        Route::get('risk/reviews', [AdminRiskReviewController::class, 'index'])->name('risk.reviews');
        Route::get('risk/disputes', [AdminRiskReviewController::class, 'disputes'])->name('risk.disputes');
        Route::get('risk/reviews/{review}', [AdminRiskReviewController::class, 'show'])->name('risk.review');
        Route::post('risk/reviews/{review}/approve', [AdminRiskReviewController::class, 'approve'])->name('risk.review.approve');
        Route::post('risk/reviews/{review}/reject', [AdminRiskReviewController::class, 'reject'])->name('risk.review.reject');
        Route::post('risk/reviews/{review}/escalate', [AdminRiskReviewController::class, 'escalate'])->name('risk.review.escalate');

        // Payment rate limiter: release a card-testing lock from the signed
        // link in its Telegram alert. Signed so the link cannot be guessed;
        // inside the admin group so it still needs an admin session.
        Route::get('payments/backoff/{event}/lift', [AdminPaymentBackoffController::class, 'lift'])
            ->middleware('signed')
            ->name('backoff.lift');

        // Customer admin actions: edit profile, ban/unban, hold/release funds,
        // and send a direct (email + dashboard) message.
        Route::patch('customers/{user}', [AdminCustomerController::class, 'update'])->name('customer.update');
        Route::post('customers/{user}/ban', [AdminCustomerController::class, 'toggleBan'])->name('customer.ban');
        Route::post('customers/{user}/close', [AdminCustomerController::class, 'toggleClosure'])->name('customer.close');
        Route::post('customers/{user}/send-data-export', [AdminCustomerController::class, 'sendDataExport'])->name('customer.send-data-export');
        Route::post('customers/{user}/revoke-sessions', [AdminCustomerController::class, 'revokeSessions'])->name('customer.revoke-sessions');
        Route::post('customers/{user}/suspend', [AdminCustomerController::class, 'toggleSuspend'])->name('customer.suspend');
        Route::post('customers/{user}/restrict', [AdminCustomerController::class, 'toggleRestrict'])->name('customer.restrict');
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
        // Transaction detail + report (preview-first, same pattern as the
        // order report). Attempt ids are UUIDs; whereUuid keeps the wildcard
        // clear of the export.csv route above.
        Route::get('transactions/{attempt}', [AdminTransactionController::class, 'show'])->whereUuid('attempt')->name('transaction');
        Route::get('transactions/{attempt}/report', [AdminTransactionController::class, 'report'])->whereUuid('attempt')->name('transaction.report');
        Route::get('transactions/{attempt}/report/pdf', [AdminTransactionController::class, 'reportPdf'])->whereUuid('attempt')->name('transaction.report.pdf');
        // Operator exit for a crypto payment that arrived short: settle with a
        // stated reason. Money override, so Super Admin only (the controller
        // enforces it on top of the route-name gate).
        Route::post('transactions/{attempt}/settle', [AdminTransactionController::class, 'settle'])->whereUuid('attempt')->name('transaction.settle');
        Route::view('wallets', 'admin.wallets')->name('wallets');
        Volt::route('rates', 'admin.rates')->name('rates');
        Volt::route('account', 'admin.account')->name('account');
        Volt::route('payment-insights', 'admin.payment-insights.dashboard')->name('payment-insights');

        // Management — CTO-level operational control: system monitoring,
        // company finances, user leaderboards and incident runbooks. Grouped
        // under the `management` permission section (Super Admin by default).
        Route::prefix('management')->name('management.')->group(function () {
            Volt::route('monitoring', 'admin.management.monitoring')->name('monitoring');
            Volt::route('finances', 'admin.management.finances')->name('finances');
            // Leaderboards moved to User Management (it is customer analytics);
            // the old URL and route name live on as a redirect so bookmarks
            // and old links keep working.
            Route::redirect('leaderboards', 'admin/users/leaderboards')->name('leaderboards');
            // Self-contained HTML reference docs (e.g. the Fraud & Risk Engine
            // handbook) served as authored; two segments, so no clash with the
            // one-segment Volt wildcard below.
            Route::get('runbooks/doc/{doc}', AdminRunbookDocController::class)->name('runbooks.doc');
            Volt::route('runbooks/{runbook?}', 'admin.management.runbooks')->name('runbooks');
            // The mobile API's contract (docs/api/v2), rendered, plus the raw
            // OpenAPI file for Roddy's tooling. Admin-only on purpose.
            // Gateway switches, per-currency payment routing and eligibility.
            Volt::route('gateways', 'admin.management.gateways')->name('gateways');
            Route::get('api-docs', [AdminApiDocsController::class, 'page'])->name('api-docs');
            Route::get('api-docs/openapi.yaml', [AdminApiDocsController::class, 'spec'])->name('api-docs.spec');
        });
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

            // Per-language copy for database-backed content. Lang files cover
            // the interface; this covers what editors type.
            Volt::route('translations', 'admin.content.translations')->name('translations');
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
