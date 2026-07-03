<?php

use App\Http\Controllers\Api\CheckoutApiController;
use App\Http\Controllers\Api\InteractionTrackingController;
use App\Http\Controllers\Api\NewsletterApiController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\PaymentSessionController;
use App\Http\Controllers\Api\Storefront\CartController;
use App\Http\Controllers\Api\Storefront\CatalogController;
use App\Http\Controllers\Api\Storefront\ConfigController;
use App\Http\Controllers\Api\Storefront\CryptoFeeController;
use App\Http\Controllers\Api\Storefront\EsimCatalogController;
use App\Http\Controllers\Api\UserDashboardController;
use App\Http\Controllers\Api\UserTransactionController;
use App\Http\Controllers\Api\UserWalletController;
use App\Http\Controllers\Api\Wallet\TransactionPinController;
use App\Http\Controllers\Api\Webhooks\NowPaymentsWebhookController;
use App\Http\Controllers\Webhooks\AiraloWebhookController;
use App\Http\Controllers\Webhooks\FlutterwaveWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Webhooks (No auth required)
Route::post('webhooks/flutterwave', [FlutterwaveWebhookController::class, 'handle'])->name('api.webhooks.flutterwave');
Route::post('webhooks/nowpayments', [NowPaymentsWebhookController::class, 'handle'])->name('api.webhooks.nowpayments');
// Airalo low-data + expiry notifications. Auth is HMAC inside the controller
// (X-Airalo-Signature), not Laravel middleware — the partner can't carry our
// CSRF token. Returns 200 even on no-match so Airalo doesn't keep retrying.
Route::post('webhooks/airalo', AiraloWebhookController::class)->name('api.webhooks.airalo');

// Storefront Catalog APIs (Public)
Route::prefix('storefront')->name('api.storefront.')->group(function () {
    Route::get('config', [ConfigController::class, 'index'])->name('config');
    Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('subcategories', [CatalogController::class, 'subcategories'])->name('subcategories');
    Route::get('products', [CatalogController::class, 'products'])->name('products');
    Route::get('products/{slug}', [CatalogController::class, 'product'])->name('product.show');

    // eSIM Specific Flow
    Route::get('esims/countries', [EsimCatalogController::class, 'countries'])->name('esims.countries');
    Route::get('esims/{slug}', [EsimCatalogController::class, 'show'])->name('esims.show');

    Route::get('crypto/fee-estimate', [CryptoFeeController::class, 'estimate'])->name('crypto.fee-estimate');

    // Cart Flow. `not-suspended` is a no-op for guests (cart access is open);
    // it only blocks an authenticated suspended user from writing to the cart.
    Route::prefix('cart')->name('cart.')->group(function () {
        Route::get('/', [CartController::class, 'show'])->name('show');
        Route::post('/items', [CartController::class, 'addItem'])->middleware('not-suspended')->name('items.add');
        Route::patch('/items/{id}', [CartController::class, 'updateItem'])->middleware('not-suspended')->name('items.update');
        Route::delete('/items/{id}', [CartController::class, 'removeItem'])->middleware('not-suspended')->name('items.remove');
        Route::delete('/', [CartController::class, 'clear'])->middleware('not-suspended')->name('clear');
        Route::post('/merge', [CartController::class, 'merge'])->middleware('not-suspended')->name('merge');
    });
});

// Newsletter Subscriptions (Public)
Route::post('newsletter/subscribe', [NewsletterApiController::class, 'subscribe'])->name('newsletter.subscribe');
Route::get('newsletter/unsubscribe', [NewsletterApiController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

// Push notification interaction tracking (Public + throttled). The service worker
// fires this on `notificationclick`, which can happen with no active session and
// from a context that carries no auth — so it must NOT sit behind auth or clicks
// never record. Attribution is by campaign_id / notification_id in the body.
Route::post('push/track', [InteractionTrackingController::class, 'record'])
    ->middleware('throttle:120,1')
    ->name('api.push.track');

// Protected Dashboard & Wallet APIs
Route::middleware('auth')->group(function () {
    Route::get('dashboard', [UserDashboardController::class, 'index'])->name('api.dashboard');

    Route::prefix('wallets')->name('api.wallets.')->group(function () {
        Route::get('/', [UserWalletController::class, 'index'])->name('index');
        Route::get('/fundings', [UserWalletController::class, 'fundings'])->name('fundings');
        Route::get('/fundings/{reference}', [UserWalletController::class, 'fundingDetails'])->name('fundings.show');
        Route::get('{currency}', [UserWalletController::class, 'show'])->name('show');
        Route::post('fund/initiate', [UserWalletController::class, 'initiateFunding'])->middleware('not-suspended')->name('fund.initiate');

        // Transaction PIN Routes
        Route::prefix('pin')->name('pin.')->group(function () {
            Route::get('status', [TransactionPinController::class, 'status'])->name('status');
            Route::post('setup', [TransactionPinController::class, 'setup'])->name('setup');
            Route::post('verify', [TransactionPinController::class, 'verify'])->name('verify')->middleware('throttle:5,1');
            Route::put('change', [TransactionPinController::class, 'change'])->name('change');
            Route::post('reset/request', [TransactionPinController::class, 'requestReset'])->name('reset.request');
            Route::post('reset/confirm', [TransactionPinController::class, 'confirmReset'])->name('reset.confirm');
            Route::delete('remove', [TransactionPinController::class, 'remove'])->name('remove');
        });
    });

    Route::prefix('checkout')->name('api.checkout.')->group(function () {
        Route::post('place-order', [CheckoutApiController::class, 'placeOrder'])->name('place-order')->middleware(['throttle:10,1', 'not-suspended']);
    });

    Route::prefix('orders')->name('api.orders.')->group(function () {
        Route::get('/', [CheckoutApiController::class, 'index'])->name('index');
        Route::get('{orderNumber}', [CheckoutApiController::class, 'show'])->name('show');
    });

    Route::prefix('notifications')->name('api.notifications.')->group(function () {
        Route::get('/', [NotificationApiController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationApiController::class, 'unreadCount'])->name('unread-count');
        Route::patch('{id}/read', [NotificationApiController::class, 'markAsRead'])->name('read');
        Route::patch('read-all', [NotificationApiController::class, 'markAllAsRead'])->name('read-all');
    });

    Route::prefix('notification-preferences')->name('api.notification-preferences.')->group(function () {
        Route::get('/', [NotificationApiController::class, 'getPreferences'])->name('get');
        Route::put('/', [NotificationApiController::class, 'updatePreferences'])->name('update');
    });

    Route::prefix('payment-sessions')->name('api.payment-sessions.')->group(function () {
        // status is polled by the checkout UI while awaiting confirmation, so it
        // gets a generous ceiling; the state-changing actions (cancel/verify/pay)
        // are capped tighter to curb polling abuse and gateway-cost amplification.
        Route::get('{id}', [PaymentSessionController::class, 'show'])->middleware('throttle:payment-session-poll')->name('show');
        Route::get('{id}/status', [PaymentSessionController::class, 'status'])->middleware('throttle:payment-session-poll')->name('status');
        Route::post('{id}/cancel', [PaymentSessionController::class, 'cancel'])->middleware('throttle:payment-session-action')->name('cancel');
        Route::post('{id}/verify', [PaymentSessionController::class, 'verify'])->middleware('throttle:payment-session-action')->name('verify');
        Route::post('{id}/pay', [PaymentSessionController::class, 'pay'])->middleware('throttle:payment-session-action')->name('pay');
    });

    Route::get('transactions', [UserTransactionController::class, 'index'])->name('api.transactions.index');
});
