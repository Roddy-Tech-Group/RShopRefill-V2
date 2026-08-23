<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use App\Domain\Audit\Services\AuditLogService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for the /admin/reports page. Mirrors the same filter state the
 * Volt component reads from the URL, so a "share the report" workflow is just
 * "copy the URL, swap /reports for /reports/export.csv".
 */
class AdminReportExportController extends Controller
{
    public function csv(Request $request, DashboardMetricsQuery $query): StreamedResponse
    {
        [$start, $end, $label] = $this->resolveRange(
            $request->string('preset')->toString() ?: 'week',
            $request->string('start')->toString() ?: null,
            $request->string('end')->toString() ?: null,
        );

        $granularity = in_array($request->string('granularity')->toString(), ['daily', 'weekly', 'monthly'], true)
            ? $request->string('granularity')->toString()
            : 'daily';

        $categoryId = $request->filled('categoryId') ? (int) $request->input('categoryId') : null;

        $series = $query->getReportSeries($start, $end, $granularity, $categoryId);

        $filename = 'rshoprefills-report-'.now()->format('Y-m-d-His').'.csv';

        // Revenue/profit data leaving the system is always attributable.
        app(AuditLogService::class)->log(
            action: 'report.metrics_exported',
            metadata: ['filename' => $filename, 'range' => $label, 'granularity' => $granularity, 'category_id' => $categoryId],
        );

        return response()->streamDownload(function () use ($series) {
            $out = fopen('php://output', 'w');
            // Header row — keep field names ASCII-safe so spreadsheets don't choke.
            fputcsv($out, [
                'Date',
                'Transactions',
                'Cost (USD)',
                'Total Sales (USD)',
                'Profit (USD)',
                'Profit Margin (%)',
                'Avg per Tx (USD)',
            ]);

            foreach ($series as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['transactions'],
                    number_format((float) $row['cost_usd'], 2, '.', ''),
                    number_format((float) $row['sales_usd'], 2, '.', ''),
                    number_format((float) $row['profit_usd'], 2, '.', ''),
                    number_format((float) $row['profit_margin'], 2, '.', ''),
                    number_format((float) $row['avg_per_tx_usd'], 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Mirror of the Volt component's `resolvedRange()` so the CSV always
     * matches what the admin sees on screen. Kept in sync by passing the same
     * URL query string through.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveRange(string $preset, ?string $start, ?string $end): array
    {
        if ($preset === 'custom' && $start && $end) {
            try {
                return [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay(), 'Custom range'];
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return match ($preset) {
            'today' => [now()->startOfDay(), now()->endOfDay(), 'Today'],
            'month' => [now()->startOfMonth(), now()->endOfDay(), 'This Month'],
            'quarter' => [now()->startOfQuarter(), now()->endOfDay(), 'This Quarter'],
            'year' => [now()->startOfYear(), now()->endOfDay(), 'This Year'],
            default => [now()->startOfWeek(), now()->endOfDay(), 'This Week'],
        };
    }
}
