<?php

namespace Tests\Feature\Operations;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * A backup that only runs when someone remembers is not a backup strategy.
 *
 * These tests encode the standing rule from the CTO roadmap: a safety system is
 * not done until it runs on a schedule and alerts a human. They fail loudly if a
 * future refactor of routes/console.php drops the nightly job.
 */
class BackupScheduleTest extends TestCase
{
    public function test_the_backup_fleet_is_scheduled(): void
    {
        $commands = $this->scheduledCommands();

        $this->assertTrue(
            $this->hasCommand($commands, 'backup:run'),
            'The nightly backup is not scheduled.'
        );

        $this->assertTrue(
            $this->hasCommand($commands, 'backup:clean'),
            'Backup retention pruning is not scheduled; storage will grow without bound.'
        );

        $this->assertTrue(
            $this->hasCommand($commands, 'backup:health'),
            'Backup verification is not scheduled; a silently failing backup would go unnoticed.'
        );
    }

    public function test_the_backup_runs_before_the_reconciliation_window(): void
    {
        // 01:30 backup, 02:00 reconciliation: the archive must capture the state
        // that the integrity fleet then verifies, not the other way round.
        $backup = $this->findEvent('backup:run');

        $this->assertNotNull($backup);
        $this->assertSame('30 1 * * *', $backup->expression);
    }

    public function test_backup_cleanup_runs_before_the_backup_itself(): void
    {
        // Pruning first guarantees the freshest archive is never the one a
        // misbehaving retention policy deletes.
        $cleanup = $this->findEvent('backup:clean');

        $this->assertNotNull($cleanup);
        $this->assertSame('15 1 * * *', $cleanup->expression);
    }

    /**
     * @return array<int, Event>
     */
    private function scheduledCommands(): array
    {
        return app(Schedule::class)->events();
    }

    /**
     * @param  array<int, Event>  $events
     */
    private function hasCommand(array $events, string $command): bool
    {
        foreach ($events as $event) {
            if (str_contains((string) $event->command, $command)) {
                return true;
            }
        }

        return false;
    }

    private function findEvent(string $command): ?Event
    {
        foreach ($this->scheduledCommands() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }
}
