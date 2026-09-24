<?php

use App\Domain\Payment\Support\FundingMethodCatalog;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\CurrencyRateService;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Domain\Wallet\Services\WalletService;
use App\Models\CurrencyRate;
use Livewire\Volt\Component;

/**
 * Fund Wallet — the action behind the dashboard wallet card's button.
 *
 * Collects an amount + currency and calls WalletFundingService::initializeFunding,
 * which creates the WalletFunding record and returns the gateway's hosted payment
 * link. We hand the customer off to that link — completing the payment and crediting
 * the wallet (gateway webhook) is backend / real-gateway work, intentionally not
 * done here.
 */
new class extends Component
{
    public function with(): array
    {
        return [
            // payable(), not just is_active: a coin we have enabled but the gateway
            // no longer accepts on our account would fail at payment creation. The
            // checkout picker already filters on this — funding must match, or the
            // two screens offer different coin sets.
            'cryptoCoins' => CurrencyRate::query()->payable()->crypto()
                ->orderBy('sort_order')->orderBy('code')->get(),
        ];
    }

    /**
     * A pause or lock this customer is already serving, so a refreshed page
     * starts the countdown on the top-up button straight away instead of
     * offering Continue to payment and refusing it a moment later.
     *
     * @return array{wait_seconds: int, locked: bool, message: string}|null
     */
    public function paymentHold(): ?array
    {
        return app(\App\Domain\Payment\Services\PaymentSessionService::class)
            ->currentHold(auth()->user(), 'wallet_funding');
    }

    /** Funding form. */
    public string $currency = 'USD';

    public string $amount = '';

    /** Trigger style: 'full' (desktop wallet card) or 'compact' (mobile hero). */
    public string $variant = 'full';

    /**
     * Open the sheet on page load. Set when the page was reached through a
     * deep link for this wallet's currency (?fund=USD&amount=50), which is
     * how the payment-recovery email resumes a failed top-up: the customer
     * lands with the sheet open and the amount filled in, not on a bare
     * dashboard wondering where to click.
     */
    public bool $openOnLoad = false;

    public function mount(string $currency = 'USD', string $variant = 'full'): void
    {
        // RCOIN is a reward ledger - it's earned, not topped up. If the parent
        // accidentally passes RCOIN (e.g. the active wallet in the mobile
        // carousel happens to be the Rcoin tab), fall back to USD so the modal
        // still opens to a fundable currency.
        $resolved = Currency::tryFrom(strtoupper($currency))?->value ?? 'USD';
        $this->currency = $resolved === 'RCOIN' ? 'USD' : $resolved;
        $this->variant = $variant;

        $requested = strtoupper(trim((string) request()->query('fund', '')));

        if ($requested !== '' && $requested === $this->currency) {
            $this->openOnLoad = true;

            $amount = (float) request()->query('amount', 0);
            if ($amount > 0) {
                $this->amount = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
            }
        }
    }

    /**
     * The currencies a customer can actually fund. Excludes RCOIN (reward
     * ledger, credited via the engine - not purchasable).
     *
     * @return array<int, Currency>
     */
    public static function fundableCurrencies(): array
    {
        return array_values(array_filter(
            Currency::cases(),
            fn (Currency $c) => $c->value !== 'RCOIN',
        ));
    }

    /** Selected currency, as the enum. */
    private function currencyEnum(): Currency
    {
        return Currency::tryFrom($this->currency) ?? Currency::USD;
    }

    /** Minimum funding amount for the selected currency. */
    public function minimum(): float
    {
        return $this->currencyEnum()->minimumFundingAmount();
    }

    /** Symbol for the selected currency. */
    public function symbol(): string
    {
        return $this->currencyEnum()->symbol();
    }

    /**
     * Crypto payments settle to USDT at the gateway, whose minimum sits around
     * $12-13 regardless of the coin the customer pays with. We hold a flat $13
     * USD floor (with a little headroom) so crypto top-ups always clear it.
     */
    private const CRYPTO_MINIMUM_USD = 13.0;

    /**
     * The $13 USD crypto floor translated into the selected funding currency.
     * Uses the display rate (never throws on a stale rate) and rounds up so the
     * translated figure always covers the USD floor.
     */
    public function cryptoMinimum(): float
    {
        if ($this->currency === 'USD') {
            return self::CRYPTO_MINIMUM_USD;
        }

        $converted = app(CurrencyRateService::class)
            ->convertForDisplay(self::CRYPTO_MINIMUM_USD, 'USD', $this->currency);

        return (float) ceil($converted);
    }

    /** The crypto floor formatted for display, e.g. "$13.00" or "₦20,150". */
    public function cryptoMinimumLabel(): string
    {
        $decimals = in_array($this->currency, ['NGN', 'XAF'], true) ? 0 : 2;

        return $this->symbol().number_format($this->cryptoMinimum(), $decimals);
    }

    /** Whether the entered amount clears the crypto floor. */
    public function meetsCryptoMinimum(): bool
    {
        return (float) $this->amount >= $this->cryptoMinimum();
    }

    /**
     * The minimum the amount screen enforces. The currency floor when a fiat
     * rail can take the money; otherwise crypto is the only rail, so its floor
     * is the real one and is shown here rather than two screens later.
     */
    public function effectiveMinimum(): float
    {
        return $this->fiatIsLive()
            ? $this->minimum()
            : max($this->minimum(), $this->cryptoMinimum());
    }

    public function effectiveMinimumLabel(): string
    {
        $decimals = in_array($this->currency, ['NGN', 'XAF'], true) ? 0 : 2;

        return $this->symbol().number_format($this->effectiveMinimum(), $decimals);
    }

    /** Whether any fiat method can take money in the selected currency right now. */
    public function fiatIsLive(): bool
    {
        return FundingMethodCatalog::hasLiveFiat($this->currency);
    }

    /**
     * Screen 1 -> screen 2 with NOTHING written.
     *
     * Validates the amount against the effective minimum, raises the email gate
     * early (the same notice the service raises at commit), and hands the sheet
     * a session-less draft: the method tiles for this currency, each flagged
     * live or not, so a dark rail greys out exactly as it does at checkout.
     * The funding row is minted only when the customer commits to a method
     * (fund(), fiat) or a coin (fundCrypto()).
     */
    public function prepare(): void
    {
        if (! \App\Support\FeatureFlag::on('wallet_funding')) {
            $this->addError('amount', 'Wallet funding is temporarily disabled.');

            return;
        }

        $this->validate([
            'currency' => ['required', 'in:'.implode(',', array_map(fn ($c) => $c->value, self::fundableCurrencies()))],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $currency = $this->currencyEnum();

        if ((float) $this->amount < $this->effectiveMinimum()) {
            $this->addError('amount', $this->fiatIsLive()
                ? 'Minimum funding amount is '.$this->effectiveMinimumLabel().'.'
                : 'Crypto top-ups start at '.$this->effectiveMinimumLabel().' while card and bank payments are unavailable.');

            return;
        }

        if ((float) $this->amount > $currency->maximumFundingAmount()) {
            $this->addError('amount', 'Maximum funding amount per transaction is '.$this->symbol().number_format($currency->maximumFundingAmount(), 2).'.');

            return;
        }

        $user = auth()->user();

        if (! $user->is_guest && ! $user->hasVerifiedEmail()) {
            $throttle = app(\App\Support\VerificationResendThrottle::class)->attemptSend($user);
            $gate = new \App\Domain\Auth\Exceptions\EmailVerificationRequiredException(
                $user->email,
                $throttle['sent'],
                max($throttle['retry_after'], 30),
            );

            $this->dispatch('email-verification-required',
                message: $gate->customerMessage(),
                retryAfter: $gate->retryAfter,
            );

            return;
        }

        $this->dispatch('funding-draft-ready', draft: [
            'currency' => $currency->value,
            'amount' => (float) $this->amount,
            'methods' => FundingMethodCatalog::forCurrency($currency->value, $this->cryptoMinimumLabel()),
            'down_rails' => FundingMethodCatalog::downRails($currency->value),
        ]);
    }

    /**
     * Commit to CRYPTO: the customer has picked a coin. This is the first
     * write on the crypto path - funding, attempt and session are created
     * around the gateway call and rolled back together if it refuses.
     */
    public function fundCrypto(string $payCurrency): void
    {
        if (! \App\Support\FeatureFlag::on('wallet_funding')) {
            $this->dispatch('funding-error', message: 'Wallet funding is temporarily disabled.');

            return;
        }

        $this->validate([
            'currency' => ['required', 'in:'.implode(',', array_map(fn ($c) => $c->value, self::fundableCurrencies()))],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $currency = $this->currencyEnum();
        $wallet = app(WalletService::class)->getOrCreateWallet(auth()->user(), $currency);

        try {
            $result = app(WalletFundingService::class)->initializeCryptoFunding(
                user: auth()->user(),
                wallet: $wallet,
                amount: (float) $this->amount,
                currency: $currency,
                payCurrency: $payCurrency,
            );

            $resource = new \App\Http\Resources\PaymentSessionResource($result['payment_session']);

            // Already awaiting_transfer: the sheet renders the address screen.
            $this->dispatch('payment-session-initialized',
                session: $resource->toArray(request()),
                reference: $result['funding']->reference,
            );
        } catch (\App\Domain\PaymentInsights\Exceptions\PaymentBackoffException $e) {
            $this->dispatch('payment-backoff-triggered', seconds: $e->waitSeconds, message: $e->customerMessage(), locked: $e->locked);
        } catch (\App\Domain\Auth\Exceptions\EmailVerificationRequiredException $e) {
            $this->dispatch('email-verification-required',
                message: $e->customerMessage(),
                retryAfter: $e->retryAfter,
            );
        } catch (\App\Domain\Kyc\Exceptions\KycStepUpRequiredException $e) {
            $this->dispatch('kyc-step-up-required', payload: $e->toResponse());
        } catch (\App\Domain\Risk\Exceptions\RiskReviewRequiredException|\App\Domain\Risk\Exceptions\RiskDeniedException|\App\Domain\Kyc\Exceptions\SpendingLimitExceededException $e) {
            $this->dispatch('risk-hold', message: $e->customerMessage());
        } catch (\App\Domain\Wallet\Exceptions\WalletFundingException|\App\Domain\Payment\Exceptions\GatewayDisabledException|\InvalidArgumentException $e) {
            // Customer-safe by construction: coin gate, kill switch, bounds,
            // or the provider's own refusal wording.
            $this->dispatch('funding-error', message: $e->getMessage());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Crypto wallet funding failed to start', [
                'user_id' => auth()->id(),
                'currency' => $currency->value,
                'pay_currency' => $payCurrency,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('funding-error', message: 'We could not start this crypto payment. Please try again.');
        }
    }

    /**
     * Commit to a FIAT method: the customer has picked a card / bank / mobile
     * money tile. Mints the funding and its session the way it always has and
     * hands the session to the sheet, which continues into that tile's flow.
     */
    public function fund(WalletService $wallets, WalletFundingService $funding)
    {
        // features.wallet_funding_enabled kill-switch. Existing balance can
        // still spend; only NEW top-ups are blocked.
        if (! \App\Support\FeatureFlag::on('wallet_funding')) {
            $this->addError('amount', 'Wallet funding is temporarily disabled.');
            return;
        }

        $this->validate([
            'currency' => ['required', 'in:'.implode(',', array_map(fn ($c) => $c->value, self::fundableCurrencies()))],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $currency = $this->currencyEnum();

        if ((float) $this->amount < $currency->minimumFundingAmount()) {
            $this->addError('amount', 'Minimum funding amount is '.$currency->symbol().number_format($currency->minimumFundingAmount(), 2).'.');

            return;
        }

        $wallet = $wallets->getOrCreateWallet(auth()->user(), $currency);

        try {
            $result = $funding->initializeFunding(
                user: auth()->user(),
                wallet: $wallet,
                amount: (float) $this->amount,
                currency: $currency,
            );

            $resource = new \App\Http\Resources\PaymentSessionResource($result['payment_session']);

            // Dispatch event with the payment session payload for inline handling
            $this->dispatch('payment-session-initialized',
                session: $resource->toArray(request()),
                reference: $result['funding']->reference,
            );
        } catch (\App\Domain\PaymentInsights\Exceptions\PaymentBackoffException $e) {
            $this->dispatch('payment-backoff-triggered', seconds: $e->waitSeconds, message: $e->customerMessage(), locked: $e->locked);
        } catch (\App\Domain\Auth\Exceptions\EmailVerificationRequiredException $e) {
            // Same verify-email notice checkout shows: amber envelope, message,
            // Resend with the escalating countdown, Got it.
            $this->dispatch('email-verification-required',
                message: $e->customerMessage(),
                retryAfter: $e->retryAfter,
            );
        } catch (\App\Domain\Kyc\Exceptions\KycStepUpRequiredException $e) {
            // Identity verification required. Hand the Smile session to the
            // frontend, which shows a Verify button that launches the flow and
            // retries this funding once the customer is verified.
            $this->dispatch('kyc-step-up-required', payload: $e->toResponse());
        } catch (\App\Domain\Risk\Exceptions\RiskReviewRequiredException $e) {
            $this->dispatch('risk-hold', message: $e->customerMessage());
        } catch (\App\Domain\Risk\Exceptions\RiskDeniedException $e) {
            $this->dispatch('risk-hold', message: $e->customerMessage());
        } catch (\App\Domain\Kyc\Exceptions\SpendingLimitExceededException $e) {
            // Rolling transaction cap reached: the same calm notice, with the
            // nudge toward verification that raises the cap.
            $this->dispatch('risk-hold', message: $e->customerMessage());
        } catch (\Throwable $e) {
            $this->addError('amount', $e->getMessage());
            // The sheet is on the method screen by now, where the amount
            // field (and its error) is out of view.
            $this->dispatch('funding-error', message: $e->getMessage());
        }
    }
}; ?>

<div
    x-data="{
        open: @js($openOnLoad),
        initialHold: @js($this->paymentHold()),
        ccyOpen: false,
        session: null,
        // Session-less method list from prepare(): the sheet shows tiles off
        // this until the customer commits and a real session replaces it.
        draft: null,
        // The fiat tile clicked before its session existed; continued into
        // once fund() hands the session back.
        pendingMethod: null,
        backoffRemaining: 0,
        backoffInterval: null,
        // When the hold ends, as a wall-clock time, and how long it was when
        // it started. The end time keeps the countdown true after the tab has
        // been in the background (browsers slow timers there to once a
        // minute); the starting length decides the wording, so it never
        // changes halfway through a countdown.
        backoffEndsAt: 0,
        backoffTotal: 0,
        selectedMethod: null,
        cardDetails: {
            card_number: '',
            cvv: '',
            expiry_month: '',
            expiry_year: '',
            card_holder: ''
        },
        cardBrand: 'unknown',
        cardExpiryRaw: '',
        pinValue: '',
        otpValue: '',
        momoDetails: {
            phone_number: '',
            network: ''
        },
        cryptoDetails: {
            pay_currency: ''
        },
        paymentState: 'idle', // idle, select_method, card_input, action_pin, action_otp, action_3ds, awaiting_transfer, awaiting_confirmation, momo_input, processing, success, error
        errorMessage: '',
        actionMessage: '',
        bankDetails: null,
        pollInterval: null,
        verifying: false,
        applePayAvailable: false,

        // Risk/KYC overlay: identity step-up (Verify -> Smile), a neutral
        // review/declined hold, or the verify-email notice, driven by events
        // the Livewire fund() dispatches.
        riskOverlay: false,
        riskKind: null, // 'kyc' | 'hold' | 'email'
        kycMessage: '',
        kycSmile: null,
        holdMessage: '',
        emailMessage: '',
        // The limiter's own sentence, and whether this is the six hour device
        // lock rather than a short pause. The heading is the only thing the
        // flag changes; the wording always comes from the server.
        backoffMessage: '',
        backoffLocked: false,
        resendRemaining: 0,
        resendTimerId: null,
        resendBusy: false,
        resendFlash: '',

        init() {
            // A pause or lock already being served when the page loaded:
            // start the countdown now, so a refresh never offers Continue to
            // payment only to refuse it a moment later.
            if (this.initialHold && this.initialHold.wait_seconds > 0) {
                this.backoffLocked = !! this.initialHold.locked;
                this.backoffMessage = this.initialHold.message || '';
                this.startBackoff(this.initialHold.wait_seconds);
            }

            if (!document.getElementById('flutterwave-v3')) {
                let script = document.createElement('script');
                script.id = 'flutterwave-v3';
                script.src = 'https://checkout.flutterwave.com/v3.js';
                // Blocked/failed SDK loads are reported when the customer
                // actually tries to pay (no session exists yet at page load).
                script.onerror = () => { window.__flwSdkLoadFailed = true; };
                document.head.appendChild(script);
            }
            this.detectApplePay();
        },

        startBackoff(seconds) {
            const total = Math.max(0, Math.round(Number(seconds) || 0));

            this.backoffTotal = total;
            this.backoffEndsAt = Date.now() + total * 1000;
            this.backoffRemaining = total;

            if (this.backoffInterval) clearInterval(this.backoffInterval);
            this.backoffInterval = setInterval(() => {
                this.backoffRemaining = Math.max(0, Math.ceil((this.backoffEndsAt - Date.now()) / 1000));

                if (this.backoffRemaining === 0) {
                    clearInterval(this.backoffInterval);
                }
            }, 1000);
        },

        /**
         * The time left as a running clock: 5:59:59 for the lock, 4:59 for the
         * pauses. Exact to the second, so it never rounds to an odd reading of
         * about 60 minutes.
         *
         * NO DOUBLE QUOTES anywhere in this x-data block, comments included:
         * the whole object sits inside a double-quoted HTML attribute, and one
         * stray quote ends the attribute, kills the component, and leaves this
         * modal stuck open with a dead close button.
         */
        backoffCountdown() {
            const left = Math.max(0, this.backoffRemaining);
            const hours = Math.floor(left / 3600);
            const minutes = Math.floor((left % 3600) / 60);
            const seconds = left % 60;
            const pad = (value) => String(value).padStart(2, '0');

            return hours > 0
                ? hours + ':' + pad(minutes) + ':' + pad(seconds)
                : minutes + ':' + pad(seconds);
        },

        /**
         * The top-up button's label while a hold is running. The five minute
         * pause says why; the one minute pause and the lock just show the
         * clock, the lock's reason being on its notice. Chosen from the hold's
         * starting length, so the wording stays put while the clock runs down.
         */
        backoffLabel() {
            if (! this.backoffLocked && this.backoffTotal > 60) {
                return 'Too many attempts, try again in ' + this.backoffCountdown();
            }

            return 'Try again in ' + this.backoffCountdown();
        },

        /**
         * Detect Apple Pay support across browsers:
         *  - Safari: window.ApplePaySession.canMakePayments()
         *  - Chrome/Edge on iOS 16+: PaymentRequest with apple-pay method
         * Our backend uses Flutterwave's redirect flow, so we only need
         * to know the device *can* use Apple Pay — we don't create a
         * local ApplePaySession ourselves.
         */
        async detectApplePay() {
            // 1. Safari-native check (fastest, synchronous)
            try {
                if (window.ApplePaySession
                    && typeof window.ApplePaySession.canMakePayments === 'function'
                    && window.ApplePaySession.canMakePayments()) {
                    this.applePayAvailable = true;
                    return;
                }
            } catch (_) {}

            // 2. W3C PaymentRequest fallback (Chrome / Edge on iOS 16+)
            try {
                if (window.PaymentRequest) {
                    const methods = [{ supportedMethods: 'https://apple.com/apple-pay' }];
                    const details = { total: { label: 'Check', amount: { currency: 'USD', value: '0.01' } } };
                    const request = new PaymentRequest(methods, details);
                    if (typeof request.canMakePayment === 'function') {
                        this.applePayAvailable = await request.canMakePayment();
                        return;
                    }
                }
            } catch (_) {}

            this.applePayAvailable = false;
        },

        getFilteredMethods() {
            const methods = this.session?.available_methods ?? this.draft?.methods ?? [];
            return methods.filter(m => {
                if (m.type === 'apple_pay' && !this.applePayAvailable) return false;
                return true;
            });
        },

        // Screen 2 without a session: nothing has been written yet.
        initDraft(draft) {
            this.session = null;
            this.draft = draft;
            this.pendingMethod = null;
            this.paymentState = 'select_method';
            this.selectedMethod = null;
            this.errorMessage = '';
            this.pinValue = '';
            this.otpValue = '';
            this.resetCardDetails();
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
            }
        },

        initPayment(session) {
            this.session = session;
            this.selectedMethod = null;
            this.errorMessage = '';
            this.pinValue = '';
            this.otpValue = '';
            this.resetCardDetails();
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
            }

            // A session born already charged (the crypto commit hands back an
            // awaiting_transfer session with its address) goes straight to
            // that screen.
            if (['awaiting_transfer', 'awaiting_confirmation', 'awaiting_customer_action', 'awaiting_redirect', 'confirmed', 'failed'].includes(session?.status)) {
                this.handlePayResponse(session);
                return;
            }

            // A session minted because a fiat tile was picked continues into
            // that tile's flow instead of asking the customer to pick again.
            if (this.pendingMethod) {
                const method = this.pendingMethod;
                this.pendingMethod = null;
                this.continueWithMethod(method);
                return;
            }

            this.paymentState = 'select_method';
        },

        resetCardDetails() {
            this.cardDetails = {
                card_number: '',
                cvv: '',
                expiry_month: '',
                expiry_year: '',
                card_holder: ''
            };
            this.cardBrand = 'unknown';
            this.cardExpiryRaw = '';
            this.momoDetails = {
                phone_number: '',
                network: ''
            };
        },

        detectCardType() {
            let num = this.cardDetails.card_number.replace(/\D/g, '');
            
            // Auto-format card number as they type (e.g. 1234 5678 1234 5678)
            let formatted = '';
            for (let i = 0; i < num.length; i++) {
                if (i > 0 && i % 4 === 0) {
                    formatted += ' ';
                }
                formatted += num[i];
            }
            this.cardDetails.card_number = formatted;

            // Detect brand
            if (num.startsWith('4')) {
                this.cardBrand = 'visa';
            } else if (/^(5[1-5]|222[1-9]|22[3-9]|2[3-6]|27[0-1]|2720)/.test(num)) {
                this.cardBrand = 'mastercard';
            } else if (/^(506[0-1]|507[8-9]|6500)/.test(num)) {
                this.cardBrand = 'verve';
            } else if (/^(34|37)/.test(num)) {
                this.cardBrand = 'amex';
            } else if (/^(6011|65)/.test(num)) {
                this.cardBrand = 'discover';
            } else if (/^(35)/.test(num)) {
                this.cardBrand = 'jcb';
            } else {
                this.cardBrand = 'unknown';
            }
        },

        formatExpiry() {
            let exp = this.cardExpiryRaw.replace(/\D/g, '');
            if (exp.length > 2) {
                this.cardExpiryRaw = exp.slice(0, 2) + ' / ' + exp.slice(2, 4);
            } else {
                this.cardExpiryRaw = exp;
            }
            this.cardDetails.expiry_month = exp.slice(0, 2);
            this.cardDetails.expiry_year = exp.slice(2, 4);
        },

        selectPaymentMethod(method) {
            // A dark rail is shown greyed, never actionable.
            if (method.enabled === false) return;

            this.selectedMethod = method;

            // Crypto commits at the coin, not here: no session yet.
            if (method.type === 'crypto') {
                this.cryptoDetails.pay_currency = method.coin || 'usdt';
                this.paymentState = 'crypto_input';
                return;
            }

            // A fiat tile is the commitment point: mint the session now, then
            // initPayment() continues into this tile once it arrives.
            if (! this.session) {
                this.pendingMethod = method;
                this.paymentState = 'processing';
                this.$wire.fund();
                return;
            }

            this.continueWithMethod(method);
        },

        // Drive a fiat tile against a live session.
        continueWithMethod(method) {
            this.selectedMethod = method;
            if (method.type === 'card') {
                this.paymentState = 'card_input';
            } else if (method.type === 'bank_transfer') {
                this.paySession('bank_transfer');
            } else if (method.type === 'mobile_money') {
                this.momoDetails.network = (method.supported_networks ?? [])[0] ?? '';
                this.paymentState = 'momo_input';
            } else if (method.type === 'apple_pay') {
                this.paySession('apple_pay', {});
            } else {
                // Hosted-redirect family (USSD, Pay With Bank, Bank QR, e-wallets):
                // the pay step returns awaiting_redirect and handlePayResponse
                // forwards the customer.
                this.paySession(method.type, {});
            }
        },

        // The crypto commit. First write on this path; the server rolls back
        // if the gateway refuses, so a failure here leaves no trace.
        selectCoin(code) {
            this.cryptoDetails.pay_currency = code;

            // A session already exists when the customer picked a fiat tile,
            // backed out, and chose crypto instead (or already holds a live
            // crypto quote). Re-stamp THAT session through the pay step, as
            // before, rather than minting a second funding beside it.
            if (this.session) {
                if (this.session.status === 'awaiting_transfer') {
                    this.handlePayResponse(this.session);
                    return;
                }
                this.paySession('crypto', { pay_currency: code });
                return;
            }

            this.paymentState = 'processing';
            this.$wire.fundCrypto(code);
        },

        onFundingError(message) {
            this.pendingMethod = null;
            this.paymentState = 'error';
            this.errorMessage = message || 'Something went wrong. Please try again.';
        },

        async paySession(method, dataPayload = {}) {
            this.paymentState = 'processing';
            this.errorMessage = '';
            try {
                let body = {
                    method: method,
                    details: dataPayload
                };
                if (this.pinValue) {
                    body.pin = this.pinValue;
                }
                if (this.otpValue) {
                    body.otp = this.otpValue;
                    body.flw_ref = this.session.payment_payload?.flw_ref || '';
                }

                let response = await fetch(`/api/payment-sessions/${this.session.id}/pay`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        // Unquoted CSS attribute selector on purpose — any inner double quote would
                        // close the outer x-data attribute and dump every line of JS below into the
                        // page as visible text (the wallet-card-overflow bug from session 2026-05-20).
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                    },
                    body: JSON.stringify(body)
                });

                let resData;
                try {
                    resData = await response.json();
                } catch (jsonErr) {
                    resData = { message: 'Server error (' + response.status + '): ' + response.statusText };
                }

                if (!response.ok) {
                    this.paymentState = 'error';
                    this.errorMessage = resData.message || 'Payment initiation failed.';
                    return;
                }

                this.handlePayResponse(resData.data || resData);
            } catch (err) {
                this.paymentState = 'error';
                this.errorMessage = 'Network connection failed. Please check your internet.';
            }
        },

        handlePayResponse(sessionData) {
            this.session = sessionData;
            const status = sessionData.status;

            if (status === 'confirmed') {
                this.paymentState = 'success';
                setTimeout(() => { window.location.reload(); }, 2000);
            } else if (status === 'failed') {
                this.paymentState = 'error';
                this.errorMessage = sessionData.payment_payload?.failure_reason || 'Transaction could not be completed.';
            } else if (status === 'awaiting_payment') {
                const inlineData = sessionData.payment_payload?.inline;
                if (inlineData) {
                    this.openFlutterwaveInline(inlineData);
                } else {
                    this.paymentState = 'error';
                    this.errorMessage = 'Could not initialize card payment.';
                }
            } else if (status === 'awaiting_redirect') {
                const url = sessionData.payment_payload?.redirect_url;
                if (url) {
                    window.location.href = url;
                    return;
                }
                this.paymentState = 'error';
                this.errorMessage = 'Could not start the payment. Please try a different method.';
            } else if (status === 'awaiting_customer_action') {
                const action = sessionData.payment_payload?.action;
                if (action === 'pin') {
                    this.paymentState = 'action_pin';
                    this.pinValue = '';
                } else if (action === 'otp') {
                    this.paymentState = 'action_otp';
                    this.otpValue = '';
                    this.actionMessage = sessionData.payment_payload?.message || 'Verification code sent';
                } else if (action === 'redirect') {
                    this.paymentState = 'action_3ds';
                    this.startStatusPolling();
                }
            } else if (status === 'awaiting_transfer') {
                this.paymentState = 'awaiting_transfer';
                this.bankDetails = sessionData.payment_payload?.bank_details || sessionData.payment_payload || null;
                this.startStatusPolling();
            } else if (status === 'awaiting_confirmation') {
                this.paymentState = 'awaiting_confirmation';
                this.actionMessage = sessionData.payment_payload?.message || 'Please accept the billing prompt on your device.';
                this.startStatusPolling();
            }
        },

        openFlutterwaveInline(data) {
            const self = this;

            // Ad blockers / CDN failures leave the SDK undefined — report a
            // terminal frontend failure instead of throwing.
            if (typeof FlutterwaveCheckout === 'undefined') {
                if (window.PaymentTelemetry && self.session?.id) {
                    window.PaymentTelemetry.recordFailure(self.session.id, 'sdk_load_failed', {
                        provider: 'flutterwave',
                        load_error: !!window.__flwSdkLoadFailed,
                    });
                }
                this.paymentState = 'error';
                this.errorMessage = 'The payment window could not load. Please disable any ad blocker and try again.';
                this.open = true;
                return;
            }

            this.paymentState = 'processing';
            this.open = false; // Hide our modal to prevent z-index/click interception issues

            // Watch for JS exceptions / offline / tab-close while the
            // provider popup owns the flow.
            const disarmTelemetry = window.PaymentTelemetry
                ? window.PaymentTelemetry.armSession(self.session?.id, { provider: 'flutterwave' })
                : function () {};

            FlutterwaveCheckout({
                public_key: data.public_key,
                tx_ref: data.tx_ref,
                amount: data.amount,
                currency: data.currency,
                customer: data.customer,
                customizations: data.customizations,
                callback: async function(response) {
                    disarmTelemetry();
                    self.paymentState = 'processing';
                    try {
                        let verifyRes = await fetch(
                            `/api/payment-sessions/${self.session.id}/verify`,
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                                },
                                body: JSON.stringify({
                                    transaction_id: response.transaction_id
                                })
                            }
                        );
                        let verifyData = await verifyRes.json();
                        if (verifyData.status === 'confirmed') {
                            self.paymentState = 'success';
                            setTimeout(() => { window.location.reload(); }, 2000);
                        } else {
                            self.paymentState = 'error';
                            self.errorMessage = verifyData.message || 'Payment could not be verified.';
                            self.open = true; // Re-open modal to show error
                        }
                    } catch (e) {
                        // Verification request itself failed — a client
                        // connectivity signal, not a payment verdict.
                        if (window.PaymentTelemetry && self.session?.id) {
                            window.PaymentTelemetry.recordSignal(self.session.id, 'network_timeout', { provider: 'flutterwave', step: 'verify' });
                        }
                        self.paymentState = 'error';
                        self.errorMessage = 'Could not verify payment. Please check your connection.';
                        self.open = true; // Re-open modal to show error
                    }
                },
                onclose: function() {
                    disarmTelemetry();
                    if (self.paymentState !== 'success') {
                        if (window.PaymentTelemetry && self.session?.id) {
                            window.PaymentTelemetry.recordFailure(self.session.id, 'popup_closed', { provider: 'flutterwave' });
                        }
                        self.paymentState = 'idle';
                        self.open = true; // Re-open our modal if user cancelled
                    }
                }
            });
        },

        startStatusPolling() {
            if (this.pollInterval) clearInterval(this.pollInterval);
            this.pollInterval = setInterval(async () => {
                try {
                    let res = await fetch(`/api/payment-sessions/${this.session.id}/status`);
                    let data = await res.json();
                    if (data.status === 'confirmed') {
                        clearInterval(this.pollInterval);
                        this.paymentState = 'success';
                        setTimeout(() => { window.location.reload(); }, 2000);
                    } else if (data.status === 'failed') {
                        clearInterval(this.pollInterval);
                        this.paymentState = 'error';
                        this.errorMessage = data.payment_payload?.failure_reason || 'Transaction failed.';
                    }
                } catch (e) {}
            }, 4500);
        },

        /**
         * Actively verify with the gateway. The passive /status poll only reads
         * our DB, which never flips until the gateway webhook lands (unreachable
         * in local dev). /verify queries the gateway directly and, on success,
         * confirms the session + credits the wallet.
         */
        async verifyPayment() {
            if (this.verifying) {
                return;
            }
            this.verifying = true;
            this.errorMessage = '';
            try {
                let response = await fetch(`/api/payment-sessions/${this.session.id}/verify`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                    },
                    body: JSON.stringify({})
                });
                let data = await response.json();
                if (data.status === 'confirmed') {
                    if (this.pollInterval) {
                        clearInterval(this.pollInterval);
                    }
                    this.paymentState = 'success';
                    setTimeout(() => { window.location.reload(); }, 1800);
                    return;
                }
                this.verifying = false;
                this.errorMessage = data.message || 'We could not confirm your payment yet. If you just completed it, wait a few seconds and try again.';
            } catch (e) {
                this.verifying = false;
                this.errorMessage = 'Could not reach the server to verify. Please check your connection and try again.';
            }
        },

        copyToClipboard(text) {
            navigator.clipboard.writeText(text);
            alert('Copied to clipboard!');
        },

        /** Icon URL for a payment-method type. Null = no icon, render letter fallback. */
        methodIcon(type) {
            const icons = {
                card: '/assets/credit%20card%20payment.webp',
                apple_pay: '/assets/apply%20pay.webp',
                bank_transfer: '/assets/Bank%20transfer.webp',
                mobile_money: '/assets/mobile.svg',
                crypto: '/assets/USDT.svg',
                wallet: '/assets/Wallet.svg',
            };
            return icons[type] || null;
        },

        /**
         * Close the wizard. Confirms first if a payment is mid-flight —
         * the customer may have already entered a card / PIN / OTP, started
         * a 3DS challenge, or is awaiting a bank/momo confirmation. Closing
         * mid-flight aborts the attempt but the backend session remains
         * authoritative (webhooks still settle if the bank completes).
         */
        closeModal() {
            const inFlight = [
                'action_pin',
                'action_otp',
                'action_3ds',
                'awaiting_transfer',
                'awaiting_confirmation',
                'processing',
            ];
            if (this.session && inFlight.includes(this.paymentState)) {
                const ok = window.confirm('A payment is in progress. Closing this window will cancel the attempt. Continue?');
                if (! ok) {
                    return;
                }
            }
            this.open = false;
            this.session = null;
            this.draft = null;
            this.pendingMethod = null;
            this.paymentState = 'idle';
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
            }
        },

        // Identity step-up: close the fund modal and show the Verify overlay.
        onKycStepUp(payload) {
            this.kycMessage = payload?.message || 'To continue with this request, please complete a quick identity verification.';
            this.kycSmile = payload?.smile || null;
            this.open = false;
            this.riskKind = 'kyc';
            this.riskOverlay = true;
        },

        // Open Smile; on success, retry the funding via Livewire. The overlay
        // (z-110) must be hidden BEFORE launching: the KYC modal would otherwise
        // risk opening underneath it. Failures are loud, never a silent no-op.
        verifyIdentity() {
            if (! window.rshopKyc || ! this.kycSmile) {
                this.holdMessage = 'The verification tool could not load. Please refresh the page and try again.';
                this.riskKind = 'hold';
                return;
            }
            const smile = this.kycSmile;
            const self = this;
            this.riskOverlay = false;
            window.rshopKyc.launch(smile, () => {
                self.$wire.fund();
            });
        },

        // A held (review) or declined action: show the neutral notice.
        onRiskHold(message) {
            this.holdMessage = message || 'Your request is being reviewed by our team and will be processed shortly.';
            this.open = false;
            this.riskKind = 'hold';
            this.riskOverlay = true;
        },

        /**
         * The card-testing limiter held this top-up. Never the red failure
         * panel: nothing was charged and nothing was attempted, and a real
         * customer told their payment failed goes looking for another card,
         * which is exactly what is being throttled. The method picker is
         * restored underneath so they can carry on when the wait is over.
         */
        onBackoffNotice(detail) {
            this.startBackoff(detail?.seconds || 0);
            this.backoffLocked = !! detail?.locked;
            this.backoffMessage = detail?.message || '';

            if (this.draft && ! this.session) {
                this.pendingMethod = null;
                this.paymentState = 'select_method';
            }

            // Everything stays inside the top-up modal, where the customer
            // already is. A short pause needs no panel at all: the Continue to
            // payment button counts itself down. Only the six hour lock, which
            // cannot be waited out, replaces the step and offers a way to
            // reach a human.
            if (this.backoffLocked) {
                this.paymentState = 'backoff_notice';
            }
        },

        // Email not verified: the same calm notice + escalating Resend that
        // checkout shows. The server just auto-sent (or told us the cooldown).
        onEmailNotice(detail) {
            this.emailMessage = detail?.message || 'Please verify your email to continue.';
            this.resendFlash = '';
            this.startResendCountdown(detail?.retryAfter || 30);
            this.open = false;
            this.riskKind = 'email';
            this.riskOverlay = true;
        },

        startResendCountdown(seconds) {
            if (this.resendTimerId) {
                clearInterval(this.resendTimerId);
            }
            this.resendRemaining = Math.max(0, Math.ceil(seconds));
            this.resendTimerId = setInterval(() => {
                if (this.resendRemaining > 0) {
                    this.resendRemaining--;
                } else {
                    clearInterval(this.resendTimerId);
                    this.resendTimerId = null;
                }
            }, 1000);
        },

        resendLabel(s) {
            if (s < 60) return s + 's';
            const m = Math.floor(s / 60), r = s % 60;
            return r ? (m + 'm ' + r + 's') : (m + 'm');
        },

        async resendVerification() {
            if (this.resendRemaining > 0 || this.resendBusy) return;
            this.resendBusy = true;
            this.resendFlash = '';
            try {
                const res = await fetch('{{ route('verification.send') }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });
                const data = await res.json();
                if (data.verified) {
                    this.closeRiskOverlay();
                    return;
                }
                this.resendFlash = res.ok
                    ? (data.message || 'Verification link sent.')
                    : (data.message || 'Please wait before requesting another link.');
                this.startResendCountdown(data.retry_after || 30);
            } catch (e) {
                this.resendFlash = 'Network error. Please try again.';
            } finally {
                this.resendBusy = false;
            }
        },

        closeRiskOverlay() {
            this.riskOverlay = false;
            this.riskKind = null;
            if (this.resendTimerId) {
                clearInterval(this.resendTimerId);
                this.resendTimerId = null;
            }
        }
    }"
    @payment-session-initialized.window="initPayment($event.detail.session)"
    @funding-draft-ready.window="if (open) initDraft($event.detail.draft)"
    @funding-error.window="if (open) onFundingError($event.detail.message)"
    @open-fund-wallet.window="if (($event.detail?.code || '').toUpperCase() === '{{ strtoupper($this->currency) }}') open = true"
    @payment-backoff-triggered.window="if (open) onBackoffNotice($event.detail)"
    @kyc-step-up-required.window="if (open) onKycStepUp($event.detail.payload)"
    @risk-hold.window="if (open) onRiskHold($event.detail.message)"
    @email-verification-required.window="if (open) onEmailNotice($event.detail)"
    class="shrink-0"
>
    {{-- Trigger. `full` = desktop wallet-card button; `compact` = mobile hero "Top Up". --}}
    @if ($variant === 'compact')
        <button
            type="button"
            @click="open = true"
            class="inline-flex shrink-0 items-center gap-1.5 rounded-[12px] bg-white px-4 py-2.5 text-sm font-semibold text-blue-700 transition-colors active:bg-blue-100"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Top Up
        </button>
    @else
        <button
            type="button"
            @click="open = true"
            class="inline-flex items-center gap-1.5 rounded-full bg-white px-4 py-2 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Top Up
        </button>
    @endif

    {{-- Fund modal teleported to <body> so it escapes any parent stacking
         context (the wallet carousel / mobile hero create new contexts that
         cap z-index, letting page elements like the "Shop Now" promo bleed
         through). `x-show + x-cloak` keeps the close handlers wired up since
         the markup always exists in the DOM after Alpine boots. --}}
    <template x-teleport="body">
    <div
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="open && closeModal()"
        x-effect="open ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
    >
        <div
            x-transition.opacity
            @click="closeModal()"
            class="absolute inset-0 bg-zinc-900/45"
            aria-hidden="true"
        ></div>

        <div
            x-transition
            :class="paymentState === 'action_3ds' ? 'max-w-lg' : 'max-w-md'"
            class="relative w-full rounded-[12px] bg-white p-6 text-left shadow-2xl shadow-zinc-900/25 transition-all duration-300"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-zinc-900">Fund wallet</h2>
                    <p class="mt-0.5 text-xs text-zinc-600">Add money to pay instantly at checkout.</p>
                </div>
                <x-close-button @click="closeModal()" />
            </div>

            <!-- STEP 1: Enter amount & select currency -->
            <div x-show="!session && !draft && paymentState !== 'backoff_notice'">
                {{-- Currency — custom dropdown selector. --}}
                <label class="mt-5 block text-xs font-semibold text-zinc-700">Currency</label>
                <div class="relative mt-1.5" @click.outside="ccyOpen = false">
                    <button
                        type="button"
                        @click="ccyOpen = ! ccyOpen"
                        :aria-expanded="ccyOpen.toString()"
                        class="flex w-full items-center justify-between gap-2 rounded-[12px] border border-zinc-200 bg-white px-3 py-2.5 text-sm font-medium text-zinc-900 outline-none transition-colors hover:border-zinc-300 focus:outline-none focus-visible:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500/15"
                    >
                        <span>{{ $currency }} &middot; {{ \App\Domain\Shared\Enums\Currency::tryFrom($currency)?->label() }}</span>
                        <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="ccyOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div
                        x-show="ccyOpen"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="absolute left-0 right-0 z-20 mt-1.5 max-h-60 overflow-y-auto overscroll-contain [-webkit-overflow-scrolling:touch] rounded-[12px] border border-zinc-200 bg-[#eff6ff] p-1 shadow-xl shadow-zinc-900/10"
                        role="listbox"
                    >
                        @foreach (self::fundableCurrencies() as $c)
                            <button
                                type="button"
                                wire:click="$set('currency', '{{ $c->value }}')"
                                @click="ccyOpen = false"
                                role="option"
                                aria-selected="{{ $currency === $c->value ? 'true' : 'false' }}"
                                class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-left text-sm font-medium transition-colors {{ $currency === $c->value ? 'bg-blue-50 text-blue-700' : 'text-zinc-700 hover:bg-zinc-100' }}"
                            >
                                <span>{{ $c->value }} &middot; {{ $c->label() }}</span>
                                @if ($currency === $c->value)
                                    <svg class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Amount --}}
                <label class="mt-4 block text-xs font-semibold text-zinc-700">Amount</label>
                <div class="mt-1.5 flex items-stretch overflow-hidden rounded-[12px] border border-zinc-200 bg-white transition-colors focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500/15">
                    <span class="flex shrink-0 items-center border-r border-zinc-200 bg-zinc-50 px-3 text-sm font-semibold text-zinc-600">{{ $this->symbol() }}</span>
                    <input
                        type="number"
                        step="any"
                        min="0"
                        wire:model="amount"
                        wire:keydown.enter="prepare"
                        placeholder="0.00"
                        class="w-full min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm font-medium text-zinc-900 outline-none"
                    >
                </div>
                {{-- The effective minimum: the crypto floor whenever crypto is the
                     only rail that can take the money, so a short amount is
                     refused here rather than two screens later. --}}
                <p class="mt-1.5 text-[11px] text-zinc-500">
                    Minimum {{ $this->effectiveMinimumLabel() }}.
                    @unless ($this->fiatIsLive())
                        <span class="text-zinc-400">Card and bank payments are temporarily unavailable; top-ups are by crypto.</span>
                    @endunless
                </p>

                @error('amount') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                @error('currency') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror

                {{-- During a lock, a separate button that reopens the lock
                     notice instead of calling the server, which would only
                     refuse. Kept apart from the Livewire button below so the
                     two clicks can never race. --}}
                <button
                    type="button"
                    x-show="backoffLocked && backoffRemaining > 0"
                    x-cloak
                    @click="paymentState = 'backoff_notice'"
                    class="mt-5 flex w-full items-center justify-center gap-2 rounded-[15px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
                >
                    <span x-text="backoffLabel()"></span>
                </button>

                <button
                    type="button"
                    x-show="! (backoffLocked && backoffRemaining > 0)"
                    wire:click="prepare"
                    wire:target="prepare"
                    wire:loading.attr="disabled"
                    x-bind:disabled="backoffRemaining > 0"
                    class="mt-5 flex w-full items-center justify-center gap-2 rounded-[15px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed"
                >
                    <svg wire:loading wire:target="prepare" class="h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <template x-if="backoffRemaining > 0">
                        <span x-text="backoffLabel()"></span>
                    </template>
                    <template x-if="backoffRemaining === 0">
                        <span>
                            <span wire:loading.remove wire:target="prepare">Continue to payment</span>
                            <span wire:loading wire:target="prepare">Loading...</span>
                        </span>
                    </template>
                </button>
            </div>

            <!-- STEP 2: Draft (nothing written yet) or the active payment session -->
            <div x-show="session || draft" class="mt-4">
                <!-- Select Method -->
                <div x-show="paymentState === 'select_method'">
                    <h3 class="text-sm font-bold text-zinc-900">Select Payment Method</h3>
                    <p class="text-xs text-zinc-500 mb-4">Choose how you want to fund your account.</p>

                    {{-- A short hold from the card-testing limiter. The customer
                         is returned to this step, so the countdown belongs here:
                         the Continue to payment button that normally carries it
                         is a step behind them and hidden. One quiet line, no
                         heading, because a minute is not worth a panel. --}}
                    <p
                        x-show="backoffRemaining > 0"
                        x-cloak
                        class="mb-4 rounded-[12px] bg-amber-50 px-3.5 py-3 text-xs font-semibold text-amber-800 ring-1 ring-amber-200"
                        x-text="backoffLabel()"
                    ></p>

                    {{-- Same notice checkout shows when a rail is dark. --}}
                    <template x-if="draft?.down_rails?.length">
                        <div class="mb-4 flex items-start gap-2.5 rounded-[12px] bg-amber-50 p-3.5 ring-1 ring-amber-200">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-8.02 13.9A1.5 1.5 0 0 0 3.57 20h16.86a1.5 1.5 0 0 0 1.3-2.24l-8.02-13.9a1.5 1.5 0 0 0-2.6 0z"/>
                            </svg>
                            <p class="text-xs leading-relaxed text-amber-800">
                                <span x-text="draft.down_rails.join(' and ')"></span>
                                <span x-text="draft.down_rails.length > 1 ? ' are' : ' is'"></span>
                                temporarily unavailable. We are working on it, please use another payment method or check back shortly.
                            </p>
                        </div>
                    </template>

                    <div class="grid grid-cols-1 gap-3">
                        <template x-for="method in getFilteredMethods()" :key="method.type">
                            <button
                                type="button"
                                @click="selectPaymentMethod(method)"
                                :disabled="method.enabled === false"
                                :aria-disabled="method.enabled === false ? 'true' : 'false'"
                                :class="method.enabled === false
                                    ? 'cursor-not-allowed opacity-50 grayscale'
                                    : 'hover:border-blue-500 hover:bg-blue-50/30'"
                                class="flex items-center gap-3 w-full p-4 border border-zinc-200 rounded-[12px] text-left transition duration-150"
                            >
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/15">
                                    {{-- Icon by method.type. Falls back to first 2 letters when no icon.
                                         Card + Apple Pay are monochrome: force black in light, white in dark. --}}
                                    <template x-if="methodIcon(method.type)">
                                        <img :src="methodIcon(method.type)" alt=""
                                             :class="['h-6 w-6 object-contain', (method.type === 'card' || method.type === 'apple_pay') ? 'brightness-0 dark:invert' : '']"
                                             loading="lazy">
                                    </template>
                                    <template x-if="! methodIcon(method.type)">
                                        <span class="text-zinc-900 font-bold text-xs uppercase dark:text-white" x-text="method.type.substring(0,2)"></span>
                                    </template>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-zinc-950" x-text="method.label"></p>
                                    <p class="text-xs text-zinc-500 truncate" x-text="method.enabled === false ? 'Temporarily unavailable' : method.description"></p>
                                </div>
                                <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Card Details Form -->
                <div x-show="paymentState === 'card_input'">
                    <div class="flex items-center gap-2 mb-4">
                        <button type="button" @click="paymentState = 'select_method'" class="text-zinc-500 hover:text-zinc-800 text-xs flex items-center gap-1">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> Back
                        </button>
                    </div>
                    
                    @if(config('services.flutterwave.direct_charge_enabled', false))
                        <h3 class="text-sm font-bold text-zinc-900 mb-4">Enter Card Details</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-zinc-700">Name on card</label>
                                <input type="text" id="wallet_card_name" x-model="cardDetails.card_holder" autocomplete="cc-name" placeholder="Full name" class="w-full mt-1.5 rounded-[12px] border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-zinc-700">Card number</label>
                                <div class="relative mt-1.5">
                                    <input 
                                        type="text" 
                                        id="wallet_card_number"
                                        inputmode="numeric" 
                                        autocomplete="cc-number" 
                                        placeholder="1234 1234 1234 1234" 
                                        x-model="cardDetails.card_number"
                                        @input="detectCardType"
                                        class="w-full rounded-[12px] border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 pr-16 tabular-nums"
                                    >
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                        <span class="text-[10px] font-extrabold px-1.5 py-0.5 rounded-[12px] tracking-wider uppercase bg-zinc-100 text-zinc-500 border border-zinc-200" 
                                              x-text="cardBrand === 'unknown' ? 'Card' : cardBrand"
                                              :class="{
                                                  'bg-blue-50 text-blue-600 border-blue-200': cardBrand === 'visa',
                                                  'bg-amber-50 text-amber-700 border-amber-200': cardBrand === 'mastercard',
                                                  'bg-emerald-50 text-emerald-600 border-emerald-200': cardBrand === 'verve',
                                                  'bg-indigo-50 text-indigo-600 border-indigo-200': cardBrand === 'amex',
                                                  'bg-purple-50 text-purple-600 border-purple-200': cardBrand === 'discover',
                                                  'bg-rose-50 text-rose-600 border-rose-200': cardBrand === 'jcb'
                                              }"
                                        ></span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700">Expiry</label>
                                    <input 
                                        type="text" 
                                        id="wallet_card_expiry"
                                        inputmode="numeric" 
                                        autocomplete="cc-exp" 
                                        placeholder="MM / YY" 
                                        x-model="cardExpiryRaw"
                                        @input="formatExpiry"
                                        class="w-full mt-1.5 rounded-[12px] border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 tabular-nums"
                                    >
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-zinc-700">CVV</label>
                                    <input 
                                        type="password" 
                                        id="wallet_card_cvc"
                                        inputmode="numeric" 
                                        autocomplete="cc-csc" 
                                        placeholder="123" 
                                        x-model="cardDetails.cvv"
                                        maxlength="4"
                                        class="w-full mt-1.5 rounded-[12px] border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 tabular-nums"
                                    >
                                </div>
                            </div>

                            <button type="button" @click="paySession('card', cardDetails)" class="w-full rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700 mt-2">
                                Pay <span x-text="session?.display_currency + ' ' + Number(session?.amount).toFixed(2)"></span>
                            </button>
                        </div>
                    @else
                        <div class="rounded-[12px] border border-zinc-200 bg-zinc-50 p-4 text-center dark:border-white/10 dark:bg-white/5">
                            {{-- White chips keep the brand logos legible on the dark
                                 modal panel; no-dark-invert stops the global image
                                 dark-mode filter from recolouring the SVGs. Mirrors
                                 the checkout payment block. --}}
                            <div class="flex justify-center gap-3 mb-3">
                                <span class="flex h-9 items-center rounded-[6px] bg-white px-2 ring-1 ring-zinc-200 dark:ring-white/20">
                                    <img src="/assets/visa.svg" alt="Visa" class="no-dark-invert h-6 object-contain">
                                </span>
                                <span class="flex h-9 items-center rounded-[6px] bg-white px-2 ring-1 ring-zinc-200 dark:ring-white/20">
                                    <img src="/assets/mastercard.svg" alt="Mastercard" class="no-dark-invert h-6 object-contain">
                                </span>
                            </div>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Secure and encrypted payment with bank-level security.
                            </p>
                        </div>
                        <button type="button" @click="paySession('card', {})" class="w-full rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700 mt-4">
                            Pay <span x-text="session?.display_currency + ' ' + Number(session?.amount).toFixed(2)"></span>
                        </button>
                    @endif
                </div>

                <!-- Card Auth: PIN Challenge -->
                <div x-show="paymentState === 'action_pin'">
                    <h3 class="text-sm font-bold text-zinc-900 mb-2">Card PIN Required</h3>
                    <p class="text-xs text-zinc-600 mb-4">Enter your card 4-digit security PIN to authorize payment.</p>
                    <div class="space-y-4">
                        <input type="password" x-model="pinValue" maxlength="4" placeholder="••••" class="w-full rounded-[12px] border border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900">
                        <button type="button" @click="paySession('card', cardDetails)" class="w-full rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                            Confirm PIN
                        </button>
                    </div>
                </div>

                <!-- Card Auth: OTP Challenge -->
                <div x-show="paymentState === 'action_otp'">
                    <h3 class="text-sm font-bold text-zinc-900 mb-2">OTP Verification</h3>
                    <p class="text-xs text-zinc-600 mb-4" x-text="actionMessage"></p>
                    <div class="space-y-4">
                        <input type="text" x-model="otpValue" placeholder="123456" class="w-full rounded-[12px] border border-zinc-200 px-3 py-3 text-center text-lg font-bold tracking-widest text-zinc-900">
                        <button type="button" @click="paySession('card', cardDetails)" class="w-full rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                            Verify OTP
                        </button>
                    </div>
                </div>

                <!-- Card Auth: 3D Secure Redirect -->
                <div x-show="paymentState === 'action_3ds'">
                    <h3 class="text-sm font-bold text-zinc-900 mb-2">Secure Verification</h3>
                    <p class="text-xs text-zinc-600 mb-4">Please complete the secure authentication inside the window below.</p>

                    <div class="w-full overflow-hidden rounded-[12px] border border-zinc-200 bg-zinc-50 h-[55vh] min-h-[400px] max-h-[520px]">
                        <iframe :src="session?.payment_payload?.redirect_url" class="h-full w-full border-0" allow="payment"></iframe>
                    </div>

                    <button @click="verifyPayment()" :disabled="verifying" class="mt-4 flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60">
                        <svg x-show="verifying" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <span x-text="verifying ? 'Verifying...' : 'I Have Completed Payment'"></span>
                    </button>
                    <p x-show="errorMessage" x-cloak class="mt-2 text-center text-xs text-amber-600" x-text="errorMessage"></p>
                </div>

                <!-- Mobile Money input -->
                <div x-show="paymentState === 'momo_input'">
                    <div class="flex items-center gap-2 mb-4">
                        <button type="button" @click="paymentState = 'select_method'" class="text-zinc-500 hover:text-zinc-800 text-xs flex items-center gap-1">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> Back
                        </button>
                    </div>
                    <h3 class="text-sm font-bold text-zinc-900 mb-4">Mobile Money Details</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700">Phone Number</label>
                            <input type="text" x-model="momoDetails.phone_number" placeholder="e.g. 237670000000" class="w-full mt-1.5 rounded-[12px] border border-zinc-200 px-3 py-2.5 text-sm font-medium text-zinc-900">
                        </div>

                        <div x-show="(selectedMethod?.supported_networks ?? []).length > 0">
                            <label class="block text-xs font-semibold text-zinc-700">Network</label>
                            <div class="relative mt-1.5">
                                <select x-model="momoDetails.network" class="w-full appearance-none rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-3 pr-9 text-sm font-medium text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15">
                                    <template x-for="net in selectedMethod?.supported_networks" :key="net">
                                        <option :value="net" x-text="net"></option>
                                    </template>
                                </select>
                                <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </div>
                        </div>

                        <button type="button" @click="paySession('mobile_money', momoDetails)" class="w-full rounded-[12px] bg-blue-600 py-3 text-sm font-semibold text-white hover:bg-blue-700">
                            Pay <span x-text="session?.display_currency + ' ' + Number(session?.amount).toFixed(2)"></span>
                        </button>
                    </div>
                </div>

                <!-- Crypto Input -->
                <div x-show="paymentState === 'crypto_input'">
                    <div class="flex items-center gap-2 mb-4">
                        <button type="button" @click="paymentState = 'select_method'" class="text-zinc-500 hover:text-zinc-800 text-xs flex items-center gap-1">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> Back
                        </button>
                    </div>
                    <h3 class="text-sm font-bold text-zinc-900 mb-2">Select Cryptocurrency</h3>

                    {{-- Crypto settles to USDT at the gateway, so it carries its own
                         floor (~$13) shown here in the funding currency. --}}
                    <p class="mb-4 text-xs text-zinc-600">
                        Add money to pay instantly from {{ $this->cryptoMinimumLabel() }} and above.
                    </p>

                    @if ($this->meetsCryptoMinimum())
                    <div class="mt-1.5 grid grid-cols-4 gap-2 mb-4">
                        @forelse ($cryptoCoins as $coin)
                            <button type="button" @click="selectCoin('{{ strtolower($coin->code) }}')"
                                class="flex flex-col items-center gap-1 rounded-[12px] border bg-white px-2 py-2.5 transition-colors border-zinc-200 hover:border-blue-500 hover:bg-blue-50">
                                @if ($coin->iconUrl())
                                    <img src="{{ $coin->iconUrl() }}" alt="" class="h-6 w-6 rounded-[12px]">
                                @else
                                    <span class="flex h-6 w-6 items-center justify-center rounded-[12px] bg-amber-500 text-[10px] font-black text-white uppercase">{{ substr($coin->code, 0, 1) }}</span>
                                @endif
                                <span class="text-xs font-bold text-zinc-900 uppercase">{{ $coin->code }}</span>
                            </button>
                        @empty
                            <p class="text-xs text-zinc-500 col-span-4">No crypto options available currently.</p>
                        @endforelse
                    </div>
                    <p class="mt-3 text-xs text-zinc-600">
                        Pick a coin and continue. The next step shows the exact wallet address and amount to send.
                    </p>
                    @else
                        <div class="rounded-[12px] border border-amber-200 bg-amber-50 p-4">
                            <p class="text-xs font-semibold text-amber-900">
                                Crypto top-ups start at {{ $this->cryptoMinimumLabel() }}. You entered {{ $this->symbol() }}{{ number_format((float) $this->amount, 2) }}.
                            </p>
                            <button type="button" @click="session = null; draft = null; paymentState = 'idle'"
                                class="mt-3 w-full rounded-[12px] bg-blue-600 py-2.5 text-xs font-semibold text-white transition-colors hover:bg-blue-700">
                                Change amount
                            </button>
                        </div>
                    @endif
                </div>

                <!-- Bank Transfer virtual accounts display or Crypto invoice -->
                <div x-show="paymentState === 'awaiting_transfer'">
                    <!-- If Bank Transfer details -->
                    <template x-if="session?.payment_payload?.bank_details || session?.payment_payload?.account_number">
                        <div>
                            <h3 class="text-sm font-bold text-zinc-900 mb-2">Virtual Bank Transfer</h3>
                            <p class="text-xs text-zinc-600 mb-4">Please make a transfer to the temporary virtual account below:</p>

                            <div class="bg-zinc-50 border border-zinc-200 rounded-[12px] p-4 space-y-3">
                                <div class="flex justify-between items-center text-xs">
                                    <span class="text-zinc-500">Bank Name</span>
                                    <span class="font-bold text-zinc-900" x-text="bankDetails?.bank_name"></span>
                                </div>
                                <div class="flex justify-between items-center text-xs">
                                    <span class="text-zinc-500">Account Number</span>
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-zinc-900 text-sm" x-text="bankDetails?.account_number"></span>
                                        <button type="button" @click="copyToClipboard(bankDetails?.account_number)" class="text-blue-600 hover:text-blue-800 text-[10px] font-semibold">Copy</button>
                                    </div>
                                </div>
                                <div class="flex justify-between items-center text-xs">
                                    <span class="text-zinc-500">Account Name</span>
                                    <span class="font-bold text-zinc-900" x-text="bankDetails?.account_name"></span>
                                </div>
                                <div class="flex justify-between items-center text-xs border-t border-zinc-200 pt-2">
                                    <span class="text-zinc-500">Amount</span>
                                    <span class="font-extrabold text-blue-700 text-sm" x-text="session?.currency + ' ' + Number(bankDetails?.amount || session?.amount).toFixed(2)"></span>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- If Crypto details -->
                    <template x-if="session?.payment_payload?.qr_payload || session?.payment_payload?.pay_address">
                        <div>
                            <h3 class="text-sm font-bold text-zinc-900 text-center mb-2">Crypto Payment Details</h3>
                            <p class="text-xs text-zinc-600 text-center mb-4">Send the exact amount of cryptocurrency shown to the address below:</p>

                            <div class="flex flex-col items-center gap-4 bg-zinc-50 p-4 border border-zinc-200 rounded-[12px] shadow-inner">
                                <!-- QR Code -->
                                <div class="flex shrink-0 flex-col items-center rounded-[12px] bg-white p-3 border border-zinc-150 shadow-sm">
                                    {{-- Rendered by us. This used to call a third-party image
                                         service, which sent the customer's deposit address
                                         off-site and would have let a compromise there swap
                                         the address shown for an attacker's. --}}
                                    <img
                                        :src="session?.id ? ('/api/payment-sessions/' + session.id + '/crypto-qr') : ''"
                                        alt="Payment QR code"
                                        class="h-32 w-32 object-contain"
                                    />
                                    <span class="mt-1.5 text-[9px] font-bold uppercase tracking-wider text-zinc-400">Scan to pay</span>
                                </div>

                                <!-- Details -->
                                <div class="w-full space-y-3.5 text-xs">
                                    <div>
                                        <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block mb-1">Cryptocurrency / Network</span>
                                        <div class="flex items-center gap-1.5">
                                            <span class="rounded-[12px] bg-blue-50 px-2 py-0.5 font-bold text-blue-700 uppercase" x-text="session?.payment_payload?.pay_currency || 'btc'"></span>
                                            <span class="text-[10px] font-medium text-zinc-500 uppercase" x-text="'Network: ' + (session?.payment_payload?.network || 'bitcoin')"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block mb-1">Amount to Send</span>
                                        <div class="flex items-center gap-2">
                                            <span class="font-extrabold text-zinc-900 text-sm tracking-wider tabular-nums bg-white px-2 py-1 rounded-[12px] border border-zinc-150" x-text="session?.payment_payload?.pay_amount"></span>
                                            <span class="font-bold text-zinc-600 uppercase" x-text="session?.payment_payload?.pay_currency"></span>
                                            <button type="button" @click="copyToClipboard(session?.payment_payload?.pay_amount, 'amount_crypto')" class="text-blue-600 hover:text-blue-800 text-[10px] font-bold bg-blue-50 px-2 py-1 rounded-[12px] transition-all">
                                                Copy
                                            </button>
                                        </div>
                                        <p class="mt-1 text-[10px] text-zinc-500">Network fees included. Send this exact amount.</p>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-semibold text-zinc-400 uppercase tracking-wider block mb-1">Deposit Address</span>
                                        <div class="mt-1 flex items-center gap-1.5">
                                            <input type="text" readonly :value="session?.payment_payload?.pay_address" class="w-full bg-white px-2.5 py-1.5 rounded-[12px] border border-zinc-200 text-[10px] text-zinc-800 font-mono select-all outline-none">
                                            <button type="button" @click="copyToClipboard(session?.payment_payload?.pay_address, 'address')" class="text-blue-600 hover:text-blue-800 text-[10px] font-bold shrink-0 bg-blue-50 px-2.5 py-1.5 rounded-[12px] transition-all border border-blue-100">
                                                Copy
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div class="flex flex-col items-center mt-5 text-center">
                        <svg class="h-5 w-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <p class="text-xs font-semibold text-zinc-800 mt-2">Waiting for transfer...</p>
                        <p class="text-[10px] text-zinc-500 mt-1">This account expires in 30 minutes. Status updates automatically.</p>
                    </div>
                </div>

                <!-- Mobile money push prompt -->
                <div x-show="paymentState === 'awaiting_confirmation'">
                    <h3 class="text-sm font-bold text-zinc-900 mb-2">Authorize on Phone</h3>
                    <p class="text-xs text-zinc-600 mb-4" x-text="actionMessage"></p>

                    <div class="flex flex-col items-center py-6 text-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-[12px] bg-blue-50 ring-8 ring-blue-100/50 mb-4">
                            <svg class="h-7 w-7 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </span>
                        <svg class="h-6 w-6 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        <p class="text-xs font-semibold text-zinc-800 mt-3">Verifying payment authorization...</p>
                    </div>
                </div>

                <!-- Processing state -->
                <div x-show="paymentState === 'processing'" class="flex flex-col items-center py-8 text-center">
                    <svg class="h-10 w-10 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="mt-4 text-sm font-bold text-zinc-900">Processing transaction...</p>
                    <p class="mt-1 text-xs text-zinc-500">Please do not close this window.</p>
                </div>

                <!-- Success state: same animated tick as the order-complete
                     page so every payment method celebrates identically. -->
                <div x-show="paymentState === 'success'" class="flex flex-col items-center py-8 text-center">
                    <x-success-tick />
                    <h3 class="mt-4 text-base font-bold text-zinc-950">Deposit Complete!</h3>
                    <p class="mt-1.5 text-xs text-zinc-600">Your wallet balance is being updated now.</p>
                </div>

                <!-- Error state: animated red cross, the tick's counterpart. -->
                <div x-show="paymentState === 'error'" class="flex flex-col items-center py-6 text-center">
                    <x-error-cross />
                    <h3 class="mt-4 text-sm font-bold text-zinc-900">Payment Failed</h3>
                    <p class="mt-1.5 text-xs text-red-600 px-4" x-text="errorMessage"></p>

                    <button type="button" @click="paymentState = 'select_method'" class="mt-6 rounded-[12px] bg-zinc-100 px-5 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-200">
                        Try Another Method
                    </button>
                </div>

            </div>

            {{-- Payments paused on this device by the card-testing limiter.
                 Lives inside the top-up modal, where the customer already is,
                 rather than throwing them back to the dashboard behind an
                 overlay. Amber, not the red failure panel: nothing was charged
                 and nothing was attempted. Outside both steps on purpose, so it
                 opens from the amount step too (a locked customer who reloads
                 and taps Continue) and not only after a method is picked. Only
                 the six hour lock gets this treatment; the short pauses just
                 count the button down. --}}
            <div x-show="paymentState === 'backoff_notice'" x-cloak class="flex flex-col items-center py-6 text-center">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50">
                    <svg class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V12l2.75 1.75"/>
                    </svg>
                </div>
                <h3 class="mt-4 text-sm font-bold text-zinc-900">Suspicious payments detected</h3>
                <p class="mt-1.5 text-xs text-zinc-600 px-4" x-text="backoffMessage"></p>

                <p x-show="backoffRemaining > 0" x-cloak class="mt-3 rounded-[10px] bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700" x-text="'Try again in ' + backoffCountdown()"></p>

                <div class="mt-6 flex items-center justify-center gap-2.5">
                    <a href="{{ route('shop.contact') }}" class="rounded-[12px] px-5 py-2.5 text-xs font-semibold text-zinc-700 ring-1 ring-zinc-300 transition-colors hover:bg-zinc-100">
                        Contact support
                    </a>
                    {{-- closeModal(), not open = false: it clears the draft and
                         the lock state, so reopening the top-up after the lock
                         ends starts fresh instead of showing a stale notice. --}}
                    <button type="button" @click="closeModal()" class="rounded-[12px] bg-blue-600 px-8 py-2.5 text-xs font-semibold text-white hover:bg-blue-700">
                        Got it
                    </button>
                </div>
            </div>
        </div>
    </div>
    </template>

    {{-- Risk / KYC overlay: identity step-up (Verify -> Smile) or a neutral
         review/declined hold. Sits above the fund modal (which we close first). --}}
    <template x-teleport="body">
    <div
        x-show="riskOverlay"
        x-cloak
        x-on:keydown.escape.window="riskOverlay && closeRiskOverlay()"
        x-effect="riskOverlay ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"
        class="fixed inset-0 z-[110] flex items-center justify-center p-4"
    >
        <div x-transition.opacity @click="closeRiskOverlay()" class="absolute inset-0 bg-zinc-900/45" aria-hidden="true"></div>

        <div x-transition class="relative w-full max-w-md rounded-[12px] bg-white p-6 text-center shadow-2xl shadow-zinc-900/25" role="dialog" aria-modal="true">
            {{-- Identity step-up --}}
            <div x-show="riskKind === 'kyc'" class="flex flex-col items-center py-2">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50">
                    <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 7.9-7 9-4-1.1-7-4.5-7-9V6l7-3z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 12l1.8 1.8L15 10"/>
                    </svg>
                </div>
                <h3 class="mt-4 text-sm font-bold text-zinc-900">Quick verification needed</h3>
                <p class="mt-1.5 text-xs text-zinc-600 px-4" x-text="kycMessage"></p>
                <button type="button" @click="verifyIdentity()" class="mt-6 rounded-[12px] bg-blue-600 px-8 py-2.5 text-xs font-semibold text-white hover:bg-blue-700">
                    Verify
                </button>
                <button type="button" @click="closeRiskOverlay()" class="mt-2.5 text-xs font-medium text-zinc-500 hover:text-zinc-700">
                    Not now
                </button>
            </div>

            {{-- Review / declined hold --}}
            <div x-show="riskKind === 'hold'" class="flex flex-col items-center py-2">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50">
                    <svg class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.5M12 16h.01M10.3 4.3 2.5 18a1.5 1.5 0 0 0 1.3 2.2h16.4a1.5 1.5 0 0 0 1.3-2.2L13.7 4.3a1.5 1.5 0 0 0-2.6 0z"/>
                    </svg>
                </div>
                <h3 class="mt-4 text-sm font-bold text-zinc-900">One moment</h3>
                <p class="mt-1.5 text-xs text-zinc-600 px-4" x-text="holdMessage"></p>
                <button type="button" @click="closeRiskOverlay()" class="mt-6 rounded-[12px] bg-zinc-100 px-6 py-2.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-200">
                    Got it
                </button>
            </div>

            {{-- Email not verified: same calm notice + escalating Resend as checkout. --}}
            <div x-show="riskKind === 'email'" class="flex flex-col items-center py-2">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-50">
                    <svg class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                    </svg>
                </div>
                <h3 class="mt-4 text-sm font-bold text-zinc-900">Verify your email to continue</h3>
                <p class="mt-1.5 text-xs text-zinc-600 px-4" x-text="emailMessage"></p>

                <p x-show="resendFlash" x-cloak class="mt-3 rounded-[10px] bg-emerald-50 px-3 py-2 text-xs text-emerald-700" x-text="resendFlash"></p>

                <div class="mt-6 flex items-center justify-center gap-2.5">
                    <button
                        type="button"
                        @click="resendVerification()"
                        :disabled="resendRemaining > 0 || resendBusy"
                        class="rounded-[12px] px-5 py-2.5 text-xs font-semibold text-zinc-700 ring-1 ring-zinc-300 transition-colors hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-50"
                        x-text="resendBusy ? 'Sending...' : (resendRemaining > 0 ? 'Resend ' + resendLabel(resendRemaining) : 'Resend email')"
                    ></button>
                    <button type="button" @click="closeRiskOverlay()" class="rounded-[12px] bg-blue-600 px-8 py-2.5 text-xs font-semibold text-white hover:bg-blue-700">
                        Got it
                    </button>
                </div>
            </div>
        </div>
    </div>
    </template>
</div>
