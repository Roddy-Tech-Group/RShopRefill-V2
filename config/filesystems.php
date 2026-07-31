<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        /*
         * On-box staging copy of the encrypted backup archives. Deliberately rooted
         * outside storage/app so a backup can never contain a previous backup.
         * This disk is a convenience, never the disaster-recovery copy — a dead VPS
         * takes it with it. `throw` is on: a silent write failure here is the exact
         * failure mode backups exist to prevent.
         */
        'backup-local' => [
            'driver' => 'local',
            'root' => storage_path('backups'),
            'visibility' => 'private',
            'throw' => true,
        ],

        /*
         * Off-site, versioned, object-locked bucket (Cloudflare R2 / Backblaze B2 /
         * S3). This is the copy that survives disk failure, a bad migration, and
         * ransomware. Credentials are separate from the application `s3` disk on
         * purpose: compromising the app must not grant reach over the backups.
         */
        'backup-remote' => [
            'driver' => 's3',
            'key' => env('BACKUP_REMOTE_ACCESS_KEY_ID'),
            'secret' => env('BACKUP_REMOTE_SECRET_ACCESS_KEY'),
            'region' => env('BACKUP_REMOTE_REGION', 'auto'),
            'bucket' => env('BACKUP_REMOTE_BUCKET'),
            'endpoint' => env('BACKUP_REMOTE_ENDPOINT'),
            'use_path_style_endpoint' => env('BACKUP_REMOTE_USE_PATH_STYLE', true),
            'visibility' => 'private',
            'throw' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
