<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Exceptions\AccountClosureException;
use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\AccountClosedMail;
use App\Domain\Communication\Mail\Security\AccountClosureRequestedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Security\Services\SessionRevocationService;
use App\Domain\Shared\Enums\OrderStatus;
use App\Models\AccountClosureFeedback;
use App\Models\ClosedAccountIdentity;
use App\Models\CurrencyRate;
use App\Models\User;
use App\Models\WalletFunding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Closing an account is a two-step affair, as the privacy policy promises.
 *
 * Step one, at the customer's request: the account is marked for closure and
 * the customer is signed out. Nothing is destroyed. Signing in again inside
 * the grace period cancels the closure.
 *
 * Step two, after the grace period: personal details are anonymised. The user
 * row itself survives as a pseudonymous holder for the orders, payments,
 * wallet ledger and identity-verification records that financial and
 * anti-money-laundering law require us to keep for years after the
 * relationship ends. Those rows are never deleted here; their own retention
 * windows are enforced separately.
 */
class AccountClosureService
{
    /**
     * Tables that exist only so this person can sign in, be reached, or be
     * recognised again. None of them is a financial record.
     *
     * @var list<string>
     */
    private const PERSONAL_TABLES = [
        'trusted_devices',
        'user_sessions',
        'security_events',
        'communication_preferences',
        'notification_preferences',
        'notifications',
        'carts',
        'sessions',
    ];

    public function __construct(
        private readonly CommunicationManager $communications,
        private readonly SessionRevocationService $sessions,
    ) {}

    public function graceDays(): int
    {
        return max(1, (int) config('retention.account_closure_grace_days', 30));
    }

    /**
     * Reasons this account cannot be closed right now, written for the customer.
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        foreach ($this->heldBalances($user) as [$currency, $held, $heldUsd]) {
            if ($heldUsd < $this->dustThresholdUsd()) {
                continue;
            }

            $blockers[] = sprintf(
                'Your %s wallet still holds %s %s. Wallet funds can only be spent inside the app, so use up the balance before closing your account.',
                $currency,
                number_format($held, 2),
                $currency,
            );
        }

        $inFlight = $user->orders()
            ->whereIn('order_status', [OrderStatus::Pending->value, OrderStatus::Processing->value])
            ->count();

        if ($inFlight > 0) {
            $blockers[] = $inFlight === 1
                ? 'You have an order that is still being processed. Wait for it to finish before closing your account.'
                : "You have {$inFlight} orders that are still being processed. Wait for them to finish before closing your account.";
        }

        return $blockers;
    }

    /**
     * Balances too small to spend. They do not block closure and are forfeited
     * with it; wallet funds are closed-loop and are never paid back out.
     *
     * @return list<string>
     */
    public function forfeitedBalances(User $user): array
    {
        $labels = [];

        foreach ($this->heldBalances($user) as [$currency, $held, $heldUsd]) {
            if ($heldUsd < $this->dustThresholdUsd()) {
                $labels[] = number_format($held, 2).' '.$currency;
            }
        }

        return $labels;
    }

    public function dustThresholdUsd(): float
    {
        return max(0.0, (float) config('retention.account_closure_dust_threshold_usd', 1.0));
    }

    /**
     * Every non-empty wallet as [currency code, amount held, USD equivalent].
     *
     * @return list<array{0: string, 1: float, 2: float}>
     */
    private function heldBalances(User $user): array
    {
        $balances = [];

        foreach ($user->wallets()->get() as $wallet) {
            $held = (float) $wallet->balance + (float) $wallet->locked_balance;

            if ($held <= 0.0) {
                continue;
            }

            $currency = is_object($wallet->currency) ? $wallet->currency->value : (string) $wallet->currency;
            $ratePerUsd = $currency === 'USD' ? 1.0 : (float) (CurrencyRate::resolve($currency)->rate_per_usd ?: 1.0);

            $balances[] = [$currency, $held, $held / $ratePerUsd];
        }

        return $balances;
    }

    /**
     * Mark the account for closure and tell the customer how to change their mind.
     * Optional exit feedback is kept as product signal, keyed by user id only.
     *
     * @param  list<string>  $reasons  keys of AccountClosureFeedback::REASONS
     *
     * @throws AccountClosureException
     */
    public function request(User $user, array $reasons = [], ?string $comment = null): void
    {
        if ($user->isClosed()) {
            throw new AccountClosureException('This account has already been closed.');
        }

        $blockers = $this->blockers($user);

        if ($blockers !== []) {
            throw new AccountClosureException($blockers[0]);
        }

        $requestedAt = now();
        $closesOn = $requestedAt->copy()->addDays($this->graceDays());

        $user->forceFill(['closure_requested_at' => $requestedAt])->save();

        // "The customer is signed out" means on every device, not just the
        // one that clicked: a session left alive elsewhere would keep the
        // account in use through the whole grace period.
        $this->sessions->revokeAll($user);

        $reasons = array_values(array_intersect($reasons, array_keys(AccountClosureFeedback::REASONS)));
        $comment = trim((string) $comment) ?: null;

        if ($reasons !== [] || $comment !== null) {
            AccountClosureFeedback::create(['user_id' => $user->id, 'reasons' => $reasons, 'comment' => $comment]);
        }

        Log::info('account.closure_requested', ['user_id' => $user->id, 'closes_on' => $closesOn->toDateString()]);

        $this->communications->send(
            recipient: $user,
            category: CommunicationCategory::AccountRestriction,
            title: 'Your account will close on '.$closesOn->format('j F Y'),
            message: 'Sign in again before then if you want to keep your account.',
            mailable: new AccountClosureRequestedMail($user->name, $closesOn, $this->graceDays()),
            correlationId: 'account_closure_'.$user->id.'_'.$requestedAt->timestamp,
        );
    }

    /**
     * The customer came back inside the grace period: the closure is void.
     */
    public function cancel(User $user): void
    {
        if ($user->closure_requested_at === null || $user->isClosed()) {
            return;
        }

        $user->forceFill(['closure_requested_at' => null])->save();

        Log::info('account.closure_cancelled', ['user_id' => $user->id]);

        $this->communications->send(
            recipient: $user,
            category: CommunicationCategory::AccountRestriction,
            title: 'Your account stays open',
            message: 'You signed in before the closing date, so your account closure has been cancelled.',
            correlationId: 'account_closure_cancelled_'.$user->id.'_'.now()->timestamp,
        );
    }

    /**
     * Accounts whose grace period has run out and which still hold personal data.
     *
     * @return Builder<User>
     */
    public function due(?Carbon $asOf = null): Builder
    {
        $cutoff = ($asOf ?? now())->copy()->subDays($this->graceDays());

        return User::query()
            ->whereNull('anonymised_at')
            ->whereNotNull('closure_requested_at')
            ->where('closure_requested_at', '<=', $cutoff)
            ->orderBy('id');
    }

    /**
     * Strip every personal detail from the account while keeping the row, and
     * with it the financial and identity records that hang off it.
     *
     * The farewell email goes to the address the customer actually used, so it
     * is sent before the address is overwritten and carries only plain strings:
     * a queued mailable holding the User model would reload the anonymised row.
     */
    public function anonymise(User $user): void
    {
        if ($user->isClosed()) {
            return;
        }

        $originalEmail = $user->email;
        $originalName = $user->name;
        $avatarUrl = $user->avatar_url;

        $this->communications->send(
            recipient: $user,
            category: CommunicationCategory::AccountRestriction,
            title: 'Your account has been closed',
            message: 'Your personal details have been removed from '.config('app.name').'.',
            mailable: new AccountClosedMail($originalName),
            correlationId: 'account_closed_'.$user->id,
            overrideEmail: $originalEmail,
        );

        DB::transaction(function () use ($user): void {
            $this->preserveIdentityIfMoneyMoved($user);

            // Any session that survived the grace period dies here on every
            // driver; the table purge below only reaches the database driver.
            $this->sessions->revokeAll($user);

            $user->forceFill([
                'name' => 'Closed account',
                'email' => sprintf('closed-%d@anonymised.invalid', $user->id),
                'email_verified_at' => null,
                'phone' => null,
                'phone_verified_at' => null,
                'gender' => null,
                'country' => null,
                'google_id' => null,
                'avatar_url' => null,
                'avatar' => null,
                'password' => null,
                'remember_token' => null,
                'transaction_pin' => null,
                'transaction_pin_set_at' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'referral_code' => null,
                'display_currency' => null,
                'anonymised_at' => now(),
            ])->save();

            foreach (self::PERSONAL_TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                    DB::table($table)->where('user_id', $user->id)->delete();
                }
            }

            $user->pushSubscriptions()->delete();
        });

        if ($avatarUrl && str_starts_with($avatarUrl, '/storage/avatars/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $avatarUrl));
        }

        Log::info('account.anonymised', ['user_id' => $user->id]);
    }

    /**
     * An account that moved money must stay answerable to "who was this?" for
     * the statutory period even though its profile is being anonymised. The
     * identity goes into its own encrypted, admin-only table with a retention
     * date; the retention sweep removes it when that date passes. Accounts
     * that never transacted have nothing to answer for and get no entry.
     */
    private function preserveIdentityIfMoneyMoved(User $user): void
    {
        $movedMoney = $user->orders()->exists()
            || $user->walletTransactions()->exists()
            || WalletFunding::query()->where('user_id', $user->id)->exists()
            || $user->paymentAttempts()->exists();

        if (! $movedMoney) {
            return;
        }

        $kyc = $user->kycVerifications()->first();
        $closedAt = now();

        ClosedAccountIdentity::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'country' => $user->country,
                'kyc_provider' => $kyc?->provider,
                'kyc_reference' => $kyc?->reference,
                'closed_at' => $closedAt,
                'retain_until' => $closedAt->copy()->addYears(max(1, (int) config('retention.closed_account_identity_years', 5))),
            ],
        );
    }
}
