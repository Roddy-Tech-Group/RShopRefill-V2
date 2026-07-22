<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Http\Controllers\Controller;
use App\Models\PaymentSession;
use App\Models\PaymentTelemetryEvent;
use App\Support\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentTelemetryController extends Controller
{
    /**
     * Enumerated non-terminal signal codes the browser may report. Kept in
     * sync with resources/js/payment-telemetry.js.
     */
    public const SIGNAL_CODES = [
        'js_exception',
        'network_offline',
        'network_timeout',
        'slow_network',
    ];

    /** Hard cap of stored signals per session — loop/replay protection. */
    private const MAX_SIGNALS_PER_SESSION = 20;

    public function __construct(
        private readonly PaymentSessionService $paymentSessionService
    ) {}

    public function recordFailure(Request $request): JsonResponse
    {
        // Graceful no-op (not an error) so cached frontend bundles that still
        // emit telemetry don't surface failures to the customer.
        if (FeatureFlag::off('frontend_payment_tracking')) {
            return response()->json(['status' => 'disabled']);
        }

        // PaymentSession uses UUID primary keys — session_id must be validated
        // as a UUID, not an integer, or every real frontend report 422s.
        $validated = $request->validate([
            'session_id' => 'required|uuid',
            'reason' => 'required|string|max:500',
            'context' => 'nullable|array',
        ]);

        $session = PaymentSession::find($validated['session_id']);

        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        // Zero-trust: telemetry may only terminate the caller's own session.
        if ($session->customer_email && strcasecmp($session->customer_email, (string) $request->user()?->email) !== 0) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        // Use the state machine to fail it, which fires PaymentSessionFailed
        // and in turn triggers the RecordPaymentOutcomeJob
        $this->paymentSessionService->failSession(
            session: $session,
            reason: $validated['reason'],
            payload: $validated['context'] ?? []
        );

        return response()->json(['status' => 'recorded']);
    }

    /**
     * Non-terminal environmental signal (JS exception, offline blip, slow
     * network). Analytics-only — never touches the session state machine,
     * so a transient client-side hiccup can't fail a payment that is
     * completing server-side.
     */
    public function recordSignal(Request $request): JsonResponse
    {
        if (FeatureFlag::off('frontend_payment_tracking')) {
            return response()->json(['status' => 'disabled']);
        }

        $validated = $request->validate([
            'session_id' => 'required|uuid',
            'code' => ['required', Rule::in(self::SIGNAL_CODES)],
            'context' => 'nullable|array',
        ]);

        $session = PaymentSession::find($validated['session_id']);

        if (! $session) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        // Zero-trust: signals may only be attached to the caller's own session.
        if ($session->customer_email && strcasecmp($session->customer_email, (string) $request->user()?->email) !== 0) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        if (PaymentTelemetryEvent::where('payment_session_id', $session->id)->count() >= self::MAX_SIGNALS_PER_SESSION) {
            return response()->json(['status' => 'capped']);
        }

        PaymentTelemetryEvent::create([
            'payment_session_id' => $session->id,
            'user_id' => $request->user()?->id,
            'code' => $validated['code'],
            'context' => $validated['context'] ?? null,
        ]);

        return response()->json(['status' => 'recorded']);
    }

    public function resetBackoff(Request $request, PaymentBackoffManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'product_line' => 'required|string',
        ]);

        $manager->resetBackoff(
            $request->user(),
            $validated['product_line'],
            $request->ip(),
            $request->cookie('device_uuid') ?? $request->header('X-Installation-ID')
        );

        return response()->json(['status' => 'reset']);
    }
}
