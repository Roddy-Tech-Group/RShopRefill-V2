<?php

namespace App\Providers;

use App\Domain\Audit\Listeners\AuditLogListener;
use App\Domain\Notification\Listeners\AdminEventNotifier;
use App\Domain\Notification\Listeners\CreateDefaultPreferencesListener;
use App\Domain\Notification\Listeners\SendFulfillmentNotificationListener;
use App\Domain\Notification\Listeners\SendOrderConfirmationListener;
use App\Domain\Notification\Listeners\SendWalletCreditNotificationListener;
use App\Domain\Notification\Listeners\SendWalletDebitNotificationListener;
use App\Domain\Notification\Listeners\SendWalletFundingFailedNotificationListener;
use App\Domain\Notification\Listeners\SendWelcomeEmailListener;
use App\Domain\Notification\Providers\MailProviderInterface;
use App\Domain\Notification\Providers\ResendProvider;
use App\Domain\Notification\Providers\WebPushProvider;
use App\Domain\Wallet\Events\FundingFailed;
use App\Domain\Wallet\Events\WalletCredited;
use App\Domain\Wallet\Events\WalletDebited;
use App\Http\View\Composers\CartComposer;
use App\Http\View\Composers\SiteIdentityComposer;
use App\Listeners\CommerceNotificationListener;
use App\Listeners\CreateWalletForNewUser;
use App\Listeners\TransactionPinNotificationListener;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Registered;
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
    public function register(): void
    {
        $this->app->singleton(
            MailProviderInterface::class,
            ResendProvider::class
        );

        $this->app->singleton(
            WebPushProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Registered::class, CreateWalletForNewUser::class);
        Event::listen(Registered::class, CreateDefaultPreferencesListener::class);
        Event::listen(Registered::class, SendWelcomeEmailListener::class);

        Event::listen(WalletCredited::class, SendWalletCreditNotificationListener::class);
        Event::listen(WalletDebited::class, SendWalletDebitNotificationListener::class);
        // Customer notification when a top-up is rejected by the gateway.
        // Pairs with the WalletCredited listener so both outcomes surface
        // in the bell feed, not just successes.
        Event::listen(FundingFailed::class, SendWalletFundingFailedNotificationListener::class);

        Event::subscribe(SendOrderConfirmationListener::class);
        Event::subscribe(SendFulfillmentNotificationListener::class);

        Event::subscribe(CommerceNotificationListener::class);

        // Fan key platform events out to the admin-dashboard notification feed.
        Event::subscribe(AdminEventNotifier::class);

        // Transaction PIN lifecycle emails (created, changed, locked, reset).
        Event::subscribe(TransactionPinNotificationListener::class);

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
