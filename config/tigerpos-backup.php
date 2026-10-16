<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Status
    |--------------------------------------------------------------------------
    |
    | Enable or disable automated backups for this application instance.
    |
    */
    'enabled' => env('TIGERPOS_BACKUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | POS Identifier
    |--------------------------------------------------------------------------
    |
    | Unique identifier or branch code for this POS application instance.
    | This tag helps distinguish backup archives stored across multiple units.
    |
    */
    'pos_id' => env('TIGERPOS_BACKUP_POS_ID', env('APP_NAME', 'pos-default')),

    /*
    |--------------------------------------------------------------------------
    | AWS & S3 Storage Settings
    |--------------------------------------------------------------------------
    |
    | S3 bucket name, region, and folder prefix used for storing backups.
    | Sensitive access keys are read by Flysystem/AWS SDK from standard AWS envs.
    |
    */
    's3' => [
        'region' => env('TIGERPOS_BACKUP_AWS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'bucket' => env('TIGERPOS_BACKUP_AWS_BUCKET', env('AWS_BUCKET', '')),
        'prefix' => env('TIGERPOS_BACKUP_S3_PREFIX', 'pos-backups'),
        'kms_key_id' => env('TIGERPOS_BACKUP_AWS_KMS_KEY_ID', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain remote backups before they are automatically purged.
    |
    */
    'retention_days' => (int) env('TIGERPOS_BACKUP_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Temporary Backup Directory
    |--------------------------------------------------------------------------
    |
    | Local filesystem directory used during dump creation and compression prior
    | to S3 upload. Defaults to storage_path('app/backup-temp').
    |
    */
    'temp_dir' => env('TIGERPOS_BACKUP_TEMP_DIR', storage_path('app/backup-temp')),

    /*
    |--------------------------------------------------------------------------
    | Automated Scheduling
    |--------------------------------------------------------------------------
    |
    | Automatically register tigerpos:backup with Laravel's Console Scheduler.
    | Defaults to running every six hours.
    |
    */
    'schedule' => [
        'enabled' => env('TIGERPOS_BACKUP_SCHEDULE_ENABLED', true),
        'cron' => env('TIGERPOS_BACKUP_SCHEDULE_CRON', '0 */6 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automated Backup Pruning (Opt-In)
    |--------------------------------------------------------------------------
    |
    | Automated pruning of expired local and remote backup archives.
    | For operational safety, this is disabled by default. When enabled,
    | Laravel's Console Scheduler will register tigerpos:prune-backups --execute.
    |
    */
    'prune' => [
        'enabled' => env('TIGERPOS_BACKUP_PRUNE_ENABLED', false),
        'cron' => env('TIGERPOS_BACKUP_PRUNE_CRON', '30 2 * * *'),
    ],
];


