<?php

namespace App\Console\Commands;

use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Domain\Risk\Services\RiskMetricsService;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\RiskReview;
use App\Notifications\CriticalSystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/**
 * The daily risk report: logs the headline metrics (review / decline / step-up
 * rates, chargeback rate, shadow delta) and, when any pending review has breached
 * its SLA, alerts the review-queue owners so the queue never goes stale.
 */
class RiskReportCommand extends Command
{
    protected $signature = 'risk:report {--window=30 : Metrics window in days}';

    protected $description = 'Log daily risk metrics and alert owners about SLA-breached reviews.';

    public function handle(RiskMetricsService $metrics): int
    {
        $window = (int) $this->option('window');
        $overview = $metrics->overview($window > 0 ? $window : 30);

        Log::info('risk.daily_report', [
            'window_days' => $overview['window_days'],
            'total_assessments' => $overview['total_assessments'],
            'rates' => $overview['rates'],
            'chargeback' => $overview['chargeback'],
            'shadow_would_act' => $overview['shadow_would_act'],
            'reviews' => $overview['reviews'],
        ]);

        $breached = RiskReview::query()
            ->whereIn('status', [RiskReviewStatus::Pending->value, RiskReviewStatus::Escalated->value])
            ->get()
            ->filter(fn (RiskReview $r) => $r->isSlaBreached());

        if ($breached->isNotEmpty()) {
            $this->alertOwners($breached->count());
        }

        $this->info("Risk report logged. SLA-breached reviews: {$breached->count()}.");

        return self::SUCCESS;
    }

    private function alertOwners(int $breachedCount): void
    {
        // Telegram first, like every newly opened review. The email owner list
        // is optional and empty by default, so an email-only alert here would
        // let held orders sit past their SLA without anyone hearing about it.
        try {
            app(OperationalAlerter::class)->send(
                severity: AlertSeverity::Sev2,
                key: 'risk:reviews-past-sla',
                title: 'Risk reviews past SLA',
                message: $breachedCount.' risk review(s) have breached the '.config('risk.review.sla_hours').'h SLA. Those customers are still waiting for a decision.',
                context: array_filter([
                    'breached_reviews' => $breachedCount,
                    'queue' => Route::has('admin.risk.reviews') ? route('admin.risk.reviews') : null,
                ]),
                runbook: 'docs/runbooks/risk-review.md',
            );
        } catch (\Throwable $e) {
            Log::warning('risk.sla_alert_failed', ['channel' => 'operational', 'error' => $e->getMessage()]);
        }

        $owners = array_filter((array) config('risk.review.owners', []));

        if ($owners === []) {
            return;
        }

        try {
            Notification::route('mail', $owners)->notify(new CriticalSystemAlert(
                title: 'Risk reviews past SLA',
                description: $breachedCount.' risk review(s) have breached the '.config('risk.review.sla_hours').'h SLA and need a decision.',
                context: ['Breached reviews' => $breachedCount],
            ));
        } catch (\Throwable $e) {
            Log::warning('risk.sla_alert_failed', ['error' => $e->getMessage()]);
        }
    }
}
