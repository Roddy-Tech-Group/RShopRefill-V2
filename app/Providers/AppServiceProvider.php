<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\AuditLogListener;
use App\Domain\Audit\Observers\UserAuditObserver;
use App\Http\View\Composers\CartComposer;
use App\Http\View\Composers\SiteIdentityComposer;
use App\Listeners\BackupFailureListener;
use App\Listeners\ScheduledTaskFailureListener;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A single query-string value, or the default. request()->query('q')
        // hands back an ARRAY for ?q[]=x, and casting that to a string is a
        // fatal error, which is how one crafted link took the top-ups page
        // down (Sentry 7770706387). Every page that reads a filter from the
        // URL reads it through this, so a shape nobody asked for is simply
        // not a filter.
        Request::macro('queryString', function (string $key, string $default = ''): string {
            /** @var Request $this */
            $value = $this->query($key);

            return is_scalar($value) ? (string) $value : $default;
        });

        // The same guard for a posted field read outside validation: a form
        // field renamed to `currency[]` by a tampered page is an array too.
        Request::macro('inputString', function (string $key, string $default = ''): string {
            /** @var Request $this */
            $value = $this->input($key);

            return is_scalar($value) ? (string) $value : $default;
        });

        // The one password policy every form uses (register, reset, change,
        // admin accounts). NIST-style: length plus a breached-password check
        // (Have I Been Pwned's k-anonymity range API, which never sees the
        // password and fails open if unreachable), no composition rules.
        // The network check runs in production only so tests and local
        // development stay offline.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(8)->uncompromised()
            : Password::min(8));

        // Append security-relevant events to the audit log.
        Event::subscribe(AuditLogListener::class);

        // Model-level audit of sensitive account fields (ban/suspend/KYC/identity):
        // captures EVERY surface that changes them, not just the admin panel.
        User::observe(UserAuditObserver::class);

        // Escalate backup failures to Sentry + the critical log channel. A backup
        // that stops running silently is the failure mode backups exist to avoid.
        Event::subscribe(BackupFailureListener::class);

        // Any scheduled task that fails alerts the phone directly; the
        // scheduler carries reconciliation, backups, syncs and sweepers, and a
        // silently-failing cron looks identical to a passing one.
        Event::subscribe(ScheduledTaskFailureListener::class);

        View::composer('*', CartComposer::class);

        // Share the admin-editable brand name ($siteName) with every view so the
        // website name is driven by the System Settings -> Site setting rather
        // than hardcoded in each layout, the nav, the footer and emails.
        View::composer('*', SiteIdentityComposer::class);

        // @money($amount, $code)      => "₦25,000.00"   (symbol + amount)
        // @moneyCode($amount, $code)  => "NGN 25,000.00" (ISO code + amount)
        // Delegates to App\Domain\Shared\Services\Money so we have one place
        // to evolve currency rendering as new codes / locales come online.
        Blade::directive('money', fn ($expr) => "<?php echo \App\Domain\Shared\Services\Money::format($expr); ?>");
        Blade::directive('moneyCode', fn ($expr) => "<?php echo \App\Domain\Shared\Services\Money::codeAmount($expr); ?>");

        // @feature('checkout') ... @endfeature       - render when ON
        // @unlessfeature('signup') ... @endfeature   - render when OFF
        // Reads features.<name>_enabled from SiteSetting (cached, on/off toggles
        // managed from the admin System Settings page).
        Blade::if('feature', fn (string $name) => FeatureFlag::on($name));

        $this->configureRateLimiters();
    }

    /**
     * Named rate limiters for the payment-session API surface. Each limiter
     * returns TWO limits so abuse is capped both per-user (a customer can only
     * juggle so many concurrent checkouts) and per-user+session (one session
     * can't be hammered, which would amplify gateway-verification cost). The
     * user key falls back to IP for the rare unauthenticated edge.
     */
    private function configureRateLimiters(): void
    {
        // The storefront cart. /cart/data is hydrated on every page load and
        // re-read after every mutation, so the read ceiling is generous; the
        // writes are capped tighter because each one is a database write on a
        // route a guest can reach without authenticating.
        //
        // Keyed by user, then the guest cart cookie, then IP. The cookie step
        // matters: without it every guest behind one corporate NAT or mobile
        // carrier egress shares a bucket and a busy office locks itself out.
        RateLimiter::for('cart-read', fn (Request $request) => Limit::perMinute(240)->by('cart:r:'.$this->cartKey($request)));

        RateLimiter::for('cart-write', fn (Request $request) => Limit::perMinute(60)->by('cart:w:'.$this->cartKey($request)));

        RateLimiter::for('payment-session-poll', function (Request $request) {
            $userKey = (string) ($request->user()?->id ?? $request->ip());
            $sessionId = (string) $request->route('id');

            return [
                Limit::perMinute(120)->by('psp:u:'.$userKey),
                Limit::perMinute(60)->by('psp:us:'.$userKey.':'.$sessionId),
            ];
        });

        RateLimiter::for('payment-session-action', function (Request $request) {
            $userKey = (string) ($request->user()?->id ?? $request->ip());
            $sessionId = (string) $request->route('id');

            return [
                Limit::perMinute(20)->by('psa:u:'.$userKey),
                Limit::perMinute(10)->by('psa:us:'.$userKey.':'.$sessionId),
            ];
        });

        // ------------------------------------------------------------------
        // The mobile API (/v2).
        //
        // Every ceiling here is keyed by ACCOUNT or DEVICE first and by IP only
        // as a flood stop. Carrier-grade NAT is the norm in our markets:
        // thousands of MTN or Orange subscribers leave through a single address,
        // so a tight per-IP cap locks real customers out in blocks while barely
        // inconveniencing an attacker holding one phone. The per-IP numbers
        // below are therefore deliberately large; they are not the defence.
        // ------------------------------------------------------------------

        // General ceiling for the whole group. Keyed on a hash of the bearer
        // token rather than the resolved user: it identifies the device just as
        // well, costs no database query, and avoids authenticating a request
        // inside a rate limiter that runs before the auth middleware.
        //
        // A caller with no token is keyed by address, and that ceiling is the
        // HIGHER one: hundreds of people browsing the catalogue before signing
        // in can be leaving through one carrier address, and the per-device
        // figure would lock them out in blocks. The signed-in figure sits well
        // above the payment poll (v2-poll), so polling cannot exhaust it.
        RateLimiter::for('v2', function (Request $request) {
            $identity = $this->v2Identity($request);

            return [
                str_starts_with($identity, 'tok:')
                    ? Limit::perMinute(300)->by('v2:'.$identity)
                    : Limit::perMinute(600)->by('v2:'.$identity),
                Limit::perMinute(1500)->by('v2:flood:'.$request->ip()),
            ];
        });

        // Anything where a caller is GUESSING: password login, and the
        // device-verification code. The per-account limit is the real one, so a
        // spray across many accounts from one address is stopped per account
        // and never punishes the innocent sharing that address.
        RateLimiter::for('v2-login', function (Request $request) {
            $email = $this->v2EmailKey($request);
            $installation = trim((string) $request->header('X-Installation-ID', ''));

            return array_values(array_filter([
                // An empty key is skipped rather than lumped into one shared
                // bucket, which would let requests carrying no email at all
                // burn through every other caller's budget.
                $email !== '' ? Limit::perMinute(5)->by('v2l:e:'.$email) : null,
                $email !== '' ? Limit::perHour(20)->by('v2l:eh:'.$email) : null,
                $installation !== '' ? Limit::perMinute(10)->by('v2l:d:'.$installation) : null,
                Limit::perMinute(300)->by('v2l:ip:'.$request->ip()),
            ]));
        });

        // Anything that CREATES or MOVES money: placing an order, authorising a
        // payment, funding a wallet, topping up a shortfall.
        //
        // Keyed on the bearer token, which is the device. Not tight, because a
        // legitimate customer buying three things in a row must not be told to wait,
        // and because the real protection against a repeated charge is the idempotency
        // key rather than a rate limit. This is a flood stop.
        RateLimiter::for('v2-money', function (Request $request) {
            return [
                Limit::perMinute(20)->by('v2m:'.$this->v2Identity($request)),
                Limit::perMinute(300)->by('v2m:flood:'.$request->ip()),
            ];
        });

        // The payment poll: GET /v2/payments/{session}. Generous on purpose: the
        // app asks repeatedly while a customer watches a spinner, and throttling
        // that is how somebody ends up staring at "pending" on a payment that
        // actually landed. The endpoint behind it is a cheap read.
        RateLimiter::for('v2-poll', function (Request $request) {
            return [
                Limit::perMinute(120)->by('v2p:'.$this->v2Identity($request)),
                Limit::perMinute(600)->by('v2p:flood:'.$request->ip()),
            ];
        });

        // Asking the gateway: POST /v2/payments/{session}/verify. NOT cheap: an
        // unsettled attempt costs a round trip to the gateway every time, so this
        // is capped at one call every five seconds per device. The app calls it on
        // return from the gateway and then sparingly; the cheap GET is for polling.
        RateLimiter::for('v2-verify', function (Request $request) {
            return [
                Limit::perMinute(12)->by('v2v:'.$this->v2Identity($request)),
                Limit::perMinute(120)->by('v2v:flood:'.$request->ip()),
            ];
        });

        // The transaction PIN. Four digits is ten thousand possibilities, so this is
        // the tightest limiter in the application and deliberately keyed by ACCOUNT
        // rather than device: somebody guessing a PIN will happily reinstall the app
        // between attempts, and the thing being protected is the wallet, not the phone.
        //
        // The service behind it locks the PIN after its own run of failures; this stops
        // the attempts ever arriving fast enough to matter.
        RateLimiter::for('v2-pin', function (Request $request) {
            $user = $request->user()?->id;

            $identity = $user !== null ? 'u:'.$user : 'ip:'.$request->ip();

            return [
                Limit::perMinute(5)->by('v2pin:'.$identity),
                Limit::perHour(20)->by('v2pin:h:'.$identity),
            ];
        });
        // Anything that CREATES an account or SENDS an email: register, resend
        // a code, forgot password. Capped tighter than reads, because each call
        // costs us a message and can be aimed at somebody else's inbox.
        RateLimiter::for('v2-issue', function (Request $request) {
            $email = $this->v2EmailKey($request);

            return array_values(array_filter([
                $email !== '' ? Limit::perMinute(3)->by('v2i:e:'.$email) : null,
                $email !== '' ? Limit::perHour(10)->by('v2i:eh:'.$email) : null,
                Limit::perMinute(20)->by('v2i:ip:'.$request->ip()),
                Limit::perHour(100)->by('v2i:iph:'.$request->ip()),
            ]));
        });
    }

    /**
     * Who a /v2 request is, for rate limiting.
     *
     * A hash of the bearer token rather than the resolved user: it identifies the
     * device just as well, costs no database query, and avoids authenticating a request
     * inside a limiter that runs before the auth middleware.
     *
     * Only on routes that actually authenticate. On a public route (login, the
     * catalogue) a bearer header proves nothing, and honouring it would let one
     * caller mint a fresh bucket per request by sending a random token each time.
     */
    private function v2Identity(Request $request): string
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null && $bearer !== '' && $this->v2RouteAuthenticates($request)) {
            return 'tok:'.hash('sha256', $bearer);
        }

        return 'ip:'.$request->ip();
    }

    private function v2RouteAuthenticates(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'auth:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The email a sign-in names, lower-cased, or '' when there is none worth keying
     * on. An array or object sent in its place is not an email and must not take
     * the limiter down with a type error before validation can refuse it.
     */
    private function v2EmailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower(trim($email)) : '';
    }

    /**
     * Identity for cart rate limiting: the authenticated user if there is one,
     * otherwise the guest cart cookie, otherwise the IP. Mirrors how
     * CartWebController itself resolves whose cart a request is touching.
     */
    private function cartKey(Request $request): string
    {
        return (string) ($request->user()?->id
            ?? $request->cookie('guest_token')
            ?? $request->ip());
    }
}
