<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Enums\RecoveryStatus;
use App\Models\PaymentOutcome;
use App\Models\PaymentTelemetryEvent;
use App\Models\RecoveryAttempt;
use App\Models\TransactionRecovery;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every number on the Payment Insights dashboard.
 * The Volt dashboard and payments:validate both read from here, so the
 * dashboard can never drift from what the validation suite proves.
 */
class PaymentInsightsMetrics
{
    /**
     * @return array{
     *     totalAttempts: int, successful: int, failed: int, abandoned: int,
     *     successRate: float, failureRate: float, abandonRate: float,
     *     buckets: array<string, int>,
     *     topReasons: Collection,
     *     recentFailures: Collection,
     *     providers: Collection,
     * }
     */
    public function outcomes(?CarbonInterface $since = null): array
    {
        $query = PaymentOutcome::query();
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        $totalAttempts = (clone $query)->count();
        $successful = (clone $query)->where('outcome', 'confirmed')->count();
        $failed = (clone $query)->where('outcome', 'failed')->count();
        $abandoned = (clone $query)->whereIn('outcome', ['expired', 'cancelled'])->count();

        $bucketCounts = (clone $query)->whereIn('outcome', ['failed', 'expired', 'cancelled'])
            ->select('failure_category', DB::raw('count(*) as count'))
            ->groupBy('failure_category')
            ->pluck('count', 'failure_category')
            ->toArray();

        $unsuccessful = (clone $query)->whereIn('outcome', ['failed', 'expired', 'cancelled']);
        $unsuccessfulCount = (clone $unsuccessful)->count();

        $topReasons = (clone $unsuccessful)
            ->select(
                'failure_reason_code',
                DB::raw('count(*) as count'),
                DB::raw('sum(case when retryable = 1 then 1 else 0 end) as retryable_count'),
            )
            ->groupBy('failure_reason_code')
            ->orderByDesc('count')
            ->limit(8)
            ->get()
            ->map(function ($row) use ($unsuccessfulCount) {
                $row->label = FailureReasonClassifier::label((string) $row->failure_reason_code);
                $row->share = $unsuccessfulCount > 0 ? round(($row->count / $unsuccessfulCount) * 100, 1) : 0.0;
                $row->retryable = $row->count > 0 && (int) $row->retryable_count >= $row->count;

                return $row;
            });

        // Drill-down: the latest unsuccessful outcomes with the gateway's
        // verbatim verdict, for support cases ("why did my payment fail?")
        // answered from one screen instead of log spelunking. Includes
        // abandonment, not just hard declines: on this platform most lost
        // payments are people walking away, and a table that hid them would
        // show a handful of rows while the real leak stayed invisible.
        $recentFailures = (clone $unsuccessful)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn (PaymentOutcome $outcome) => [
                'at' => $outcome->created_at?->toDateTimeString(),
                'outcome' => $outcome->outcome->value,
                'stage' => $outcome->failure_stage?->value,
                'provider' => $outcome->provider,
                'payment_method' => $outcome->payment_method,
                'code' => $outcome->failure_reason_code,
                'label' => FailureReasonClassifier::label((string) $outcome->failure_reason_code),
                'provider_code' => $outcome->provider_code,
                'provider_message' => $outcome->metadata['failure_details']['provider_message'] ?? null,
                'raw_reason' => $outcome->metadata['raw_reason'] ?? null,
                'retryable' => $outcome->retryable,
                'user_id' => $outcome->user_id,
            ]);

        // Per-provider success rate: confirmed / all outcomes for the provider.
        $providers = (clone $query)->whereNotNull('provider')
            ->select(
                'provider',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when outcome = 'confirmed' then 1 else 0 end) as confirmed"),
            )
            ->groupBy('provider')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                $row->success_rate = $row->total > 0 ? round(($row->confirmed / $row->total) * 100, 1) : 0.0;

                return $row;
            });

        // Two rates, never one. The payments industry separates them because
        // they are different problems with different owners:
        //
        //   Authorization rate = approved / actually submitted to a gateway.
        //   Excludes people who never entered details, so it measures whether
        //   PAYMENTS work. This is the number to compare against a provider.
        //
        //   Checkout conversion = approved / everyone who started checkout.
        //   Includes abandonment, so it measures whether CHECKOUT works.
        //
        // Blending them produces a figure that is neither, and on a platform
        // where most losses are abandonment it reads as a payments crisis
        // when the payments are fine.
        $submitted = $successful + $failed;

        return [
            'totalAttempts' => $totalAttempts,
            'successful' => $successful,
            'failed' => $failed,
            'abandoned' => $abandoned,
            'submitted' => $submitted,
            'authorizationRate' => $submitted > 0 ? round(($successful / $submitted) * 100, 1) : 0.0,
            'checkoutConversionRate' => $totalAttempts > 0 ? round(($successful / $totalAttempts) * 100, 1) : 0.0,
            // Retained: same definition as checkout conversion, kept so older
            // readers of this array keep working.
            'successRate' => $totalAttempts > 0 ? round(($successful / $totalAttempts) * 100, 1) : 0.0,
            'failureRate' => $totalAttempts > 0 ? round(($failed / $totalAttempts) * 100, 1) : 0.0,
            'abandonRate' => $totalAttempts > 0 ? round(($abandoned / $totalAttempts) * 100, 1) : 0.0,
            'buckets' => [
                'Customer' => $bucketCounts[FailureCategory::Customer->value] ?? 0,
                'Provider' => $bucketCounts[FailureCategory::Provider->value] ?? 0,
                'System' => $bucketCounts[FailureCategory::System->value] ?? 0,
                'Frontend' => $bucketCounts[FailureCategory::Frontend->value] ?? 0,
            ],
            'topReasons' => $topReasons,
            'recentFailures' => $recentFailures,
            'providers' => $providers,
        ];
    }

    /**
     * Where in the funnel payments die, aggregated.
     *
     * On a platform whose losses are mostly walk-aways, "which step were they
     * on when they left" is the most valuable question on the page, and the
     * classifier has been recording the answer on every outcome all along.
     *
     * @return array<int, array{stage: string, label: string, count: int, share: float}>
     */
    public function funnelStages(?CarbonInterface $since = null): array
    {
        $query = PaymentOutcome::query()->whereIn('outcome', ['failed', 'expired', 'cancelled']);
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        $counts = (clone $query)
            ->select('failure_stage', DB::raw('count(*) as count'))
            ->groupBy('failure_stage')
            ->pluck('count', 'failure_stage');

        $total = (int) $counts->sum();

        // Fixed order: the funnel reads in the order a customer walks it, so
        // a missing stage is visible as a gap rather than reordering the row.
        $labels = [
            'initialization' => 'Starting checkout',
            'authentication' => 'Verifying (OTP / 3DS / PIN)',
            'processing' => 'Charging',
            'confirmation' => 'Awaiting confirmation',
        ];

        $stages = [];
        foreach ($labels as $stage => $label) {
            $count = (int) ($counts[$stage] ?? 0);
            $stages[] = [
                'stage' => $stage,
                'label' => $label,
                'count' => $count,
                'share' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        return $stages;
    }

    /**
     * Daily series so degradation is visible as a slope rather than
     * discovered as a surprise. Gap-filled: a day with no payments is a fact
     * worth seeing, not a day to skip.
     *
     * @return array<int, array{date: string, submitted: int, confirmed: int, failed: int, abandoned: int, authorizationRate: ?float}>
     */
    public function dailyTrend(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = PaymentOutcome::query()
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw($this->dateSql('created_at').' as day'),
                DB::raw("sum(case when outcome = 'confirmed' then 1 else 0 end) as confirmed"),
                DB::raw("sum(case when outcome = 'failed' then 1 else 0 end) as failed"),
                DB::raw("sum(case when outcome in ('expired','cancelled') then 1 else 0 end) as abandoned"),
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $row = $rows[$date] ?? null;

            $confirmed = (int) ($row->confirmed ?? 0);
            $failed = (int) ($row->failed ?? 0);
            $submitted = $confirmed + $failed;

            $series[] = [
                'date' => $date,
                'submitted' => $submitted,
                'confirmed' => $confirmed,
                'failed' => $failed,
                'abandoned' => (int) ($row->abandoned ?? 0),
                // Null, not zero: a day with no submitted payments has no
                // authorization rate, and plotting it as 0% invents a crisis.
                'authorizationRate' => $submitted > 0 ? round(($confirmed / $submitted) * 100, 1) : null,
            ];
        }

        return $series;
    }

    /**
     * Authorization rate per payment method. Provider-level averaging hides
     * the actionable version of this: "cards are struggling, bank transfer is
     * fine" is a checkout decision, "Flutterwave is at 80%" is not.
     *
     * @return Collection<int, object>
     */
    public function byPaymentMethod(?CarbonInterface $since = null): Collection
    {
        $query = PaymentOutcome::query()->whereNotNull('payment_method');
        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query
            ->select(
                'payment_method',
                DB::raw('count(*) as started'),
                DB::raw("sum(case when outcome = 'confirmed' then 1 else 0 end) as confirmed"),
                DB::raw("sum(case when outcome = 'failed' then 1 else 0 end) as failed"),
                DB::raw("sum(case when outcome in ('expired','cancelled') then 1 else 0 end) as abandoned"),
            )
            ->groupBy('payment_method')
            ->orderByDesc('started')
            ->get()
            ->map(function ($row) {
                $submitted = (int) $row->confirmed + (int) $row->failed;
                $row->submitted = $submitted;
                $row->authorization_rate = $submitted > 0 ? round(($row->confirmed / $submitted) * 100, 1) : null;
                $row->abandon_rate = $row->started > 0 ? round(($row->abandoned / $row->started) * 100, 1) : 0.0;

                return $row;
            });
    }

    /** Driver-aware day bucketing (MySQL DATE_FORMAT vs SQLite strftime). */
    private function dateSql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m-%d')";
    }

    /**
     * Recovery funnel: emails sent → links clicked → payments recovered, plus
     * skip reasons (from outcome metadata) and customer feedback breakdown.
     *
     * @return array{
     *     sent: int, clicked: int, recovered: int, cancelled: int,
     *     abandoned: int, feedbackReceived: int,
     *     clickRate: float, conversionRate: float,
     *     recoveredValue: Collection,
     *     skipReasons: Collection,
     *     feedbackBreakdown: Collection,
     * }
     */
    public function recovery(?CarbonInterface $since = null): array
    {
        // Qualified column — the recovered-value clone joins payment_sessions,
        // which also has created_at.
        $recoveries = TransactionRecovery::query();
        if ($since) {
            $recoveries->where('transaction_recoveries.created_at', '>=', $since);
        }

        $sent = (clone $recoveries)->count();
        // Recovered now means a payment actually confirmed and was attributed
        // to this recovery, not that someone clicked a button.
        $recovered = (clone $recoveries)->where('status', RecoveryStatus::Recovered->value)->count();
        $cancelled = (clone $recoveries)->where('status', RecoveryStatus::Cancelled->value)->count();
        $abandoned = (clone $recoveries)->where('status', RecoveryStatus::Abandoned->value)->count();
        $feedbackReceived = (clone $recoveries)->where('status', RecoveryStatus::FeedbackReceived->value)->count();
        $retrying = (clone $recoveries)->where('status', RecoveryStatus::Retrying->value)->count();

        // Engagement from the recovery's own timestamps rather than a join on
        // the attempt log: one row per recovery, so a customer who opened the
        // link five times counts once.
        $clicked = (clone $recoveries)->whereNotNull('clicked_at')->count();
        $reminded = (clone $recoveries)->whereNotNull('reminder_sent_at')->count();
        $recoveredAfterReminder = (clone $recoveries)
            ->where('status', RecoveryStatus::Recovered->value)
            ->whereNotNull('reminder_sent_at')
            ->count();

        // Recovered value per currency — amounts live in the session currency,
        // so a blended single figure would be meaningless.
        $recoveredValue = (clone $recoveries)->where('transaction_recoveries.status', RecoveryStatus::Recovered->value)
            ->join('payment_sessions', 'payment_sessions.id', '=', 'transaction_recoveries.payment_session_id')
            ->select('payment_sessions.currency', DB::raw('sum(payment_sessions.amount) as amount'))
            ->groupBy('payment_sessions.currency')
            ->pluck('amount', 'currency');

        // Why recoveries were NOT sent — recorded on the payment outcome by
        // EvaluateRecoveryJob. Filtered in SQL (portable JSON path), grouped
        // in PHP: the skipped set inside a timeframe is small, and
        // json_unquote() only exists on MySQL.
        $skips = PaymentOutcome::query()
            ->where('metadata->recovery_evaluation->eligible', false);
        if ($since) {
            $skips->where('created_at', '>=', $since);
        }
        $skipReasons = $skips->pluck('metadata')
            ->map(fn ($metadata) => $metadata['recovery_evaluation']['reason'] ?? 'unknown')
            ->countBy()
            ->sortDesc();

        $feedback = RecoveryAttempt::where('action', 'provided_feedback');
        if ($since) {
            $feedback->where('created_at', '>=', $since);
        }
        $feedbackBreakdown = $feedback->pluck('payload')
            ->map(fn ($payload) => $payload['reason'] ?? 'unknown')
            ->countBy()
            ->sortDesc();

        return [
            'sent' => $sent,
            'clicked' => $clicked,
            'retrying' => $retrying,
            'recovered' => $recovered,
            'cancelled' => $cancelled,
            'abandoned' => $abandoned,
            'feedbackReceived' => $feedbackReceived,
            'reminded' => $reminded,
            'recoveredAfterReminder' => $recoveredAfterReminder,
            'clickRate' => $sent > 0 ? round(($clicked / $sent) * 100, 1) : 0.0,
            'conversionRate' => $sent > 0 ? round(($recovered / $sent) * 100, 1) : 0.0,
            'recoveredValue' => $recoveredValue,
            'skipReasons' => $skipReasons,
            'feedbackBreakdown' => $feedbackBreakdown,
        ];
    }

    /**
     * Frontend observability: terminal frontend-category failures broken
     * down by reason code, provider, and browser, plus the non-terminal
     * environmental signals reported by the browser telemetry module.
     *
     * @return array{
     *     total: int, shareOfFailures: float,
     *     byCode: Collection, byProvider: Collection,
     *     browsers: Collection, signals: Collection,
     * }
     */
    public function frontend(?CarbonInterface $since = null): array
    {
        $failures = PaymentOutcome::query()
            ->where('failure_category', FailureCategory::Frontend->value);
        if ($since) {
            $failures->where('payment_outcomes.created_at', '>=', $since);
        }

        $total = (clone $failures)->count();

        $allFailures = PaymentOutcome::query()->whereIn('outcome', ['failed', 'expired', 'cancelled']);
        if ($since) {
            $allFailures->where('created_at', '>=', $since);
        }
        $allFailureCount = $allFailures->count();

        $byCode = (clone $failures)
            ->select('failure_reason_code', DB::raw('count(*) as count'))
            ->groupBy('failure_reason_code')
            ->orderByDesc('count')
            ->pluck('count', 'failure_reason_code');

        $byProvider = (clone $failures)->whereNotNull('provider')
            ->select('provider', DB::raw('count(*) as count'))
            ->groupBy('provider')
            ->orderByDesc('count')
            ->pluck('count', 'provider');

        // Browser split from the session's captured user agent. The frontend
        // failure set inside a timeframe is small, so family parsing happens
        // in PHP rather than DB-specific JSON SQL.
        $browsers = (clone $failures)
            ->join('payment_sessions', 'payment_sessions.id', '=', 'payment_outcomes.payment_session_id')
            ->pluck('payment_sessions.device_metadata')
            ->map(function ($metadata) {
                $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

                return $this->browserFamily($decoded['user_agent'] ?? null);
            })
            ->countBy()
            ->sortDesc();

        $signals = PaymentTelemetryEvent::query();
        if ($since) {
            $signals->where('created_at', '>=', $since);
        }
        $signals = $signals
            ->select('code', DB::raw('count(*) as count'))
            ->groupBy('code')
            ->orderByDesc('count')
            ->pluck('count', 'code');

        return [
            'total' => $total,
            'shareOfFailures' => $allFailureCount > 0 ? round(($total / $allFailureCount) * 100, 1) : 0.0,
            'byCode' => $byCode,
            'byProvider' => $byProvider,
            'browsers' => $browsers,
            'signals' => $signals,
        ];
    }

    private function browserFamily(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown';
        }

        return match (true) {
            str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'Edge/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Other',
        };
    }
}
