<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Domain\Risk\Services\RiskMetricsService;
use App\Domain\Risk\Services\RiskReviewService;
use App\Http\Controllers\Controller;
use App\Models\RiskLabel;
use App\Models\RiskReview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-side risk review queue. Lives behind the `admin` middleware group, so
 * every action is admin-only. The decisions themselves (with their audit trail
 * and clearance handling) live in RiskReviewService; this controller is just the
 * HTTP surface over it.
 */
class AdminRiskReviewController extends Controller
{
    public function __construct(private readonly RiskReviewService $reviews) {}

    /**
     * The risk overview: engine status, outcome/band mix, review/decline/step-up
     * rates, the shadow-vs-enforce delta, chargeback rate, queue health, and the
     * top-firing signals for tuning.
     */
    public function dashboard(Request $request, RiskMetricsService $metrics): View
    {
        $windowDays = (int) $request->integer('window', 30);
        $windowDays = in_array($windowDays, [7, 30, 90], true) ? $windowDays : 30;

        return view('admin.risk.dashboard', [
            'metrics' => $metrics->overview($windowDays),
            'windowDays' => $windowDays,
        ]);
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', RiskReviewStatus::Pending->value);

        $query = RiskReview::query()
            ->with(['user', 'assessment'])
            ->latest();

        if ($status !== 'all' && RiskReviewStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $reviews = $query->paginate(30)->withQueryString();

        $counts = RiskReview::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.risk.reviews', [
            'reviews' => $reviews,
            'status' => $status,
            'counts' => $counts,
            'slaHours' => (int) config('risk.review.sla_hours', 12),
        ]);
    }

    public function show(RiskReview $review): View
    {
        $review->load(['user', 'assessment.signals']);

        return view('admin.risk.review', [
            'review' => $review,
        ]);
    }

    public function approve(Request $request, RiskReview $review): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->reviews->approve($review, (int) auth('admin')->id(), $validated['reason'] ?? null);

        return back()->with('status', 'Review approved. The customer can retry within the clearance window.');
    }

    public function reject(Request $request, RiskReview $review): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->reviews->reject($review, (int) auth('admin')->id(), $validated['reason']);

        return back()->with('status', 'Review rejected. The action stays refused.');
    }

    public function escalate(Request $request, RiskReview $review): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->reviews->escalate($review, (int) auth('admin')->id(), $validated['reason'] ?? null);

        return back()->with('status', 'Review escalated.');
    }

    /**
     * Manually attach a ground-truth label to a customer (feedback loop): record a
     * chargeback, mark confirmed fraud, or clear a false positive / good actor.
     * Chargebacks feed CHARGEBACK_HISTORY; confirmed fraud trips the deny override.
     */
    public function label(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'label' => ['required', Rule::in([
                RiskLabel::LABEL_CHARGEBACK,
                RiskLabel::LABEL_CONFIRMED_FRAUD,
                RiskLabel::LABEL_FALSE_POSITIVE,
                RiskLabel::LABEL_GOOD,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        RiskLabel::create([
            'user_id' => $validated['user_id'],
            'label' => $validated['label'],
            'source' => 'admin',
            'note' => $validated['note'] ?? null,
        ]);

        return back()->with('status', 'Label recorded.');
    }
}
