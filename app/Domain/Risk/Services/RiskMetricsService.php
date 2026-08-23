<?php

namespace App\Domain\Risk\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Models\Order;
use App\Models\RiskAssessment;
use App\Models\RiskLabel;
use App\Models\RiskReview;
use App\Models\RiskSignalRecord;
use App\Support\FeatureFlag;
use Carbon\CarbonImmutable;

/**
 * Reads the risk decision store into the metrics an operator (and an acquirer)
 * cares about: how much the engine reviews / declines / steps up, how that
 * compares to what it WOULD do if enforced (the shadow delta), the chargeback
 * rate against scheme ceilings, and the health of the review queue.
 *
 * Everything is computed from persisted rows, so the numbers are reproducible and
 * match exactly what the audit trail holds. No sampling, so nothing is silently
 * truncated.
 */
class RiskMetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(int $windowDays = 30): array
    {
        $since = CarbonImmutable::now()->subDays($windowDays);

        $assessments = RiskAssessment::query()->where('created_at', '>=', $since);
        $total = (clone $assessments)->count();

        $outcomes = (clone $assessments)
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $bands = (clone $assessments)
            ->selectRaw('band, count(*) as total')
            ->groupBy('band')
            ->pluck('total', 'band');

        $review = (int) ($outcomes['review'] ?? 0);
        $deny = (int) ($outcomes['deny'] ?? 0);
        $stepUp = (int) ($outcomes['step_up'] ?? 0);

        // What enforcement WOULD change: assessments that computed a blocking
        // outcome while running in shadow (enforced = false).
        $shadowWouldAct = (clone $assessments)
            ->where('enforced', false)
            ->where('outcome', '!=', 'allow')
            ->count();

        return [
            'window_days' => $windowDays,
            'enforcement' => [
                'scoring_on' => FeatureFlag::on('risk_engine', false),
                'enforcing' => FeatureFlag::on('risk_engine_enforce', false),
            ],
            'total_assessments' => $total,
            'outcomes' => [
                'allow' => (int) ($outcomes['allow'] ?? 0),
                'step_up' => $stepUp,
                'review' => $review,
                'deny' => $deny,
            ],
            'bands' => [
                'LOW' => (int) ($bands['LOW'] ?? 0),
                'MEDIUM' => (int) ($bands['MEDIUM'] ?? 0),
                'HIGH' => (int) ($bands['HIGH'] ?? 0),
                'CRITICAL' => (int) ($bands['CRITICAL'] ?? 0),
            ],
            'rates' => [
                'review_rate' => $this->rate($review, $total),
                'decline_rate' => $this->rate($deny, $total),
                'step_up_rate' => $this->rate($stepUp, $total),
            ],
            'shadow_would_act' => $shadowWouldAct,
            'reviews' => $this->reviewHealth(),
            'chargeback' => $this->chargeback($since),
            'top_signals' => $this->topSignals($since),
        ];
    }

    /**
     * The current state of the manual review queue (all-time, since a pending
     * review from before the window still needs working).
     *
     * @return array<string, int>
     */
    public function reviewHealth(): array
    {
        $counts = RiskReview::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $slaBreached = RiskReview::query()
            ->whereIn('status', [RiskReviewStatus::Pending->value, RiskReviewStatus::Escalated->value])
            ->get()
            ->filter(fn (RiskReview $r) => $r->isSlaBreached())
            ->count();

        return [
            'pending' => (int) ($counts[RiskReviewStatus::Pending->value] ?? 0),
            'escalated' => (int) ($counts[RiskReviewStatus::Escalated->value] ?? 0),
            'approved' => (int) ($counts[RiskReviewStatus::Approved->value] ?? 0),
            'rejected' => (int) ($counts[RiskReviewStatus::Rejected->value] ?? 0),
            'sla_breached' => $slaBreached,
        ];
    }

    /**
     * Chargeback rate against the acquirer ceiling. Rate is chargeback labels in
     * the window over paid orders in the window.
     *
     * @return array<string, mixed>
     */
    private function chargeback(CarbonImmutable $since): array
    {
        $chargebacks = RiskLabel::query()
            ->where('label', RiskLabel::LABEL_CHARGEBACK)
            ->where('created_at', '>=', $since)
            ->count();

        $paidOrders = Order::query()
            ->where('payment_status', PaymentStatus::Paid)
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'chargebacks' => $chargebacks,
            'paid_orders' => $paidOrders,
            'rate' => $this->rate($chargebacks, $paidOrders),
            // Acquirer/scheme monitoring ceiling for reference (Kora 0.5%).
            'ceiling' => 0.5,
        ];
    }

    /**
     * The most frequently fired signals in the window, for tuning.
     *
     * @return array<int, array{code: string, hits: int}>
     */
    private function topSignals(CarbonImmutable $since, int $limit = 10): array
    {
        return RiskSignalRecord::query()
            ->whereHas('assessment', fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('code, count(*) as hits')
            ->groupBy('code')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['code' => $row->code, 'hits' => (int) $row->hits])
            ->all();
    }

    /**
     * A percentage to one decimal place, guarding division by zero.
     */
    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 1);
    }
}
