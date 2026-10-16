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

        $this->app->singleton(\Tiger\PosBackup\Services\BackupPrunerService::class, function ($app) {
            return new \Tiger\PosBackup\Services\BackupPrunerService(
                $app->make(\Tiger\PosBackup\Services\S3BackupUploader::class)
            );
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
                \Tiger\PosBackup\Console\PruneBackupsCommand::class,
            ]);

            $this->registerSchedule();
        }
    }

    /**
     * Register automated backup with Laravel Console Scheduler.
     */
    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            // Respect global package enabled setting
            $enabled = config('tigerpos-backup.enabled', true);
            if (!$enabled) {
                return;
            }

            if (!$this->app->bound(\Illuminate\Console\Scheduling\Schedule::class)) {
                return;
            }

            /** @var \Illuminate\Console\Scheduling\Schedule $schedule */
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            // 1. Scheduled Backup (every six hours by default)
            $scheduleEnabled = config('tigerpos-backup.schedule.enabled', true);
            if ($scheduleEnabled) {
                $hasBackupEvent = false;
                foreach ($schedule->events() as $event) {
                    if (isset($event->command) && str_contains($event->command, 'tigerpos:backup')) {
                        $hasBackupEvent = true;
                        break;
                    }
                }

                if (!$hasBackupEvent) {
                    $cronExpression = (string) config('tigerpos-backup.schedule.cron', '0 */6 * * *');
                    $schedule->command(\Tiger\PosBackup\Console\BackupCommand::class)
                        ->cron($cronExpression);
                }
            }

            // 2. Scheduled Pruning (opt-in only, disabled by default)
            $pruneEnabled = config('tigerpos-backup.prune.enabled', false);
            if ($pruneEnabled) {
                $hasPruneEvent = false;
                foreach ($schedule->events() as $event) {
                    if (isset($event->command) && str_contains($event->command, 'tigerpos:prune-backups')) {
                        $hasPruneEvent = true;
                        break;
                    }
                }

                if (!$hasPruneEvent) {
                    $pruneCron = (string) config('tigerpos-backup.prune.cron', '30 2 * * *');
                    $schedule->command(\Tiger\PosBackup\Console\PruneBackupsCommand::class, ['--execute'])
                        ->cron($pruneCron);
                }
            }
        });
    }
}
