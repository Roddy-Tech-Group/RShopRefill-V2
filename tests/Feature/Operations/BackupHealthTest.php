<?php

namespace Tests\Feature\Operations;

use App\Domain\Shared\Services\BackupHealthService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * Guards the disaster-recovery detection layer.
 *
 * Each test here corresponds to a way a backup can be worthless while appearing
 * to work. The empty-dump case is not hypothetical: it happened during the P0-1
 * build, where a 20-byte database dump sat undetected inside a 6.33 MB archive.
 */
class BackupHealthTest extends TestCase
{
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = storage_path('framework/testing/backup-health');

        File::deleteDirectory($this->diskRoot);
        File::ensureDirectoryExists($this->diskRoot.'/testapp');

        config([
            'filesystems.disks.backup-test' => [
                'driver' => 'local',
                'root' => $this->diskRoot,
                'throw' => true,
            ],
            'backup.backup.name' => 'testapp',
            'backup.backup.destination.disks' => ['backup-test'],
            'backup.backup.password' => str_repeat('a', 32),
            'backup.backup.encryption' => 'default',
            'backup.monitor_backups.0.name' => 'testapp',
            'backup.monitor_backups.0.disks' => ['backup-test'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);

        parent::tearDown();
    }

    public function test_it_reports_healthy_for_a_recent_encrypted_archive_containing_a_real_dump(): void
    {
        $this->makeArchive($this->recentArchiveName(), dumpBytes: 250000, encrypted: true);

        $status = app(BackupHealthService::class)->status();

        $this->assertTrue($status['healthy'], 'A recent, encrypted, well-populated archive should be healthy.');
        $this->assertSame([], $status['configuration_issues']);
        $this->assertTrue($status['destinations'][0]['encrypted']);
        $this->assertGreaterThan(4096, $status['destinations'][0]['database_dump_bytes']);
    }

    public function test_it_detects_an_empty_database_dump_hiding_inside_a_large_archive(): void
    {
        // The archive is comfortably above the size floor thanks to bundled files,
        // but the database — the only part that matters — is absent.
        $this->makeArchive($this->recentArchiveName(), dumpBytes: 20, encrypted: true, padBytes: 200000);

        $status = app(BackupHealthService::class)->status();

        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString(
            'Database dump inside the archive is only',
            implode(' ', $status['destinations'][0]['problems'])
        );
    }

    public function test_it_detects_an_unencrypted_archive(): void
    {
        $this->makeArchive($this->recentArchiveName(), dumpBytes: 250000, encrypted: false);

        $status = app(BackupHealthService::class)->status();

        $this->assertFalse($status['healthy']);
        $this->assertFalse($status['destinations'][0]['encrypted']);
        $this->assertContains('Newest archive is NOT encrypted.', $status['destinations'][0]['problems']);
    }

    public function test_it_detects_a_stale_backup(): void
    {
        $this->makeArchive($this->recentArchiveName(), dumpBytes: 250000, encrypted: true);

        $this->travel(3)->days();

        $status = app(BackupHealthService::class)->status();

        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString(
            'exceeding the 26h threshold',
            implode(' ', $status['destinations'][0]['problems'])
        );
    }

    public function test_it_detects_a_destination_with_no_backups_at_all(): void
    {
        $status = app(BackupHealthService::class)->status();

        $this->assertFalse($status['healthy']);
        $this->assertContains(
            'No backup archive found on this destination.',
            $status['destinations'][0]['problems']
        );
    }

    public function test_it_flags_missing_encryption_configuration(): void
    {
        config(['backup.backup.password' => '']);

        $issues = app(BackupHealthService::class)->configurationIssues();

        $this->assertStringContainsString('Archive encryption is disabled', implode(' ', $issues));
    }

    public function test_it_flags_a_weak_archive_password(): void
    {
        config(['backup.backup.password' => 'short']);

        $issues = app(BackupHealthService::class)->configurationIssues();

        $this->assertStringContainsString('shorter than 24 characters', implode(' ', $issues));
    }

    public function test_it_flags_a_production_deployment_with_no_offsite_destination(): void
    {
        // The failure that ends companies: backups that faithfully accumulate on
        // the same disk they are meant to protect against losing.
        app()->detectEnvironment(fn () => 'production');

        $issues = app(BackupHealthService::class)->configurationIssues();

        $this->assertStringContainsString('No off-site destination configured', implode(' ', $issues));
    }

    public function test_it_flags_an_offsite_disk_missing_credentials(): void
    {
        config([
            'filesystems.disks.backup-remote.driver' => 's3',
            'filesystems.disks.backup-remote.key' => '',
            'filesystems.disks.backup-remote.secret' => '',
            'filesystems.disks.backup-remote.bucket' => '',
            'backup.backup.destination.disks' => ['backup-test', 'backup-remote'],
        ]);

        $issues = app(BackupHealthService::class)->configurationIssues();

        $this->assertStringContainsString('missing credentials or a bucket name', implode(' ', $issues));
    }

    public function test_the_command_exits_non_zero_when_backups_are_unhealthy(): void
    {
        $this->artisan('backup:health')->assertExitCode(1);
    }

    public function test_the_command_exits_zero_when_backups_are_healthy(): void
    {
        $this->makeArchive($this->recentArchiveName(), dumpBytes: 250000, encrypted: true);

        $this->artisan('backup:health')->assertExitCode(0);
    }

    /**
     * Name for an archive that is fresh right now.
     *
     * spatie derives a backup's age from the timestamp in its filename, not from
     * the file's mtime — so a hardcoded date here is a test that passes on the day
     * it is written and silently starts failing once real time drifts past the
     * staleness threshold. Two hours keeps it comfortably inside the 26h window
     * regardless of when the suite runs.
     */
    private function recentArchiveName(): string
    {
        return now()->subHours(2)->format('Y-m-d-H-i-s').'.zip';
    }

    /**
     * Builds a backup archive shaped like the ones spatie/laravel-backup produces:
     * a `db-dumps/` entry plus bundled application files.
     */
    private function makeArchive(string $filename, int $dumpBytes, bool $encrypted, int $padBytes = 1024): void
    {
        $path = $this->diskRoot.'/testapp/'.$filename;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('db-dumps/mysql-testapp.sql.gz', random_bytes($dumpBytes));
        $zip->addFromString('.env', str_repeat('x', 512));
        $zip->addFromString('storage/app/media.bin', random_bytes($padBytes));

        if ($encrypted) {
            $zip->setPassword(str_repeat('a', 32));

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $zip->setEncryptionIndex($index, ZipArchive::EM_AES_256);
            }
        }

        $zip->close();
    }
}
