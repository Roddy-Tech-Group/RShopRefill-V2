<?php

namespace App\Domain\PaymentInsights\Console;

use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\PaymentOutcome;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns the payment-outcome stream into interruptions while they still matter.
 *
 * On 2026-09-09 a customer failed five payments in twenty minutes because our
 * integration asked the gateway for a rail it does not offer. Every failure
 * was recorded, graded and drawn on the insights dashboard, and nobody knew
 * for hours because a dashboard only speaks when someone is looking at it.
 * Three signals now reach Telegram on their own, each a SEV-2 (a revenue path
 * is degraded; act within hours):
 *
 *  - platform faults: failures the classifier grades as OUR problem, which a
 *    customer cannot fix by retrying;
 *  - a customer failing repeatedly: someone is trying to pay and cannot,
 *    which is a support call to make, not a limiter to tighten;
 *  - a reason code spiking against its own recent baseline.
 *
 * Runs every ten minutes over a rolling window. The alerter's throttle keeps a
 * standing problem from re-sending on every run, so a fault that persists
 * produces one message an hour, not six.
 */
class AlertPaymentFailuresCommand extends Command
{
    protected $signature = 'insights:alert-failures {--window=30 : Minutes of outcomes to evaluate}';

    protected $description = 'Alert on platform-side payment faults, customers failing repeatedly, and failure-code spikes';

    /** Unsuccessful attempts by one customer inside the window that mean they need a human. */
    public const REPEAT_CUSTOMER_THRESHOLD = 3;

    /** A reason code needs at least this many occurrences before it can be a spike. */
    public const SPIKE_MIN_COUNT = 5;

    /** And at least this multiple of its per-window average over the recent baseline. */
    public const SPIKE_MULTIPLIER = 3.0;

    /**
     * Baseline = the previous N windows. Recent, not a whole day: a day-long
     * average dilutes a busy afternoon into a "spike" every window, while a
     * three-hour one asks the right question, "did this just change?".
     */
    public const BASELINE_WINDOWS = 6;

    /**
     * Platform-side codes with their own alert stream already. The stuck-session
     * sweeper alerts on webhook loss; re-alerting here would give one problem
     * two voices.
     *
     * @var list<string>
     */
    private const FAULTS_ALERTED_ELSEWHERE = ['orphaned_timeout'];

    /**
     * Walk-aways are volume-driven: a busy hour spikes them without anything
     * being wrong. They still count toward a customer's repeat threshold.
     *
     * @var list<string>
     */
    private const NEVER_A_SPIKE = ['session_expired', 'user_cancelled'];

    public function __construct(private readonly OperationalAlerter $alerter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $window = max(5, (int) $this->option('window'));
        $since = now()->subMinutes($window);

        $sent = $this->alertPlatformFaults($since, $window)
            + $this->alertRepeatCustomers($since, $window)
            + $this->alertSpikes($since, $window);

        $this->info($sent === 0 ? 'No payment failure signals.' : "{$sent} alert(s) raised.");

        // The check ran; a failing messenger is not a failing payment platform.
        return self::SUCCESS;
    }

    private function unsuccessful(\DateTimeInterface $since): Builder
    {
        return PaymentOutcome::query()
            ->whereIn('outcome', [OutcomeType::Failed->value, OutcomeType::Expired->value, OutcomeType::Cancelled->value])
            ->where('created_at', '>=', $since);
    }

    private function alertPlatformFaults(\DateTimeInterface $since, int $window): int
    {
        $faults = $this->unsuccessful($since)
            ->where('failure_category', FailureCategory::System->value)
            ->whereNotIn('failure_reason_code', self::FAULTS_ALERTED_ELSEWHERE)
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (PaymentOutcome $outcome) => ($outcome->provider ?? 'unknown').'|'.($outcome->failure_reason_code ?? 'unknown_error'));

        $sent = 0;

        foreach ($faults as $key => $group) {
            [$provider, $code] = explode('|', (string) $key, 2);
            /** @var PaymentOutcome $first */
            $first = $group->first();
            $gatewaySaid = $first->metadata['failure_details']['provider_message']
                ?? $first->metadata['raw_reason']
                ?? 'no message captured';

            $sent += (int) $this->alerter->send(
                severity: AlertSeverity::Sev2,
                key: "insights:fault:{$provider}:{$code}",
                title: 'Payments failing on our side: '.FailureReasonClassifier::label($code),
                message: sprintf(
                    '%d %s payment(s) failed in the last %d minutes with a fault the customer cannot fix by retrying (%d customer(s), via %s). The gateway said: "%s".',
                    $group->count(),
                    $provider,
                    $window,
                    $group->pluck('user_id')->filter()->unique()->count(),
                    $group->pluck('payment_method')->filter()->unique()->implode(', ') ?: 'unknown method',
                    $gatewaySaid,
                ),
                context: [
                    'provider' => $provider,
                    'reason_code' => $code,
                    'count' => $group->count(),
                    'first_seen' => $first->created_at?->toDateTimeString(),
                    'sample_user_id' => $first->user_id,
                    'sample_session' => $first->payment_session_id,
                ],
                runbook: 'docs/runbooks/payment-provider-failure.md',
            );
        }

        return $sent;
    }

    private function alertRepeatCustomers(\DateTimeInterface $since, int $window): int
    {
        $repeaters = $this->unsuccessful($since)
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('count(*) as attempts'))
            ->groupBy('user_id')
            ->having('attempts', '>=', self::REPEAT_CUSTOMER_THRESHOLD)
            ->pluck('attempts', 'user_id');

        $sent = 0;

        foreach ($repeaters as $userId => $attempts) {
            $outcomes = $this->unsuccessful($since)->where('user_id', $userId)->get();

            $reasons = $outcomes
                ->countBy(fn (PaymentOutcome $outcome) => FailureReasonClassifier::label((string) ($outcome->failure_reason_code ?? 'unknown_error')))
                ->sortDesc()
                ->map(fn (int $count, string $label) => "{$label} x{$count}")
                ->implode(', ');

            $sent += (int) $this->alerter->send(
                severity: AlertSeverity::Sev2,
                key: "insights:customer:{$userId}",
                title: "Customer #{$userId} cannot pay",
                message: sprintf(
                    '%d unsuccessful payment attempts in %d minutes (%s). Someone is trying to give us money and cannot. Open the customer page, check what they hit, and reach out before they give up.',
                    $attempts,
                    $window,
                    $reasons,
                ),
                context: [
                    'user_id' => $userId,
                    'attempts' => $attempts,
                    'product_lines' => $outcomes->pluck('product_line')->map(fn ($line) => $line?->value)->filter()->unique()->implode(', '),
                    'methods' => $outcomes->pluck('payment_method')->filter()->unique()->implode(', '),
                    'customer_page' => route('admin.customer', $userId),
                ],
                runbook: 'docs/runbooks/payment-provider-failure.md',
            );
        }

        return $sent;
    }

    private function alertSpikes(\DateTimeInterface $since, int $window): int
    {
        $current = $this->unsuccessful($since)
            ->whereNotIn('failure_reason_code', self::NEVER_A_SPIKE)
            ->select('failure_reason_code', DB::raw('count(*) as count'))
            ->groupBy('failure_reason_code')
            ->having('count', '>=', self::SPIKE_MIN_COUNT)
            ->pluck('count', 'failure_reason_code');

        if ($current->isEmpty()) {
            return 0;
        }

        // Baseline: the same code's average per window over the preceding
        // BASELINE_WINDOWS windows, excluding the window under test.
        $baselineCounts = PaymentOutcome::query()
            ->whereIn('outcome', [OutcomeType::Failed->value, OutcomeType::Expired->value, OutcomeType::Cancelled->value])
            ->whereIn('failure_reason_code', $current->keys()->all())
            ->where('created_at', '>=', now()->subMinutes($window * (self::BASELINE_WINDOWS + 1)))
            ->where('created_at', '<', $since)
            ->select('failure_reason_code', DB::raw('count(*) as count'))
            ->groupBy('failure_reason_code')
            ->pluck('count', 'failure_reason_code');

        $sent = 0;

        foreach ($current as $code => $count) {
            $baseline = ((int) ($baselineCounts[$code] ?? 0)) / self::BASELINE_WINDOWS;

            if ($count < max(self::SPIKE_MIN_COUNT, $baseline * self::SPIKE_MULTIPLIER)) {
                continue;
            }

            $sent += (int) $this->alerter->send(
                severity: AlertSeverity::Sev2,
                key: "insights:spike:{$code}",
                title: 'Payment failure spike: '.FailureReasonClassifier::label((string) $code),
                message: sprintf(
                    '%d "%s" failures in the last %d minutes against an average of %.1f per %d-minute window over the previous %d hours. Something changed; find out what before the day\'s revenue does.',
                    $count,
                    FailureReasonClassifier::label((string) $code),
                    $window,
                    $baseline,
                    $window,
                    (int) round($window * self::BASELINE_WINDOWS / 60),
                ),
                context: ['reason_code' => $code, 'count' => $count, 'baseline_per_window' => round($baseline, 2)],
                runbook: 'docs/runbooks/payment-provider-failure.md',
            );
        }

        return $sent;
    }
}
