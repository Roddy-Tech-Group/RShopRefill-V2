<?php

namespace App\Domain\Shared\Services;

use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Throwable;
use ZipArchive;

/**
 * Answers one question with evidence rather than optimism: "if the database died
 * right now, what would we restore from?"
 *
 * Two failure modes are covered, because both have ended companies:
 *
 *  1. The backup never ran, or stopped running weeks ago and nobody noticed.
 *  2. The backup ran perfectly — into a single on-box directory, unencrypted,
 *     with no off-site copy — and is therefore worthless in the disaster it
 *     exists for.
 *
 * Consumed by the `backup:health` command (scheduled, alert-wired) and, from
 * Phase 1 onwards, by the CTO daily dashboard's backup tile.
 */
class BackupHealthService
{
    /**
     * A nightly backup is stale at 26h: the two-hour grace absorbs a late run or
     * a clock skew without hiding a genuinely missed night.
     */
    private const DEFAULT_MAX_AGE_HOURS = 26;

    /**
     * An archive smaller than this is a truncated dump or an empty zip, not a
     * backup. A real archive for this platform is measured in megabytes.
     */
    private const DEFAULT_MIN_ARCHIVE_BYTES = 32768;

    /**
     * An empty gzip stream is 20 bytes and looks like a file. Any real dump of
     * this platform's schema is orders of magnitude larger, so this floor
     * separates "a dump happened" from "a dump-shaped file exists".
     */
    private const DEFAULT_MIN_DUMP_BYTES = 4096;

    /**
     * Full health report across every configured destination.
     *
     * @return array{
     *     healthy: bool,
     *     checked_at: string,
     *     archive_name: string,
     *     max_age_hours: int,
     *     configuration_issues: array<int, string>,
     *     destinations: array<int, array{
     *         disk: string,
     *         driver: string,
     *         offsite: bool,
     *         reachable: bool,
     *         healthy: bool,
     *         backup_count: int,
     *         newest_backup_at: string|null,
     *         age_hours: float|null,
     *         size_bytes: int|null,
     *         used_storage_bytes: int,
     *         encrypted: bool|null,
     *         problems: array<int, string>
     *     }>
     * }
     */
    public function status(): array
    {
        $configurationIssues = $this->configurationIssues();
        $destinations = $this->destinations();

        $destinationsHealthy = $destinations !== [] && collect($destinations)->every(
            fn (array $destination): bool => $destination['healthy']
        );

        return [
            'healthy' => $configurationIssues === [] && $destinationsHealthy,
            'checked_at' => now()->toIso8601String(),
            'archive_name' => $this->archiveName(),
            'max_age_hours' => $this->maxAgeHours(),
            'configuration_issues' => $configurationIssues,
            'destinations' => $destinations,
        ];
    }

    /**
     * Misconfigurations that make backups worthless without making them fail.
     * These are the silent killers — every one of them lets `backup:run` report
     * success while leaving the company unrecoverable.
     *
     * @return array<int, string>
     */
    public function configurationIssues(): array
    {
        $issues = [];

        $password = (string) config('backup.backup.password', '');
        $encryption = config('backup.backup.encryption');

        if ($password === '' || $encryption === false || $encryption === null) {
            $issues[] = 'Archive encryption is disabled: set BACKUP_ARCHIVE_PASSWORD. Backups contain the ledger, customer PII and the .env file.';
        } elseif (strlen($password) < 24) {
            $issues[] = 'BACKUP_ARCHIVE_PASSWORD is shorter than 24 characters; generate a long random passphrase.';
        }

        $disks = $this->destinationDisks();

        if ($disks === []) {
            $issues[] = 'No backup destination disks configured: set BACKUP_DESTINATION_DISKS.';
        }

        if (app()->isProduction() && ! $this->hasOffsiteDisk()) {
            $issues[] = 'No off-site destination configured: BACKUP_DESTINATION_DISKS must include backup-remote in production. An on-box backup dies with the box.';
        }

        foreach ($disks as $disk) {
            if ($this->isOffsiteDisk($disk) && ! $this->offsiteDiskIsConfigured($disk)) {
                $issues[] = "Off-site disk [{$disk}] is missing credentials or a bucket name.";
            }
        }

        if (! $this->dumpBinaryIsAvailable()) {
            $binaryPath = (string) config('database.connections.'.config('database.default').'.dump.dump_binary_path');
            $issues[] = "Configured database dump binary path [{$binaryPath}] does not contain an executable dump binary.";
        }

        return $issues;
    }

    /**
     * Per-destination state: what is actually sitting on each disk right now.
     *
     * @return array<int, array<string, mixed>>
     */
    public function destinations(): array
    {
        return array_map(
            fn (string $disk): array => $this->inspectDestination($disk),
            $this->destinationDisks()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectDestination(string $disk): array
    {
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'unknown');
        $offsite = $this->isOffsiteDisk($disk);

        $report = [
            'disk' => $disk,
            'driver' => $driver,
            'offsite' => $offsite,
            'reachable' => false,
            'healthy' => false,
            'backup_count' => 0,
            'newest_backup_at' => null,
            'age_hours' => null,
            'size_bytes' => null,
            'used_storage_bytes' => 0,
            'encrypted' => null,
            'database_dump_bytes' => null,
            'problems' => [],
        ];

        $destination = BackupDestination::create($disk, $this->archiveName());

        if (! $destination->isReachable()) {
            $report['problems'][] = 'Destination unreachable: '
                .($destination->connectionError?->getMessage() ?? 'unknown error');

            return $report;
        }

        $report['reachable'] = true;

        try {
            $backups = $destination->backups();
            $newest = $destination->newestBackup();

            $report['backup_count'] = $backups->count();
            $report['used_storage_bytes'] = (int) $destination->usedStorage();
        } catch (Throwable $exception) {
            $report['problems'][] = 'Could not list backups: '.$exception->getMessage();

            return $report;
        }

        if ($newest === null) {
            $report['problems'][] = 'No backup archive found on this destination.';

            return $report;
        }

        $ageHours = round($newest->date()->diffInMinutes(now()) / 60, 2);
        $sizeBytes = (int) $newest->sizeInBytes();

        $report['newest_backup_at'] = $newest->date()->toIso8601String();
        $report['age_hours'] = $ageHours;
        $report['size_bytes'] = $sizeBytes;

        if ($ageHours > $this->maxAgeHours()) {
            $report['problems'][] = sprintf(
                'Newest backup is %.1fh old, exceeding the %dh threshold.',
                $ageHours,
                $this->maxAgeHours()
            );
        }

        if ($sizeBytes < $this->minArchiveBytes()) {
            $report['problems'][] = sprintf(
                'Newest backup is only %d bytes, below the %d byte floor — likely a truncated dump.',
                $sizeBytes,
                $this->minArchiveBytes()
            );
        }

        // Encryption and dump contents are verified by inspection, not assumed
        // from config, wherever the archive is readable without egress cost.
        if ($driver === 'local') {
            $inspection = $this->inspectArchive(Storage::disk($disk)->path($newest->path()));

            $report['encrypted'] = $inspection['encrypted'];
            $report['database_dump_bytes'] = $inspection['database_dump_bytes'];

            if ($inspection['encrypted'] === false) {
                $report['problems'][] = 'Newest archive is NOT encrypted.';
            }

            if ($inspection['database_dump_bytes'] === null) {
                $report['problems'][] = 'Newest archive contains no database dump.';
            } elseif ($inspection['database_dump_bytes'] < $this->minDumpBytes()) {
                $report['problems'][] = sprintf(
                    'Database dump inside the archive is only %d bytes, below the %d byte floor — the dump is empty or truncated.',
                    $inspection['database_dump_bytes'],
                    $this->minDumpBytes()
                );
            }
        }

        $report['healthy'] = $report['problems'] === [];

        return $report;
    }

    /**
     * Reads the zip central directory — no password required, no extraction — to
     * establish two facts that config alone cannot prove:
     *
     *  - entries carry a real encryption method, and
     *  - a non-trivial database dump is actually inside.
     *
     * The second check exists because a healthy-looking archive can contain an
     * empty dump: bundled application files keep the total size plausible while
     * the only thing that matters is missing. Verified against exactly that
     * failure during the P0-1 build.
     *
     * @return array{encrypted: bool|null, database_dump_bytes: int|null}
     */
    private function inspectArchive(string $absolutePath): array
    {
        $result = ['encrypted' => null, 'database_dump_bytes' => null];

        if (! is_readable($absolutePath)) {
            return $result;
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return $result;
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    continue;
                }

                if ($result['encrypted'] === null && array_key_exists('encryption_method', $stat)) {
                    $result['encrypted'] = $stat['encryption_method'] !== ZipArchive::EM_NONE;
                }

                $normalisedName = str_replace('\\', '/', (string) $stat['name']);

                if (str_starts_with($normalisedName, 'db-dumps/')) {
                    $result['database_dump_bytes'] = (int) $stat['size']
                        + (int) ($result['database_dump_bytes'] ?? 0);
                }
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    public function destinationDisks(): array
    {
        /** @var array<int, string> $disks */
        $disks = (array) config('backup.backup.destination.disks', []);

        return array_values(array_filter($disks));
    }

    public function hasOffsiteDisk(): bool
    {
        foreach ($this->destinationDisks() as $disk) {
            if ($this->isOffsiteDisk($disk)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any non-local driver leaves the machine, which is the whole point.
     */
    private function isOffsiteDisk(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") !== 'local';
    }

    private function offsiteDiskIsConfigured(string $disk): bool
    {
        $config = (array) config("filesystems.disks.{$disk}", []);

        foreach (['key', 'secret', 'bucket'] as $required) {
            if (blank($config[$required] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A dump binary path that points nowhere turns every backup into a failure
     * at 01:30 — cheaper to catch here than in the incident.
     */
    private function dumpBinaryIsAvailable(): bool
    {
        $connection = (string) config('database.default');
        $path = (string) config("database.connections.{$connection}.dump.dump_binary_path", '');

        if ($path === '') {
            return true;
        }

        $directory = rtrim($path, '\\/');

        foreach (['mysqldump', 'mysqldump.exe', 'pg_dump', 'pg_dump.exe'] as $binary) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$binary)) {
                return true;
            }
        }

        return false;
    }

    private function archiveName(): string
    {
        return (string) config('backup.backup.name', 'rshoprefills');
    }

    public function maxAgeHours(): int
    {
        return (int) config('backup.monitor_max_age_hours', self::DEFAULT_MAX_AGE_HOURS);
    }

    private function minArchiveBytes(): int
    {
        return (int) config('backup.monitor_min_archive_bytes', self::DEFAULT_MIN_ARCHIVE_BYTES);
    }

    private function minDumpBytes(): int
    {
        return (int) config('backup.monitor_min_dump_bytes', self::DEFAULT_MIN_DUMP_BYTES);
    }
}
