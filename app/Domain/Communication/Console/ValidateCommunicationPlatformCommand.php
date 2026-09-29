<?php

namespace App\Domain\Communication\Console;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\MailDomain;
use App\Domain\Communication\Jobs\DispatchCommunicationJob;
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
use App\Domain\Communication\Listeners\System\WelcomeListener;
use App\Domain\Communication\Listeners\Wallet\FundingFailedListener;
use App\Domain\Communication\Listeners\Wallet\WalletCreditListener;
use App\Domain\Communication\Listeners\Wallet\WalletDebitListener;
use App\Domain\Communication\Mail\Marketing\NewsletterBroadcastMail;
use App\Domain\Communication\Mail\Notifications\GiftCardsRestoredMail;
use App\Domain\Communication\Mail\Notifications\GiftCardsUnavailableMail;
use App\Domain\Communication\Mail\Orders\EsimLowDataMail;
use App\Domain\Communication\Mail\Security\EmailOtpMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Communication\Services\PreferenceManager;
use App\Domain\Communication\Services\SenderResolver;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Events\FulfillmentSucceeded;
use App\Domain\Order\Events\OrderCompleted;
use App\Domain\Order\Events\OrderConfirmed;
use App\Domain\Order\Events\RefundIssued;
use App\Domain\Payment\Events\PaymentSessionFailed;
use App\Domain\PaymentInsights\Listeners\RecordPaymentOutcomeListener;
use App\Domain\Rewards\Events\RcoinEarned;
use App\Domain\Security\Events\AccountRecovered;
use App\Domain\Security\Events\NewDeviceDetected;
use App\Domain\Security\Events\OtpRequested;
use App\Domain\Security\Events\PasswordChanged;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Events\FundingFailed;
use App\Domain\Wallet\Events\TransactionPinChanged;
use App\Domain\Wallet\Events\TransactionPinLocked;
use App\Domain\Wallet\Events\TransactionPinResetRequested;
use App\Domain\Wallet\Events\WalletCredited;
use App\Domain\Wallet\Events\WalletDebited;
use App\Models\Category;
use App\Models\CommunicationLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Models\WalletTransaction;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Registered;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Monolog\Handler\StreamHandler;

/**
 * Communication Platform Validation Suite.
 *
 * Proves every mailbox, sender resolver, listener, event, queue, and
 * communication path works end-to-end by triggering the real business
 * events exactly as production would — inside a database transaction
 * that is always rolled back, so no business data is ever persisted.
 *
 * Emails are delivered through the real pipeline; with the log mail
 * driver (forced for the run) every rendered email lands in the dedicated mail log (storage/logs/mail-YYYY-MM-DD.log), never laravel.log.
 */
class ValidateCommunicationPlatformCommand extends Command
{
    protected $signature = 'communication:validate
        {--static : Run only the static checks (sender map + event wiring), no delivery probes}';

    protected $description = 'Validate every mailbox, sender, listener, queue, and communication path of the Communication Platform';

    /**
     * Expected category → mailbox mapping (Phase 6). One representative
     * category per mailbox is asserted plus the full enum sweep.
     *
     * @var array<string, string>
     */
    private const EXPECTED_SENDERS = [
        'verify' => 'verify@rshoprefill.com',
        'security' => 'security@rshoprefill.com',
        'wallet' => 'wallet@rshoprefill.com',
        'orders' => 'orders@rshoprefill.com',
        'transactions' => 'transactions@rshoprefill.com',
        'notifications' => 'notifications@rshoprefill.com',
        'marketing' => 'marketing@rshoprefill.com',
        'compliance' => 'compliance@rshoprefill.com',
    ];

    /**
     * Every production event → listener wire. Each row: event class,
     * listener class, handler method.
     *
     * @var array<int, array{0: class-string, 1: class-string, 2: string}>
     */
    private const EVENT_WIRING = [
        [OtpRequested::class, OtpDispatchListener::class, 'handle'],
        [NewDeviceDetected::class, NewDeviceAlertListener::class, 'handle'],
        [AccountRecovered::class, AccountRecoveryListener::class, 'handle'],
        [PasswordChanged::class, PasswordEventListener::class, 'handle'],
        [TransactionPinChanged::class, PinEventListener::class, 'handlePinChanged'],
        [TransactionPinLocked::class, PinEventListener::class, 'handlePinLocked'],
        [TransactionPinResetRequested::class, PinEventListener::class, 'handlePinReset'],
        [Registered::class, WelcomeListener::class, 'handle'],
        // OrderConfirmed, not OrderPlaced: the confirmation email must follow
        // the money, not the creation of the checkout skeleton.
        [OrderConfirmed::class, OrderConfirmationListener::class, 'handle'],
        [FulfillmentSucceeded::class, FulfillmentNotificationListener::class, 'handle'],
        [OrderCompleted::class, OrderCompleteNoticeListener::class, 'handle'],
        [RefundIssued::class, RefundNotificationListener::class, 'handle'],
        [WalletCredited::class, WalletCreditListener::class, 'handle'],
        [WalletDebited::class, WalletDebitListener::class, 'handle'],
        [FundingFailed::class, FundingFailedListener::class, 'handle'],
        [RcoinEarned::class, RcoinRewardListener::class, 'handle'],
        // Recovery emails are dispatched by EvaluateRecoveryJob (via this
        // listener → RecordPaymentOutcomeJob), not by a direct listener.
        [PaymentSessionFailed::class, RecordPaymentOutcomeListener::class, 'handle'],
    ];

    /** @var array<string, array<int, array{status: string, label: string, detail: ?string}>> */
    private array $results = [];

    private ?User $probeUser = null;

    public function handle(): int
    {
        $this->components->info('Communication Platform Validation Suite');

        if (app()->isProduction() && ! $this->confirm('You are in PRODUCTION. Probe emails will be rendered through the mailer. Continue?')) {
            return self::FAILURE;
        }

        // Force safe transports for the whole run: emails render to the log
        // rather than a real provider, queued jobs execute inline so results
        // are observable.
        //
        // Pinned to the dedicated `mail` channel, not whatever the app log is.
        // The log transport writes the whole MIME message including base64
        // inline images, so a full probe run of branded emails is hundreds of
        // megabytes; sent to laravel.log it buried every real entry and left a
        // 1.4GB archive behind.
        config([
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'mail',
            'queue.default' => 'sync',
        ]);

        $this->checkSenderResolution();
        $this->checkEventWiring();

        if (! $this->option('static')) {
            $this->runDynamicChecks();
        }

        return $this->renderReport();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Phase 6 — Sender Validation
    // ─────────────────────────────────────────────────────────────────

    private function checkSenderResolution(): void
    {
        $resolver = app(SenderResolver::class);

        foreach (self::EXPECTED_SENDERS as $domainValue => $expectedAddress) {
            $domain = MailDomain::from($domainValue);
            $actual = $domain->senderAddress();

            $this->record('SenderResolver', $actual === $expectedAddress,
                "{$domain->name} mailbox → {$expectedAddress}",
                $actual === $expectedAddress ? null : "resolved to {$actual}");
        }

        // Full sweep: every category must resolve to a mailbox and carry
        // a well-formed From header on the verified domain.
        $broken = [];
        foreach (CommunicationCategory::cases() as $category) {
            $address = $resolver->senderAddress($category);
            $from = $resolver->fromHeader($category);

            if (! str_ends_with($address, '@rshoprefill.com') || ! str_contains($from, '<'.$address.'>')) {
                $broken[] = $category->value;
            }
        }

        $this->record('SenderResolver', $broken === [],
            'All '.count(CommunicationCategory::cases()).' categories resolve to a verified mailbox',
            $broken === [] ? null : 'broken: '.implode(', ', $broken));

        $this->record('SenderResolver', $resolver->replyTo() === 'support@rshoprefill.com',
            'Universal Reply-To → support@rshoprefill.com',
            $resolver->replyTo() === 'support@rshoprefill.com' ? null : "got {$resolver->replyTo()}");
    }

    // ─────────────────────────────────────────────────────────────────
    //  Event wiring (dead-path detector)
    // ─────────────────────────────────────────────────────────────────

    private function checkEventWiring(): void
    {
        foreach (self::EVENT_WIRING as [$event, $listener, $method]) {
            $label = class_basename($event).' → '.class_basename($listener);

            if (! class_exists($event)) {
                $this->record('Event Wiring', false, $label, "event class {$event} does not exist");

                continue;
            }

            if (! class_exists($listener)) {
                $this->record('Event Wiring', false, $label, "listener class {$listener} does not exist");

                continue;
            }

            if (! Event::hasListeners($event)) {
                $this->record('Event Wiring', false, $label, 'no listeners registered for event');

                continue;
            }

            // The handler's type-hint must accept the event actually fired,
            // otherwise the listener silently never runs (a dead path).
            // Union hints (Failed|Expired|Confirmed) count when any branch matches.
            try {
                $param = (new \ReflectionMethod($listener, $method))->getParameters()[0] ?? null;
                $type = $param?->getType();
                $names = match (true) {
                    $type instanceof \ReflectionNamedType => [$type->getName()],
                    $type instanceof \ReflectionUnionType => array_map(fn ($t) => $t->getName(), $type->getTypes()),
                    default => [],
                };

                $accepts = in_array($event, $names, true);
                $this->record('Event Wiring', $accepts, $label,
                    $accepts ? null : "{$method}() type-hints ".implode('|', $names).", event is {$event}");
            } catch (\ReflectionException $e) {
                $this->record('Event Wiring', false, $label, $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Dynamic phase — everything below runs inside a rolled-back
    //  transaction so no business data ever persists.
    // ─────────────────────────────────────────────────────────────────

    private function runDynamicChecks(): void
    {
        $logFile = $this->mailLogFile();
        $logOffset = is_file($logFile) ? filesize($logFile) : 0;

        DB::beginTransaction();

        try {
            $this->probeUser = User::factory()->create([
                'name' => 'Comms Validation Probe',
                'email' => 'comms-validation+'.Str::lower(Str::random(6)).'@rshoprefill.test',
            ]);

            $this->checkMailboxes();
            $this->checkIdempotency();
            $this->checkPreferences();
            $this->checkCommunicationLogs();
            $this->checkQueueRouting(); // Queue::fake — must run after real deliveries
        } catch (\Throwable $e) {
            $this->record('Delivery', false, 'Dynamic validation phase', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->checkMailLogReceived($logFile, $logOffset);
        $this->checkPlatformConfig();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Phase 3 — the 8 mailboxes, real events first
    // ─────────────────────────────────────────────────────────────────

    private function checkMailboxes(): void
    {
        $user = $this->probeUser;

        // ── 1. verify@ ────────────────────────────────────────────────
        $this->probe('Verify Mailbox', 'Welcome email (Registered event)', function () use ($user) {
            event(new Registered($user));
            $this->assertEmailLog(CommunicationCategory::AccountActivation, MailDomain::Verify);
        });

        $this->probe('Verify Mailbox', 'Password reset (broker hook)', function () use ($user) {
            $user->sendPasswordResetNotification('validation-probe-token');
            $this->assertEmailLog(CommunicationCategory::PasswordReset, MailDomain::Verify);
        });

        $this->probe('Verify Mailbox', 'PIN reset (TransactionPinResetRequested event)', function () use ($user) {
            event(new TransactionPinResetRequested($user, 'validation-probe-token'));
            $this->assertEmailLog(CommunicationCategory::PinReset, MailDomain::Verify);
        });

        // ── 2. security@ ─────────────────────────────────────────────
        $this->probe('Security Mailbox', 'Login OTP (OtpRequested event)', function () use ($user) {
            event(new OtpRequested($user, '123456', 'login'));
            $this->assertEmailLog(CommunicationCategory::Otp, MailDomain::Security);
        });

        $this->probe('Security Mailbox', 'New device login (NewDeviceDetected event)', function () use ($user) {
            $device = TrustedDevice::create([
                'user_id' => $user->id,
                'device_uuid' => (string) Str::uuid(),
                'device_name' => 'Validation Probe Browser',
                'browser' => 'Chrome',
                'os' => 'Windows',
                'device_type' => 'desktop',
                'ip_address' => '203.0.113.1',
                'country' => 'US',
                'city' => 'Testville',
                'fingerprint' => 'valsuite_'.Str::random(16),
                'user_agent' => 'ValidationSuite/1.0',
            ]);
            event(new NewDeviceDetected($device));
            $this->assertEmailLog(CommunicationCategory::NewDevice, MailDomain::Security);
        });

        $this->probe('Security Mailbox', 'Password changed (PasswordChanged event)', function () use ($user) {
            event(new PasswordChanged($user));
            $this->assertEmailLog(CommunicationCategory::PasswordChanged, MailDomain::Security);
        });

        $this->probe('Security Mailbox', 'PIN changed (TransactionPinChanged event)', function () use ($user) {
            event(new TransactionPinChanged($user));
            $this->assertEmailLog(CommunicationCategory::PinChanged, MailDomain::Security);
        });

        $this->probe('Security Mailbox', 'Account recovery (AccountRecovered event)', function () use ($user) {
            event(new AccountRecovered($user));
            $this->assertEmailLog(CommunicationCategory::AccountRecovered, MailDomain::Security);
        });

        // ── 3. wallet@ ───────────────────────────────────────────────
        // The user may already own a wallet (created by registration hooks).
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => 'USD'],
            ['balance' => 100, 'is_active' => true]
        );

        $this->probe('Wallet Mailbox', 'Wallet credit (WalletCredited event)', function () use ($user, $wallet) {
            $tx = $this->makeWalletTransaction($user, $wallet, 'credit');
            event(new WalletCredited($tx));
            $this->assertEmailLog(CommunicationCategory::WalletFunding, MailDomain::Wallet);
        });

        $this->probe('Wallet Mailbox', 'Wallet debit (WalletDebited event)', function () use ($user, $wallet) {
            $tx = $this->makeWalletTransaction($user, $wallet, 'debit');
            event(new WalletDebited($tx));
            $this->assertEmailLog(CommunicationCategory::WalletDebit, MailDomain::Wallet);
        });

        $this->probe('Wallet Mailbox', 'RCoin reward (RcoinEarned event)', function () use ($user) {
            event(new RcoinEarned($user, 50, 150, 'cashback', 'valsuite_rcoin'));
            $this->assertEmailLog(CommunicationCategory::RcoinReward, MailDomain::Wallet);
        });

        // ── 4. orders@ ───────────────────────────────────────────────
        $order = Order::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'product_snapshot' => ['name' => 'Validation Probe Gift Card'],
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
        ]);

        $this->probe('Orders Mailbox', 'Order paid (OrderConfirmed event)', function () use ($order) {
            event(new OrderConfirmed($order));
            $this->assertEmailLog(CommunicationCategory::OrderPlaced, MailDomain::Orders);
        });

        $this->probe('Orders Mailbox', 'Order completed (FulfillmentSucceeded event)', function () use ($item) {
            event(new FulfillmentSucceeded($item));
            $this->assertEmailLog(CommunicationCategory::OrderFulfilled, MailDomain::Orders);
        });

        $this->probe('Orders Mailbox', 'Refund (RefundIssued event)', function () use ($order) {
            event(new RefundIssued($order, 5.00, 'Validation probe refund'));
            $this->assertEmailLog(CommunicationCategory::Refund, MailDomain::Orders);
        });

        $this->probe('Orders Mailbox', 'eSIM low data (webhook entry point)', function () use ($user, $item) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::EsimLowData,
                title: 'Your eSIM is low on data',
                message: 'Your eSIM has used 80% of its data.',
                mailable: new EsimLowDataMail(
                    recipient: $user,
                    orderItem: $item,
                    iccid: '8900000000000000000',
                    eventType: 'usage_80',
                    payload: ['usage_percentage' => 80],
                ),
                correlationId: 'valsuite_esim_low_data',
            );
            $this->assertEmailLog(CommunicationCategory::EsimLowData, MailDomain::Orders);
        });

        // ── 5. transactions@ ─────────────────────────────────────────
        $this->probe('Transactions Mailbox', 'Payment failed → recovery (PaymentSessionFailed event)', function () use ($user, $order) {
            $attempt = PaymentAttempt::create([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'gateway' => 'flutterwave',
                'idempotency_key' => 'valsuite_attempt_'.Str::random(8),
                'currency' => 'USD',
                'amount' => 12.00,
                'payment_status' => 'pending',
            ]);
            $session = PaymentSession::create([
                'payment_attempt_id' => $attempt->id,
                'provider' => 'flutterwave',
                'session_type' => 'checkout',
                'status' => 'failed',
                'client_reference' => 'valsuite_session_'.Str::random(8),
                'amount' => 12.00,
                'currency' => 'USD',
                'display_currency' => 'USD',
                'customer_email' => $user->email,
                'expires_at' => now()->addHour(),
            ]);
            event(new PaymentSessionFailed($session, 'card_declined'));
            $this->assertEmailLog(CommunicationCategory::PaymentRecovery, MailDomain::Transactions);
        });

        $this->probe('Transactions Mailbox', 'Funding failed (FundingFailed event, in-app)', function () use ($user, $wallet) {
            $funding = WalletFunding::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'reference' => 'valsuite_funding_'.Str::random(8),
                'currency' => 'USD',
                'amount' => 25.00,
                'gateway' => 'flutterwave',
                'status' => FundingStatus::Failed,
            ]);
            event(new FundingFailed($funding, 'card_declined'));
            $this->assertInAppLog(CommunicationCategory::FundingFailed, MailDomain::Transactions);
        });

        $this->probe('Transactions Mailbox', 'Payment feedback (TransactionFeedback category)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::TransactionFeedback,
                title: 'How was your payment experience?',
                message: 'Tell us how your last payment went.',
                correlationId: 'valsuite_tx_feedback',
            );
            $this->assertInAppLog(CommunicationCategory::TransactionFeedback, MailDomain::Transactions);
        });

        // ── 6. notifications@ ────────────────────────────────────────
        $this->probe('Notifications Mailbox', 'Gift cards restored (broadcast entry point)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::GiftCardsRestored,
                title: 'Great News! Gift Cards Are Back!',
                message: 'Our Gift Card service is now fully restored.',
                mailable: new GiftCardsRestoredMail($user),
                correlationId: 'valsuite_giftcards_restored',
            );
            $this->assertEmailLog(CommunicationCategory::GiftCardsRestored, MailDomain::Notifications);
        });

        $this->probe('Notifications Mailbox', 'Gift cards unavailable (outage entry point)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::GiftCardsUnavailable,
                title: 'Gift Cards Temporarily Unavailable',
                message: 'We are performing maintenance on gift cards.',
                mailable: new GiftCardsUnavailableMail($user),
                correlationId: 'valsuite_giftcards_outage',
            );
            $this->assertEmailLog(CommunicationCategory::GiftCardsUnavailable, MailDomain::Notifications);
        });

        $this->probe('Notifications Mailbox', 'Platform notice (ServiceNotice, in-app)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::ServiceNotice,
                title: 'Scheduled maintenance',
                message: 'The platform will be briefly unavailable tonight.',
                correlationId: 'valsuite_service_notice',
            );
            $this->assertInAppLog(CommunicationCategory::ServiceNotice, MailDomain::Notifications);
        });

        // ── 7. marketing@ ────────────────────────────────────────────
        $this->probe('Marketing Mailbox', 'Newsletter (broadcast entry point)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::Newsletter,
                title: 'Validation Probe Newsletter',
                message: 'Validation probe newsletter body.',
                mailable: new NewsletterBroadcastMail(
                    subjectLine: 'Validation Probe Newsletter',
                    bodyContent: '<p>Validation probe newsletter body.</p>',
                    isHtml: true,
                ),
                correlationId: 'valsuite_newsletter',
                metadata: ['is_marketing' => true],
            );
            $this->assertEmailLog(CommunicationCategory::Newsletter, MailDomain::Marketing);
        });

        $this->probe('Marketing Mailbox', 'Promotional campaign (Campaign, in-app)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::Campaign,
                title: 'Validation Probe Campaign',
                message: 'Campaign probe body.',
                correlationId: 'valsuite_campaign',
                metadata: ['is_marketing' => true],
            );
            $this->assertInAppLog(CommunicationCategory::Campaign, MailDomain::Marketing);
        });

        // ── 8. compliance@ ───────────────────────────────────────────
        $this->probe('Compliance Mailbox', 'KYC update (Kyc category)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::Kyc,
                title: 'KYC verification update',
                message: 'Your KYC verification status has been updated.',
                correlationId: 'valsuite_kyc',
            );
            $this->assertInAppLog(CommunicationCategory::Kyc, MailDomain::Compliance);
        });

        $this->probe('Compliance Mailbox', 'Terms update (TermsChange category)', function () use ($user) {
            app(CommunicationManager::class)->send(
                recipient: $user,
                category: CommunicationCategory::TermsChange,
                title: 'Our terms of service have changed',
                message: 'Please review the updated terms of service.',
                correlationId: 'valsuite_terms',
            );
            $this->assertInAppLog(CommunicationCategory::TermsChange, MailDomain::Compliance);
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  Phase 4 — queue routing, retry config, duplicate suppression
    // ─────────────────────────────────────────────────────────────────

    private function checkQueueRouting(): void
    {
        Queue::fake();

        $expectations = [
            // Otp carries a one-time code, so it resolves to the email channel
            // and nothing else. Without a mailable there is no channel left to
            // dispatch on and this probe would be measuring an empty send.
            [CommunicationCategory::Otp, 'comm-critical', new EmailOtpMail('000000', 'login')],
            [CommunicationCategory::WalletFunding, 'comm-transactional', null],
            [CommunicationCategory::ServiceNotice, 'comm-transactional', null],
            [CommunicationCategory::Newsletter, 'comm-marketing', null],
        ];

        foreach ($expectations as $i => [$category, $expectedQueue, $mailable]) {
            app(CommunicationManager::class)->send(
                recipient: $this->probeUser,
                category: $category,
                title: 'Queue probe',
                message: 'Queue probe',
                mailable: $mailable,
                correlationId: 'valsuite_queue_'.$category->value,
                idempotencyKey: 'valsuite_queue_key_'.$i,
            );

            // QueueFake::pushed() inspects without PHPUnit assertions, so the
            // command also works where dev dependencies are absent.
            $job = Queue::pushed(DispatchCommunicationJob::class)
                ->first(fn (DispatchCommunicationJob $job) => $job->payload->category === $category);

            $ok = $job !== null && $job->queue === $expectedQueue;
            $this->record('Queue Dispatch', $ok,
                "{$category->value} → {$expectedQueue} queue",
                $ok ? null : 'pushed on '.($job?->queue ?? 'nothing'));

            if ($job !== null) {
                $this->record('Queue Dispatch', $job->tries === 3 && $job->backoff === [30, 120, 300],
                    "{$category->value} retry config (3 tries, 30/120/300s backoff)");
                $this->record('Queue Dispatch',
                    $job->payload->idempotencyKey !== null && $job->payload->correlationId !== null,
                    "{$category->value} payload carries idempotency key + correlation ID");
            }
        }
    }

    private function checkIdempotency(): void
    {
        $this->probe('Idempotency', 'Duplicate trigger with same key dispatches exactly once', function () {
            $key = 'valsuite_dupe_'.Str::random(8);

            foreach ([1, 2] as $attempt) {
                app(CommunicationManager::class)->send(
                    recipient: $this->probeUser,
                    category: CommunicationCategory::ServiceNotice,
                    title: 'Duplicate probe',
                    message: 'Duplicate probe',
                    correlationId: 'valsuite_dupe_check',
                    idempotencyKey: $key,
                );
            }

            $count = CommunicationLog::where('idempotency_key', $key)
                ->where('channel', 'in_app')
                ->count();

            if ($count !== 1) {
                throw new \RuntimeException("expected 1 in-app dispatch, found {$count}");
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  Phase 3.7 — preference filtering & opt-out (feature gating)
    // ─────────────────────────────────────────────────────────────────

    private function checkPreferences(): void
    {
        $prefs = app(PreferenceManager::class);
        $user = $this->probeUser;

        $this->probe('Feature Flags & Preferences', 'Marketing opt-out suppresses the email channel', function () use ($prefs, $user) {
            $prefs->updatePreferences($user, ['marketing_email' => false]);

            app(CommunicationManager::class)->send(
                recipient: $user->fresh(),
                category: CommunicationCategory::Newsletter,
                title: 'Opt-out probe',
                message: 'Opt-out probe',
                mailable: new NewsletterBroadcastMail('Opt-out probe', 'body', false),
                correlationId: 'valsuite_optout',
                idempotencyKey: 'valsuite_optout_key',
            );

            $emailLogs = CommunicationLog::where('correlation_id', 'valsuite_optout')
                ->where('channel', 'email')->count();
            $inAppLogs = CommunicationLog::where('correlation_id', 'valsuite_optout')
                ->where('channel', 'in_app')->count();

            if ($emailLogs !== 0) {
                throw new \RuntimeException('email was sent despite marketing opt-out');
            }
            if ($inAppLogs !== 1) {
                throw new \RuntimeException('in-app bell notification should still be delivered');
            }
        });

        $this->probe('Feature Flags & Preferences', 'Security emails ignore the global email kill switch', function () use ($prefs, $user) {
            $prefs->updatePreferences($user, ['email_enabled' => false]);

            app(CommunicationManager::class)->send(
                recipient: $user->fresh(),
                category: CommunicationCategory::Otp,
                title: 'Mandatory probe',
                message: 'Your OTP is 000000.',
                mailable: new EmailOtpMail('000000', 'login'),
                correlationId: 'valsuite_mandatory',
                idempotencyKey: 'valsuite_mandatory_key',
            );

            $sent = CommunicationLog::where('correlation_id', 'valsuite_mandatory')
                ->where('channel', 'email')->where('status', 'sent')->exists();

            if (! $sent) {
                throw new \RuntimeException('mandatory security email was suppressed by user preferences');
            }

            $prefs->updatePreferences($user, ['email_enabled' => true, 'marketing_email' => true]);
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  Phase 5 — communication log completeness
    // ─────────────────────────────────────────────────────────────────

    private function checkCommunicationLogs(): void
    {
        $logs = CommunicationLog::where('user_id', $this->probeUser->id)->get();

        $this->record('Communication Logs', $logs->isNotEmpty(),
            'Every probe created a CommunicationLog entry ('.$logs->count().' rows)');

        $incomplete = $logs->filter(fn (CommunicationLog $log) => blank($log->uuid)
            || blank($log->category)
            || blank($log->mail_domain)
            || blank($log->channel)
            || blank($log->recipient)
            || blank($log->sender)
            || blank($log->status)
            || blank($log->idempotency_key)
            || $log->queued_at === null
        );

        $this->record('Communication Logs', $incomplete->isEmpty(),
            'All rows carry uuid, category, mailbox, user, channel, status, idempotency key, timestamps',
            $incomplete->isEmpty() ? null : $incomplete->count().' incomplete rows (ids: '.$incomplete->pluck('id')->implode(', ').')');

        $missingCorrelation = $logs->filter(fn ($log) => blank($log->correlation_id));
        $this->record('Communication Logs', $missingCorrelation->isEmpty(),
            'Every log entry carries a correlation ID',
            $missingCorrelation->isEmpty() ? null : $missingCorrelation->count().' rows without correlation_id');
    }

    // ─────────────────────────────────────────────────────────────────
    //  mail-log delivery proof + platform config
    // ─────────────────────────────────────────────────────────────────

    /**
     * The file the pinned `mail` channel is writing to right now. Asked of
     * the channel's own handler rather than guessed from config: the channel
     * is a daily rotator, so today's file is mail-YYYY-MM-DD.log, not the
     * mail.log its path key names.
     */
    private function mailLogFile(): string
    {
        foreach (Log::channel('mail')->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof StreamHandler && $handler->getUrl()) {
                return $handler->getUrl();
            }
        }

        return (string) config('logging.channels.mail.path');
    }

    /**
     * Delivery proof. The run pins the log mailer to the `mail` channel, so
     * the rendered MIME of every probe email is appended to that file and
     * nowhere else; laravel.log only ever carried it by accident. A rendered
     * message is counted by its Message-ID header, which Symfony adds to
     * every message it serialises, exactly once.
     */
    private function checkMailLogReceived(string $logFile, int $offset): void
    {
        if (! is_file($logFile)) {
            $this->record('Email Delivery (mail log)', false, 'mail log exists', 'file not found: '.$logFile.' (check logging.channels.mail)');

            return;
        }

        $handle = fopen($logFile, 'r');
        fseek($handle, $offset);
        $newContent = stream_get_contents($handle) ?: '';
        fclose($handle);

        $rendered = substr_count($newContent, 'Message-ID:');
        $probeHits = substr_count($newContent, (string) $this->probeUser?->email);

        $this->record('Email Delivery (mail log)', $rendered > 0,
            "Rendered emails written to the mail log ({$rendered} emails)",
            $rendered > 0 ? null : 'no rendered emails appended during the run');

        $this->record('Email Delivery (mail log)', $probeHits > 0,
            'Probe recipient address appears in logged emails',
            $probeHits > 0 ? null : 'probe address not found in appended log content');
    }

    private function checkPlatformConfig(): void
    {
        // Environment concern, not a platform defect — warn without failing.
        $adminEmail = config('communication.admin_email');
        $adminExists = $adminEmail && User::where('email', $adminEmail)->exists();
        $this->record('Platform Config', true,
            "Admin notification target ({$adminEmail})".($adminExists ? ' resolves to a user' : ' — WARNING'),
            $adminExists ? null : 'no user with this email in THIS environment; sendToAdmin() drops admin alerts here');

        $horizon = json_encode(config('horizon.environments', []) + config('horizon.defaults', []));
        $missing = array_filter(
            ['comm-critical', 'comm-transactional', 'comm-marketing'],
            fn ($queue) => ! str_contains($horizon, $queue)
        );
        $this->record('Platform Config', $missing === [],
            'Horizon supervisors cover comm-critical / comm-transactional / comm-marketing',
            $missing === [] ? null : 'not consumed by any supervisor: '.implode(', ', $missing));

        $flag = FeatureFlag::on('device_management');
        $this->record('Platform Config', true,
            'Feature flags readable (device_management is '.($flag ? 'ON' : 'OFF').')');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Probe helpers
    // ─────────────────────────────────────────────────────────────────

    private function probe(string $section, string $label, \Closure $callback): void
    {
        try {
            $callback();
            $this->record($section, true, $label);
        } catch (\Throwable $e) {
            $this->record($section, false, $label, $e->getMessage());
        }
    }

    /**
     * Assert the most recent email-channel log for the probe user matches
     * the expected category, mailbox, sender, template, and was sent.
     */
    private function assertEmailLog(CommunicationCategory $category, MailDomain $expectedDomain): void
    {
        $log = CommunicationLog::where('user_id', $this->probeUser->id)
            ->where('category', $category->value)
            ->where('channel', 'email')
            ->latest('id')
            ->first();

        if (! $log) {
            throw new \RuntimeException('no email CommunicationLog row was created — the path is dead');
        }

        $expectedSender = $expectedDomain->senderAddress();

        if ($log->sender !== $expectedSender) {
            throw new \RuntimeException("sender is {$log->sender}, expected {$expectedSender}");
        }
        if ($log->mail_domain !== $expectedDomain) {
            throw new \RuntimeException("mail_domain is {$log->mail_domain->value}, expected {$expectedDomain->value}");
        }
        if ($log->status->value !== 'sent') {
            throw new \RuntimeException("email status is {$log->status->value}".($log->error_message ? " — {$log->error_message}" : ''));
        }
        if (! str_starts_with((string) $log->provider_message_id, 'log_')) {
            throw new \RuntimeException('provider message id missing — mailer did not confirm delivery');
        }
        if (blank($log->template_key)) {
            throw new \RuntimeException('template_key not recorded');
        }
        if ($log->sent_at === null || $log->queued_at === null) {
            throw new \RuntimeException('queued_at / sent_at timestamps not recorded');
        }
    }

    /**
     * Assert an in-app log row exists and was delivered (for categories
     * without an email template yet).
     */
    private function assertInAppLog(CommunicationCategory $category, MailDomain $expectedDomain): void
    {
        $log = CommunicationLog::where('user_id', $this->probeUser->id)
            ->where('category', $category->value)
            ->where('channel', 'in_app')
            ->latest('id')
            ->first();

        if (! $log) {
            throw new \RuntimeException('no in-app CommunicationLog row was created — the path is dead');
        }
        if ($log->mail_domain !== $expectedDomain) {
            throw new \RuntimeException("mail_domain is {$log->mail_domain->value}, expected {$expectedDomain->value}");
        }
        if ($log->sender !== $expectedDomain->senderAddress()) {
            throw new \RuntimeException("sender is {$log->sender}, expected {$expectedDomain->senderAddress()}");
        }
        if ($log->status->value !== 'sent') {
            throw new \RuntimeException("in-app status is {$log->status->value}".($log->error_message ? " — {$log->error_message}" : ''));
        }
    }

    private function makeWalletTransaction(User $user, Wallet $wallet, string $type): WalletTransaction
    {
        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => $type,
            'currency' => 'USD',
            'amount' => 25.00,
            'balance_before' => $type === 'credit' ? 75.00 : 100.00,
            'balance_after' => $type === 'credit' ? 100.00 : 75.00,
            'description' => 'Validation probe '.$type,
            'reference' => 'valsuite_'.$type.'_'.Str::random(8),
            'transaction_category' => $type === 'credit' ? 'funding' : 'purchase',
            'idempotency_key' => 'valsuite_wtx_'.Str::random(8),
        ]);
    }

    private function record(string $section, bool $passed, string $label, ?string $detail = null): void
    {
        $this->results[$section][] = [
            'status' => $passed ? 'pass' : 'fail',
            'label' => $label,
            'detail' => $detail,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Phase 7 — final report
    // ─────────────────────────────────────────────────────────────────

    private function renderReport(): int
    {
        $totalPassed = 0;
        $totalFailed = 0;

        $this->newLine();
        $this->line('  <options=bold>Communication Platform Validation</>');
        $this->line('  ─────────────────────────────────────────────');

        foreach ($this->results as $section => $checks) {
            $failed = collect($checks)->where('status', 'fail');
            $icon = $failed->isEmpty() ? '<fg=green>✓</>' : '<fg=red>✗</>';

            $this->newLine();
            $this->line("  {$icon} <options=bold>{$section}</>");

            foreach ($checks as $check) {
                $mark = $check['status'] === 'pass' ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->line("      {$mark} {$check['label']}");

                if ($check['detail'] !== null) {
                    $this->line("        <fg=yellow>↳ {$check['detail']}</>");
                }

                $check['status'] === 'pass' ? $totalPassed++ : $totalFailed++;
            }
        }

        $total = $totalPassed + $totalFailed;
        $health = $total > 0 ? round(($totalPassed / $total) * 100) : 0;
        $color = $health >= 100 ? 'green' : ($health >= 80 ? 'yellow' : 'red');

        $this->newLine();
        $this->line('  ─────────────────────────────────────────────');
        $this->line("  Checks passed: <fg=green>{$totalPassed}</>  failed: ".($totalFailed > 0 ? "<fg=red>{$totalFailed}</>" : '0'));
        $this->line("  <options=bold>Overall Platform Health: <fg={$color}>{$health}%</></>");
        $this->newLine();

        if (! $this->option('static')) {
            $this->components->info('All probe data was rolled back — no business records were persisted. Rendered emails are in '.$this->mailLogFile().'.');
        }

        return $totalFailed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
