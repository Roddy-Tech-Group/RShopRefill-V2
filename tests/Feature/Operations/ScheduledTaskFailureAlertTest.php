<?php

namespace Tests\Feature\Operations;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Listeners\ScheduledTaskFailureListener;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledTask;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The scheduler carries reconciliation, backups, syncs and sweepers; a cron
 * job that fails silently looks identical to one that passes. This listener
 * makes every scheduled failure reach the alert channel, except tasks that
 * already send their own tier-correct alert.
 */
class ScheduledTaskFailureAlertTest extends TestCase
{
    /** @var OperationalAlerter recording fake */
    private $alerter;

    private ScheduledTaskFailureListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alerter = new class extends OperationalAlerter
        {
            /** @var array<int, array{severity: AlertSeverity, key: string}> */
            public array $sent = [];

            public function send(
                AlertSeverity $severity,
                string $key,
                string $title,
                string $message,
                array $context = [],
                ?string $runbook = null,
            ): bool {
                $this->sent[] = ['severity' => $severity, 'key' => $key];

                return true;
            }
        };

        $this->listener = new ScheduledTaskFailureListener($this->alerter);
    }

    private function scheduledTask(string $command, ?string $name = null): ScheduledTask
    {
        $task = app(Schedule::class)->command($command);
        if ($name !== null) {
            $task->name($name);
        }

        return $task;
    }

    public function test_a_failing_scheduled_command_alerts_sev2(): void
    {
        $task = $this->scheduledTask('rates:fetch', 'rates:fetch-daily');
        $task->exitCode = 1;

        $this->listener->handleFinished(new ScheduledTaskFinished($task, 0.5));

        $this->assertCount(1, $this->alerter->sent);
        $this->assertSame('schedule:rates:fetch-daily', $this->alerter->sent[0]['key']);
        $this->assertSame(AlertSeverity::Sev2, $this->alerter->sent[0]['severity']);
    }

    public function test_a_successful_task_stays_silent(): void
    {
        $task = $this->scheduledTask('rates:fetch', 'rates:fetch-daily');
        $task->exitCode = 0;

        $this->listener->handleFinished(new ScheduledTaskFinished($task, 0.5));

        $this->assertSame([], $this->alerter->sent);
    }

    public function test_a_task_that_threw_alerts_with_the_exception_message(): void
    {
        $task = $this->scheduledTask('topups:sync', 'topups:sync-daily');

        $this->listener->handleFailed(new ScheduledTaskFailed($task, new \RuntimeException('API unreachable')));

        $this->assertCount(1, $this->alerter->sent);
        $this->assertSame('schedule:topups:sync-daily', $this->alerter->sent[0]['key']);
    }

    public function test_self_alerting_tasks_are_not_double_reported(): void
    {
        // ledger:verify already raises its own SEV-1; a second generic SEV-2
        // for the same failure would teach the reader alerts come in
        // duplicate.
        $task = $this->scheduledTask('ledger:verify', 'ledger:verify-nightly');
        $task->exitCode = 1;

        $this->listener->handleFinished(new ScheduledTaskFinished($task, 0.5));

        $this->assertSame([], $this->alerter->sent);
    }

    public function test_an_unnamed_task_falls_back_to_its_command_line(): void
    {
        $task = $this->scheduledTask('inspire');
        $task->exitCode = 1;

        $this->listener->handleFinished(new ScheduledTaskFinished($task, 0.5));

        $this->assertCount(1, $this->alerter->sent);
        $this->assertStringContainsString('inspire', $this->alerter->sent[0]['key']);
    }
}
