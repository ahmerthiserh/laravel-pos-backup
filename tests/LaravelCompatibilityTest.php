<?php

namespace Tiger\PosBackup\Tests;

use Aws\S3\S3Client;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tiger\PosBackup\Console\BackupCommand;
use Tiger\PosBackup\Console\PruneBackupsCommand;
use Tiger\PosBackup\Services\BackupPrunerService;
use Tiger\PosBackup\Services\MySqlDumpService;
use Tiger\PosBackup\Services\S3BackupUploader;
use Tiger\PosBackup\TigerPosBackupServiceProvider;

class LaravelCompatibilityTest extends TestCase
{
    /**
     * Test that the running Laravel framework version is supported (Laravel 9.x through 13.x).
     */
    public function test_framework_version_compatibility(): void
    {
        $version = $this->app->version();
        $this->assertNotEmpty($version);

        // Extract major version
        preg_match('/^(\d+)\./', $version, $matches);
        $this->assertNotEmpty($matches[1], 'Could not determine framework major version');

        $majorVersion = (int) $matches[1];
        $this->assertGreaterThanOrEqual(9, $majorVersion, 'Framework version must be at least Laravel 9');
        $this->assertLessThanOrEqual(13, $majorVersion, 'Framework version is expected to be up to Laravel 13');
    }

    /**
     * Test service provider register bindings and singleton resolution.
     */
    public function test_service_provider_singleton_bindings(): void
    {
        $dumpService1 = $this->app->make(MySqlDumpService::class);
        $dumpService2 = $this->app->make(MySqlDumpService::class);
        $this->assertInstanceOf(MySqlDumpService::class, $dumpService1);
        $this->assertSame($dumpService1, $dumpService2, 'MySqlDumpService must be bound as singleton');

        $uploader1 = $this->app->make(S3BackupUploader::class);
        $uploader2 = $this->app->make(S3BackupUploader::class);
        $this->assertInstanceOf(S3BackupUploader::class, $uploader1);
        $this->assertSame($uploader1, $uploader2, 'S3BackupUploader must be bound as singleton');

        $pruner1 = $this->app->make(BackupPrunerService::class);
        $pruner2 = $this->app->make(BackupPrunerService::class);
        $this->assertInstanceOf(BackupPrunerService::class, $pruner1);
        $this->assertSame($pruner1, $pruner2, 'BackupPrunerService must be bound as singleton');
    }

    /**
     * Test configuration merge and publishing tags.
     */
    public function test_config_merging_and_publishing(): void
    {
        $this->assertTrue($this->app['config']->has('tigerpos-backup'));
        $this->assertEquals('test-pos-01', config('tigerpos-backup.pos_id'));
        $this->assertEquals(30, config('tigerpos-backup.retention_days'));

        $publishGroups = TigerPosBackupServiceProvider::pathsToPublish(
            TigerPosBackupServiceProvider::class,
            'tigerpos-backup-config'
        );

        $this->assertNotEmpty($publishGroups, 'Publishing tag tigerpos-backup-config must be registered');
    }

    /**
     * Test console commands are registered and executable in Artisan.
     */
    public function test_console_commands_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('tigerpos:backup', $commands);
        $this->assertInstanceOf(BackupCommand::class, $commands['tigerpos:backup']);

        $this->assertArrayHasKey('tigerpos:prune-backups', $commands);
        $this->assertInstanceOf(PruneBackupsCommand::class, $commands['tigerpos:prune-backups']);
    }

    /**
     * Test scheduler integration under the active framework version.
     */
    public function test_scheduler_event_registration(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $events = $schedule->events();
        $this->assertNotEmpty($events);

        $backupEvent = null;
        foreach ($events as $event) {
            if (isset($event->command) && str_contains($event->command, 'tigerpos:backup')) {
                $backupEvent = $event;
                break;
            }
        }

        $this->assertNotNull($backupEvent, 'Scheduled backup event must be present in scheduler');
        $this->assertEquals('0 */6 * * *', $backupEvent->expression);
    }

    /**
     * Test filesystem and storage path handling under active framework.
     */
    public function test_filesystem_and_temp_directory_handling(): void
    {
        $tempDir = storage_path('app/backup-temp-compat-' . uniqid());
        $dumpService = $this->app->make(MySqlDumpService::class);

        $resolved = $dumpService->ensureTempDirectory($tempDir);
        $this->assertDirectoryExists($resolved);

        // Verify restrictive permissions (0700 on POSIX systems)
        if (DIRECTORY_SEPARATOR === '/') {
            $perms = fileperms($resolved) & 0777;
            $this->assertEquals(0700, $perms, 'Temporary directory permissions must be 0700');
        }

        @rmdir($resolved);
    }

    /**
     * Test AWS S3 client instantiation and configuration validation.
     */
    public function test_s3_client_integration(): void
    {
        $uploader = $this->app->make(S3BackupUploader::class);

        $client = $uploader->getS3Client([
            'region' => 'eu-west-1',
            'bucket' => 'test-pos-bucket',
        ]);

        $this->assertInstanceOf(S3Client::class, $client);
        $this->assertEquals('eu-west-1', $client->getRegion());
    }
}
