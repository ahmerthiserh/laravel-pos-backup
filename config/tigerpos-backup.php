<?php

$envFirst = function (array $keys, $default = null) {
    foreach ($keys as $key) {
        $val = env($key);
        if ($val !== null && $val !== '') {
            return $val;
        }
    }
    return $default;
};

$envBool = function (array $keys, bool $default = false) use ($envFirst): bool {
    $val = $envFirst($keys, null);
    if ($val === null) {
        return $default;
    }
    return filter_var($val, FILTER_VALIDATE_BOOLEAN);
};

$retentionRaw = $envFirst(['POS_BACKUP_RETENTION_DAYS'], 30);

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Status
    |--------------------------------------------------------------------------
    |
    | Enable or disable automated backups for this application instance.
    | Environment variable: POS_BACKUP_ENABLED
    |
    */
    'enabled' => $envBool(['POS_BACKUP_ENABLED'], true),

    /*
    |--------------------------------------------------------------------------
    | POS Identifier
    |--------------------------------------------------------------------------
    |
    | Unique identifier or branch code for this POS application instance.
    | This tag helps distinguish backup archives stored across multiple units.
    | Environment variable: POS_BACKUP_POS_ID (fallback: APP_NAME)
    |
    */
    'pos_id' => $envFirst(['POS_BACKUP_POS_ID', 'APP_NAME'], 'pos-default'),

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
        'region' => $envFirst(['POS_BACKUP_AWS_REGION', 'AWS_DEFAULT_REGION', 'AWS_REGION'], 'us-east-1'),
        'bucket' => $envFirst(['POS_BACKUP_AWS_BUCKET', 'AWS_BUCKET'], ''),
        'prefix' => $envFirst(['POS_BACKUP_S3_PREFIX'], 'pos-backups'),
        'kms_key_id' => $envFirst(['POS_BACKUP_AWS_KMS_KEY_ID'], null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain remote backups before they are automatically purged.
    | Environment variable: POS_BACKUP_RETENTION_DAYS
    |
    */
    'retention_days' => is_numeric($retentionRaw) ? (int) $retentionRaw : $retentionRaw,

    /*
    |--------------------------------------------------------------------------
    | Temporary Backup Directory
    |--------------------------------------------------------------------------
    |
    | Local filesystem directory used during dump creation and compression prior
    | to S3 upload. Defaults to storage_path('app/backup-temp').
    | Environment variable: POS_BACKUP_TEMP_DIR
    |
    */
    'temp_dir' => $envFirst(
        ['POS_BACKUP_TEMP_DIR'],
        function_exists('storage_path') ? storage_path('app/backup-temp') : '/tmp/backup-temp'
    ),

    /*
    |--------------------------------------------------------------------------
    | Automated Scheduling
    |--------------------------------------------------------------------------
    |
    | Automatically register tigerpos:backup with Laravel's Console Scheduler.
    | Defaults to running every six hours.
    | Environment variable: POS_BACKUP_SCHEDULE_ENABLED, POS_BACKUP_SCHEDULE_CRON
    |
    */
    'schedule' => [
        'enabled' => $envBool(['POS_BACKUP_SCHEDULE_ENABLED'], true),
        'cron' => $envFirst(['POS_BACKUP_SCHEDULE_CRON'], '0 */6 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automated Backup Pruning (Opt-In)
    |--------------------------------------------------------------------------
    |
    | Automated pruning of expired local and remote backup archives.
    | For operational safety, this is disabled by default. When enabled,
    | Laravel's Console Scheduler will register tigerpos:prune-backups --execute.
    | Environment variable: POS_BACKUP_PRUNE_ENABLED, POS_BACKUP_PRUNE_CRON
    |
    */
    'prune' => [
        'enabled' => $envBool(['POS_BACKUP_PRUNE_ENABLED'], false),
        'cron' => $envFirst(['POS_BACKUP_PRUNE_CRON'], '30 2 * * *'),
    ],
];
