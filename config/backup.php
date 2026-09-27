<?php

use App\Notifications\BackupNotifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

/*
|--------------------------------------------------------------------------
| Backup destinations
|--------------------------------------------------------------------------
|
| Comma-separated list of filesystem disks that receive every archive.
| Production MUST include an off-site disk (`backup-remote`); a backup that
| only exists on the machine being backed up is not a backup. This is
| asserted at runtime by App\Domain\Shared\Services\BackupHealthService.
|
*/

$destinationDisks = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('BACKUP_DESTINATION_DISKS', 'backup-local'))
)));

return [

    'backup' => [
        /*
         * Used as the archive folder name on every destination disk and as the
         * key the monitor uses to find those archives. Changing it orphans
         * existing backups, so it is pinned rather than derived from APP_NAME.
         */
        'name' => env('BACKUP_ARCHIVE_NAME', 'rshoprefills'),

        'source' => [
            'files' => [
                /*
                 * The database is the company; these files are what makes a restored
                 * database usable. `.env` carries every provider credential, and
                 * storage/app holds customer-uploaded artefacts (KYC documents,
                 * trade media) that exist nowhere else.
                 */
                'include' => [
                    base_path('.env'),
                    storage_path('app'),
                ],

                /*
                 * Livewire's half-finished uploads are worthless in a restore and
                 * grow without bound. Both roots are listed because the private
                 * disk (storage/app/private) is where this application puts them.
                 */
                'exclude' => [
                    storage_path('app/private/livewire-tmp'),
                    storage_path('app/public/livewire-tmp'),
                ],

                'follow_links' => false,

                'ignore_unreadable_directories' => true,

                /*
                 * Store paths relative to the project root so an archive can be
                 * unpacked over a fresh checkout without path surgery.
                 */
                'relative_path' => base_path(),
            ],

            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        /*
         * Gzip the SQL dump (it compresses ~10:1) and store the zip without a
         * second compression pass — re-compressing gzip burns CPU on the
         * production box for no size gain. AES-256 encryption is applied to the
         * zip regardless of compression method.
         */
        'database_dump_compressor' => GzipCompressor::class,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_STORE,

            'compression_level' => 0,

            'filename_prefix' => '',

            'disks' => $destinationDisks,
        ],

        'temporary_directory' => storage_path('backup-temp'),

        /*
         * AES-256 archive encryption. Backups contain the ledger, customer PII and
         * the `.env` file: an unencrypted archive sitting in object storage is a
         * breach waiting for a misconfigured bucket policy.
         *
         * The password lives in the credential escrow (docs/ONCALL.md), NOT only
         * on the server — an encrypted backup whose key died with the VPS is not
         * a recoverable backup.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        'encryption' => 'default',

        /*
         * A transient network blip to object storage should not cost a night's
         * backup, so retry twice with a pause before giving up and alerting.
         */
        'tries' => 3,

        'retry_delay' => 60,
    ],

    /*
     * Only failures notify. Success notifications train you to ignore the channel,
     * and the absence of a nightly backup is caught by `backup:health` (which
     * alerts on staleness) rather than by you noticing a missing email.
     */
    'notifications' => [
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        'notifiable' => BackupNotifiable::class,

        'mail' => [
            /*
             * `?:` rather than an env() default: an empty OPS_ALERT_EMAIL is a
             * blank string, not a missing key, and spatie rejects it at boot —
             * which would take the whole backup command down with it.
             */
            'to' => env('OPS_ALERT_EMAIL') ?: env('MAIL_FROM_ADDRESS', 'ops@rshoprefill.com'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'RshopRefill Operations'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('OPS_SLACK_WEBHOOK_URL', ''),
            'channel' => null,
            'username' => 'RshopRefill Backups',
            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',
            'username' => '',
            'avatar_url' => '',
        ],
    ],

    /*
     * Thresholds used by `backup:health` (App\Domain\Shared\Services\BackupHealthService).
     *
     * 26 hours gives a nightly schedule two hours of grace for a slow run or a
     * clock skew without hiding a genuinely missed night. The byte floor catches
     * the nastier failure: a dump that "succeeded" but was truncated.
     */
    'monitor_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),

    'monitor_min_archive_bytes' => (int) env('BACKUP_MIN_ARCHIVE_BYTES', 32768),

    /*
     * Floor for the database dump *inside* the archive. Bundled application files
     * can keep an archive's total size plausible while the dump itself is empty —
     * a failure observed during the P0-1 build, and the reason this check exists
     * separately from the archive-size floor above.
     */
    'monitor_min_dump_bytes' => (int) env('BACKUP_MIN_DUMP_BYTES', 4096),

    /*
     * Consumed by `backup:monitor` and by BackupHealthService. Age is the metric
     * that matters: a backup older than a day means the RPO promised in
     * docs/OPERATIONS.md is already being broken.
     */
    'monitor_backups' => [
        [
            'name' => env('BACKUP_ARCHIVE_NAME', 'rshoprefills'),
            'disks' => $destinationDisks,
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => (int) env('BACKUP_MAX_STORAGE_MB', 20000),
            ],
        ],
    ],

    /*
     * Retention: 7 days of everything, then daily for a fortnight, weekly for a
     * month, monthly for half a year, and one yearly archive. Deletes are logical
     * only — the bucket is versioned and object-locked, so `backup:clean` cannot
     * be turned into a data-destruction tool by a stolen credential.
     */
    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            'keep_all_backups_for_days' => 7,

            'keep_daily_backups_for_days' => 14,

            'keep_weekly_backups_for_weeks' => 4,

            'keep_monthly_backups_for_months' => 6,

            'keep_yearly_backups_for_years' => 1,

            'delete_oldest_backups_when_using_more_megabytes_than' => (int) env('BACKUP_MAX_STORAGE_MB', 20000),
        ],

        'tries' => 3,

        'retry_delay' => 60,
    ],

];
