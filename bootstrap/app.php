<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdvertiseDiscoveryLinks;
use App\Http\Middleware\CaptureReferralCookie;
use App\Http\Middleware\EnforceDeviceVerification;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAccountNotSuspended;
use App\Http\Middleware\EnsureDeviceMature;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureProfileCompleted;
use App\Http\Middleware\EnsureSupportedAppBuild;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\NegotiateMarkdown;
use App\Http\Middleware\NormaliseV2ErrorEnvelope;
use App\Http\Middleware\RedirectAlternateHosts;
use App\Http\Middleware\RejectMalformedInput;
use App\Http\Middleware\ResolveApiDisplayCurrency;
use App\Http\Middleware\ResolveDisplayCurrency;
use App\Http\Middleware\ResolveRegion;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleApiV2Group;
use App\Http\Middleware\TraceRequestMiddleware;
use App\Http\Middleware\VerifyTurnstile;
use App\Models\Admin;
use App\Support\ApiV2Error;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException;
use Psy\Exception\BreakException;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/admin.php',
        ],
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The mobile API. Registered here rather than as part of the `api`
        // group on purpose: that group appends StartSession and
        // authenticates a browser session cookie, which is exactly what a
        // token API must not do. /v2 gets its own stack (see the `api-v2`
        // group below) so nothing about the 133 live /api routes changes.
        then: function () {
            Route::middleware('api-v2')
                ->prefix('v2')
                ->name('v2.')
                ->group(base_path('routes/api_v2.php'));
        },
    )
    // Console commands live under domain folders, which are NOT auto-discovered
    // (only app/Console/Commands is). Register the domain command dirs so the
    // scheduler (reconcile:*, zendit:*, etc.) can resolve them.
    ->withCommands([
        __DIR__.'/../app/Domain/Reconciliation/Console',
        __DIR__.'/../app/Domain/Fulfillment/Console',
        __DIR__.'/../app/Domain/Payment/Console',
        __DIR__.'/../app/Domain/Communication/Console',
        __DIR__.'/../app/Domain/PaymentInsights/Console',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Real client IPs: the app runs behind Cloudflare -> the VPS web server,
        // so the raw connection PHP sees is the local proxy (127.0.0.1) and
        // Request::ip() logged that for every audit event. Trust the Cloudflare
        // edge ranges + the internal/private hops so the forwarded client IP is
        // read instead. NOTE: this reads X-Forwarded-For; because Cloudflare
        // appends to XFF (spoofable), the origin MUST be firewalled to only
        // accept Cloudflare, and nginx should pin the real IP from the
        // un-spoofable CF-Connecting-IP header (set_real_ip_from + real_ip_header).
        $middleware->trustProxies(
            at: [
                // Internal / private hops between nginx and PHP.
                '127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                // Cloudflare IPv4 (https://www.cloudflare.com/ips-v4).
                '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
                '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
                '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
                '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
                // Cloudflare IPv6 (https://www.cloudflare.com/ips-v6).
                '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
                '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'admin' => AdminAuth::class,
            // Selectively applied to write routes (checkout, cart writes,
            // wallet funding). Suspended users stay logged in so they can
            // see the banner + request a review - only the action is refused.
            'not-suspended' => EnsureAccountNotSuspended::class,
            // Selectively applied to the money-in write routes (order placement,
            // wallet funding). A logged-in, unverified customer is refused and
            // re-sent their verification link; guests and verified users pass.
            // Never attach to the global web group — browsing stays open.
            'verified-email' => EnsureEmailVerified::class,
            // Hard kill-switch for transactional writes when the admin flips
            // system.maintenance_mode on. Browsing stays open, only state
            // changes are refused. Admin operators always bypass.
            'maintenance-guard' => EnforceMaintenanceMode::class,
            'verify-turnstile' => VerifyTurnstile::class,
            // Gate for features still under construction. Fails closed, so a
            // route stays 404 until an admin turns its features.* row on.
            'feature' => EnsureFeatureEnabled::class,
            // The 24-hour hold on new devices for account-security mutations
            // (PIN change/reset/remove, device revoke, OTP preference).
            'device.mature' => EnsureDeviceMature::class,
        ]);

        // Refuses malformed-UTF-8 and oversized input with 422 before any
        // controller runs. Global, not per-group: an endpoint that echoes a
        // parameter into a JSON response 500s on bad encoding wherever it
        // lives, and web and api both do that.
        $middleware->prepend(RejectMalformedInput::class);

        $middleware->append(TraceRequestMiddleware::class);

        // Theme hint cookies are written client-side (plain '0'/'1') by the theme
        // engine so the server can paint the right dark / Extra Dark ramp on the
        // first byte. They carry no sensitive data; exclude them from cookie
        // encryption or they decrypt to null and the pre-paint frame flashes.
        //
        // region_suggest_dismissed is the same shape: the region suggestion
        // popup writes a plain '1' from JavaScript when it is dismissed, and
        // the component checks it server-side so a dismissed popup is never
        // rendered at all. Encrypted, that check reads null on every request -
        // the cookie would still hide the popup client-side, but only after it
        // had already been sent to someone who said no.
        $middleware->encryptCookies(except: [
            'theme_web_dark',
            'theme_admin_dark',
            'theme_web_puredark',
            'theme_admin_puredark',
            'region_suggest_dismissed',
        ]);

        // Locks the storefront catalog to the customer's chosen country/region.
        // CaptureReferralCookie persists `?ref=…` for 90 days so the referral
        // survives the visitor leaving and signing up later.
        // Runs first on every web request: folds any configured alternate domain
        // (e.g. the plural rshoprefills.com) onto the canonical host with a 301.
        // No-op until APP_ALTERNATE_HOSTS is set, so it's safe to ship dormant.
        $middleware->web(prepend: [
            RedirectAlternateHosts::class,
        ]);

        $middleware->web(append: [
            // FIRST in the append list, which puts it after EncryptCookies and
            // StartSession and before anything that renders.
            //
            // It cannot be prepended: prepend runs ahead of the framework's own
            // web stack, where the locale cookie is still encrypted and there is
            // no session yet - so both of those sources silently read as absent
            // and every request fell back to English.
            SetLocale::class,
            ResolveRegion::class,
            // Ties every web session to the password hash it signed in with:
            // a password change or reset signs every OTHER session out on its
            // next request, on any session driver. Without it, a thief who
            // was already logged in survived the victim changing the password.
            AuthenticateSession::class,
            // Display currency is read from the query string and a cookie only,
            // never from auth state, so it is independent of the line above and
            // sits after it for no reason beyond matching how sessions settle
            // before anything renders a price.
            ResolveDisplayCurrency::class,
            EnsureAccountActive::class,
            CaptureReferralCookie::class,
            EnforceDeviceVerification::class,
            // Holds a brand-new Google sign-up on the completion step until it
            // supplies the country / phone / gender / terms the register wizard
            // collects. Inert for every other account.
            EnsureProfileCompleted::class,
            // Order matters on the response unwind: AdvertiseDiscoveryLinks runs
            // first (while the body is still HTML) so it can set the Link header,
            // then NegotiateMarkdown converts the body for agents while keeping it.
            NegotiateMarkdown::class,
            AdvertiseDiscoveryLinks::class,
        ]);

        // Enable stateful sessions for API routes so AJAX requests can resolve
        // the authenticated user. The same ban / closure / revoked-session
        // check as the web group runs here too: these routes authenticate the
        // very same session cookie, so a banned customer must not keep
        // working /api endpoints just because they never load a web page.
        //
        // EnforceDeviceVerification runs here for the same reason: a session
        // still waiting on its new-device email code was refused every web
        // page but could read /api/orders, delivered codes included.
        $middleware->api(append: [
            EncryptCookies::class,
            StartSession::class,
            EnsureAccountActive::class,
            EnforceDeviceVerification::class,
        ]);

        // The mobile API. Deliberately NOT part of the `api` group above:
        // stateless, no cookies, no session, no CSRF. A phone carries a
        // bearer token instead, so a session would add cost and a footgun
        // and nothing else. RejectMalformedInput and TraceRequestMiddleware
        // already cover it, being global.
        //
        // Account state (banned, closed) is checked by EnsureApiAccountActive
        // on the authenticated routes rather than here, because it needs a
        // resolved token user and the public routes have none.
        $middleware->group('api-v2', [
            ForceJsonResponse::class,
            // Immediately after ForceJsonResponse so it wraps everything below it,
            // including the middleware /v2 shares with the website. Those refuse in
            // their own shapes; the app is promised exactly one.
            NormaliseV2ErrorEnvelope::class,
            EnsureSupportedAppBuild::class,
            // The general ceiling, and it has to run BEFORE auth:sanctum so a flood
            // of made-up tokens is refused without a database lookup each. A plain
            // `throttle:v2` here would be re-sorted to run after authentication; see
            // the class for why this wrapper exists.
            ThrottleApiV2Group::class,
            // Puts the app's chosen currency where DisplayCurrency looks, so
            // every price the API sends is converted by the same chain the
            // storefront uses. Stateless: no cookie is set or read.
            ResolveApiDisplayCurrency::class,
            SubstituteBindings::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(function () {
            if (request()->is('admin*')) {
                /** @var Admin|null $admin */
                $admin = auth('admin')->user();
                if ($admin && ! $admin->canAccessAdminRoute('dashboard')) {
                    return $admin->canAccessAdminRoute('operations')
                        ? route('admin.operations')
                        : route('admin.account');
                }

                return route('admin.dashboard');
            }

            return route('dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Guard: duplicate failed_jobs UUID insert is a harmless race condition,
        // not a real application error. Downgrade to a warning so Sentry doesn't
        // fire alerts on it.
        $exceptions->report(function (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'failed_jobs')) {
                Log::warning('Duplicate failed_jobs UUID — race condition (harmless)', [
                    'message' => $e->getMessage(),
                ]);

                return false; // Stop propagation — don't report to Sentry.
            }
        });

        $exceptions->dontReport([
            BreakException::class,
            MethodNotFoundException::class,
            PublicPropertyNotFoundException::class,
            CorruptComponentPayloadException::class,
        ]);

        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'transaction_pin',
            'api_key',
            'secret',
            'credentials',
        ]);
        // One error shape for /v2, and ONLY /v2: fromException returns null
        // for every other path, which leaves the storefront and the existing
        // /api responses exactly as they are.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiV2Error::fromException($e, $request));

        Integration::handles($exceptions);
    })->create();
