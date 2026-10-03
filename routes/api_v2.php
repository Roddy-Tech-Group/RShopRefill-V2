<?php

use App\Http\Controllers\Api\V2\AccountController;
use App\Http\Controllers\Api\V2\Auth\ChallengeController;
use App\Http\Controllers\Api\V2\Auth\GoogleController;
use App\Http\Controllers\Api\V2\Auth\LoginController;
use App\Http\Controllers\Api\V2\Auth\PasswordController;
use App\Http\Controllers\Api\V2\Auth\RegisterController;
use App\Http\Controllers\Api\V2\Auth\SessionController;
use App\Http\Controllers\Api\V2\Auth\TokenController;
use App\Http\Controllers\Api\V2\CartController;
use App\Http\Controllers\Api\V2\Catalog\CategoryController;
use App\Http\Controllers\Api\V2\Catalog\ConfigController;
use App\Http\Controllers\Api\V2\Catalog\ProductController;
use App\Http\Controllers\Api\V2\Catalog\QuoteController;
use App\Http\Controllers\Api\V2\CheckoutController;
use App\Http\Controllers\Api\V2\MetaController;
use App\Http\Controllers\Api\V2\NotificationController;
use App\Http\Controllers\Api\V2\OrderController;
use App\Http\Controllers\Api\V2\PaymentController;
use App\Http\Controllers\Api\V2\PushTokenController;
use App\Http\Controllers\Api\V2\TransactionPinController;
use App\Http\Controllers\Api\V2\WalletController;
use App\Http\Controllers\Api\V2\WalletFundingController;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureApiAccountActive;
use App\Http\Middleware\EnsureApiDeviceMature;
use App\Http\Middleware\RequireInstallationId;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API (/v2)
|--------------------------------------------------------------------------
|
| The API the Expo app talks to. Registered in bootstrap/app.php under the
| `api-v2` middleware group, which is STATELESS: no cookies, no session, no
| CSRF. Authentication is a bearer token through the `sanctum` guard.
|
| Deliberately separate from routes/api.php. Those 133 routes authenticate a
| browser session cookie and serve the storefront's own JavaScript; they are
| live, and nothing here touches them.
|
| Conventions the app can rely on:
|   - success is { "data": ... }
|   - failure is { "error": { "type": ..., "message": ... } }, see ApiV2Error
|   - `type` is the contract and is stable; `message` is written for the
|     customer and may be reworded at any time
|   - a sign-in answers with "status": either "authenticated" (with tokens) or
|     "verification_required" (with a challenge token and NO tokens)
|
| Headers every call should carry:
|   X-Installation-ID  a stable id generated on first launch and kept in the
|                      phone's storage. Required on every sign-in route: it is
|                      what device trust, the new-device hold and per-device
|                      revocation all hang off.
|   X-App-Build        the build number, so retired builds can be turned away.
|
*/

Route::get('meta', MetaController::class)->name('meta');

Route::prefix('auth')->name('auth.')->group(function () {

    /*
     * Public, and each one must name the device it comes from. Without a stable
     * installation id every call looks like a new device, which would demand an
     * email code on every single login. RequireInstallationId refuses rather
     * than letting it degrade into that quietly.
     */
    Route::middleware(RequireInstallationId::class)->group(function () {
        Route::post('register', RegisterController::class)
            ->middleware('throttle:v2-issue')
            ->name('register');

        Route::post('login', LoginController::class)
            ->middleware('throttle:v2-login')
            ->name('login');

        // The phone does the Google dance natively and sends the resulting ID
        // token. Throttled as an ISSUE rather than a guess: there is no password
        // being brute-forced here, and each call costs us a round trip to Google.
        Route::post('google', GoogleController::class)
            ->middleware('throttle:v2-issue')
            ->name('google');

        // Finishing a sign-in that needed an emailed code. The challenge token
        // authenticates these, not an access token: a sign-in that has not been
        // verified holds no API token at all.
        Route::post('challenge/verify', [ChallengeController::class, 'verify'])
            ->middleware('throttle:v2-login')
            ->name('challenge.verify');

        Route::post('challenge/resend', [ChallengeController::class, 'resend'])
            ->middleware('throttle:v2-issue')
            ->name('challenge.resend');

        // Unauthenticated by necessity: the app calls this precisely because its
        // access token has expired. The refresh token in the body is the
        // credential.
        Route::post('refresh', [TokenController::class, 'refresh'])
            ->middleware('throttle:v2-login')
            ->name('refresh');
    });

    /*
     * No installation id needed: these only send an email or spend a token that
     * arrived in one, and a customer may well be resetting from a device that
     * has never signed in.
     */
    Route::post('password/forgot', [PasswordController::class, 'forgot'])
        ->middleware('throttle:v2-issue')
        ->name('password.forgot');

    Route::post('password/reset', [PasswordController::class, 'reset'])
        ->middleware('throttle:v2-issue')
        ->name('password.reset');

    /*
     * Signed in. EnsureApiAccountActive is the token-side counterpart of
     * EnsureAccountActive: a banned or closed account has to stop working on the
     * phone as well as in the browser.
     */
    Route::middleware(['auth:sanctum', EnsureApiAccountActive::class])->group(function () {
        Route::get('me', [SessionController::class, 'me'])->name('me');

        Route::post('logout', [SessionController::class, 'logout'])->name('logout');

        Route::post('logout-all', [SessionController::class, 'logoutAll'])->name('logout-all');

        Route::post('email/resend', [SessionController::class, 'resendEmailVerification'])
            ->middleware('throttle:v2-issue')
            ->name('email.resend');
    });
});

/*
|--------------------------------------------------------------------------
| Catalogue
|--------------------------------------------------------------------------
|
| Public: a customer browses before signing in, exactly as on the website.
|
| Prices are converted into the currency named in X-Display-Currency, and every
| amount goes out as { amount, currency, usd }. The USD figure is the one we
| settle in and the one that still reconciles months later; `amount` is
| presentational. The app learns the customer's preferred currency from
| GET /v2/auth/me and sends it back on each request.
|
*/

Route::prefix('catalog')->name('catalog.')->group(function () {
    Route::get('config', ConfigController::class)->name('config');

    Route::get('categories', [CategoryController::class, 'categories'])->name('categories');
    Route::get('subcategories', [CategoryController::class, 'subcategories'])->name('subcategories');

    Route::get('products', [ProductController::class, 'index'])->name('products');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');

    // What a typed amount costs, for variable products. A read with a body, so
    // POST; it creates nothing and needs no idempotency key.
    Route::post('quote', QuoteController::class)->name('quote');

    // eSIMs have no endpoints of their own on purpose: they are ordinary
    // products in the 'esims' category, so
    //   GET products?category=esims&subcategory=data-esim
    // lists them and products/{slug} returns the plans. Each variant carries an
    // `esim` block with its allowance, validity and coverage. One contract
    // rather than two that drift.
});

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
|
| Signed in only, unlike the storefront's cart. Guest checkout was removed, so a
| guest cart on the app could only ever be a dead end, and the app has a sign-in
| screen at the front. That also means there is no merge endpoint and no guest
| token to look after.
|
| Adding an item carries an Idempotency-Key: adding the same variant twice
| INCREASES its quantity, so a retry on a dropped connection would quietly put two
| in the basket. Updating a quantity and removing a line are absolute, so they are
| safe to retry without one.
|
*/

Route::prefix('cart')->name('cart.')->middleware(['auth:sanctum', EnsureApiAccountActive::class])->group(function () {
    Route::get('/', [CartController::class, 'show'])->name('show');

    Route::post('items', [CartController::class, 'addItem'])
        ->middleware([EnforceIdempotency::class, 'not-suspended'])
        ->name('items.add');

    Route::patch('items/{item}', [CartController::class, 'updateItem'])
        ->middleware('not-suspended')
        ->name('items.update');

    Route::delete('items/{item}', [CartController::class, 'removeItem'])
        ->middleware('not-suspended')
        ->name('items.remove');

    Route::delete('/', [CartController::class, 'clear'])
        ->middleware('not-suspended')
        ->name('clear');
});

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
|
| Read only. Amounts are historical and never re-converted: an order placed in
| naira in July was charged at July's rate, and re-pricing it at today's would
| disagree with the customer's own bank statement.
|
| Addressed by order_number, which is what a customer quotes to support. Ownership
| is part of every query rather than a check afterwards.
|
*/

Route::prefix('orders')->name('orders.')->middleware(['auth:sanctum', EnsureApiAccountActive::class])->group(function () {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('{orderNumber}', [OrderController::class, 'show'])->name('show');
});

/*
|--------------------------------------------------------------------------
| Wallet
|--------------------------------------------------------------------------
|
| READ ONLY here. Adding money moves money and gets its own endpoint with its own
| idempotency guard; nothing in this block changes a balance.
|
| Balances are NOT converted into the display currency: a naira wallet holds naira,
| and showing it as dollars would suggest it can be spent as dollars.
|
*/

Route::prefix('wallets')->name('wallets.')->middleware(['auth:sanctum', EnsureApiAccountActive::class])->group(function () {
    Route::get('/', [WalletController::class, 'index'])->name('index');
    Route::get('transactions', [WalletController::class, 'transactions'])->name('transactions');
    // Declared last, and constrained to three letters so a longer path segment can
    // never be read as a currency. The constraint is not a complete defence: the
    // PIN routes once lived at wallets/pin and "pin" is itself three letters, which
    // is why they now live under /transaction-pin, where nothing can shadow them.
    Route::get('{currency}', [WalletController::class, 'show'])
        ->where('currency', '[A-Za-z]{3}')
        ->name('show');
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Marking as read is idempotent by nature, so none of these carry a key.
|
*/

Route::prefix('notifications')->name('notifications.')->middleware(['auth:sanctum', EnsureApiAccountActive::class])->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
    Route::patch('read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
    Route::patch('{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');

    Route::get('preferences', [NotificationController::class, 'preferences'])->name('preferences');
    Route::put('preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
});

/*
|--------------------------------------------------------------------------
| Account
|--------------------------------------------------------------------------
|
| Changing the EMAIL is deliberately absent. On the web that flow re-proves the
| password, notifies the old address and re-verifies the new one, because the email
| is the account. A thinner version here would be the weakest door into every
| account we have, so it waits until it can be done properly.
|
| Revoking a device is gated on the account PASSWORD, not on the 24-hour new-device
| hold: somebody whose account has just been taken over is signing in from somewhere
| new themselves, and making them wait a day to cut the thief loose would be exactly
| the wrong way round. An account with no password (Google-only) has nothing to prove
| with, so for those the hold applies instead. The controller makes that choice, so
| the route itself carries no hold.
|
| Relaxing the OTP preference IS behind the hold, because that is among the first
| things an account thief reaches for.
|
| The hold is EnsureApiDeviceMature, not the website's device.mature: that one
| resolves the device from the session and falls through open when it cannot, which
| on a token API means omitting the installation header switched the hold off.
|
*/

Route::prefix('account')->name('account.')->middleware(['auth:sanctum', EnsureApiAccountActive::class])->group(function () {
    Route::put('profile', [AccountController::class, 'updateProfile'])->name('profile.update');

    Route::get('devices', [AccountController::class, 'devices'])->name('devices');
    Route::delete('devices/{device}', [AccountController::class, 'revokeDevice'])
        ->name('devices.revoke');

    Route::put('security/otp-preference', [AccountController::class, 'updateOtpPreference'])
        ->middleware(EnsureApiDeviceMature::class)
        ->name('security.otp-preference');

    Route::get('kyc', [AccountController::class, 'kyc'])->name('kyc');
});

/*
|--------------------------------------------------------------------------
| Push registration
|--------------------------------------------------------------------------
|
| Called on launch and whenever the operating system rotates the token, which it
| does without warning, so it must be safe to call constantly: it upserts on the
| device rather than inserting, and needs no idempotency key.
|
| Requires the installation id, because a token belongs to a PHONE rather than to an
| account. Registering one takes it from whoever held it before: phones get sold and
| shared, and the previous owner's wallet activity must not keep arriving on it.
|
*/

Route::prefix('push')->name('push.')
    ->middleware(['auth:sanctum', EnsureApiAccountActive::class, RequireInstallationId::class])
    ->group(function () {
        Route::post('token', [PushTokenController::class, 'store'])->name('token.store');
        Route::delete('token', [PushTokenController::class, 'destroy'])->name('token.destroy');
    });

/*
|--------------------------------------------------------------------------
| Checkout and payments
|--------------------------------------------------------------------------
|
| The money. Three rules run through this whole block.
|
| ONE IMPLEMENTATION. Placing an order calls CheckoutService and funding a wallet
| calls WalletFundingService, the same services the website charges through; those
| two carry the gateway kill switch, the category eligibility gate, the risk gate,
| the payment-insights backoff and the spending cap. The three actions with heavy
| gateway logic (authorising a session, cancelling one, topping one up) DELEGATE to
| the storefront's PaymentSessionController rather than being reimplemented: its
| `pay` method carries the per-currency method allowlist, category-aware routing
| with fiat failover, the per-rail kill switches and an atomic claim on the wallet
| PIN token. A second copy of that would be the most dangerous code in this
| repository.
|
| EVERY MONEY-CREATING CALL CARRIES AN IDEMPOTENCY KEY. Placing an order, authorising
| a payment, funding a wallet and topping up a shortfall all create or move money, so a
| retry on a dropped connection must return the first answer rather than do it again.
| The account-state guards run BEFORE the key is claimed, so "verify your email first"
| is answered fresh each time rather than remembered against the key for a day.
|
| THE MAINTENANCE SWITCH APPLIES. The admin's maintenance mode refuses every money
| write on the website; the same guard sits on every money write here.
|
| VERIFY CARRIES NO KEY, BUT IS RATE LIMITED. It has to be callable repeatedly:
| recovering from a missed deep link is precisely to keep asking, and VerifyPaymentJob's
| row lock and duplicate-payment guard make that safe. It is not cheap, though: an
| unsettled attempt costs a gateway round trip each call, so it is capped and the app
| POLLS the cheap GET instead, calling verify on return from the gateway and sparingly.
|
| The return journey: the app opens the gateway page, comes back by deep link, and
| calls verify. If the deep link is missed (the app was killed, the browser was closed)
| it polls GET and calls verify every few seconds. If the app never comes back at all,
| the gateway's webhook and the reconcilers settle it anyway. Three independent paths
| to the same outcome, which is what a payment flow on unreliable networks needs.
|
*/

Route::middleware(['auth:sanctum', EnsureApiAccountActive::class])->group(function () {

    Route::post('checkout/orders', [CheckoutController::class, 'placeOrder'])
        ->middleware(['not-suspended', 'verified-email', 'maintenance-guard', EnforceIdempotency::class, 'throttle:v2-money'])
        ->name('checkout.orders.place');

    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('{session}', [PaymentController::class, 'show'])
            ->middleware('throttle:v2-poll')
            ->name('show');

        // Called on return from a gateway, then every few seconds while waiting. No
        // idempotency key: it must be callable repeatedly, and the settlement chain
        // behind it is already safe against being run twice.
        Route::post('{session}/verify', [PaymentController::class, 'verify'])
            ->middleware('throttle:v2-verify')
            ->name('verify');

        Route::post('{session}/pay', [PaymentController::class, 'pay'])
            ->middleware(['not-suspended', 'maintenance-guard', EnforceIdempotency::class, 'throttle:v2-money'])
            ->name('pay');

        Route::post('{session}/cancel', [PaymentController::class, 'cancel'])
            ->middleware(['maintenance-guard', 'throttle:v2-money'])
            ->name('cancel');

        // Makes up the shortfall on an underpaid crypto payment. Without it a customer
        // whose exchange deducted a withdrawal fee has paid, received nothing, and has
        // no way to fix it themselves.
        Route::post('{session}/top-up', [PaymentController::class, 'topUp'])
            ->middleware(['not-suspended', 'maintenance-guard', EnforceIdempotency::class, 'throttle:v2-money'])
            ->name('top-up');
    });

    Route::post('wallets/fund', WalletFundingController::class)
        ->middleware(['not-suspended', 'verified-email', 'maintenance-guard', EnforceIdempotency::class, 'throttle:v2-money'])
        ->name('wallets.fund');

    /*
     * The PIN that authorises spending from a wallet.
     *
     * Setting a FIRST one is open: a new customer has to be able to start spending, and
     * there is no existing PIN a thief would be displacing. Changing or resetting one
     * sits behind the 24-hour new-device hold, because those are the moves somebody who
     * has just taken over an account makes.
     *
     * Verifying is rate limited hard. Four digits is ten thousand possibilities.
     *
     * Deliberately NOT under wallets/. It lived at wallets/pin and was swallowed by
     * the wallets/{currency} route, because "pin" is itself three letters and matched
     * the currency pattern exactly. Constraining the pattern would have fixed that
     * one word and left the next collision waiting. The PIN is an account credential
     * rather than a wallet sub-resource, so it belongs on its own path where nothing
     * can shadow it.
     */
    Route::prefix('transaction-pin')->name('transaction-pin.')->group(function () {
        Route::get('/', [TransactionPinController::class, 'status'])->name('status');

        Route::post('/', [TransactionPinController::class, 'setup'])
            ->middleware(['maintenance-guard', 'throttle:v2-money'])
            ->name('setup');

        Route::post('verify', [TransactionPinController::class, 'verify'])
            ->middleware('throttle:v2-pin')
            ->name('verify');

        Route::put('/', [TransactionPinController::class, 'change'])
            ->middleware([EnsureApiDeviceMature::class, 'throttle:v2-pin'])
            ->name('change');

        Route::post('reset/request', [TransactionPinController::class, 'requestReset'])
            ->middleware([EnsureApiDeviceMature::class, 'throttle:v2-issue'])
            ->name('reset.request');

        Route::post('reset/confirm', [TransactionPinController::class, 'confirmReset'])
            ->middleware([EnsureApiDeviceMature::class, 'throttle:v2-pin'])
            ->name('reset.confirm');
    });
});
