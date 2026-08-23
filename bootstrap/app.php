<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdvertiseDiscoveryLinks;
use App\Http\Middleware\CaptureReferralCookie;
use App\Http\Middleware\EnforceDeviceTrustRestrictions;
use App\Http\Middleware\EnforceDeviceVerification;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAccountNotSuspended;
use App\Http\Middleware\EnsureDeviceMature;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\NegotiateMarkdown;
use App\Http\Middleware\RedirectAlternateHosts;
use App\Http\Middleware\ResolveRegion;
use App\Http\Middleware\TraceRequestMiddleware;
use App\Http\Middleware\VerifyTurnstile;
use App\Models\Admin;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
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
            'device.trust' => EnforceDeviceTrustRestrictions::class,
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

        $middleware->append(TraceRequestMiddleware::class);

        // Theme hint cookies are written client-side (plain '0'/'1') by the theme
        // engine so the server can paint the right dark / Extra Dark ramp on the
        // first byte. They carry no sensitive data; exclude them from cookie
        // encryption or they decrypt to null and the pre-paint frame flashes.
        $middleware->encryptCookies(except: [
            'theme_web_dark',
            'theme_admin_dark',
            'theme_web_puredark',
            'theme_admin_puredark',
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
            ResolveRegion::class,
            EnsureAccountActive::class,
            CaptureReferralCookie::class,
            EnforceDeviceVerification::class,
            // Order matters on the response unwind: AdvertiseDiscoveryLinks runs
            // first (while the body is still HTML) so it can set the Link header,
            // then NegotiateMarkdown converts the body for agents while keeping it.
            NegotiateMarkdown::class,
            AdvertiseDiscoveryLinks::class,
        ]);

        // Enable stateful sessions for API routes so AJAX requests can resolve the authenticated user
        $middleware->api(append: [
            EncryptCookies::class,
            StartSession::class,
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
            \Livewire\Exceptions\MethodNotFoundException::class,
            \Livewire\Exceptions\PublicPropertyNotFoundException::class,
            \Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException::class,
        ]);

        $exceptions->dontFlash([
            'password',
            'password_confirmation',
            'transaction_pin',
            'api_key',
            'secret',
            'credentials',
        ]);
        Integration::handles($exceptions);
    })->create();
