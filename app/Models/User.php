<?php

namespace App\Models;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Security\PasswordResetMail;
use App\Domain\Communication\Mail\Security\VerifyEmailMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Shared\Enums\Currency;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/**
 * Represents a platform user.
 *
 * password is nullable to support Google OAuth users who sign up
 * without a password. The google_id column will be added in a
 * future migration when we implement Google authentication.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $gender "male" | "female" | "other"
 * @property string|null $google_id
 * @property string|null $avatar_url
 * @property string $theme "light" | "dark" | "system"
 * @property string|null $display_currency Customer-chosen display currency (null = auto)
 * @property Carbon|null $banned_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property Carbon|null $suspension_review_requested_at
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property int $session_version Bumped by "sign out everywhere"; sessions carrying an older value are refused
 * @property string|null $transaction_pin
 * @property Carbon|null $transaction_pin_set_at
 * @property int $transaction_pin_attempts
 * @property Carbon|null $transaction_pin_locked_until
 * @property Carbon|null $last_transaction_pin_used_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone_verified_at',
        'experience_prompted_at',
        'experience_rated_at',
        'country',
        'profile_completed_at',
        'gender',
        'referral_code',
        'password',
        'google_id',
        'avatar_url',
        'avatar',
        'theme',
        'locale',
        'display_currency',
        'rcoin_multiplier',
        'kyc_status',
        'banned_at',
        'suspended_at',
        'restricted_at',
        'suspension_reason',
        'suspension_review_requested_at',
        'closure_requested_at',
        'anonymised_at',
        'financial_records_purged_at',
        'transaction_pin',
        'transaction_pin_set_at',
        'transaction_pin_attempts',
        'transaction_pin_locked_until',
        'pin_status',
        'last_transaction_pin_used_at',
        'email_verified_at',
        'is_guest',
        'guest_claim_last_sent_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'transaction_pin',
    ];

    /**
     * A user row is never hard-deleted. Orders, payments, the wallet ledger
     * and identity records hang off it and must be kept for years after the
     * relationship ends, so the only supported exit is account closure, which
     * anonymises the row (see AccountClosureService). MySQL enforces the same
     * rule with RESTRICT foreign keys; this guard covers every other driver.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Users are never hard-deleted. Use AccountClosureService to close an account.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * The language this user's mail and notifications should render in.
     *
     * Laravel calls this on every Mailable and Notification addressed to the
     * model, which is what stops a queued job inheriting whichever locale the
     * worker happened to be left in by the previous job.
     *
     * Falls back rather than returning null: null would leave the queue on the
     * worker's current locale, which is the leak this exists to prevent.
     */
    public function preferredLocale(): string
    {
        $supported = array_keys(config('localization.supported', ['en' => []]));

        return in_array($this->locale, $supported, true)
            ? $this->locale
            : (string) config('localization.default', config('app.fallback_locale', 'en'));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'experience_prompted_at' => 'datetime',
            'experience_rated_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'rcoin_multiplier' => 'decimal:2',
            'banned_at' => 'datetime',
            'suspended_at' => 'datetime',
            'restricted_at' => 'datetime',
            'suspension_review_requested_at' => 'datetime',
            'closure_requested_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'financial_records_purged_at' => 'datetime',
            'password' => 'hashed',
            'session_version' => 'integer',
            'transaction_pin_set_at' => 'datetime',
            'transaction_pin_locked_until' => 'datetime',
            'last_transaction_pin_used_at' => 'datetime',
            'is_guest' => 'boolean',
            'guest_claim_last_sent_at' => 'datetime',
        ];
    }

    /**
     * Does this account still owe us the details every customer needs (country,
     * phone, gender, terms)?
     *
     * Only a Google sign-up can answer true: the register wizard collects all of
     * it up front, and every account that predates the completion step was
     * backfilled by its migration. Legacy guest shadow accounts are exempt for
     * the same reason they skip the email gate - they are claimed, not
     * registered.
     */
    public function needsProfileCompletion(): bool
    {
        return ! $this->is_guest && $this->profile_completed_at === null;
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * A deterministic SVG initials avatar returned as a base64 data URI, used
     * wherever the user hasn't set a real photo (Google `picture` or an
     * uploaded avatar). The background colour is hashed from the name/email so
     * the same user always gets the same swatch, and the initials come from
     * {@see initials()}. Drops straight into an <img src>.
     */
    public function initialsAvatar(int $size = 128): string
    {
        $initials = Str::of($this->initials())->upper()->substr(0, 2)->value() ?: 'U';
        $initials = htmlspecialchars($initials, ENT_QUOTES);

        $palette = ['#2563eb', '#7c3aed', '#0d9488', '#dc2626', '#ea580c', '#0891b2', '#4f46e5', '#db2777', '#16a34a', '#d97706'];
        $bg = $palette[crc32((string) ($this->name ?: $this->email ?: 'user')) % count($palette)];
        $fontSize = (int) round($size * 0.42);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'">'
            .'<rect width="'.$size.'" height="'.$size.'" fill="'.$bg.'"/>'
            .'<text x="50%" y="50%" dy=".05em" fill="#ffffff" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="'.$fontSize.'" font-weight="600" text-anchor="middle" dominant-baseline="central">'.$initials.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Whether the account is banned. A banned user is forced out of the session
     * by EnsureAccountActive middleware and blocked from signing in again.
     */
    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * Whether the account is suspended. Softer than ban: the user can still log
     * in and view their dashboard, but write actions (checkout, cart, funding)
     * are blocked by EnsureAccountNotSuspended. They can request a review,
     * which surfaces in the admin notification feed.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Whether the account is in Protected Mode (Level 2). The user can still log in,
     * but financial operations require self-serve recovery.
     */
    public function isRestricted(): bool
    {
        return $this->restricted_at !== null;
    }

    public function hasRequestedSuspensionReview(): bool
    {
        return $this->suspension_review_requested_at !== null;
    }

    /**
     * Whether the customer has asked to close the account and is inside the
     * grace period. Signing in again cancels it (CancelAccountClosureOnLogin).
     */
    public function hasRequestedClosure(): bool
    {
        return $this->closure_requested_at !== null && $this->anonymised_at === null;
    }

    /**
     * Whether the account has been closed and its personal details anonymised.
     * The row is kept as a pseudonymous holder for the financial records that
     * must outlive the relationship; it can never sign in again.
     */
    public function isClosed(): bool
    {
        return $this->anonymised_at !== null;
    }

    /**
     * The day the grace period ends and the account is anonymised.
     */
    public function closesOn(): ?Carbon
    {
        if (! $this->hasRequestedClosure()) {
            return null;
        }

        return $this->closure_requested_at->copy()->addDays((int) config('retention.account_closure_grace_days', 30));
    }

    /**
     * Whether the customer has passed identity verification (KYC). Drives the
     * verified badge shown next to their name across the admin and on the
     * reviews they leave.
     */
    public function isKycVerified(): bool
    {
        return strtolower((string) $this->kyc_status) === 'verified';
    }

    /**
     * Whether the captured phone number has been proven by OTP. Drives the
     * PhoneVerified rung of the KYC tier ladder.
     */
    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * The currency the user sees prices in across the app. Resolution order:
     *   1. Explicit `users.display_currency` preference (when the user picked one)
     *   2. Their primary wallet's currency
     *   3. USD as a final safety net
     *
     * Returns an ISO code (e.g. "NGN") so callers can pass it straight to
     * App\Domain\Shared\Services\Money::format().
     */
    public function displayCurrency(): string
    {
        if (! empty($this->display_currency)) {
            return strtoupper($this->display_currency);
        }

        $walletCurrency = $this->wallets()->orderBy('id')->value('currency');

        // Eloquent returns the Enum instance, so use ->value if it's an Enum, or cast if string
        $currencyString = $walletCurrency instanceof Currency ? $walletCurrency->value : (string) $walletCurrency;

        return $currencyString ? strtoupper($currencyString) : 'USD';
    }

    // ────────────────────────────────────────────────────────────
    //  Commerce Relationships
    // ────────────────────────────────────────────────────────────

    /**
     * Get a specific wallet by currency. Defaults to USD for legacy support.
     */
    public function wallet(string $currency = 'USD'): HasOne
    {
        return $this->hasOne(Wallet::class)->where('currency', $currency);
    }

    /**
     * Get all wallets owned by this user across different currencies.
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * Get the user's trusted devices.
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * USD balance figure for the storefront nav wallet chip. A user can hold one
     * wallet per currency; every funded wallet is converted to USD and summed so
     * the chip always shows a single dollar amount (formatted compactly by
     * {@see Wallet::compactUsd()}). `combined` flags that more than one wallet
     * contributed, for the aria-label.
     *
     * @return array{amount: float, combined: bool}
     */
    public function navWalletSummary(): array
    {
        // Rcoin is the rewards points balance, not spendable cash — it has its
        // own display on the rewards/dashboard pages and converts to USD via a
        // deliberate flow, so it never inflates the cash chip here.
        $funded = $this->wallets
            ->filter(fn (Wallet $wallet) => (float) $wallet->balance > 0)
            ->reject(fn (Wallet $wallet) => $this->walletCurrencyCode($wallet) === Currency::RCOIN->value)
            ->values();

        if ($funded->isEmpty()) {
            return ['amount' => 0.0, 'combined' => false];
        }

        // rate_per_usd is "currency units per 1 USD", so USD = balance / rate.
        $ratesPerUsd = CurrencyRate::query()
            ->where('is_active', true)
            ->pluck('rate_per_usd', 'code');

        $usdTotal = $funded->reduce(function (float $carry, Wallet $wallet) use ($ratesPerUsd): float {
            $code = $this->walletCurrencyCode($wallet);

            // A dollar wallet is already dollars. Never divide it by the USD
            // row, which carries the platform's pricing spread (e.g. 1.04).
            if ($code === 'USD') {
                return $carry + (float) $wallet->balance;
            }

            // No active rate for the currency = no honest conversion. Skip it
            // rather than pass the raw figure through 1:1 (4000 XAF is not
            // $4000 just because the rates table is missing a row).
            $rate = (float) ($ratesPerUsd[$code] ?? 0.0);

            return $carry + ($rate > 0 ? (float) $wallet->balance / $rate : 0.0);
        }, 0.0);

        return ['amount' => round($usdTotal, 2), 'combined' => $funded->count() > 1];
    }

    /**
     * The wallet dashboards present first. A funded wallet always wins over an
     * empty one; when several are funded, the largest USD-equivalent balance
     * takes the spot (a 12,000 XAF wallet beats a $10 one only if it converts
     * to more dollars). Rcoin is excluded - it is points, not cash. Falls back
     * to the USD wallet, then any wallet, when nothing is funded.
     */
    public function defaultWallet(): ?Wallet
    {
        $wallets = $this->wallets
            ->filter(fn (Wallet $wallet) => $wallet->is_active)
            ->reject(fn (Wallet $wallet) => $this->walletCurrencyCode($wallet) === Currency::RCOIN->value)
            ->values();

        if ($wallets->isEmpty()) {
            return null;
        }

        $funded = $wallets->filter(fn (Wallet $wallet) => (float) $wallet->balance > 0)->values();

        if ($funded->isEmpty()) {
            return $wallets->first(fn (Wallet $wallet) => $this->walletCurrencyCode($wallet) === 'USD')
                ?? $wallets->first();
        }

        if ($funded->count() === 1) {
            return $funded->first();
        }

        $ratesPerUsd = CurrencyRate::query()
            ->where('is_active', true)
            ->pluck('rate_per_usd', 'code');

        return $funded->sortByDesc(function (Wallet $wallet) use ($ratesPerUsd): float {
            $code = $this->walletCurrencyCode($wallet);
            if ($code === 'USD') {
                return (float) $wallet->balance;
            }
            $rate = (float) ($ratesPerUsd[$code] ?? 0);

            return $rate > 0 ? (float) $wallet->balance / $rate : 0.0;
        })->first();
    }

    /**
     * Uppercase ISO code of a wallet's currency, whether the cast returned the
     * enum or a raw string.
     */
    private function walletCurrencyCode(Wallet $wallet): string
    {
        $code = $wallet->currency instanceof Currency ? $wallet->currency->value : (string) $wallet->currency;

        return strtoupper($code);
    }

    /**
     * Get all wallet transactions for this user.
     *
     * Denormalized relationship — wallet_transactions has a direct
     * user_id column for fast queries without joining through wallets.
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Get all KYC submissions for this user, newest first.
     */
    public function kycSubmissions(): HasMany
    {
        return $this->hasMany(KycSubmission::class)->latest();
    }

    /**
     * Provider-era KYC attempts (Smile ID sessions), newest first.
     */
    public function kycVerifications(): HasMany
    {
        return $this->hasMany(KycVerification::class)->latest();
    }

    /**
     * Get all orders placed by this user.
     */
    public function kycTier(): BelongsTo
    {
        return $this->belongsTo(KycTier::class);
    }

    public function pushSubscriptions(): MorphMany
    {
        return $this->morphMany(PushSubscription::class, 'subscribable');
    }

    /**
     * Get all orders placed by this user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get all payment records for this user.
     *
     * Denormalized relationship — payments has a direct user_id
     * column for fast queries without joining through orders.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get all payment attempts for this user.
     */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    /**
     * Get all persistent notifications for this user.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get the user's notification preferences.
     */
    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class, 'user_id');
    }

    // ────────────────────────────────────────────────────────────
    //  Transaction PIN Orchestration
    // ────────────────────────────────────────────────────────────

    public function hasTransactionPin(): bool
    {
        return ! is_null($this->transaction_pin);
    }

    public function verifyTransactionPin(string $pin): bool
    {
        if (! $this->hasTransactionPin()) {
            return false;
        }

        return Hash::check($pin, $this->transaction_pin);
    }

    public function incrementTransactionPinAttempts(): void
    {
        $this->transaction_pin_attempts++;
        $this->save();
    }

    public function resetTransactionPinAttempts(): void
    {
        if ($this->transaction_pin_attempts > 0 || $this->transaction_pin_locked_until !== null) {
            $this->transaction_pin_attempts = 0;
            $this->transaction_pin_locked_until = null;
            $this->save();
        }
    }

    public function lockTransactionPin(int $minutes = 15): void
    {
        $this->transaction_pin_locked_until = now()->addMinutes($minutes);
        $this->save();
    }

    public function isTransactionPinLocked(): bool
    {
        // Check for permanent lock via pin_status
        if ($this->pin_status === 'locked') {
            return true;
        }

        // Check for temporary rate-limiting lockout
        return $this->transaction_pin_locked_until && $this->transaction_pin_locked_until->isFuture();
    }

    public function markTransactionPinUsed(): void
    {
        $this->last_transaction_pin_used_at = now();
        $this->save();
    }

    /**
     * Route password reset emails through the Communication Platform so they
     * are sent from verify@rshoprefill.com, logged, and preference-audited
     * instead of using Laravel's default notification mailer.
     */
    public function sendPasswordResetNotification($token): void
    {
        app(CommunicationManager::class)->send(
            recipient: $this,
            category: CommunicationCategory::PasswordReset,
            title: 'Reset your password',
            message: 'We received a request to reset your password. The reset link expires shortly.',
            mailable: new PasswordResetMail($this, $token),
            correlationId: 'password_reset_'.$this->id,
        );
    }

    /**
     * Route the account email-verification message through the Communication
     * Platform so it is branded (verify@), logged, and preference-audited like
     * every other transactional email, rather than Laravel's default template.
     * The mailable mints the same signed link the default notification would.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(CommunicationManager::class)->send(
            recipient: $this,
            category: CommunicationCategory::EmailVerification,
            title: 'Verify your email address',
            message: 'Confirm your email address to activate your account. The verification link expires shortly.',
            mailable: new VerifyEmailMail($this),
            correlationId: 'email_verification_'.$this->id,
        );
    }
}
