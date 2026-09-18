<?php

use App\Http\Controllers\Api\CheckoutApiController;
use App\Http\Controllers\Api\CryptoQrController;
use App\Http\Controllers\Api\EmailUnsubscribeController;
use App\Http\Controllers\Api\InteractionTrackingController;
use App\Http\Controllers\Api\KycStatusController;
use App\Http\Controllers\Api\NewsletterApiController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\PaymentSessionController;
use App\Http\Controllers\Api\PaymentTelemetryController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\Storefront\CartController;
use App\Http\Controllers\Api\Storefront\CatalogController;
use App\Http\Controllers\Api\Storefront\ConfigController;
use App\Http\Controllers\Api\Storefront\EsimCatalogController;
use App\Http\Controllers\Api\UserDashboardController;
use App\Http\Controllers\Api\UserTransactionController;
use App\Http\Controllers\Api\UserWalletController;
use App\Http\Controllers\Api\Wallet\TransactionPinController;
use App\Http\Controllers\Api\Webhooks\NowPaymentsWebhookController;
use App\Http\Controllers\Webhooks\AiraloWebhookController;
use App\Http\Controllers\Webhooks\FlutterwaveWebhookController;
use App\Http\Controllers\Webhooks\KorapayWebhookController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use App\Http\Controllers\Webhooks\SmileWebhookController;
use App\Http\Middleware\VerifyWebhookSignature;
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

// Webhooks (No auth required, but HMAC verification enforced)
Route::post('webhooks/flutterwave', [FlutterwaveWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class.':flutterwave')
    ->name('api.webhooks.flutterwave');

Route::post('webhooks/paystack', [PaystackWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class.':paystack')
    ->name('api.webhooks.paystack');

Route::post('webhooks/korapay', [KorapayWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class.':korapay')
    ->name('api.webhooks.korapay');

Route::post('webhooks/nowpayments', [NowPaymentsWebhookController::class, 'handle'])
    ->middleware(VerifyWebhookSignature::class.':nowpayments')
    ->name('api.webhooks.nowpayments');

// Airalo low-data + expiry notifications, HMAC-signed with X-Airalo-Signature.
// Verification lives in the shared middleware alongside every other provider so
// there is one implementation to audit, and so this endpoint inherits the same
// replay protection. Returns 200 on no-match so Airalo doesn't keep retrying.
Route::post('webhooks/airalo', AiraloWebhookController::class)
    ->middleware(VerifyWebhookSignature::class.':airalo')
    ->name('api.webhooks.airalo');

// Smile ID KYC result callback. Signed in the request BODY (verified inside the
// provider via the Smile SDK), so it uses no header-signature middleware.
Route::post('webhooks/smileid', [SmileWebhookController::class, 'handle'])
    ->name('api.webhooks.smileid');

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

    // crypto/fee-estimate removed with CryptoFeeService: it computed a network +
    // service fee locally, which is a figure we must never quote. The gateway
    // grosses its own fees into the amount it returns, and that amount is now the
    // only one shown to a customer.

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

// One-click unsubscribe for optional email (RFC 8058). POST is the mail
// client's own Unsubscribe button, GET the footer link. Signed, so a link only
// ever acts on the customer it was minted for; under /api so the client's
// POST never meets a CSRF check.
Route::match(['get', 'post'], 'email/unsubscribe/{user}/{category}', EmailUnsubscribeController::class)
    ->middleware(['signed', 'throttle:20,1'])
    ->name('email.unsubscribe');

// Push notification interaction tracking (Public + throttled). The service worker
// fires this on `notificationclick`, which can happen with no active session and
// from a context that carries no auth — so it must NOT sit behind auth or clicks
// never record. Attribution is by campaign_id / notification_id in the body.
Route::post('push/track', [InteractionTrackingController::class, 'record'])
    ->middleware('throttle:120,1')
    ->name('api.push.track');

Route::prefix('payment-sessions')->name('api.payment-sessions.')->group(function () {
    // status is polled by the checkout UI while awaiting confirmation, so it
    // gets a generous ceiling; the state-changing actions (cancel/verify/pay)
    // are capped tighter to curb polling abuse and gateway-cost amplification.
    Route::get('{id}', [PaymentSessionController::class, 'show'])->middleware('throttle:payment-session-poll')->name('show');
    Route::get('{id}/status', [PaymentSessionController::class, 'status'])->middleware('throttle:payment-session-poll')->name('status');
    // Self-hosted payment QR. Replaces the third-party image service the checkout
    // used to call, which required sending the customer's deposit address off-site.
    Route::get('{id}/crypto-qr', [CryptoQrController::class, 'show'])->middleware('throttle:payment-session-poll')->name('crypto-qr');
    Route::post('{id}/cancel', [PaymentSessionController::class, 'cancel'])->middleware('throttle:payment-session-action')->name('cancel');
    Route::post('{id}/verify', [PaymentSessionController::class, 'verify'])->middleware('throttle:payment-session-action')->name('verify');
    Route::post('{id}/pay', [PaymentSessionController::class, 'pay'])->middleware('throttle:payment-session-action')->name('pay');
    // Issues a follow-up payment for the balance on an underpaid crypto session.
    // Without it a customer whose exchange deducted a withdrawal fee has paid,
    // received nothing, and has no self-service way to make up the difference.
    Route::post('{id}/top-up', [PaymentSessionController::class, 'topUp'])->middleware('throttle:payment-session-action')->name('top-up');
});

// Protected Dashboard & Wallet APIs
Route::middleware('auth')->group(function () {
    Route::get('dashboard', [UserDashboardController::class, 'index'])->name('api.dashboard');

    // Poll KYC state after completing the Smile flow, so the UI can wait for the
    // callback and then retry the action that required verification.
    Route::get('kyc/status', [KycStatusController::class, 'show'])->name('api.kyc.status');

    // Self-serve identity verification: the KYC page's "Verify now" button mints
    // a Smile session here, and the modal marks the capture as submitted so the
    // page can show an honest "in review" state while the verdict is in flight.
    // A confirmed email comes first: verified-email refuses (and re-sends the
    // link) before anything reaches the identity provider.
    Route::post('kyc/start', [KycStatusController::class, 'start'])->middleware(['throttle:6,1', 'verified-email'])->name('api.kyc.start');
    Route::post('kyc/submitted', [KycStatusController::class, 'submitted'])->middleware('throttle:6,1')->name('api.kyc.submitted');

    Route::prefix('wallets')->name('api.wallets.')->group(function () {
        Route::get('/', [UserWalletController::class, 'index'])->name('index');
        Route::get('/fundings', [UserWalletController::class, 'fundings'])->name('fundings');
        Route::get('/fundings/{reference}', [UserWalletController::class, 'fundingDetails'])->name('fundings.show');
        Route::get('{currency}', [UserWalletController::class, 'show'])->name('show');
        Route::post('fund/initiate', [UserWalletController::class, 'initiateFunding'])->middleware(['not-suspended', 'verified-email'])->name('fund.initiate');

        // Transaction PIN Routes
        Route::prefix('pin')->name('pin.')->group(function () {
            Route::get('status', [TransactionPinController::class, 'status'])->name('status');
            Route::post('setup', [TransactionPinController::class, 'setup'])->name('setup');
            Route::post('verify', [TransactionPinController::class, 'verify'])->name('verify')->middleware('throttle:5,1');
            // The 24-hour new-device hold covers every PIN MUTATION. setup
            // (first-time) and verify (spending) stay open: a new customer
            // must be able to set a PIN, and paying is never device-gated.
            Route::put('change', [TransactionPinController::class, 'change'])->middleware('device.mature')->name('change');
            Route::post('reset/request', [TransactionPinController::class, 'requestReset'])->middleware(['device.mature', 'throttle:5,1'])->name('reset.request');
            Route::post('reset/confirm', [TransactionPinController::class, 'confirmReset'])->middleware('device.mature')->name('reset.confirm');
        });
    });

    Route::prefix('checkout')->name('api.checkout.')->group(function () {
        Route::post('place-order', [CheckoutApiController::class, 'placeOrder'])->name('place-order')->middleware(['throttle:10,1', 'not-suspended', 'verified-email']);
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

    // Frontend payment telemetry stays auth-only (the core payment-session
    // routes moved to the public block above for guest checkout): the
    // controller's ownership check pins a session to the signed-in
    // customer's email.
    Route::prefix('payment-sessions')->name('api.payment-sessions.')->group(function () {
        Route::post('telemetry/record-failure', [PaymentTelemetryController::class, 'recordFailure'])->middleware('throttle:10,1')->name('telemetry.record-failure');
        // Signals are chattier than terminal failures (exceptions, offline
        // blips) - looser throttle, and the controller caps rows per session.
        Route::post('telemetry/record-signal', [PaymentTelemetryController::class, 'recordSignal'])->middleware('throttle:30,1')->name('telemetry.record-signal');
    });

    Route::get('transactions', [UserTransactionController::class, 'index'])->name('api.transactions.index');

    // Security Center APIs
    Route::prefix('security')->name('api.security.')->group(function () {
        // Both behind the new-device hold: flipping the OTP preference to
        // "never" or revoking the owner's real devices are exactly the moves
        // an account thief makes in their first hour.
        Route::put('preferences', [SecurityController::class, 'updatePreferences'])->middleware('device.mature')->name('preferences.update');
        // Revoking is gated by the account password instead (see the
        // controller): a customer on a NEW device must be able to cut a
        // stolen one loose right now, not after the 24-hour hold.
        Route::delete('devices/{device}', [SecurityController::class, 'revokeDevice'])->name('devices.revoke');
    });
});
