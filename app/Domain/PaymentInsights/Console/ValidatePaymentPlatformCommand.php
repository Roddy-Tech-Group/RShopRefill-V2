<?php

namespace App\Domain\PaymentInsights\Console;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\MailDomain;
use App\Domain\Communication\Jobs\DispatchCommunicationJob;
use App\Domain\Communication\Mail\Transactions\PaymentRecoveryMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Payment\Events\PaymentSessionConfirmed;
use App\Domain\Payment\Events\PaymentSessionExpired;
use App\Domain\Payment\Events\PaymentSessionFailed;
use App\Domain\Payment\Jobs\ExpireStalePaymentSessionsJob;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Enums\IneligibilityReason;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\PaymentInsights\Jobs\EvaluateRecoveryJob;
use App\Domain\PaymentInsights\Jobs\ExpireStaleRecoveriesJob;
use App\Domain\PaymentInsights\Jobs\RecordPaymentOutcomeJob;
use App\Domain\PaymentInsights\Listeners\RecordPaymentOutcomeListener;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\PaymentInsights\Services\PaymentInsightsMetrics;
use App\Domain\PaymentInsights\Services\RecoveryEligibilityService;
use App\Domain\Shared\Enums\FundingStatus;
use App\Http\Controllers\Api\PaymentTelemetryController;
use App\Http\Controllers\Web\RecoveryController;
use App\Models\CommunicationLog;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentBackoffEvent;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\PaymentTelemetryEvent;
use App\Models\RecoveryAttempt;
use App\Models\SiteSetting;
use App\Models\TransactionRecovery;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payment Platform Validation Suite.
 *
 * Proves the Payment Intelligence platform (outcome recording, failure
 * classification, transaction recovery, progressive backoff, frontend
 * telemetry, analytics, communication integration) is wired end-to-end by
 * driving the REAL production code paths — PaymentSessionService state
 * machine, real events, real jobs, real eligibility rules — inside a
 * database transaction that is always rolled back.
 *
 * Guarantees: no wallet mutations, no real orders (probe orders exist only
 * inside the rolled-back transaction), no external provider calls (session
 * lifecycle only — providers are never contacted), no balance changes.
 * Emails render through the log mailer into laravel.log.
 */
class ValidatePaymentPlatformCommand extends Command
{
    protected $signature = 'payments:validate
        {--static : Run only the static wiring checks, no simulation probes}';

    protected $description = 'Validate the Payment Intelligence platform: outcomes, recovery, backoff, telemetry, analytics, flags, queues, and event wiring';

    /**
     * Every payment lifecycle event → listener wire.
     *
     * @var array<int, array{0: class-string, 1: class-string}>
     */
    private const EVENT_WIRING = [
        [PaymentSessionFailed::class, RecordPaymentOutcomeListener::class],
        [PaymentSessionExpired::class, RecordPaymentOutcomeListener::class],
        [PaymentSessionConfirmed::class, RecordPaymentOutcomeListener::class],
    ];

    /**
     * Failure-reason vocabulary → expected classification (category, code).
     * Mirrors every scenario the frontend tracker and providers can report.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CLASSIFIER_MATRIX = [
        // Issuer verdicts now grade to precise reason codes (the whole point
        // of the diagnostics upgrade); generic "declined" stays the fallback.
        'Insufficient funds' => ['customer', 'insufficient_funds'],
        'Do not honor' => ['customer', 'do_not_honor'],
        'Expired Card' => ['customer', 'expired_card'],
        'Incorrect PIN' => ['customer', 'incorrect_pin'],
        'Transaction not permitted to cardholder' => ['customer', 'card_restricted'],
        'Fraudulent transaction suspected' => ['customer', 'suspected_fraud'],
        'Card declined by issuer' => ['customer', 'bank_declined'],
        'User closed modal' => ['customer', 'user_cancelled'],
        'User cancelled the payment' => ['customer', 'user_cancelled'],
        'Payment page abandoned' => ['customer', 'user_cancelled'],
        'Gateway timeout' => ['provider', 'provider_timeout'],
        'Gateway unavailable' => ['provider', 'provider_error'],
        'Provider error 502' => ['provider', 'provider_error'],
        'Ad blocker detected' => ['frontend', 'frontend_issue'],
        'JS exception during checkout' => ['frontend', 'frontend_issue'],
        'Popup blocked by browser' => ['frontend', 'frontend_issue'],
        'Flutterwave popup failed to open' => ['frontend', 'frontend_issue'],
        'SDK failed to load' => ['frontend', 'frontend_issue'],
        'Browser offline' => ['frontend', 'frontend_issue'],
        'Slow network during payment' => ['frontend', 'frontend_issue'],
        'Browser refresh mid-payment' => ['frontend', 'frontend_issue'],
        'Internal server error' => ['system', 'system_error'],
        // Sweeper verdict keeps its own code now instead of dissolving into
        // the generic system bucket.
        'orphaned_timeout' => ['system', 'orphaned_timeout'],
        'Completely novel failure text' => ['system', 'unknown_error'],
        // Enumerated client telemetry codes (payment-telemetry.js) keep
        // their own granular reason codes.
        'popup_closed' => ['customer', 'user_cancelled'],
        'page_abandoned' => ['customer', 'user_cancelled'],
        'popup_blocked' => ['frontend', 'popup_blocked'],
        'sdk_load_failed' => ['frontend', 'sdk_load_failed'],
        'ad_blocker' => ['frontend', 'ad_blocker'],
        'js_exception' => ['frontend', 'js_exception'],
        'network_offline' => ['frontend', 'network_offline'],
        'network_timeout' => ['frontend', 'network_timeout'],
        'slow_network' => ['frontend', 'slow_network'],
    ];

    /** Progressive backoff schedule: consecutive fails → expected wait seconds. */
    private const BACKOFF_SCHEDULE = [1 => 0, 2 => 0, 3 => 30, 4 => 60, 5 => 300];

    private const FEATURE_FLAGS = ['payment_insights', 'frontend_payment_tracking', 'progressive_backoff'];

    /** @var array<string, array<int, array{status: string, label: string, detail: ?string}>> */
    private array $results = [];

    private string $correlationId = '';

    public function handle(): int
    {
        $this->components->info('Payment Platform Validation Suite');

        if (app()->isProduction() && ! $this->confirm('You are in PRODUCTION. Probes run inside a rolled-back transaction and never contact providers. Continue?')) {
            return self::FAILURE;
        }

        // Safe transports for the whole run: emails render into laravel.log,
        // queued jobs execute inline, cache writes stay in-process.
        config([
            'mail.default' => 'log',
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        $this->checkComponents();
        $this->checkEventWiring();
        $this->checkClassifierMatrix();
        $this->checkRoutes();
        $this->checkQueueTopology();
        $this->checkCommunicationMapping();
        $this->checkFeatureFlagRegistry();

        if (! $this->option('static')) {
            $this->runSimulations();
        }

        return $this->renderReport();
    }

    // ─────────────────────────────────────────────────────────────────
    //  Static: component inventory
    // ─────────────────────────────────────────────────────────────────

    private function checkComponents(): void
    {
        $classes = [
            PaymentSessionService::class, PaymentBackoffManager::class,
            FailureReasonClassifier::class, RecoveryEligibilityService::class,
            PaymentInsightsMetrics::class, RecordPaymentOutcomeJob::class,
            EvaluateRecoveryJob::class, ExpireStaleRecoveriesJob::class,
            ExpireStalePaymentSessionsJob::class, RecordPaymentOutcomeListener::class,
            PaymentTelemetryController::class, RecoveryController::class,
            PaymentOutcome::class, TransactionRecovery::class, RecoveryAttempt::class,
            PaymentTelemetryEvent::class,
            PaymentRecoveryMail::class, PaymentBackoffException::class,
        ];

        foreach ($classes as $class) {
            $this->record('Component Inventory', class_exists($class), class_basename($class));
        }

        foreach (['recovery.show', 'recovery.thanks', 'emails.transactions.payment-recovery', 'livewire.admin.payment-insights.dashboard'] as $view) {
            $this->record('Component Inventory', view()->exists($view), "view {$view}");
        }

        foreach (['payment_outcomes', 'transaction_recoveries', 'recovery_attempts', 'payment_telemetry_events'] as $table) {
            $this->record('Component Inventory',
                DB::getSchemaBuilder()->hasTable($table), "table {$table}");
        }

        // The tracking columns the insights pipeline depends on must exist AND
        // be mass-assignable, or the service writes are silently discarded.
        foreach (['correlation_id', 'device_uuid'] as $column) {
            $this->record('Component Inventory',
                DB::getSchemaBuilder()->hasColumn('payment_sessions', $column) && (new PaymentSession)->isFillable($column),
                "payment_sessions.{$column} present + fillable");
        }
        $this->record('Component Inventory',
            (new RecoveryAttempt)->isFillable('transaction_recovery_id'),
            'RecoveryAttempt is mass-assignable (recovery page writes)');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Static: event wiring (Phase 11)
    // ─────────────────────────────────────────────────────────────────

    private function checkEventWiring(): void
    {
        $raw = Event::getRawListeners();

        foreach (self::EVENT_WIRING as [$event, $listener]) {
            $registered = collect($raw[$event] ?? [])
                ->map(fn ($entry) => is_string($entry) ? Str::before($entry, '@') : null)
                ->filter();

            $count = $registered->filter(fn ($class) => $class === $listener)->count();

            $this->record('Event Wiring', $count === 1,
                class_basename($event).' → '.class_basename($listener),
                $count === 1 ? null : ($count === 0 ? 'listener NOT registered — dead path' : "registered {$count}× — duplicate dispatch"));

            // Every registered listener class must exist (no dead listeners).
            foreach ($registered as $class) {
                if (! class_exists($class)) {
                    $this->record('Event Wiring', false, class_basename($event).' has dead listener', $class);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Static: failure classification matrix
    // ─────────────────────────────────────────────────────────────────

    private function checkClassifierMatrix(): void
    {
        $classifier = app(FailureReasonClassifier::class);

        foreach (self::CLASSIFIER_MATRIX as $reason => [$category, $code]) {
            $result = $classifier->classify('flutterwave', $reason, 'processing');
            $pass = $result['category'] === FailureCategory::from($category) && $result['code'] === $code;

            $this->record('Failure Classification', $pass,
                "\"{$reason}\" → {$category}/{$code}",
                $pass ? null : "classified as {$result['category']->value}/{$result['code']}");
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Static: HTTP surface
    // ─────────────────────────────────────────────────────────────────

    private function checkRoutes(): void
    {
        $expectations = [
            ['api.payment-sessions.telemetry.record-failure', ['auth', 'throttle:10,1']],
            ['api.payment-sessions.telemetry.record-signal', ['auth', 'throttle:30,1']],
            ['recovery.show', ['throttle:20,1']],
            ['recovery.feedback', ['throttle:20,1']],
            ['recovery.retry', ['throttle:20,1']],
            ['recovery.thanks', ['throttle:20,1']],
            ['admin.payment-insights', []],
        ];

        foreach ($expectations as [$name, $requiredMiddleware]) {
            $route = Route::getRoutes()->getByName($name);

            if (! $route) {
                $this->record('HTTP Surface', false, "route {$name}", 'route not registered');

                continue;
            }

            $missing = collect($requiredMiddleware)->reject(fn ($m) => in_array($m, $route->gatherMiddleware()));
            $this->record('HTTP Surface', $missing->isEmpty(), "route {$name}".($requiredMiddleware ? ' ['.implode(', ', $requiredMiddleware).']' : ''),
                $missing->isEmpty() ? null : 'missing middleware: '.$missing->implode(', '));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Static: queue + scheduler topology (Phase 10)
    // ─────────────────────────────────────────────────────────────────

    private function checkQueueTopology(): void
    {
        // Horizon must consume the intelligence queue or outcomes never record.
        // Queue lists live on the supervisor defaults; environment blocks may
        // override them, so gather from both.
        $horizonQueues = collect(config('horizon.defaults', []))
            ->values()
            ->concat(collect(config('horizon.environments', []))->flatMap(fn ($env) => array_values((array) $env)))
            ->flatMap(fn ($supervisor) => (array) ($supervisor['queue'] ?? []));
        $this->record('Queues', $horizonQueues->contains('intelligence'),
            "Horizon supervises 'intelligence' queue",
            $horizonQueues->contains('intelligence') ? null : 'RecordPaymentOutcomeJob / EvaluateRecoveryJob would never run in production');
        $this->record('Queues', $horizonQueues->contains('comm-transactional'),
            "Horizon supervises 'comm-transactional' (recovery emails)");

        // Retry policy on every insights job.
        foreach ([new RecordPaymentOutcomeJob('x', OutcomeType::Failed), new EvaluateRecoveryJob(0), new ExpireStaleRecoveriesJob] as $job) {
            $this->record('Queues', $job->tries === 3 && $job->backoff() === [30, 120, 300],
                class_basename($job).' retry policy (3 tries, 30/120/300s backoff)');
        }
        foreach ([new RecordPaymentOutcomeJob('x', OutcomeType::Failed), new EvaluateRecoveryJob(0)] as $job) {
            $this->record('Queues', $job->deleteWhenMissingModels === true,
                class_basename($job).' deleteWhenMissingModels');
        }

        // Scheduler entries.
        $descriptions = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->description ?? (string) $event->command);
        foreach ([ExpireStaleRecoveriesJob::class, ExpireStalePaymentSessionsJob::class] as $scheduled) {
            $found = $descriptions->contains(fn ($d) => str_contains((string) $d, class_basename($scheduled)) || str_contains((string) $d, $scheduled));
            $this->record('Queues', $found, class_basename($scheduled).' is scheduled',
                $found ? null : 'no schedule entry found in routes/console.php');
        }
    }

    // ─────────────────────────────────────────────────────────────────
    //  Static: communication integration mapping (Phase 7)
    // ─────────────────────────────────────────────────────────────────

    private function checkCommunicationMapping(): void
    {
        foreach ([CommunicationCategory::PaymentRecovery, CommunicationCategory::TransactionFeedback] as $category) {
            $domain = $category->mailDomain();
            $this->record('Communication Mapping',
                $domain === MailDomain::Transactions && $domain->senderAddress() === 'transactions@rshoprefill.com',
                $category->name.' → transactions@rshoprefill.com',
                $domain === MailDomain::Transactions ? null : "maps to {$domain->value}");
        }

        $this->record('Communication Mapping',
            MailDomain::replyTo() === 'support@rshoprefill.com',
            'Reply-To → support@rshoprefill.com');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Static: feature flag registry (Phase 9)
    // ─────────────────────────────────────────────────────────────────

    private function checkFeatureFlagRegistry(): void
    {
        foreach (self::FEATURE_FLAGS as $flag) {
            $row = SiteSetting::query()->where('key', "features.{$flag}_enabled")->exists();
            $this->record('Feature Flags', $row, "features.{$flag}_enabled seeded",
                $row ? null : 'settings row missing — flag not visible to admins (defaults on)');
            $this->record('Feature Flags', FeatureFlag::on($flag), "{$flag} currently enabled");
        }

        // Deliberately NOT in FEATURE_FLAGS: that list asserts each flag is on,
        // and enforcement is meant to ship off. The limiter records what it
        // would have blocked until its thresholds have been checked against
        // real traffic, so "off" here is the correct, healthy state.
        $enforcing = FeatureFlag::on('payment_backoff_enforce', false);
        $this->record('Feature Flags', true, 'payment_backoff_enforce = '.($enforcing ? 'on (enforcing)' : 'off (shadow, recording only)'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Dynamic simulations — everything below runs inside a transaction
    //  that is ALWAYS rolled back.
    // ─────────────────────────────────────────────────────────────────

    private function runSimulations(): void
    {
        // Console requests have no client context; give the probe a stable
        // IP + device so capture assertions are meaningful.
        request()->server->set('REMOTE_ADDR', '203.0.113.10');
        request()->cookies->set('device_uuid', 'payval-device-'.Str::random(8));
        $this->correlationId = 'payval_'.Str::uuid()->toString();
        Context::add('correlation_id', $this->correlationId);

        DB::beginTransaction();

        try {
            $this->probeTerminalOutcomes();
            $this->probeFrontendTelemetry();
            $this->probeRecoveryLifecycle();
            $this->probeProgressiveBackoff();
            $this->probeFeatureFlagIsolation();
            $this->probeQueueRouting();
            $this->probeAnalytics();
        } finally {
            DB::rollBack();
            Context::forget('correlation_id');
        }
    }

    /**
     * Phase 3 — every terminal payment state through the REAL state machine.
     */
    private function probeTerminalOutcomes(): void
    {
        // Successful payment.
        $this->probe('Payment Outcomes', 'confirmed → outcome recorded with full capture', function () {
            [$user, $session] = $this->makeCheckoutSession();
            app(PaymentSessionService::class)->confirmSession($session);

            $outcome = $this->latestOutcome($session, 'confirmed');
            $this->ensure($outcome->user_id === $user->id, 'user not attributed');
            $this->ensure($outcome->product_line->value === 'checkout', "product_line {$outcome->product_line->value}");
            $this->ensure($outcome->correlation_id === $this->correlationId, 'correlation ID not propagated to outcome');
            $this->ensure((bool) $outcome->device_uuid, 'device not captured');
            $this->ensure($outcome->customer_ip === '203.0.113.10', 'IP not captured');
            $this->ensure($outcome->session_duration_seconds !== null && $outcome->session_duration_seconds >= 0, 'timing not captured');
            $this->ensure($session->fresh()->status === 'confirmed', 'session not confirmed');
        });

        // Failed payment (bank decline). The gateway verdict travels in the
        // GatewayFailure block and must surface as the precise reason code
        // plus the verbatim provider_code on the outcome row.
        $this->probe('Payment Outcomes', 'failed → classified customer/insufficient_funds + provider code', function () {
            [, $session] = $this->makeCheckoutSession();
            app(PaymentSessionService::class)->failSession($session, 'Transaction could not be completed', [
                'failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null],
            ]);

            $outcome = $this->latestOutcome($session, 'failed');
            $this->ensure($outcome->failure_category->value === 'customer', "category {$outcome->failure_category->value}");
            $this->ensure($outcome->failure_reason_code === 'insufficient_funds', "code {$outcome->failure_reason_code}");
            $this->ensure($outcome->provider_code === 'Insufficient Funds', 'gateway verdict not stored on outcome');
            $this->ensure($outcome->retryable === true, 'insufficient funds must stay retryable');
            $this->ensure($outcome->failure_stage !== null, 'failure stage missing');
            $this->ensure(($outcome->metadata['raw_reason'] ?? null) === 'Transaction could not be completed', 'raw reason not preserved');
        });

        // Cancelled payment (customer closed the modal).
        $this->probe('Payment Outcomes', 'cancelled (closed modal) → outcome type cancelled', function () {
            [, $session] = $this->makeCheckoutSession();
            app(PaymentSessionService::class)->failSession($session, 'User closed modal', ['provider' => 'flutterwave']);

            $outcome = $this->latestOutcome($session, 'cancelled');
            $this->ensure($outcome->failure_reason_code === 'user_cancelled', "code {$outcome->failure_reason_code}");
        });

        // Expired / abandoned payment.
        $this->probe('Payment Outcomes', 'expired (abandoned) → outcome type expired', function () {
            [, $session] = $this->makeCheckoutSession();
            app(PaymentSessionService::class)->expireSession($session);

            $this->latestOutcome($session, 'expired');
            $this->ensure($session->fresh()->status === 'expired', 'session not expired');
        });

        // Provider failure + timeout.
        $this->probe('Payment Outcomes', 'provider failure / timeout → classified provider', function () {
            [, $session] = $this->makeCheckoutSession();
            app(PaymentSessionService::class)->failSession($session, 'Gateway timeout - provider error');

            $outcome = $this->latestOutcome($session, 'failed');
            $this->ensure($outcome->failure_category->value === 'provider', "category {$outcome->failure_category->value}");
        });

        // Consecutive failure counter.
        $this->probe('Payment Outcomes', 'consecutive failures increment per user until success', function () {
            [$user, $session1] = $this->makeCheckoutSession();
            app(PaymentSessionService::class)->failSession($session1, 'declined');
            $session2 = $this->makeSessionFor($user);
            app(PaymentSessionService::class)->failSession($session2, 'declined again');

            $second = $this->latestOutcome($session2, 'failed');
            $this->ensure($second->consecutive_failure_count === 2, "count is {$second->consecutive_failure_count}, expected 2");
        });

        // Wallet funding product line (paid by card — regression for the
        // session_type-based misclassification).
        $this->probe('Payment Outcomes', 'wallet funding by card → product_line wallet_funding', function () {
            [$user, $session] = $this->makeWalletFundingSession();
            app(PaymentSessionService::class)->failSession($session, 'declined');

            $outcome = $this->latestOutcome($session, 'failed');
            $this->ensure($outcome->product_line->value === 'wallet_funding', "product_line {$outcome->product_line->value}");
            $this->ensure($outcome->user_id === $user->id, 'wallet funding outcome lost user attribution');
        });
    }

    /**
     * Phase 5 — frontend telemetry through the real API controller.
     */
    private function probeFrontendTelemetry(): void
    {
        $this->probe('Frontend Telemetry', 'record-failure accepts UUID session + updates outcome', function () {
            [$user, $session] = $this->makeCheckoutSession();

            $response = $this->callTelemetry($user, [
                'session_id' => $session->id,
                'reason' => 'Ad blocker detected - popup blocked',
                'context' => ['provider' => 'flutterwave', 'sdk' => 'v3'],
            ]);
            $this->ensure($response->getStatusCode() === 200, "HTTP {$response->getStatusCode()}");

            $outcome = $this->latestOutcome($session, 'failed');
            $this->ensure($outcome->failure_category->value === 'frontend', "category {$outcome->failure_category->value}");
            $this->ensure(($session->fresh()->payment_payload['failure_reason'] ?? null) === 'Ad blocker detected - popup blocked', 'customer-facing failure reason not attached to session');
            $this->ensure(($outcome->metadata['provider_payload']['provider'] ?? null) === 'flutterwave', 'telemetry context not preserved');
        });

        $this->probe('Frontend Telemetry', 'closed-popup telemetry → cancelled outcome', function () {
            [$user, $session] = $this->makeCheckoutSession();
            $this->callTelemetry($user, ['session_id' => $session->id, 'reason' => 'popup_closed']);
            $outcome = $this->latestOutcome($session, 'cancelled');
            $this->ensure($outcome->failure_reason_code === 'user_cancelled', "code {$outcome->failure_reason_code}");
        });

        $this->probe('Frontend Telemetry', 'enumerated codes keep granular reason codes', function () {
            foreach ([
                'sdk_load_failed' => ['failed', 'sdk_load_failed'],
                'popup_blocked' => ['failed', 'popup_blocked'],
                'page_abandoned' => ['cancelled', 'user_cancelled'],
            ] as $clientCode => [$expectedOutcome, $expectedReasonCode]) {
                [$user, $session] = $this->makeCheckoutSession();
                $this->callTelemetry($user, ['session_id' => $session->id, 'reason' => $clientCode, 'context' => ['provider' => 'flutterwave']]);

                $outcome = $this->latestOutcome($session, $expectedOutcome);
                $this->ensure($outcome->failure_reason_code === $expectedReasonCode,
                    "{$clientCode}: code {$outcome->failure_reason_code}, expected {$expectedReasonCode}");
            }
        });

        $this->probe('Frontend Telemetry', 'signals are recorded WITHOUT touching the session', function () {
            [$user, $session] = $this->makeCheckoutSession();
            $statusBefore = $session->fresh()->status;

            $response = $this->callSignal($user, [
                'session_id' => $session->id,
                'code' => 'js_exception',
                'context' => ['message' => 'TypeError: x is undefined'],
            ]);
            $this->ensure($response->getStatusCode() === 200 && ($response->getData(true)['status'] ?? null) === 'recorded', 'signal not recorded');

            $event = PaymentTelemetryEvent::where('payment_session_id', $session->id)->first();
            $this->ensure($event !== null && $event->code === 'js_exception', 'telemetry event row missing');
            $this->ensure(($event->context['message'] ?? null) === 'TypeError: x is undefined', 'signal context not stored');
            $this->ensure($session->fresh()->status === $statusBefore, 'signal mutated the payment session');
            $this->ensure(PaymentOutcome::where('payment_session_id', $session->id)->doesntExist(), 'signal produced an outcome row');
        });

        $this->probe('Frontend Telemetry', 'signals reject unknown codes + cap per session', function () {
            [$user, $session] = $this->makeCheckoutSession();

            try {
                $this->callSignal($user, ['session_id' => $session->id, 'code' => 'made_up_code']);
                $this->ensure(false, 'unknown signal code was accepted');
            } catch (ValidationException) {
                // expected
            }

            for ($i = 0; $i < 20; $i++) {
                PaymentTelemetryEvent::create(['payment_session_id' => $session->id, 'code' => 'js_exception']);
            }
            $response = $this->callSignal($user, ['session_id' => $session->id, 'code' => 'network_offline']);
            $this->ensure(($response->getData(true)['status'] ?? null) === 'capped', 'per-session signal cap not enforced');
            $this->ensure(PaymentTelemetryEvent::where('payment_session_id', $session->id)->count() === 20, 'cap exceeded');
        });

        $this->probe('Frontend Telemetry', 'rejects non-UUID session ids (422)', function () {
            [$user] = $this->makeCheckoutSession();
            try {
                $this->callTelemetry($user, ['session_id' => 12345, 'reason' => 'x']);
                $this->ensure(false, 'integer session id was accepted');
            } catch (ValidationException) {
                // expected
            }
        });

        $this->probe('Frontend Telemetry', "cannot terminate another customer's session", function () {
            [, $session] = $this->makeCheckoutSession();
            [$otherUser] = $this->makeCheckoutSession();

            $response = $this->callTelemetry($otherUser, ['session_id' => $session->id, 'reason' => 'x']);
            $this->ensure($response->getStatusCode() === 404, "HTTP {$response->getStatusCode()}, expected 404");
            $this->ensure(! in_array($session->fresh()->status, ['failed', 'cancelled']), 'foreign session was terminated');
        });
    }

    /**
     * Phase 4 — the recovery lifecycle, evaluated on the delayed-job timeline.
     */
    private function probeRecoveryLifecycle(): void
    {
        // Scenario 1: failure → delayed evaluation → recovery + email.
        $this->probe('Recovery Engine', 'S1: failure → 20min delayed evaluation → recovery + email via transactions@', function () {
            [$user, $session, $outcome, $evaluation] = $this->failWithDeferredEvaluation('Insufficient funds');

            $this->ensure($evaluation !== null, 'EvaluateRecoveryJob was not dispatched');
            $delaySeconds = $evaluation->delay ? now()->diffInSeconds($evaluation->delay, true) : 0;
            $this->ensure($delaySeconds > 1100 && $delaySeconds < 1300, "evaluation delay is {$delaySeconds}s, expected ~1200s (20 min)");

            // Run the evaluation as the queue worker would, 20 minutes later.
            $this->runEvaluation($outcome);

            $recovery = TransactionRecovery::where('payment_session_id', $session->id)->first();
            $this->ensure($recovery !== null, 'no recovery created');
            $this->ensure($recovery->status === RecoveryStatus::Pending, "status {$recovery->status->value}");
            $this->ensure(strlen($recovery->token) === 40, 'token not generated');
            // 48h, so the ~20h reminder always lands on a live link.
            $this->ensure($recovery->expires_at->gt(now()->addHours(47)), 'expiry not ~48h');
            $this->ensure($recovery->failure_reason_code !== null, 'failure reason not snapshotted onto the recovery');
            $this->ensure($recovery->payment_outcome_id !== null, 'recovery not linked back to its outcome');

            $log = CommunicationLog::where('correlation_id', 'payment_recovery_'.$recovery->id)
                ->where('channel', 'email')->first();
            $this->ensure($log !== null, 'no CommunicationLog for the recovery email');
            $this->ensure($log->sender === 'transactions@rshoprefill.com', "sender {$log->sender}");
            $this->ensure($log->status->value === 'sent', "email status {$log->status->value}".($log->error_message ? " — {$log->error_message}" : ''));
            $this->ensure($log->template_key === PaymentRecoveryMail::class, 'template not recorded');

            $eligible = $outcome->fresh()->metadata['recovery_evaluation']['eligible'] ?? null;
            $this->ensure($eligible === true, 'eligibility verdict not recorded on outcome');
        });

        // Idempotency: re-running the evaluation never duplicates.
        $this->probe('Recovery Engine', 'duplicate evaluation → single recovery, single email', function () {
            $outcome = PaymentOutcome::whereNotNull('user_id')->where('outcome', 'failed')
                ->whereHas('paymentSession', fn ($q) => $q->whereIn('id', TransactionRecovery::select('payment_session_id')))
                ->latest('id')->firstOrFail();

            $recoveries = TransactionRecovery::where('payment_session_id', $outcome->payment_session_id)->count();
            $logs = CommunicationLog::where('correlation_id', 'like', 'payment_recovery_%')->count();

            $this->runEvaluation($outcome);

            $this->ensure(TransactionRecovery::where('payment_session_id', $outcome->payment_session_id)->count() === $recoveries, 'duplicate recovery created');
            $this->ensure(CommunicationLog::where('correlation_id', 'like', 'payment_recovery_%')->count() === $logs, 'duplicate email dispatched');
        });

        // Scenario 2: customer retried before evaluation → no email.
        $this->probe('Recovery Engine', 'S2: customer retried before evaluation → cancelled, no email', function () {
            [$user, $session, $outcome] = $this->failWithDeferredEvaluation('declined');

            // Customer retries within the 20-minute window.
            $this->makeSessionFor($user);

            $logsBefore = CommunicationLog::count();
            $this->runEvaluation($outcome);

            $this->ensure(TransactionRecovery::where('payment_session_id', $session->id)->doesntExist(), 'recovery created despite retry');
            $this->ensure(CommunicationLog::count() === $logsBefore, 'recovery email sent despite retry');
            $reason = $outcome->fresh()->metadata['recovery_evaluation']['reason'] ?? null;
            $this->ensure($reason === IneligibilityReason::NotApplicable->value, "reason {$reason}");
        });

        // Scenario 3: one recovery per day.
        $this->probe('Recovery Engine', 'S3: recovery already sent today → skipped, cooldown recorded', function () {
            [$user, $session, $outcome] = $this->failWithDeferredEvaluation('declined');

            TransactionRecovery::create([
                'payment_session_id' => $session->id,
                'user_id' => $user->id,
                'customer_email' => $user->email,
                'token' => Str::random(40),
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);

            // A second failure for the same customer.
            $session2 = $this->makeSessionFor($user);
            app(PaymentSessionService::class)->failSession($session2, 'declined');
            $outcome2 = $this->latestOutcome($session2, 'failed');

            $this->runEvaluation($outcome2);

            $this->ensure(TransactionRecovery::where('payment_session_id', $session2->id)->doesntExist(), 'second recovery created within cooldown');
            $reason = $outcome2->fresh()->metadata['recovery_evaluation']['reason'] ?? null;
            $this->ensure($reason === IneligibilityReason::CooldownActive->value, "reason {$reason}");
        });

        // Scenario 4: guest → analytics kept, recovery skipped with reason.
        $this->probe('Recovery Engine', 'S4: guest outcome → analytics recorded, recovery skipped (guest_no_account)', function () {
            [, $session] = $this->makeCheckoutSession();
            $outcome = PaymentOutcome::create([
                'payment_session_id' => $session->id,
                'outcome' => 'failed',
                'user_id' => null,
                'product_line' => 'checkout',
                'provider' => 'flutterwave',
                'failure_category' => 'customer',
                'failure_stage' => 'processing',
                'failure_reason_code' => 'bank_declined',
                'correlation_id' => $this->correlationId,
            ]);

            $this->runEvaluation($outcome);

            $this->ensure(PaymentOutcome::whereKey($outcome->id)->exists(), 'guest analytics row lost');
            $this->ensure(TransactionRecovery::where('payment_session_id', $session->id)->doesntExist(), 'recovery created for guest');
            $reason = $outcome->fresh()->metadata['recovery_evaluation']['reason'] ?? null;
            $this->ensure($reason === IneligibilityReason::GuestNoAccount->value, "reason {$reason}");
        });

        // A payment for a DIFFERENT order closes the recovery as superseded:
        // real revenue, but not revenue this recovery brought back. Counting
        // it as recovered is how a recovery programme flatters itself.
        $this->probe('Recovery Engine', 'confirmed payment on another order → recovery superseded, not recovered', function () {
            [$user, $session, $outcome] = $this->failWithDeferredEvaluation('declined');
            $this->runEvaluation($outcome);
            $recovery = TransactionRecovery::where('payment_session_id', $session->id)->first();
            $this->ensure($recovery !== null, 'no recovery created — evaluation: '.json_encode($outcome->fresh()->metadata['recovery_evaluation'] ?? null));

            $retrySession = $this->makeSessionFor($user);
            app(PaymentSessionService::class)->confirmSession($retrySession);

            $fresh = $recovery->fresh();
            $this->ensure($fresh->status === RecoveryStatus::Cancelled, "recovery status {$fresh->status->value}, expected cancelled");
            $this->ensure($fresh->recovered_at === null, 'superseded recovery must not claim a recovery timestamp');
        });

        // The genuine article: the same order finally pays.
        $this->probe('Recovery Engine', 'confirmed payment on the same order → recovered with attribution', function () {
            [$user, $session, $outcome] = $this->failWithDeferredEvaluation('declined');
            $this->runEvaluation($outcome);
            $recovery = TransactionRecovery::where('payment_session_id', $session->id)->first();
            $this->ensure($recovery !== null, 'no recovery created');

            // Same order, new session: exactly what happens when a customer
            // follows the email back to checkout and pays.
            $order = $session->paymentAttempt->order;
            $retrySession = $this->makeSessionFor($user, $order);
            app(PaymentSessionService::class)->confirmSession($retrySession);

            $fresh = $recovery->fresh();
            $this->ensure($fresh->status === RecoveryStatus::Recovered, "recovery status {$fresh->status->value}, expected recovered");
            $this->ensure($fresh->recovered_at !== null, 'recovered_at not stamped');
            $this->ensure($fresh->recovered_payment_session_id === $retrySession->id, 'recovery not attributed to the paying session');
        });

        // Stale recovery sweep.
        $this->probe('Recovery Engine', 'expired pending recovery → abandoned by sweeper', function () {
            [, $session] = $this->makeCheckoutSession();
            $recovery = TransactionRecovery::create([
                'payment_session_id' => $session->id,
                'customer_email' => 'stale@rshoprefill.test',
                'token' => Str::random(40),
                'status' => 'pending',
                'expires_at' => now()->subHour(),
            ]);

            (new ExpireStaleRecoveriesJob)->handle();

            $this->ensure($recovery->fresh()->status === RecoveryStatus::Abandoned, "status {$recovery->fresh()->status->value}");
        });

        // Recovery page interactions: feedback + retry tracking.
        $this->probe('Recovery Engine', 'recovery page: click, feedback, retry all tracked', function () {
            [$user, $session, $outcome] = $this->failWithDeferredEvaluation('declined');
            $this->runEvaluation($outcome);
            $recovery = TransactionRecovery::where('payment_session_id', $session->id)->first();
            $this->ensure($recovery !== null, 'no recovery created — evaluation: '.json_encode($outcome->fresh()->metadata['recovery_evaluation'] ?? null));
            $controller = app(RecoveryController::class);

            $this->step('show', fn () => $controller->show($recovery->token));
            $this->ensure(RecoveryAttempt::where('transaction_recovery_id', $recovery->id)->where('action', 'clicked_link')->exists(), 'clicked_link not tracked');

            $feedbackRequest = Request::create('/recovery/'.$recovery->token.'/feedback', 'POST', ['reason' => 'declined', 'details' => 'Bank kept refusing']);
            $this->step('feedback', fn () => $controller->feedback($feedbackRequest, $recovery->token));
            $attempt = RecoveryAttempt::where('transaction_recovery_id', $recovery->id)->where('action', 'provided_feedback')->first();
            $this->ensure($attempt !== null && ($attempt->payload['reason'] ?? null) === 'declined', 'feedback not tracked with payload');
            $this->ensure($recovery->fresh()->status === RecoveryStatus::FeedbackReceived, 'feedback status not applied');

            // Retry needs an open recovery — reset the probe row. refresh()
            // first: the in-memory instance still says 'pending' from creation,
            // and update() skips clean attributes.
            $recovery->refresh()->update(['status' => RecoveryStatus::Pending]);
            $redirect = $this->step('retry', fn () => $controller->retry($recovery->token));
            // Retrying, not recovered: intent is not revenue. Only a confirmed
            // payment may mark a recovery recovered.
            $this->ensure($recovery->fresh()->status === RecoveryStatus::Retrying, 'retry did not mark retrying');
            $this->ensure($recovery->fresh()->retried_at !== null, 'retried_at not stamped');
            $this->ensure(RecoveryAttempt::where('transaction_recovery_id', $recovery->id)->where('action', 'retried_payment')->exists(), 'retried_payment not tracked');
            $this->ensure(str_contains($redirect->getTargetUrl(), '/order/'), "redirected to {$redirect->getTargetUrl()}");
        });
    }

    /**
     * Phase 6 — progressive backoff schedule, expiry, and resets.
     */
    private function probeProgressiveBackoff(): void
    {
        // Enforcement ships disabled so the limiter can observe real traffic
        // before it is allowed to turn anyone away. These probes assert the
        // enforcing behaviour, so they switch it on for their own duration.
        $this->setFlag('payment_backoff_enforce', true);

        try {
            $this->runBackoffProbes();
        } finally {
            $this->setFlag('payment_backoff_enforce', false);
        }

        $this->probeBackoffShadowMode();
    }

    private function runBackoffProbes(): void
    {
        $manager = app(PaymentBackoffManager::class);
        $user = $this->makeProbeUser();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->probe('Progressive Backoff', 'decline schedule: 1→none, 2→none, 3→30s, 4→60s, 5→300s', function () use ($manager, $user, $order) {
            foreach (self::BACKOFF_SCHEDULE as $failNumber => $expectedWait) {
                $this->failAtGateway($user, $order, 'declined #'.$failNumber);

                $status = $manager->getBackoffStatus($user, 'checkout', '203.0.113.10');
                $this->ensure($status['consecutive_fails'] === $failNumber, "after fail {$failNumber}: count {$status['consecutive_fails']}");

                if ($expectedWait === 0) {
                    $this->ensure(! $status['is_rate_limited'], "rate limited after only {$failNumber} failures");
                } else {
                    $this->ensure($status['is_rate_limited'], "not rate limited after {$failNumber} failures");
                    $this->ensure($status['wait_seconds'] > $expectedWait - 5 && $status['wait_seconds'] <= $expectedWait,
                        "fail {$failNumber}: wait {$status['wait_seconds']}s, expected ~{$expectedWait}s");

                    // The guard must refuse while limited. It is asserted here
                    // rather than through session creation because enforcement
                    // moved pre-flight: refusing at createForOrder meant the
                    // order, its items and the attempt were already committed,
                    // so a throttled customer was left with an unpayable order
                    // no sweeper could see.
                    try {
                        app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');
                        $this->ensure(false, 'guard allowed a rate limited customer through');
                    } catch (PaymentBackoffException $e) {
                        $response = $e->render(request());
                        $this->ensure($response->getStatusCode() === 429, "backoff renders {$response->getStatusCode()}");
                        $data = $response->getData(true);
                        $this->ensure(($data['is_rate_limited'] ?? false) === true && isset($data['wait_seconds']), 'backoff payload incomplete');
                    }

                    // …and unblocked once the cooldown has actually elapsed
                    // (regression: reversed Carbon diff made cooldowns grow forever).
                    PaymentOutcome::where('user_id', $user->id)->update(['created_at' => now()->subSeconds($expectedWait + 2)]);
                    $after = $manager->getBackoffStatus($user, 'checkout', '203.0.113.10');
                    $this->ensure(! $after['is_rate_limited'], "cooldown did not expire after {$expectedWait}s elapsed");
                }
            }
        });

        $this->probe('Progressive Backoff', 'successful payment resets the counter', function () use ($manager, $user, $order) {
            $session = $this->makeSessionFor($user, $order);
            app(PaymentSessionService::class)->confirmSession($session);

            $status = $manager->getBackoffStatus($user, 'checkout', '203.0.113.10');
            $this->ensure($status['consecutive_fails'] === 0, "count {$status['consecutive_fails']} after success");
            $this->ensure(! $status['is_rate_limited'], 'still limited after success');
        });

        $this->probe('Progressive Backoff', 'abandoning at the gateway is NOT counted as a failure', function () use ($manager) {
            // The behaviour this whole rewrite exists for. A customer who
            // reaches the payment screen and walks away is shopping, not
            // failing, and on real traffic this is the overwhelming majority
            // of unsuccessful outcomes. Counting it throttled ordinary buyers.
            $walker = $this->makeProbeUser();
            $walkerOrder = Order::factory()->create(['user_id' => $walker->id]);

            for ($i = 0; $i < 8; $i++) {
                $session = $this->makeSessionFor($walker, $walkerOrder);
                $session->transitionTo('awaiting_confirmation');
                app(PaymentSessionService::class)->failSession($session, 'popup_closed');
            }

            $status = $manager->getBackoffStatus($walker, 'checkout', '203.0.113.20');
            $this->ensure(! $status['is_rate_limited'], 'deep abandonment triggered a rate limit');
            $this->ensure($status['declines'] === 0, "deep abandonment counted {$status['declines']} declines");
            $this->ensure($status['no_shows'] === 0, "deep abandonment counted {$status['no_shows']} no-shows");
        });

        $this->probe('Progressive Backoff', 'repeat Pay taps that never reach a gateway ARE counted', function () use ($manager) {
            // The trial-and-error pattern: checkout created, no payment method
            // ever engaged. Guests get the tightest allowance on this signal.
            $tapper = $this->makeProbeUser();
            $tapperOrder = Order::factory()->create(['user_id' => $tapper->id]);

            for ($i = 0; $i < 6; $i++) {
                $session = $this->makeSessionFor($tapper, $tapperOrder);
                app(PaymentSessionService::class)->failSession($session, 'popup_closed');
            }

            $status = $manager->getBackoffStatus($tapper, 'checkout', '203.0.113.30');
            $this->ensure($status['no_shows'] === 6, "expected 6 no-shows, got {$status['no_shows']}");
            $this->ensure($status['signal'] === PaymentBackoffManager::SIGNAL_NO_SHOW, 'wrong signal drove the verdict');
            $this->ensure($status['is_rate_limited'], 'six no-shows did not trip the limiter');
        });

        $this->probe('Progressive Backoff', 'a customer who has paid before gets a longer leash', function () use ($manager) {
            $trusted = $this->makeProbeUser();
            $trustedOrder = Order::factory()->create(['user_id' => $trusted->id]);

            // Establish trust with a real payment, then reset the clock so the
            // success does not also wipe the failures we are about to record.
            $paid = $this->makeSessionFor($trusted, $trustedOrder);
            app(PaymentSessionService::class)->confirmSession($paid);
            PaymentOutcome::where('user_id', $trusted->id)->update(['created_at' => now()->subMinutes(90)]);

            $status = $manager->getBackoffStatus($trusted, 'checkout', '203.0.113.40');
            $this->ensure($status['tier'] === PaymentBackoffManager::TIER_TRUSTED, "tier {$status['tier']} for a paying customer");

            // Three declines would stop an untrusted customer dead.
            for ($i = 0; $i < 3; $i++) {
                $this->failAtGateway($trusted, $trustedOrder, 'declined');
            }

            $status = $manager->getBackoffStatus($trusted, 'checkout', '203.0.113.40');
            $this->ensure($status['declines'] === 3, "expected 3 declines, got {$status['declines']}");
            $this->ensure(! $status['is_rate_limited'], 'a paying customer was throttled at the baseline threshold');
        });

        $this->probe('Progressive Backoff', 'recovery link retry resets the backoff', function () use ($manager) {
            // A dedicated customer, so the schedule probe's failure history
            // can't bleed into this scenario.
            $retryUser = $this->makeProbeUser();
            $retryOrder = Order::factory()->create(['user_id' => $retryUser->id]);

            // Rack up three declines → limited. They have to be real declines:
            // three no-shows sit well inside that counter's allowance.
            $lastSession = null;
            for ($i = 0; $i < 3; $i++) {
                $lastSession = $this->failAtGateway($retryUser, $retryOrder, 'declined');
            }
            $this->ensure($manager->getBackoffStatus($retryUser, 'checkout', '203.0.113.10')['is_rate_limited'], 'expected to be limited before recovery retry');

            $recovery = TransactionRecovery::create([
                'payment_session_id' => $lastSession->id,
                'user_id' => $retryUser->id,
                'customer_email' => $retryUser->email,
                'token' => Str::random(40),
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);
            app(RecoveryController::class)->retry($recovery->token);

            $status = $manager->getBackoffStatus($retryUser, 'checkout', '203.0.113.10');
            $this->ensure(! $status['is_rate_limited'], 'backoff not reset by recovery retry');
        });
    }

    /**
     * Shadow mode is the default posture: measure everything, block nobody.
     * If this probe ever fails it means a fresh deployment would start turning
     * customers away on thresholds that have never been checked against real
     * traffic.
     */
    private function probeBackoffShadowMode(): void
    {
        $this->probe('Progressive Backoff', 'shadow mode records the decision without blocking', function () {
            $user = $this->makeProbeUser();
            $order = Order::factory()->create(['user_id' => $user->id]);

            for ($i = 0; $i < 4; $i++) {
                $this->failAtGateway($user, $order, 'declined');
            }

            $manager = app(PaymentBackoffManager::class);
            $this->ensure(! $manager->enforcementEnabled(), 'enforcement is on by default');

            $before = PaymentBackoffEvent::count();
            $status = $manager->getBackoffStatus($user, 'checkout', '203.0.113.50');

            $this->ensure(! $status['is_rate_limited'], 'shadow mode blocked a customer');
            $this->ensure($status['enforced'] === false, 'status claims enforcement while shadowing');
            $this->ensure($status['wait_seconds'] > 0, 'shadow mode computed no wait, so it measured nothing');
            $this->ensure(PaymentBackoffEvent::count() > $before, 'shadow mode recorded no event');

            $event = PaymentBackoffEvent::latest('id')->first();
            $this->ensure($event->enforced === false, 'event marked enforced while shadowing');
            $this->ensure($event->signal === PaymentBackoffManager::SIGNAL_DECLINE, "event signal {$event->signal}");

            // And the guard must let them through.
            app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');
        });
    }

    /**
     * A payment that reached the gateway and was refused there.
     *
     * The backoff counter separates these from checkouts that never engaged a
     * payment method, and a session created by makeSessionFor() sits at
     * 'awaiting_method'. Failing it directly would record a no-show, so any
     * probe asserting the decline schedule has to advance the session first.
     */
    private function failAtGateway(User $user, Order $order, string $reason): PaymentSession
    {
        $session = $this->makeSessionFor($user, $order);
        $session->transitionTo('processing');
        app(PaymentSessionService::class)->failSession($session, $reason);

        return $session;
    }

    /**
     * Phase 9 — each flag disables ONLY its own subsystem.
     */
    private function probeFeatureFlagIsolation(): void
    {
        $this->probe('Feature Flags', 'payment_insights off → no outcome recorded, payments unaffected', function () {
            $this->setFlag('payment_insights', false);
            try {
                [, $session] = $this->makeCheckoutSession();
                app(PaymentSessionService::class)->failSession($session, 'declined');

                $this->ensure(PaymentOutcome::where('payment_session_id', $session->id)->doesntExist(), 'outcome recorded while insights disabled');
                $this->ensure($session->fresh()->status === 'failed', 'payment state machine broken by flag');
            } finally {
                $this->setFlag('payment_insights', true);
            }
        });

        $this->probe('Feature Flags', 'frontend_payment_tracking off → telemetry no-ops gracefully', function () {
            [$user, $session] = $this->makeCheckoutSession();
            $this->setFlag('frontend_payment_tracking', false);
            try {
                $response = $this->callTelemetry($user, ['session_id' => $session->id, 'reason' => 'Ad blocker']);
                $this->ensure($response->getStatusCode() === 200 && ($response->getData(true)['status'] ?? null) === 'disabled', 'telemetry did not no-op');
                $this->ensure(! in_array($session->fresh()->status, ['failed', 'cancelled']), 'telemetry mutated session while disabled');

                $signalResponse = $this->callSignal($user, ['session_id' => $session->id, 'code' => 'js_exception']);
                $this->ensure(($signalResponse->getData(true)['status'] ?? null) === 'disabled', 'signal endpoint did not no-op');
                $this->ensure(PaymentTelemetryEvent::where('payment_session_id', $session->id)->doesntExist(), 'signal recorded while disabled');
            } finally {
                $this->setFlag('frontend_payment_tracking', true);
            }
        });

        $this->probe('Feature Flags', 'progressive_backoff off → limited customer can still pay', function () {
            $user = $this->makeProbeUser();
            $order = Order::factory()->create(['user_id' => $user->id]);

            // Enforcement must be on for "limited" to mean anything, and the
            // failures have to be real declines rather than no-shows.
            $this->setFlag('payment_backoff_enforce', true);

            for ($i = 0; $i < 3; $i++) {
                $this->failAtGateway($user, $order, 'declined');
                if ($i < 2) {
                    PaymentOutcome::where('user_id', $user->id)->update(['created_at' => now()->subSeconds(35)]);
                }
            }
            $this->ensure(app(PaymentBackoffManager::class)->getBackoffStatus($user, 'checkout', '203.0.113.10')['is_rate_limited'], 'expected limited');

            $this->setFlag('progressive_backoff', false);
            try {
                // The kill switch has to disarm the guard itself, which is what
                // checkout consults pre-flight. Asserting only that a session
                // can be created would pass even if the flag did nothing.
                app(PaymentSessionService::class)->assertNotRateLimited($user, 'checkout');

                $session = $this->makeSessionFor($user, $order); // must NOT throw
                $this->ensure($session->exists, 'session not created');
                // Outcome recording keeps working with backoff disabled.
                app(PaymentSessionService::class)->failSession($session, 'declined');
                $this->ensure(PaymentOutcome::where('payment_session_id', $session->id)->exists(), 'insights broken by backoff flag');
            } finally {
                $this->setFlag('progressive_backoff', true);
                $this->setFlag('payment_backoff_enforce', false);
            }
        });
    }

    /**
     * Phase 10 — queue names, payloads, and correlation on the wire.
     */
    private function probeQueueRouting(): void
    {
        $this->probe('Queue Routing', 'PaymentSessionFailed → RecordPaymentOutcomeJob on intelligence queue', function () {
            [, $session] = $this->makeCheckoutSession();

            $original = Queue::getFacadeRoot();
            Queue::fake();
            try {
                event(new PaymentSessionFailed($session, 'declined'));

                $pushed = Queue::pushed(RecordPaymentOutcomeJob::class);
                $this->ensure($pushed->count() === 1, "pushed {$pushed->count()} jobs, expected exactly 1 (no duplicates)");
                $job = $pushed->first();
                $this->ensure($job->queue === 'intelligence', "queued on '{$job->queue}'");
                $this->ensure($job->correlationId === $this->correlationId, 'correlation ID not carried in payload');
            } finally {
                Queue::swap($original);
            }
        });

        $this->probe('Queue Routing', 'recovery email → DispatchCommunicationJob on comm-transactional', function () {
            $user = $this->makeProbeUser();
            [, $session] = $this->makeCheckoutSession();
            $recovery = TransactionRecovery::create([
                'payment_session_id' => $session->id,
                'user_id' => $user->id,
                'customer_email' => $user->email,
                'token' => Str::random(40),
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);

            $original = Queue::getFacadeRoot();
            Queue::fake();
            try {
                app(CommunicationManager::class)->send(
                    recipient: $user,
                    category: CommunicationCategory::PaymentRecovery,
                    title: 'Complete your Payment',
                    message: 'Queue routing probe.',
                    mailable: new PaymentRecoveryMail($recovery),
                    correlationId: 'payval_queue_'.$recovery->id,
                );

                $pushed = Queue::pushed(DispatchCommunicationJob::class);
                $this->ensure($pushed->isNotEmpty(), 'DispatchCommunicationJob not queued');
                $this->ensure($pushed->first()->queue === 'comm-transactional', "queued on '{$pushed->first()->queue}'");
            } finally {
                Queue::swap($original);
            }
        });
    }

    /**
     * Phase 8 — the analytics the dashboard renders, from the same service.
     */
    private function probeAnalytics(): void
    {
        $since = now()->subHours(6); // probe rows are backdated at most ~5 minutes

        $this->probe('Analytics', 'outcome metrics reflect the simulated traffic', function () use ($since) {
            $metrics = app(PaymentInsightsMetrics::class)->outcomes($since);

            $this->ensure($metrics['totalAttempts'] > 0, 'no attempts counted');
            $this->ensure($metrics['successful'] > 0 && $metrics['failed'] > 0 && $metrics['abandoned'] > 0, 'terminal states missing from metrics');
            $this->ensure($metrics['successRate'] > 0 && $metrics['failureRate'] > 0, 'rates not computed');
            $this->ensure(($metrics['buckets']['Customer'] ?? 0) > 0, 'customer failure bucket empty');
            $this->ensure(($metrics['buckets']['Provider'] ?? 0) > 0, 'provider failure bucket empty');
            $this->ensure(($metrics['buckets']['Frontend'] ?? 0) > 0, 'frontend failure bucket empty');
            $this->ensure($metrics['topReasons']->isNotEmpty(), 'top reasons empty');

            $flutterwave = $metrics['providers']->firstWhere('provider', 'flutterwave');
            $this->ensure($flutterwave !== null && $flutterwave->total > 0, 'provider success rate missing');
        });

        $this->probe('Analytics', 'frontend failures: share / codes / signals / providers / browsers', function () use ($since) {
            $frontend = app(PaymentInsightsMetrics::class)->frontend($since);

            $this->ensure($frontend['total'] > 0, 'no frontend failures counted');
            $this->ensure($frontend['shareOfFailures'] > 0, 'frontend share of failures not computed');
            $this->ensure(($frontend['byCode']['sdk_load_failed'] ?? 0) > 0, 'sdk_load_failed not broken out');
            $this->ensure(($frontend['byCode']['popup_blocked'] ?? 0) > 0, 'popup_blocked not broken out');
            $this->ensure(($frontend['signals']['js_exception'] ?? 0) > 0, 'js_exception signals not surfaced');
            $this->ensure(($frontend['byProvider']['flutterwave'] ?? 0) > 0, 'provider breakdown missing');
            $this->ensure($frontend['browsers']->isNotEmpty(), 'browser split empty');
        });

        $this->probe('Analytics', 'recovery funnel: sent / clicked / recovered / skips / feedback', function () use ($since) {
            $recovery = app(PaymentInsightsMetrics::class)->recovery($since);

            $this->ensure($recovery['sent'] > 0, 'no recoveries counted');
            $this->ensure($recovery['clicked'] > 0, 'click tracking not surfaced');
            $this->ensure($recovery['recovered'] > 0, 'recovered count empty');
            $this->ensure($recovery['conversionRate'] > 0, 'conversion rate not computed');
            $this->ensure($recovery['cancelled'] > 0, 'auto-cancelled recoveries not surfaced');
            $this->ensure($recovery['abandoned'] > 0, 'abandoned recoveries not surfaced');
            $this->ensure(isset($recovery['skipReasons'][IneligibilityReason::CooldownActive->value]), 'cooldown skips not surfaced');
            $this->ensure(isset($recovery['skipReasons'][IneligibilityReason::GuestNoAccount->value]), 'guest skips not surfaced');
            $this->ensure(($recovery['feedbackBreakdown']['declined'] ?? 0) > 0, 'feedback breakdown missing');
        });
    }

    // ─────────────────────────────────────────────────────────────────
    //  Probe plumbing
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array{0: User, 1: PaymentSession}
     */
    private function makeCheckoutSession(): array
    {
        $user = $this->makeProbeUser();
        $session = $this->makeSessionFor($user);

        return [$user, $session];
    }

    private function makeSessionFor(User $user, ?Order $order = null): PaymentSession
    {
        $order ??= Order::factory()->create(['user_id' => $user->id]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'payval_attempt_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'pending',
        ]);

        return app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
    }

    /**
     * @return array{0: User, 1: PaymentSession}
     */
    private function makeWalletFundingSession(): array
    {
        $user = $this->makeProbeUser();

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => 'USD'],
            ['balance' => 0, 'locked_balance' => 0],
        );

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'payval_funding_'.Str::random(10),
            'currency' => 'USD',
            'amount' => 50.00,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'idempotency_key' => 'payval_fund_attempt_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 50.00,
            'payment_status' => 'pending',
        ]);

        return [$user, app(PaymentSessionService::class)->createForWalletFunding($funding, $attempt, [])];
    }

    private function makeProbeUser(): User
    {
        return User::factory()->create([
            'email' => 'payval+'.Str::random(10).'@rshoprefill.test',
        ]);
    }

    /**
     * Fail a fresh session while the queue is faked, then run
     * RecordPaymentOutcomeJob by hand so EvaluateRecoveryJob is captured
     * instead of running inline — reproducing the real 20-minute timeline.
     *
     * @return array{0: User, 1: PaymentSession, 2: PaymentOutcome, 3: ?EvaluateRecoveryJob}
     */
    private function failWithDeferredEvaluation(string $reason): array
    {
        [$user, $session] = $this->makeCheckoutSession();

        $original = Queue::getFacadeRoot();
        Queue::fake();
        try {
            app(PaymentSessionService::class)->failSession($session, $reason);
            (new RecordPaymentOutcomeJob($session->id, OutcomeType::Failed, $reason, $this->correlationId))
                ->handle(app(FailureReasonClassifier::class));

            $evaluation = Queue::pushed(EvaluateRecoveryJob::class)->first();
        } finally {
            Queue::swap($original);
        }

        $outcome = $this->latestOutcome($session, 'failed');

        return [$user, $session, $outcome, $evaluation];
    }

    private function runEvaluation(PaymentOutcome $outcome): void
    {
        (new EvaluateRecoveryJob($outcome->id, $this->correlationId))
            ->handle(app(RecoveryEligibilityService::class), app(CommunicationManager::class));
    }

    private function callTelemetry(User $user, array $payload): JsonResponse
    {
        $request = Request::create('/api/payment-sessions/telemetry/record-failure', 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        return app(PaymentTelemetryController::class)->recordFailure($request);
    }

    private function callSignal(User $user, array $payload): JsonResponse
    {
        $request = Request::create('/api/payment-sessions/telemetry/record-signal', 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        return app(PaymentTelemetryController::class)->recordSignal($request);
    }

    private function latestOutcome(PaymentSession $session, string $expected): PaymentOutcome
    {
        $outcome = PaymentOutcome::where('payment_session_id', $session->id)
            ->where('outcome', $expected)
            ->latest('id')
            ->first();

        if (! $outcome) {
            $found = PaymentOutcome::where('payment_session_id', $session->id)->pluck('outcome')->implode(', ') ?: 'none';
            throw new \RuntimeException("no '{$expected}' PaymentOutcome recorded (found: {$found})");
        }

        return $outcome;
    }

    private function setFlag(string $flag, bool $on): void
    {
        $key = "features.{$flag}_enabled";
        SiteSetting::put($key, $on ? 'on' : 'off', 'features');
        Cache::forget('site_setting_'.$key);
    }

    /**
     * Run a sub-step and prefix any failure with its name, so a multi-step
     * probe reports exactly where it broke.
     */
    private function step(string $name, \Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            throw new \RuntimeException("[{$name}] ".$e->getMessage(), previous: $e);
        }
    }

    private function ensure(bool $condition, string $failure): void
    {
        if (! $condition) {
            throw new \RuntimeException($failure);
        }
    }

    private function probe(string $section, string $label, \Closure $callback): void
    {
        try {
            $callback();
            $this->record($section, true, $label);
        } catch (\Throwable $e) {
            $this->record($section, false, $label, $e->getMessage());
        }
    }

    private function record(string $section, bool $passed, string $label, ?string $detail = null): void
    {
        $this->results[$section][] = [
            'status' => $passed ? 'pass' : 'fail',
            'label' => $label,
            'detail' => $detail,
        ];
    }

    private function renderReport(): int
    {
        $totalPassed = 0;
        $totalFailed = 0;

        $this->newLine();
        $this->line('  <options=bold>Payment Platform Validation</>');
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
            $this->components->info('All probe data was rolled back — no orders, balances, or provider calls were made. Recovery emails rendered to storage/logs/laravel.log.');
        }

        return $totalFailed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
