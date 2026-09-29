<?php

namespace App\Providers;

use App\Domain\Communication\Listeners\Commerce\FulfillmentNotificationListener;
use App\Domain\Communication\Listeners\Commerce\OrderCompleteNoticeListener;
use App\Domain\Communication\Listeners\Commerce\OrderConfirmationListener;
use App\Domain\Communication\Listeners\Commerce\RefundNotificationListener;
use App\Domain\Communication\Listeners\Rewards\RcoinRewardListener;
use App\Domain\Communication\Listeners\Security\AccountRecoveryListener;
use App\Domain\Communication\Listeners\Security\NewDeviceAlertListener;
use App\Domain\Communication\Listeners\Security\OtpDispatchListener;
use App\Domain\Communication\Listeners\Security\PasswordEventListener;
use App\Domain\Communication\Listeners\Security\PinEventListener;
use App\Domain\Communication\Listeners\System\AdminEventNotifier;
use App\Domain\Communication\Listeners\System\WelcomeListener;
use App\Domain\Communication\Listeners\Wallet\FundingFailedListener;
use App\Domain\Communication\Listeners\Wallet\WalletCreditListener;
use App\Domain\Communication\Listeners\Wallet\WalletDebitListener;
use App\Domain\Communication\Providers\LogProvider;
use App\Domain\Communication\Providers\MailProviderInterface;
use App\Domain\Communication\Providers\ResendProvider;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Domain\Order\Events\OrderCompleted;
use App\Domain\Order\Events\OrderConfirmed;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Rewards\Events\RcoinEarned;
use App\Domain\Security\Events\AccountRecovered;
use App\Domain\Security\Events\NewDeviceDetected;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Events\PasswordChanged;
use App\Domain\Wallet\Events\FundingFailed;
use App\Domain\Wallet\Events\TransactionPinChanged;
use App\Domain\Wallet\Events\TransactionPinLocked;
use App\Domain\Wallet\Events\TransactionPinResetRequested;
use App\Domain\Wallet\Events\WalletCredited;
use App\Domain\Wallet\Events\WalletDebited;
use App\Models\CommunicationLog;
use App\Models\CommunicationPreference;
use App\Models\EmailSuppression;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CommunicationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(
            MailProviderInterface::class,
            function ($app) {
                // Resend needs an API key; without one (local, testing) fall
                // back to the log provider so emails land in laravel.log
                // instead of fataling on a null key.
                if (config('mail.default') === 'log' || blank(config('services.resend.key'))) {
                    return $app->make(LogProvider::class);
                }

                return $app->make(ResendProvider::class);
            }
        );

        $this->app->singleton(
            CommunicationManager::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Add database morph map for the new models to keep polymorphic types
        // clean. Deliberately NOT enforceMorphMap(): other domains (push
        // subscriptions, payment attempts) store FQCN morph types, which an
        // enforced map would reject at runtime.
        Relation::morphMap([
            'communication_log' => CommunicationLog::class,
            'communication_preference' => CommunicationPreference::class,
            'email_suppression' => EmailSuppression::class,
        ]);

        // Register Security Listeners
        Event::listen(OtpRequested::class, OtpDispatchListener::class);
        Event::listen(NewDeviceDetected::class, NewDeviceAlertListener::class);
        Event::listen(AccountRecovered::class, AccountRecoveryListener::class);
        Event::listen(PasswordChanged::class, PasswordEventListener::class);

        // Register PIN Listeners
        Event::listen(TransactionPinChanged::class, [PinEventListener::class, 'handlePinChanged']);
        Event::listen(TransactionPinLocked::class, [PinEventListener::class, 'handlePinLocked']);
        Event::listen(TransactionPinResetRequested::class, [PinEventListener::class, 'handlePinReset']);

        // Register System Listeners. The welcome email waits for a PROVEN
        // address: Verified fires it for normal signups (after the link click),
        // while Registered covers only accounts created pre-verified (Google).
        // The listener itself decides which case applies and dedupes for life.
        Event::listen(Registered::class, WelcomeListener::class);
        Event::listen(Verified::class, WelcomeListener::class);

        // Register Commerce Listeners. The confirmation email hangs on
        // OrderConfirmed (money arrived), NOT OrderPlaced (checkout skeleton
        // created): most checkouts are abandoned, so the old wiring emailed
        // "your order has been received" to customers who never paid, then the
        // sweeper cancelled the order behind the email's back.
        Event::listen(OrderConfirmed::class, OrderConfirmationListener::class);
        Event::listen(FulfillmentSucceeded::class, FulfillmentNotificationListener::class);
        Event::listen(OrderCompleted::class, OrderCompleteNoticeListener::class);
        Event::listen(RefundIssued::class, RefundNotificationListener::class);

        // Register Wallet Listeners
        Event::listen(WalletCredited::class, WalletCreditListener::class);
        Event::listen(WalletDebited::class, WalletDebitListener::class);
        Event::listen(FundingFailed::class, FundingFailedListener::class);

        // Register Rewards Listeners
        Event::listen(RcoinEarned::class, RcoinRewardListener::class);

        // Fan key business events out to the admin-dashboard notification feed.
        Event::subscribe(AdminEventNotifier::class);
    }
}
