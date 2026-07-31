<?php

namespace App\Listeners;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Events\Dispatcher;

/**
 * Alerts Telegram whenever ANY scheduled task fails, so a broken cron job
 * reaches the phone directly instead of only Sentry's cron monitors. The
 * scheduler is the platform's nervous system: reconciliation, backups,
 * catalog syncs and sweepers all live on it, and a task that fails silently
 * looks identical to one that keeps passing.
 */
class ScheduledTaskFailureListener
{
    /**
     * Tasks that already send their own tier-correct alert on failure; a
     * second, generic alert for the same problem would teach the reader that
     * alerts come in duplicate and can be half-ignored.
     */
    private const SELF_ALERTING = [
        'ledger:verify-nightly',
        'pricing:audit-below-cost-daily',
        'backup:health-daily',
        'backup:run-nightly',
        'backup:clean-nightly',
    ];

    public function __construct(private OperationalAlerter $alerter) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            ScheduledTaskFinished::class => 'handleFinished',
            ScheduledBackgroundTaskFinished::class => 'handleBackgroundFinished',
            ScheduledTaskFailed::class => 'handleFailed',
        ];
    }

    /** A foreground task completed; a non-zero exit code is a failure. */
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $this->alertIfFailed($event->task);
    }

    /** Background tasks report their exit code through a separate event. */
    public function handleBackgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->alertIfFailed($event->task);
    }

    /** The task threw before it could even finish. */
    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->alert($event->task, $event->exception->getMessage());
    }

    private function alertIfFailed(ScheduledTask $task): void
    {
        if ($task->exitCode === null || $task->exitCode === 0) {
            return;
        }

        $this->alert($task, "Exited with code {$task->exitCode}.");
    }

    private function alert(ScheduledTask $task, string $reason): void
    {
        $name = $this->taskName($task);

        if (in_array($name, self::SELF_ALERTING, true)) {
            return;
        }

        $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: "schedule:{$name}",
            title: "Scheduled task failed: {$name}",
            message: "{$reason} Whatever this task maintains (sync, sweep, reconciliation) has stopped happening; check the logs and re-run it by hand.",
            context: ['task' => $name],
            runbook: 'docs/runbooks/logs.md',
        );
    }

    private function taskName(ScheduledTask $task): string
    {
        if ($task->description) {
            return (string) $task->description;
        }

        // Strip the php binary and artisan prefix from the raw command line.
        $command = (string) $task->command;
        $position = strpos($command, 'artisan');

        return $position === false
            ? ($command !== '' ? $command : 'closure')
            : trim(substr($command, $position + strlen('artisan')), " '\"");
    }
}
