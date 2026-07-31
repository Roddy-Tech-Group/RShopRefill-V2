<?php

namespace App\Listeners;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Throwable;

/**
 * Escalates backup failures onto a path a human actually sees.
 *
 * A failed backup is not a background chore that can wait for someone to read a
 * log file: it means the platform's recovery point is silently ageing. Every
 * failure is logged at critical and reported to Sentry, which is the delivery
 * path that exists today. P0-3 adds the SEV-1 phone-level route on top of this
 * same listener rather than inventing a second one.
 */
class BackupFailureListener
{
    public function handleBackupFailed(BackupHasFailed $event): void
    {
        $this->escalate(
            'Backup FAILED — the platform is accumulating unrecoverable time.',
            $event->exception,
            ['disk' => $event->backupDestination?->diskName()]
        );
    }

    public function handleCleanupFailed(CleanupHasFailed $event): void
    {
        $this->escalate(
            'Backup cleanup failed — retention is not being enforced and storage will grow unbounded.',
            $event->exception,
            ['disk' => $event->backupDestination?->diskName()]
        );
    }

    public function handleUnhealthyBackup(UnhealthyBackupWasFound $event): void
    {
        $failure = $event->backupDestinationStatus->getHealthCheckFailure();

        $this->escalate(
            'Unhealthy backup detected: '.($failure?->healthCheck()->name() ?? 'unknown health check'),
            null,
            [
                'disk' => $event->backupDestinationStatus->backupDestination()->diskName(),
                'reason' => $failure?->exception()->getMessage(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function escalate(string $message, ?Throwable $exception, array $context = []): void
    {
        // A failed backup means the platform's recovery point is silently ageing.
        // SEV-2 rather than SEV-1: one missed night is survivable, so it does not
        // justify waking someone — but `backup:health` escalates to SEV-1 if the
        // archive actually goes stale.
        app(OperationalAlerter::class)->send(
            severity: AlertSeverity::Sev2,
            key: 'backup:failure:'.($context['disk'] ?? 'unknown'),
            title: 'Backup failure',
            message: $message,
            context: array_merge($context, ['exception' => $exception?->getMessage()]),
            runbook: 'docs/runbooks/database-restore.md',
        );

        if ($exception instanceof Throwable && app()->bound('sentry')) {
            // The stack trace is worth more than the message alone.
            app('sentry')->captureException($exception);
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            BackupHasFailed::class => 'handleBackupFailed',
            CleanupHasFailed::class => 'handleCleanupFailed',
            UnhealthyBackupWasFound::class => 'handleUnhealthyBackup',
        ];
    }
}
