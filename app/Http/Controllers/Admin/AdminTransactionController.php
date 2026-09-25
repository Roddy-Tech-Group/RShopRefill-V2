<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\ManualSettlementRejectedException;
use App\Domain\Payment\Providers\NowPaymentsProvider;
use App\Domain\Payment\Services\CryptoManualSettlementService;
use App\Domain\Payment\Services\TransactionReportService;
use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutcome;
use App\Models\PaymentWebhook;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The transaction detail page (one payment attempt, everything about it) and
 * its report, preview-first: the same pattern as the order report - see the
 * exact PDF before pulling it, with viewing and downloading audited
 * separately.
 */
class AdminTransactionController extends Controller
{
    public function show(PaymentAttempt $attempt): View
    {
        $attempt->load(['user', 'order.items', 'paymentSession']);

        $references = array_values(array_filter([$attempt->gateway_reference, $attempt->idempotency_key, $attempt->purchase_id]));

        $webhooks = $references === [] ? collect() : PaymentWebhook::query()
            ->where('gateway', $attempt->gateway)
            ->whereIn('reference', $references)
            ->orderBy('created_at')
            ->limit(20)
            ->get();

        // Sibling attempts on the same order: the retry story at a glance.
        $siblings = $attempt->order_id
            ? PaymentAttempt::where('order_id', $attempt->order_id)->where('id', '!=', $attempt->id)->latest()->limit(10)->get()
            : collect();

        // The short-settlement panel is offered only where it can be used: a
        // crypto attempt holding real money short of the quote, to a Super
        // Admin. The fiat figure is informational; a rate lookup that fails
        // must not take the page down.
        $settleable = $this->canSettleShort($attempt);
        $shortfallFiat = $settleable
            ? rescue(fn () => app(NowPaymentsProvider::class)->shortfallInPriceCurrency($attempt), null, false)
            : null;

        return view('admin.transaction', [
            'attempt' => $attempt,
            'webhooks' => $webhooks,
            'siblings' => $siblings,
            'settleable' => $settleable,
            'shortfallFiat' => $shortfallFiat,
            'verdict' => $this->failureVerdict($attempt),
        ]);
    }

    /**
     * Why a payment failed, for the operator: who the verdict points at (the
     * customer, the gateway, us, or their browser), the gateway's own words,
     * and the sentence the customer was shown. Read from the outcome row once
     * the queue has graded the failure, else graded here from the same
     * evidence, so the page never has to wait for a worker.
     *
     * @return array<string, mixed>|null
     */
    private function failureVerdict(PaymentAttempt $attempt): ?array
    {
        $session = $attempt->paymentSession;

        if ($session === null) {
            return null;
        }

        $sessionStatus = $session->status?->value ?? $session->status;
        $ended = in_array($attempt->payment_status, [PaymentStatus::Failed, PaymentStatus::Expired], true)
            || in_array($sessionStatus, ['failed', 'expired', 'cancelled'], true);

        if (! $ended) {
            return null;
        }

        $metadata = $session->metadata ?? [];
        $details = is_array($metadata['failure_details'] ?? null) ? $metadata['failure_details'] : null;
        $reason = $metadata['failure_reason'] ?? null;
        $stage = $metadata['status_at_failure'] ?? $sessionStatus;

        $outcome = PaymentOutcome::query()->where('payment_session_id', $session->id)->latest('id')->first();

        if ($outcome !== null) {
            $category = $outcome->failure_category;
            $code = (string) ($outcome->failure_reason_code ?? 'unknown_error');
            $retryable = (bool) $outcome->retryable;
        } else {
            $classification = app(FailureReasonClassifier::class)->classify(
                $session->provider ?? 'unknown',
                (string) ($reason ?? match ($sessionStatus) {
                    'expired' => 'session_expired',
                    'cancelled' => 'user cancelled',
                    default => 'unknown',
                }),
                (string) $stage,
                $details,
            );
            $category = $classification['category'];
            $code = $classification['code'];
            $retryable = $classification['retryable'];
        }

        [$owner, $tone] = match ($category) {
            FailureCategory::Customer => ['Customer side', 'amber'],
            FailureCategory::Provider => ['Gateway side', 'red'],
            FailureCategory::System => ['Our platform', 'red'],
            FailureCategory::Frontend => ['Customer browser', 'zinc'],
            default => ['Not graded', 'zinc'],
        };

        $gatewayVerdict = is_array($attempt->metadata['gateway_verdict'] ?? null) ? $attempt->metadata['gateway_verdict'] : [];
        $mobileMoney = is_array($session->payment_payload['mobile_money'] ?? null) ? $session->payment_payload['mobile_money'] : null;

        return [
            'owner' => $owner,
            'tone' => $tone,
            'reason' => FailureReasonClassifier::label($code),
            'code' => $code,
            'gateway_said' => $details['provider_message'] ?? ($gatewayVerdict['processor_response'] ?? null),
            'http_status' => $details['http_status'] ?? null,
            'stage' => $stage,
            'retryable' => $retryable,
            'customer_saw' => $session->payment_payload['failure_reason'] ?? null,
            'mobile_money' => $mobileMoney !== null
                ? trim(($mobileMoney['network'] ?? '').' '.($mobileMoney['phone_number'] ?? '').($mobileMoney['charge_label'] ?? null ? ' for '.$mobileMoney['charge_label'] : ''))
                : null,
            'seen_at' => $gatewayVerdict['seen_at'] ?? null,
        ];
    }

    /**
     * Settle a crypto payment that arrived short, with a stated reason. The
     * service stamps the attempt and hands it to the normal settlement chain;
     * nothing here touches the order or fulfilment directly.
     */
    public function settle(Request $request, PaymentAttempt $attempt, CryptoManualSettlementService $settlements): RedirectResponse
    {
        $admin = auth('admin')->user();

        // Money override: Super Admin only, on top of the route-name gate, so
        // the rule survives a change to the section mapping.
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:280'],
        ]);

        try {
            $note = $settlements->settle($attempt, $admin, $validated['reason']);
        } catch (ManualSettlementRejectedException $e) {
            return back()->withErrors(['settle' => $e->getMessage()]);
        }

        $short = rtrim(rtrim(number_format((float) $note['shortfall_crypto'], 8, '.', ''), '0'), '.');
        $coin = strtoupper((string) $attempt->pay_currency);

        return back()->with('status', "Settled. Fulfilment is queued and the {$short} {$coin} shortfall is on record.");
    }

    private function canSettleShort(PaymentAttempt $attempt): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin
            && $admin->isSuperAdmin()
            && in_array($attempt->gateway, ['nowpayments', 'crypto'], true)
            && $attempt->payment_status === PaymentStatus::PartiallyPaid
            && (float) $attempt->actually_paid > 0;
    }

    public function report(PaymentAttempt $attempt, AuditLogService $auditLog): View
    {
        $auditLog->log(
            action: 'transaction_report.viewed',
            model: $attempt,
            metadata: ['gateway' => $attempt->gateway, 'reference' => $attempt->gateway_reference],
        );

        return view('admin.transaction-report-preview', ['attempt' => $attempt]);
    }

    public function reportPdf(Request $request, PaymentAttempt $attempt, TransactionReportService $reports, AuditLogService $auditLog): Response
    {
        $admin = auth('admin')->user();
        $data = $reports->assemble($attempt, $admin?->email ?? 'admin');
        $pdf = Pdf::loadView('admin.reports.transaction-report-pdf', ['data' => $data]);
        $filename = 'transaction-report-'.$attempt->id.'.pdf';

        if ($request->boolean('download')) {
            // Audit BEFORE streaming: the download must be attributable even if
            // it is then abandoned.
            $auditLog->log(
                action: 'transaction_report.generated',
                model: $attempt,
                metadata: [
                    'report_id' => $data['report']['id'],
                    'gateway' => $attempt->gateway,
                    'reference' => $attempt->gateway_reference,
                ],
            );

            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }
}
