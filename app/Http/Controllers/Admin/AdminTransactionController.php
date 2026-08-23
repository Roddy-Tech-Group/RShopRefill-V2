<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Payment\Services\TransactionReportService;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
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

        return view('admin.transaction', [
            'attempt' => $attempt,
            'webhooks' => $webhooks,
            'siblings' => $siblings,
        ]);
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
