<?php

namespace Tiger\PosBackup\Tests;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use Tiger\PosBackup\Services\BackupPrunerService;
use Tiger\PosBackup\Services\S3BackupUploader;

class PruneBackupsCommandTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/tigerpos_cmd_prune_' . uniqid();
        @mkdir($this->tempDir, 0777, true);
        config(['tigerpos-backup.temp_dir' => $this->tempDir]);
        config(['tigerpos-backup.pos_id' => 'test-pos-01']);
        config(['tigerpos-backup.retention_days' => 30]);
    }

    protected function tearDown(): void
    {
        $this->cleanupDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_link($path) || is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    $this->cleanupDirectory($path);
                }
            }
        }
        @rmdir($dir);
    }

    public function test_prune_command_exits_cleanly_when_global_backup_disabled(): void
    {
        config(['tigerpos-backup.enabled' => false]);

        $this->artisan('tigerpos:prune-backups')
            ->expectsOutputToContain('TigerPOS backup is disabled via configuration')
            ->assertSuccessful();
    }

    public function test_prune_command_dry_run_is_default_and_deletes_nothing(): void
    {
        $now = time();
        $expiredDate = date('Ymd_His', $now - (40 * 86400));
        $file = $this->tempDir . "/pos_backup_test-pos-01_{$expiredDate}.sql.gz";
        file_put_contents($file, 'test backup data');
        touch($file, $now - (40 * 86400));

        config(['tigerpos-backup.s3.bucket' => '']); // skip s3

        $this->artisan('tigerpos:prune-backups')
            ->expectsOutputToContain('Execution Mode : DRY-RUN (simulation only)')
            ->expectsOutputToContain('[WOULD DELETE] ' . basename($file))
            ->assertSuccessful();

        $this->assertFileExists($file);
    }

    public function test_prune_command_execute_mode_deletes_expired_local_archive(): void
    {
        $now = time();
        $expiredDate = date('Ymd_His', $now - (40 * 86400));
        $file = $this->tempDir . "/pos_backup_test-pos-01_{$expiredDate}.sql.gz";
        file_put_contents($file, 'test backup data');
        touch($file, $now - (40 * 86400));

        $this->artisan('tigerpos:prune-backups', ['--execute' => true, '--local-only' => true])
            ->expectsOutputToContain('Execution Mode : DESTRUCTIVE (--execute specified)')
            ->expectsOutputToContain('[DELETED] ' . basename($file))
            ->assertSuccessful();

        $this->assertFileDoesNotExist($file);
    }

    public function test_prune_command_fails_safely_when_retention_days_invalid(): void
    {
        config(['tigerpos-backup.retention_days' => 0]);

        $this->artisan('tigerpos:prune-backups')
            ->expectsOutputToContain('Pruning configuration error: Retention days must be a positive whole integer')
            ->assertFailed();
    }

    public function test_prune_command_fails_safely_when_retention_days_fractional(): void
    {
        config(['tigerpos-backup.retention_days' => '3.5']);

        $this->artisan('tigerpos:prune-backups')
            ->expectsOutputToContain('Pruning configuration error: Retention days cannot be a fractional value.')
            ->assertFailed();
    }

    public function test_prune_command_prevents_concurrent_runs_with_lockfile(): void
    {
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tigerpos_prune_' . md5('test-pos-01') . '.lock';
        $fp = fopen($lockFile, 'c+');
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        try {
            $this->artisan('tigerpos:prune-backups')
                ->expectsOutputToContain('Another pruning operation is currently running')
                ->assertSuccessful();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFile);
        }
    }

    public function test_prune_command_handles_s3_pruning_with_mock(): void
    {
        $now = 1700000000;
        $mock = new MockHandler();

        $prefix = 'pos-backups/test-pos-01/database/';
        $expiredKey = $prefix . 'pos_backup_test-pos-01_20231001_120000.sql.gz';

        // 1. listObjectsV2 response
        $mock->append(new Result([
            'IsTruncated' => false,
            'Contents' => [
                [
                    'Key' => $expiredKey,
                    'LastModified' => new DateTimeImmutable('@' . ($now - (45 * 86400))),
                ],
            ],
        ]));

        // 2. deleteObject response
        $mock->append(new Result([]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'MOCK_KEY', 'secret' => 'MOCK_SECRET'],
        ]);

        $uploader = new S3BackupUploader($s3Client);
        $pruner = new BackupPrunerService($uploader);
        $this->app->instance(BackupPrunerService::class, $pruner);

        config([
            'tigerpos-backup.s3.bucket' => 'test-bucket',
            'tigerpos-backup.s3.prefix' => 'pos-backups',
        ]);

        $this->artisan('tigerpos:prune-backups', ['--execute' => true])
            ->expectsOutputToContain('Execution Mode : DESTRUCTIVE (--execute specified)')
            ->expectsOutputToContain('[DELETED] ' . $expiredKey)
            ->expectsOutputToContain('Pruning finished successfully.')
            ->assertSuccessful();
    }

    public function test_prune_command_fails_safely_when_retention_days_is_null(): void
    {
        config(['tigerpos-backup.retention_days' => null]);

        $this->artisan('tigerpos:prune-backups')
            ->expectsOutputToContain('Pruning configuration error: Retention days must be a valid positive whole number.')
            ->assertFailed();
    }

    public function test_prune_command_fails_when_local_directory_missing(): void
    {
        config(['tigerpos-backup.temp_dir' => sys_get_temp_dir() . '/non_existent_dir_' . uniqid()]);

        $this->artisan('tigerpos:prune-backups')
            ->expectsOutputToContain('Local pruning failed: Configured local backup directory does not exist')
            ->expectsOutputToContain('Pruning finished with errors.')
            ->assertFailed();
    }

    public function test_prune_command_returns_failure_exit_code_when_s3_deletion_fails(): void
    {
        $now = 1700000000;
        $mock = new MockHandler();

        $prefix = 'pos-backups/test-pos-01/database/';
        $expiredKey = $prefix . 'pos_backup_test-pos-01_20231001_120000.sql.gz';

        $mock->append(new Result([
            'IsTruncated' => false,
            'Contents' => [
                [
                    'Key' => $expiredKey,
                    'LastModified' => new DateTimeImmutable('@' . ($now - (45 * 86400))),
                ],
            ],
        ]));

        $mock->append(function (\Aws\CommandInterface $cmd) {
            return new \Aws\Exception\AwsException('Access Denied', $cmd);
        });

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'MOCK_KEY', 'secret' => 'MOCK_SECRET'],
        ]);

        $uploader = new S3BackupUploader($s3Client);
        $pruner = new BackupPrunerService($uploader);
        $this->app->instance(BackupPrunerService::class, $pruner);

        config([
            'tigerpos-backup.s3.bucket' => 'test-bucket',
            'tigerpos-backup.s3.prefix' => 'pos-backups',
        ]);

        $this->artisan('tigerpos:prune-backups', ['--execute' => true])
            ->expectsOutputToContain('Execution Mode : DESTRUCTIVE (--execute specified)')
            ->expectsOutputToContain('[FAILED]  ' . $expiredKey)
            ->expectsOutputToContain('Pruning finished with errors.')
            ->assertFailed();
    }
}

