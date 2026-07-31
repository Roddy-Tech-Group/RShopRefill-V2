<?php

namespace App\Console\Commands;

use App\Domain\Shared\Services\BackupHealthService;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Console\Command;

class CheckBackupHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:health {--json : Emit the raw health payload for dashboards and probes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify a recent, correctly-sized, encrypted, off-site backup exists on every configured destination.';

    /**
     * Exits non-zero on any problem so the scheduler, Sentry cron monitor and the
     * alert channel all treat an unrecoverable platform as the incident it is.
     */
    public function handle(BackupHealthService $backupHealth): int
    {
        $status = $backupHealth->status();

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $status['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderReport($status);

        if ($status['healthy']) {
            $this->newLine();
            $this->info('Backups are healthy.');

            return self::SUCCESS;
        }

        // SEV-1: this is the state in which the company cannot be recovered. It is
        // the one backup condition that justifies waking someone, because every
        // hour it stays true is an hour of unrecoverable business.
        app(OperationalAlerter::class)->send(
            severity: AlertSeverity::Sev1,
            key: 'backup:unhealthy',
            title: 'Backups are not recoverable',
            message: 'The platform does not have a verified, recent, encrypted, off-site backup.',
            context: [
                'configuration_issues' => $status['configuration_issues'],
                'destinations' => array_map(
                    fn (array $destination): string => $destination['disk'].': '.($destination['problems'] === [] ? 'ok' : implode('; ', $destination['problems'])),
                    $status['destinations']
                ),
            ],
            runbook: 'docs/runbooks/database-restore.md',
        );

        $this->newLine();
        $this->error('BACKUP HEALTH CHECK FAILED — the platform may not be recoverable.');
        $this->line('Runbook: docs/runbooks/database-restore.md');

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function renderReport(array $status): void
    {
        $this->info('Backup health — archive "'.$status['archive_name'].'", staleness threshold '.$status['max_age_hours'].'h');

        /** @var array<int, string> $configurationIssues */
        $configurationIssues = $status['configuration_issues'];

        if ($configurationIssues !== []) {
            $this->newLine();
            $this->error('Configuration issues:');

            foreach ($configurationIssues as $issue) {
                $this->line('  - '.$issue);
            }
        }

        /** @var array<int, array<string, mixed>> $destinations */
        $destinations = $status['destinations'];

        if ($destinations === []) {
            return;
        }

        $this->newLine();
        $this->table(
            ['Disk', 'Off-site', 'Backups', 'Newest', 'Age', 'Size', 'DB dump', 'Encrypted', 'State'],
            array_map(fn (array $destination): array => [
                $destination['disk'],
                $destination['offsite'] ? 'yes' : 'no',
                $destination['backup_count'],
                $destination['newest_backup_at'] ?? '—',
                $destination['age_hours'] !== null ? $destination['age_hours'].'h' : '—',
                $destination['size_bytes'] !== null ? $this->humanBytes($destination['size_bytes']) : '—',
                $destination['database_dump_bytes'] !== null ? $this->humanBytes($destination['database_dump_bytes']) : '—',
                match ($destination['encrypted']) {
                    true => 'yes',
                    false => 'NO',
                    default => 'unverified',
                },
                $destination['healthy'] ? 'OK' : 'PROBLEM',
            ], $destinations)
        );

        foreach ($destinations as $destination) {
            /** @var array<int, string> $problems */
            $problems = $destination['problems'];

            foreach ($problems as $problem) {
                $this->error("  [{$destination['disk']}] {$problem}");
            }
        }
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2).' '.$units[$power];
    }
}
