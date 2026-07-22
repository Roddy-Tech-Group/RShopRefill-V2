<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\AuditLogListener;
use App\Http\View\Composers\CartComposer;
use App\Http\View\Composers\SiteIdentityComposer;
use App\Support\FeatureFlag;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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

        // Append security-relevant events to the audit log.
        Event::subscribe(AuditLogListener::class);

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
    }
}
