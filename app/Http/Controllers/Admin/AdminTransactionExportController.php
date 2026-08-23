<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogService;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for the /admin/transactions page. Streams the same dataset the
 * admin sees in the list, applying any optional `?status=` / `?gateway=`
 * filters so a "what did Flutterwave do this week?" question is one click,
 * not a database export ticket.
 *
 * Uses chunkById() under the hood so a year's worth of payments doesn't
 * balloon PHP's memory limit on the export.
 */
class AdminTransactionExportController extends Controller
{
    /**
     * Friendly filter labels used by the admin chips ("completed", "pending",
     * "cancelled", "failed") and the underlying PaymentStatus enum values
     * they group. Keep in sync with resources/views/admin/transactions.blade.php.
     */
    private const STATUS_BUCKETS = [
        'completed' => ['paid'],
        'pending' => ['pending', 'processing', 'reserved', 'unpaid'],
        'cancelled' => ['expired'],
        'failed' => ['failed'],
        'refunded' => ['refunded', 'partially_refunded'],
    ];

    public function csv(Request $request): StreamedResponse
    {
        $status = strtolower((string) $request->string('status')->toString());
        $gateway = $request->string('gateway')->toString();
        $search = trim((string) $request->string('q')->toString());

        $filename = 'transactions-'.($status !== '' && $status !== 'all' ? $status.'-' : '').now()->format('Y-m-d-His').'.csv';

        // Bulk financial + customer data leaving the system is always
        // attributable: who exported, with which filters.
        app(AuditLogService::class)->log(
            action: 'report.transactions_exported',
            metadata: ['filename' => $filename, 'status' => $status ?: 'all', 'gateway' => $gateway ?: 'all', 'search' => $search],
        );

        return response()->streamDownload(function () use ($status, $gateway, $search) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Date',
                'Reference',
                'Customer name',
                'Customer email',
                'Gateway',
                'Type',
                'Amount',
                'Currency',
                'Status',
                'Order #',
            ]);

            $query = PaymentAttempt::query()
                ->with(['user', 'order'])
                ->latest();

            if ($status !== '' && $status !== 'all') {
                // Map friendly filter labels to underlying enum buckets.
                // Falls back to a literal match for direct ?status=paid usage.
                $values = self::STATUS_BUCKETS[$status] ?? [$status];
                $query->whereIn('payment_status', $values);
            }
            if ($gateway !== '') {
                $query->where('gateway', $gateway);
            }
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('gateway_reference', 'like', "%{$search}%")
                        ->orWhere('idempotency_key', 'like', "%{$search}%");
                });
            }

            $query->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $attempt) {
                    $reference = $attempt->gateway_reference ?: $attempt->idempotency_key;
                    $isWalletFunding = ! $attempt->order;

                    fputcsv($out, [
                        $attempt->created_at->format('Y-m-d H:i:s'),
                        $reference,
                        $attempt->user?->name,
                        $attempt->user?->email,
                        $attempt->gateway,
                        $isWalletFunding ? 'Wallet funding' : 'Order payment',
                        number_format((float) $attempt->amount, 2, '.', ''),
                        $attempt->currency,
                        $attempt->payment_status?->value,
                        $attempt->order?->order_number,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
