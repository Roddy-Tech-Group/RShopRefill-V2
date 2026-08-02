<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\PaymentInsights\Enums\FailureCategory;
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

        // Drill-down: the latest failures with the gateway's verbatim verdict,
        // for support cases ("why did my payment fail?") answered from one
        // screen instead of log spelunking. Admin eyes only.
        $recentFailures = (clone $query)->where('outcome', 'failed')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn (PaymentOutcome $outcome) => [
                'at' => $outcome->created_at?->toDateTimeString(),
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

        return [
            'totalAttempts' => $totalAttempts,
            'successful' => $successful,
            'failed' => $failed,
            'abandoned' => $abandoned,
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
        $recovered = (clone $recoveries)->where('status', 'recovered')->count();
        $cancelled = (clone $recoveries)->where('status', 'cancelled')->count();
        $abandoned = (clone $recoveries)->where('status', 'abandoned')->count();
        $feedbackReceived = (clone $recoveries)->where('status', 'feedback_received')->count();

        $clicked = (clone $recoveries)
            ->whereIn('id', RecoveryAttempt::where('action', 'clicked_link')->select('transaction_recovery_id'))
            ->count();

        // Recovered value per currency — amounts live in the session currency,
        // so a blended single figure would be meaningless.
        $recoveredValue = (clone $recoveries)->where('transaction_recoveries.status', 'recovered')
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
            'recovered' => $recovered,
            'cancelled' => $cancelled,
            'abandoned' => $abandoned,
            'feedbackReceived' => $feedbackReceived,
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
