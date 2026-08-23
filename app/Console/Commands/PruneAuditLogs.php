<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The audit retention policy: rows older than the configured window (default
 * two years) are archived to CSV on the private local disk, then deleted.
 * Archive-then-delete, never delete alone - an audit trail that silently
 * vanishes is as bad as one that can be edited. The prune itself is recorded
 * as an audit event, so the trail documents its own trimming.
 */
class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune
        {--days= : Override the retention window in days (default: config audit.retention_days)}
        {--dry-run : Report what would be archived without touching anything}';

    protected $description = 'Archive audit logs older than the retention window to CSV, then delete them.';

    public function handle(AuditLogService $auditLog): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 730));

        if ($days < 30) {
            $this->error('Refusing a retention window under 30 days - that would gut the audit trail.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = AuditLog::where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("Nothing to prune: no audit rows older than {$days} days.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] {$count} audit rows older than {$cutoff->toDateString()} would be archived and deleted.");

            return self::SUCCESS;
        }

        $archivePath = 'audit-archive/audit-logs-'.now()->format('Y-m-d-His').'.csv';
        $disk = Storage::disk('local');
        $disk->makeDirectory('audit-archive');

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [
            'id', 'created_at', 'actor_id', 'action', 'model_type', 'model_id',
            'before_state', 'after_state', 'correlation_id', 'ip_address', 'user_agent', 'metadata',
        ]);

        $archivedIds = [];

        (clone $query)->orderBy('id')->chunkById(500, function ($rows) use ($handle, &$archivedIds) {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->created_at?->toIso8601String(),
                    $row->actor_id,
                    $row->action,
                    $row->model_type,
                    $row->model_id,
                    json_encode($row->before_state),
                    json_encode($row->after_state),
                    $row->correlation_id,
                    $row->ip_address,
                    $row->user_agent,
                    json_encode($row->metadata),
                ]);
                $archivedIds[] = $row->id;
            }
        });

        rewind($handle);
        $disk->put($archivePath, stream_get_contents($handle));
        fclose($handle);

        // Delete only what was actually written to the archive, in chunks so a
        // large prune never holds one giant transaction.
        foreach (array_chunk($archivedIds, 500) as $chunk) {
            DB::table('audit_logs')->whereIn('id', $chunk)->delete();
        }

        // The trail documents its own trimming.
        $auditLog->log(
            action: 'audit.pruned',
            metadata: [
                'rows_archived' => count($archivedIds),
                'cutoff' => $cutoff->toIso8601String(),
                'retention_days' => $days,
                'archive_path' => $archivePath,
            ],
        );

        $this->info(sprintf('Archived and deleted %d audit rows older than %s. Archive: storage/app/%s', count($archivedIds), $cutoff->toDateString(), $archivePath));

        return self::SUCCESS;
    }
}
