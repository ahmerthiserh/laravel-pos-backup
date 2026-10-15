<?php

namespace Tiger\PosBackup;

use Illuminate\Support\ServiceProvider;

class TigerPosBackupServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/tigerpos-backup.php',
            'tigerpos-backup'
        );

        $this->app->singleton(\Tiger\PosBackup\Services\MySqlDumpService::class, function () {
            return new \Tiger\PosBackup\Services\MySqlDumpService();
        });

        $this->app->singleton(\Tiger\PosBackup\Services\S3BackupUploader::class, function () {
            return new \Tiger\PosBackup\Services\S3BackupUploader();
        });
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/tigerpos-backup.php' => config_path('tigerpos-backup.php'),
            ], 'tigerpos-backup-config');

            $this->commands([
                \Tiger\PosBackup\Console\BackupCommand::class,
            ]);
        }
    }
}
