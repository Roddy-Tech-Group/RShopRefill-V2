<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Services\LedgerIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Restores the newest backup into a scratch database and proves it is usable.
 *
 * An untested backup is a hope, not a backup. This command exists so the monthly
 * drill is a five-minute command rather than an hour of remembered steps — and so
 * the number it prints (elapsed time) becomes the platform's measured RTO instead
 * of an asserted one.
 *
 * It is deliberately incapable of touching the live database: the scratch target
 * is validated against the configured connection before anything is written.
 *
 * @see docs/runbooks/database-restore.md
 */
class RestoreDrillCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore-drill
        {--database=rshoprefills_restore_drill : Scratch database to restore into. Must not be the live database.}
        {--disk= : Backup destination disk to restore from. Defaults to the first configured destination.}
        {--keep : Leave the scratch database in place for manual inspection.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore the newest backup into a scratch database and verify it, measuring the real recovery time.';

    /**
     * Spot-checked tables: one per money-critical domain, so a structurally
     * complete but partially-restored database is caught rather than celebrated.
     *
     * @var array<int, string>
     */
    private const VERIFIED_TABLES = [
        'users',
        'orders',
        'order_items',
        'wallets',
        'wallet_transactions',
        'ledger_journals',
        'ledger_entries',
        'payment_sessions',
    ];

    private float $startedAt = 0.0;

    public function handle(LedgerIntegrityService $ledgerIntegrity): int
    {
        $this->startedAt = microtime(true);

        $connection = (string) config('database.default');
        $liveDatabase = (string) config("database.connections.{$connection}.database");
        $scratchDatabase = (string) $this->option('database');

        if ($scratchDatabase === '' || strcasecmp($scratchDatabase, $liveDatabase) === 0) {
            $this->error("Refusing to run: the scratch database must differ from the live database [{$liveDatabase}].");

            return self::FAILURE;
        }

        try {
            $archivePath = $this->downloadNewestArchive();
            $this->step("Archive obtained: {$archivePath}");

            $dumpPath = $this->extractDatabaseDump($archivePath);
            $this->step('Database dump extracted and decompressed: '.$this->humanBytes(filesize($dumpPath) ?: 0));

            $this->recreateScratchDatabase($connection, $scratchDatabase);
            $this->step("Scratch database [{$scratchDatabase}] created.");

            $this->importDump($connection, $scratchDatabase, $dumpPath);
            $this->step('Dump imported.');

            $verified = $this->verifyRestoredDatabase($connection, $scratchDatabase, $ledgerIntegrity);

            $this->cleanUp($archivePath, $dumpPath);

            if (! $this->option('keep')) {
                $this->dropScratchDatabase($connection, $scratchDatabase);
                $this->step("Scratch database [{$scratchDatabase}] dropped.");
            }

            $elapsed = round(microtime(true) - $this->startedAt, 1);

            $this->newLine();

            if (! $verified) {
                $this->error("RESTORE DRILL DID NOT FULLY PASS after {$elapsed}s.");
                $this->line('The archive restored, but at least one check above could not be satisfied.');
                $this->line('Do not record an RTO from an unverified drill — resolve the checks first.');

                return self::FAILURE;
            }

            $this->info("RESTORE DRILL PASSED in {$elapsed}s.");
            $this->line('Record this duration in docs/OPERATIONS.md as the measured RTO for a database restore.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error('Restore drill aborted: '.$exception->getMessage());
            $this->line('Runbook: docs/runbooks/database-restore.md');

            return self::FAILURE;
        }
    }

    /**
     * Pulls the newest archive to local temp storage. Streamed rather than read
     * into memory so this behaves the same for a multi-gigabyte production
     * archive on remote object storage as it does for a small local one.
     */
    private function downloadNewestArchive(): string
    {
        $disk = (string) ($this->option('disk') ?: (config('backup.backup.destination.disks')[0] ?? 'backup-local'));

        $destination = BackupDestination::create($disk, (string) config('backup.backup.name'));

        if (! $destination->isReachable()) {
            throw new RuntimeException("Backup disk [{$disk}] is unreachable: ".($destination->connectionError?->getMessage() ?? 'unknown error'));
        }

        $newest = $destination->newestBackup();

        if ($newest === null) {
            throw new RuntimeException("No backup archive found on disk [{$disk}].");
        }

        $this->step("Newest backup on [{$disk}]: {$newest->path()} ({$newest->date()->diffForHumans()})");

        $localPath = $this->temporaryPath('restore-drill-archive.zip');

        $source = Storage::disk($disk)->readStream($newest->path());

        if ($source === null) {
            throw new RuntimeException('Could not open the backup archive for reading.');
        }

        $target = fopen($localPath, 'wb');

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($target);
            fclose($source);
        }

        return $localPath;
    }

    /**
     * Decrypts the archive and inflates the SQL dump. Failure here is the single
     * most valuable outcome a drill can produce: it means the archive password or
     * the dump itself is wrong, discovered on a quiet Tuesday rather than during
     * an outage.
     */
    private function extractDatabaseDump(string $archivePath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Could not open the backup archive.');
        }

        try {
            $password = (string) config('backup.backup.password', '');

            if ($password !== '') {
                $zip->setPassword($password);
            }

            $entryName = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    continue;
                }

                if (str_starts_with(str_replace('\\', '/', (string) $stat['name']), 'db-dumps/')) {
                    $entryName = (string) $stat['name'];
                    break;
                }
            }

            if ($entryName === null) {
                throw new RuntimeException('The archive contains no database dump.');
            }

            $contents = $zip->getFromName($entryName);

            if ($contents === false || $contents === '') {
                throw new RuntimeException(
                    "Could not decrypt [{$entryName}]. Check BACKUP_ARCHIVE_PASSWORD matches the password used to create this archive."
                );
            }
        } finally {
            $zip->close();
        }

        $sqlPath = $this->temporaryPath('restore-drill-dump.sql');

        // gzip is detected from the magic bytes rather than the file extension so
        // the drill keeps working if the compressor configuration changes.
        if (str_starts_with($contents, "\x1f\x8b")) {
            $compressedPath = $this->temporaryPath('restore-drill-dump.sql.gz');
            file_put_contents($compressedPath, $contents);

            $in = gzopen($compressedPath, 'rb');
            $out = fopen($sqlPath, 'wb');

            try {
                while (! gzeof($in)) {
                    fwrite($out, (string) gzread($in, 262144));
                }
            } finally {
                fclose($out);
                gzclose($in);
                @unlink($compressedPath);
            }
        } else {
            file_put_contents($sqlPath, $contents);
        }

        if ((filesize($sqlPath) ?: 0) < 1024) {
            throw new RuntimeException('The extracted dump is empty or truncated.');
        }

        return $sqlPath;
    }

    private function recreateScratchDatabase(string $connection, string $database): void
    {
        $pdo = $this->serverConnection($connection);

        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function dropScratchDatabase(string $connection, string $database): void
    {
        $this->serverConnection($connection)->exec("DROP DATABASE IF EXISTS `{$database}`");
    }

    /**
     * Imports through the mysql client rather than PDO: a real dump contains
     * client-side directives (DELIMITER, conditional comments) that only the
     * client understands.
     */
    private function importDump(string $connection, string $database, string $dumpPath): void
    {
        $config = (array) config("database.connections.{$connection}");

        $command = [
            $this->clientBinary($connection),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
        ];

        if (($config['password'] ?? '') !== '') {
            $command[] = '--password='.$config['password'];
        }

        $command[] = $database;

        $process = new Process($command, base_path(), null, fopen($dumpPath, 'rb'), (float) config("database.connections.{$connection}.dump.timeout", 900));

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Import failed: '.trim($process->getErrorOutput()));
        }
    }

    /**
     * A restored database is only proven when the data inside it survives the
     * same integrity checks production runs — row counts alone would pass on a
     * structurally intact but financially corrupt restore.
     */
    private function verifyRestoredDatabase(string $connection, string $database, LedgerIntegrityService $ledgerIntegrity): bool
    {
        $originalDatabase = config("database.connections.{$connection}.database");

        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        $passed = true;

        try {
            $tables = DB::connection($connection)->select('SHOW TABLES');

            $this->newLine();
            $this->info('Restored database contents');

            $rows = [];

            foreach (self::VERIFIED_TABLES as $table) {
                try {
                    $rows[] = [$table, number_format(DB::connection($connection)->table($table)->count())];
                } catch (Throwable) {
                    $rows[] = [$table, 'MISSING'];
                    $passed = false;
                }
            }

            $this->table(['Table', 'Rows'], $rows);
            $this->line('  Tables restored: '.count($tables));

            if (count($tables) === 0) {
                $this->error('  No tables in the restored database.');

                return false;
            }

            try {
                $result = $ledgerIntegrity->verifyLedgerIntegrity();

                if (($result['status'] ?? null) === 'passed') {
                    $this->info('  Ledger integrity verified against the restored data.');
                } else {
                    $this->error('  Ledger integrity FAILED against the restored data.');

                    foreach ($result['violations'] ?? [] as $violation) {
                        $this->error("    - [{$violation['type']}] {$violation['message']}");
                    }

                    $passed = false;
                }
            } catch (Throwable $exception) {
                // A crashing integrity tool is a different defect from a bad
                // restore, and conflating the two would send the operator hunting
                // the wrong problem during an incident.
                $this->warn('  Ledger integrity could NOT be evaluated — the verifier itself failed:');
                $this->warn('    '.$exception->getMessage());
                $this->warn('  The restore is unproven until `ledger:verify` works. Tracked as roadmap P0-4.');

                $passed = false;
            }
        } finally {
            config(["database.connections.{$connection}.database" => $originalDatabase]);
            DB::purge($connection);
        }

        return $passed;
    }

    private function serverConnection(string $connection): PDO
    {
        $config = (array) config("database.connections.{$connection}");

        $dsn = sprintf('mysql:host=%s;port=%s', $config['host'] ?? '127.0.0.1', $config['port'] ?? '3306');

        return new PDO($dsn, (string) ($config['username'] ?? 'root'), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function clientBinary(string $connection): string
    {
        $directory = rtrim((string) config("database.connections.{$connection}.dump.dump_binary_path", ''), '\\/');

        if ($directory === '') {
            return 'mysql';
        }

        foreach (['mysql', 'mysql.exe'] as $binary) {
            $candidate = $directory.DIRECTORY_SEPARATOR.$binary;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return 'mysql';
    }

    private function temporaryPath(string $filename): string
    {
        $directory = (string) config('backup.backup.temporary_directory', storage_path('backup-temp'));

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.$filename;
    }

    private function cleanUp(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function step(string $message): void
    {
        $elapsed = str_pad(number_format(microtime(true) - $this->startedAt, 1).'s', 7, ' ', STR_PAD_LEFT);

        $this->line("  [{$elapsed}] {$message}");
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2).' '.$units[$power];
    }
}
