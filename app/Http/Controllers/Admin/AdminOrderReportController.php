<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Order\Services\OrderReportService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The order report, preview-first: "Generate Report" on the order page opens a
 * preview (the exact PDF, inline in the admin chrome) with Download and Share
 * actions, so the admin sees precisely what they are about to pull before
 * pulling it. Viewing and downloading are separately audited - evidence access
 * must be attributable either way.
 */
class AdminOrderReportController extends Controller
{
    /**
     * The preview page: PDF rendered inline in an iframe + Download / Share.
     */
    public function show(Order $order, AuditLogService $auditLog): View
    {
        $auditLog->log(
            action: 'order_report.viewed',
            model: $order,
            metadata: ['order_number' => $order->order_number],
        );

        return view('admin.order-report-preview', ['order' => $order]);
    }

    /**
     * The PDF itself: inline for the preview iframe, attachment with ?download=1.
     */
    public function pdf(Request $request, Order $order, OrderReportService $reports, AuditLogService $auditLog): Response
    {
        $admin = auth('admin')->user();
        $data = $reports->assemble($order, $admin?->email ?? 'admin');
        $pdf = Pdf::loadView('admin.reports.order-report-pdf', ['data' => $data]);
        $filename = 'order-report-'.$order->order_number.'.pdf';

        if ($request->boolean('download')) {
            // Audit BEFORE streaming: the download must be attributable even if
            // it is then abandoned.
            $auditLog->log(
                action: 'order_report.generated',
                model: $order,
                metadata: [
                    'report_id' => $data['report']['id'],
                    'order_number' => $order->order_number,
                ],
            );

            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }
}
