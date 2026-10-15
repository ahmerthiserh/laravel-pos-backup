<?php

namespace Tiger\PosBackup\Tests;

use Mockery;
use RuntimeException;
use Tiger\PosBackup\Services\MySqlDumpService;

class BackupCommandTest extends TestCase
{
    public function test_command_skips_when_backup_disabled(): void
    {
        $this->app['config']->set('tigerpos-backup.enabled', false);

        $this->artisan('tigerpos:backup')
            ->expectsOutputToContain('TigerPOS backup is disabled via configuration')
            ->assertSuccessful();
    }

    public function test_command_fails_when_connection_driver_is_unsupported(): void
    {
        $this->app['config']->set('database.connections.sqlite_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->artisan('tigerpos:backup', ['--connection' => 'sqlite_test'])
            ->expectsOutputToContain('Unsupported database driver [sqlite]')
            ->assertFailed();
    }

    public function test_command_fails_when_exec_disabled(): void
    {
        $mock = Mockery::mock(MySqlDumpService::class)->makePartial();
        $mock->shouldReceive('isExecDisabled')->andReturn(true);
        $this->app->instance(MySqlDumpService::class, $mock);

        $this->artisan('tigerpos:backup')
            ->expectsOutputToContain('Process execution functions (proc_open) are disabled')
            ->assertFailed();
    }

    public function test_command_fails_when_mysqldump_utility_is_missing(): void
    {
        $mock = Mockery::mock(MySqlDumpService::class)->makePartial();
        $mock->shouldReceive('isExecDisabled')->andReturn(false);
        $mock->shouldReceive('isDumpUtilityAvailable')->andReturn(false);
        $this->app->instance(MySqlDumpService::class, $mock);

        $this->artisan('tigerpos:backup')
            ->expectsOutputToContain("The 'mysqldump' utility is not installed")
            ->assertFailed();
    }

    public function test_command_fails_and_cleans_up_on_dump_error(): void
    {
        $mock = Mockery::mock(MySqlDumpService::class)->makePartial();
        $mock->shouldReceive('isExecDisabled')->andReturn(false);
        $mock->shouldReceive('isDumpUtilityAvailable')->andReturn(true);
        $mock->shouldReceive('dump')->andThrow(new RuntimeException('Access denied for user'));
        $this->app->instance(MySqlDumpService::class, $mock);

        $this->artisan('tigerpos:backup')
            ->expectsOutputToContain('Backup failed: Access denied for user')
            ->assertFailed();
    }

    public function test_command_succeeds_locally_with_local_only_flag(): void
    {
        $mock = Mockery::mock(MySqlDumpService::class)->makePartial();
        $mock->shouldReceive('isExecDisabled')->andReturn(false);
        $mock->shouldReceive('isDumpUtilityAvailable')->andReturn(true);
        $mock->shouldReceive('dump')->andReturnUsing(function ($conn, $targetPath) {
            $gz = gzopen($targetPath, 'wb9');
            gzwrite($gz, 'DUMMY SQL DATA');
            gzclose($gz);
            return $targetPath;
        });

        $this->app->instance(MySqlDumpService::class, $mock);

        $this->artisan('tigerpos:backup', ['--local-only' => true])
            ->expectsOutputToContain('Database backup created successfully.')
            ->expectsOutputToContain('Local Archive: ')
            ->expectsOutputToContain('Size: ')
            ->expectsOutputToContain('Skipping S3 upload (--local-only specified).')
            ->doesntExpectOutput('secret_db_pass')
            ->assertSuccessful();
    }

    public function test_command_fails_when_s3_bucket_not_configured(): void
    {
        $mock = Mockery::mock(MySqlDumpService::class)->makePartial();
        $mock->shouldReceive('isExecDisabled')->andReturn(false);
        $mock->shouldReceive('isDumpUtilityAvailable')->andReturn(true);
        $mock->shouldReceive('dump')->andReturnUsing(function ($conn, $targetPath) {
            $gz = gzopen($targetPath, 'wb9');
            gzwrite($gz, 'DUMMY SQL DATA');
            gzclose($gz);
            return $targetPath;
        });

        $this->app->instance(MySqlDumpService::class, $mock);
        $this->app['config']->set('tigerpos-backup.s3.bucket', '');

        $this->artisan('tigerpos:backup')
            ->expectsOutputToContain('S3 bucket is not configured')
            ->assertFailed();
    }

    public function test_command_succeeds_with_s3_upload(): void
    {
        $dumpMock = Mockery::mock(MySqlDumpService::class)->makePartial();
        $dumpMock->shouldReceive('isExecDisabled')->andReturn(false);
        $dumpMock->shouldReceive('isDumpUtilityAvailable')->andReturn(true);
        $dumpMock->shouldReceive('dump')->andReturnUsing(function ($conn, $targetPath) {
            $gz = gzopen($targetPath, 'wb9');
            gzwrite($gz, 'DUMMY SQL DATA');
            gzclose($gz);
            return $targetPath;
        });

        $s3Mock = Mockery::mock(\Tiger\PosBackup\Services\S3BackupUploader::class);
        $s3Mock->shouldReceive('upload')->andReturn([
            'key' => 'pos-backups/test-pos-01/database/pos_backup_test.sql.gz',
            'bucket' => 'my-test-bucket',
            'size' => 1024,
            'sha256' => 'mockedsha256hash',
        ]);

        $this->app->instance(MySqlDumpService::class, $dumpMock);
        $this->app->instance(\Tiger\PosBackup\Services\S3BackupUploader::class, $s3Mock);
        $this->app['config']->set('tigerpos-backup.s3.bucket', 'my-test-bucket');

        $this->artisan('tigerpos:backup')
            ->expectsOutputToContain('Backup successfully uploaded and verified in AWS S3.')
            ->expectsOutputToContain('S3 Key: pos-backups/test-pos-01/database/pos_backup_test.sql.gz')
            ->expectsOutputToContain('SHA-256: mockedsha256hash')
            ->assertSuccessful();
    }

    public function test_command_fails_and_preserves_local_archive_when_s3_fails(): void
    {
        $dumpMock = Mockery::mock(MySqlDumpService::class)->makePartial();
        $dumpMock->shouldReceive('isExecDisabled')->andReturn(false);
        $dumpMock->shouldReceive('isDumpUtilityAvailable')->andReturn(true);
        $dumpMock->shouldReceive('dump')->andReturnUsing(function ($conn, $targetPath) {
            $gz = gzopen($targetPath, 'wb9');
            gzwrite($gz, 'DUMMY SQL DATA');
            gzclose($gz);
            return $targetPath;
        });

        $s3Mock = Mockery::mock(\Tiger\PosBackup\Services\S3BackupUploader::class);
        $s3Mock->shouldReceive('upload')->andThrow(new RuntimeException('Network timeout connecting to S3'));

        $this->app->instance(MySqlDumpService::class, $dumpMock);
        $this->app->instance(\Tiger\PosBackup\Services\S3BackupUploader::class, $s3Mock);
        $this->app['config']->set('tigerpos-backup.s3.bucket', 'my-test-bucket');

        $this->artisan('tigerpos:backup')
            ->expectsOutputToContain('AWS S3 upload failed: Network timeout connecting to S3')
            ->expectsOutputToContain('Local archive preserved at:')
            ->assertFailed();
    }
}
