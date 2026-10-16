<?php

namespace Tiger\PosBackup\Tests;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Tiger\PosBackup\Services\BackupPrunerService;
use Tiger\PosBackup\Services\S3BackupUploader;

class BackupPrunerServiceTest extends TestCase
{
    protected BackupPrunerService $pruner;
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pruner = new BackupPrunerService();
        $this->testDir = sys_get_temp_dir() . '/tigerpos_prune_test_' . uniqid();
        @mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupDirectory($this->testDir);
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

    public function test_validate_retention_days_accepts_valid_integers(): void
    {
        $this->assertEquals(30, $this->pruner->validateRetentionDays(30));
        $this->assertEquals(7, $this->pruner->validateRetentionDays('7'));
        $this->assertEquals(1, $this->pruner->validateRetentionDays(1));
    }

    public function test_validate_retention_days_rejects_zero_or_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');
        $this->pruner->validateRetentionDays(0);
    }

    public function test_validate_retention_days_rejects_negative_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('greater than zero');
        $this->pruner->validateRetentionDays(-5);
    }

    public function test_validate_retention_days_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive integer');
        $this->pruner->validateRetentionDays('invalid');
    }

    public function test_calculate_cutoff_timestamp(): void
    {
        $now = 1700000000;
        $cutoff = $this->pruner->calculateCutoffTimestamp(10, $now);
        $this->assertEquals($now - (10 * 86400), $cutoff);
    }

    public function test_local_prune_dry_run_identifies_expired_archive_without_deleting(): void
    {
        $now = time();
        $expiredDate = date('Ymd_His', $now - (40 * 86400));
        $expiredFilename = "pos_backup_test-pos-01_{$expiredDate}.sql.gz";
        $expiredPath = $this->testDir . DIRECTORY_SEPARATOR . $expiredFilename;

        file_put_contents($expiredPath, 'test-backup-content');
        touch($expiredPath, $now - (40 * 86400));

        $result = $this->pruner->pruneLocal($this->testDir, 'test-pos-01', 30, false, $now);

        $this->assertCount(1, $result['eligible']);
        $this->assertEquals($expiredPath, $result['eligible'][0]);
        $this->assertEmpty($result['deleted']);
        $this->assertEmpty($result['failed']);
        $this->assertFileExists($expiredPath);
    }

    public function test_local_prune_execution_deletes_expired_archive(): void
    {
        $now = time();
        $expiredDate = date('Ymd_His', $now - (40 * 86400));
        $expiredFilename = "pos_backup_test-pos-01_{$expiredDate}.sql.gz";
        $expiredPath = $this->testDir . DIRECTORY_SEPARATOR . $expiredFilename;

        file_put_contents($expiredPath, 'test-backup-content');
        touch($expiredPath, $now - (40 * 86400));

        $result = $this->pruner->pruneLocal($this->testDir, 'test-pos-01', 30, true, $now);

        $this->assertCount(1, $result['deleted']);
        $this->assertEquals($expiredPath, $result['deleted'][0]);
        $this->assertEmpty($result['failed']);
        $this->assertFileDoesNotExist($expiredPath);
    }

    public function test_local_prune_preserves_recent_archives(): void
    {
        $now = time();
        $recentDate = date('Ymd_His', $now - (5 * 86400));
        $recentFilename = "pos_backup_test-pos-01_{$recentDate}.sql.gz";
        $recentPath = $this->testDir . DIRECTORY_SEPARATOR . $recentFilename;

        file_put_contents($recentPath, 'recent-content');
        touch($recentPath, $now - (5 * 86400));

        $result = $this->pruner->pruneLocal($this->testDir, 'test-pos-01', 30, true, $now);

        $this->assertEmpty($result['eligible']);
        $this->assertEmpty($result['deleted']);
        $this->assertFileExists($recentPath);
    }

    public function test_local_prune_preserves_unrelated_files_directories_and_other_pos_archives(): void
    {
        $now = time();
        $oldDate = date('Ymd_His', $now - (50 * 86400));

        // 1. Unrelated text file
        $txt = $this->testDir . '/readme.txt';
        file_put_contents($txt, 'do not delete');
        touch($txt, $now - (50 * 86400));

        // 2. Uncompressed temporary .sql file
        $sql = $this->testDir . '/temp.sql';
        file_put_contents($sql, 'dump data');
        touch($sql, $now - (50 * 86400));

        // 3. Subdirectory
        $subDir = $this->testDir . '/subfolder';
        @mkdir($subDir, 0777, true);

        // 4. Archive belonging to a DIFFERENT POS ID
        $otherPosFile = $this->testDir . "/pos_backup_other_pos_99_{$oldDate}.sql.gz";
        file_put_contents($otherPosFile, 'other pos data');
        touch($otherPosFile, $now - (50 * 86400));

        // 5. Malformed filename
        $malformed = $this->testDir . "/pos_backup_test_pos_01_badtimestamp.sql.gz";
        file_put_contents($malformed, 'malformed data');
        touch($malformed, $now - (50 * 86400));

        $result = $this->pruner->pruneLocal($this->testDir, 'test-pos-01', 30, true, $now);

        $this->assertEmpty($result['deleted']);
        $this->assertFileExists($txt);
        $this->assertFileExists($sql);
        $this->assertDirectoryExists($subDir);
        $this->assertFileExists($otherPosFile);
        $this->assertFileExists($malformed);
    }

    public function test_local_prune_never_follows_symlinks(): void
    {
        $outsideDir = sys_get_temp_dir() . '/tigerpos_outside_' . uniqid();
        @mkdir($outsideDir, 0777, true);

        $now = time();
        $oldDate = date('Ymd_His', $now - (50 * 86400));
        $realTargetFile = $outsideDir . "/pos_backup_test-pos-01_{$oldDate}.sql.gz";
        file_put_contents($realTargetFile, 'critical external file');
        touch($realTargetFile, $now - (50 * 86400));

        $symlinkPath = $this->testDir . "/pos_backup_test-pos-01_{$oldDate}.sql.gz";
        @symlink($realTargetFile, $symlinkPath);

        try {
            $result = $this->pruner->pruneLocal($this->testDir, 'test-pos-01', 30, true, $now);

            $this->assertEmpty($result['deleted']);
            $this->assertFileExists($realTargetFile);
            $this->assertTrue(is_link($symlinkPath));
        } finally {
            @unlink($symlinkPath);
            @unlink($realTargetFile);
            @rmdir($outsideDir);
        }
    }

    public function test_local_prune_handles_non_existent_directory_gracefully(): void
    {
        $nonExistent = $this->testDir . '/does_not_exist';
        $result = $this->pruner->pruneLocal($nonExistent, 'test-pos-01', 30, true);

        $this->assertEmpty($result['eligible']);
        $this->assertEmpty($result['deleted']);
        $this->assertEmpty($result['failed']);
    }

    public function test_s3_prune_dry_run_identifies_expired_objects_without_deleting(): void
    {
        $now = 1700000000;
        $mock = new MockHandler();

        $prefix = 'pos-backups/test-pos-01/database/';
        $expiredKey = $prefix . 'pos_backup_test-pos-01_20231001_120000.sql.gz';
        $recentKey = $prefix . 'pos_backup_test-pos-01_20231110_120000.sql.gz';

        $mock->append(new Result([
            'IsTruncated' => false,
            'Contents' => [
                [
                    'Key' => $expiredKey,
                    'LastModified' => new DateTimeImmutable('@' . ($now - (40 * 86400))),
                ],
                [
                    'Key' => $recentKey,
                    'LastModified' => new DateTimeImmutable('@' . ($now - (5 * 86400))),
                ],
            ],
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'MOCK_KEY', 'secret' => 'MOCK_SECRET'],
        ]);

        $result = $this->pruner->pruneS3(
            ['bucket' => 'test-bucket', 'prefix' => 'pos-backups'],
            'test-pos-01',
            30,
            false,
            $now,
            $s3Client
        );

        $this->assertCount(1, $result['eligible']);
        $this->assertEquals($expiredKey, $result['eligible'][0]);
        $this->assertEmpty($result['deleted']);
        $this->assertEmpty($result['failed']);
    }

    public function test_s3_prune_execution_deletes_expired_objects_with_pagination(): void
    {
        $now = 1700000000;
        $mock = new MockHandler();

        $prefix = 'pos-backups/test-pos-01/database/';
        $expiredKey1 = $prefix . 'pos_backup_test-pos-01_20230901_120000.sql.gz';
        $expiredKey2 = $prefix . 'pos_backup_test-pos-01_20230902_120000.sql.gz';

        // Page 1
        $mock->append(new Result([
            'IsTruncated' => true,
            'NextContinuationToken' => 'token-page-2',
            'Contents' => [
                [
                    'Key' => $expiredKey1,
                    'LastModified' => new DateTimeImmutable('@' . ($now - (60 * 86400))),
                ],
            ],
        ]));

        // Page 2
        $mock->append(new Result([
            'IsTruncated' => false,
            'Contents' => [
                [
                    'Key' => $expiredKey2,
                    'LastModified' => new DateTimeImmutable('@' . ($now - (50 * 86400))),
                ],
            ],
        ]));

        // Delete calls for both keys
        $mock->append(new Result([]));
        $mock->append(new Result([]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'MOCK_KEY', 'secret' => 'MOCK_SECRET'],
        ]);

        $result = $this->pruner->pruneS3(
            ['bucket' => 'test-bucket', 'prefix' => 'pos-backups'],
            'test-pos-01',
            30,
            true,
            $now,
            $s3Client
        );

        $this->assertCount(2, $result['deleted']);
        $this->assertEquals([$expiredKey1, $expiredKey2], $result['deleted']);
        $this->assertEmpty($result['failed']);
    }

    public function test_s3_prune_preserves_unrelated_and_other_pos_objects(): void
    {
        $now = 1700000000;
        $mock = new MockHandler();

        $prefix = 'pos-backups/test-pos-01/database/';
        $mock->append(new Result([
            'IsTruncated' => false,
            'Contents' => [
                [
                    // Unrelated file name in database prefix
                    'Key' => $prefix . 'manual_export.sql',
                    'LastModified' => new DateTimeImmutable('@' . ($now - (60 * 86400))),
                ],
                [
                    // Key not matching POS pattern
                    'Key' => $prefix . 'pos_backup_other_pos_20230901_120000.sql.gz',
                    'LastModified' => new DateTimeImmutable('@' . ($now - (60 * 86400))),
                ],
            ],
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'MOCK_KEY', 'secret' => 'MOCK_SECRET'],
        ]);

        $result = $this->pruner->pruneS3(
            ['bucket' => 'test-bucket', 'prefix' => 'pos-backups'],
            'test-pos-01',
            30,
            true,
            $now,
            $s3Client
        );

        $this->assertEmpty($result['eligible']);
        $this->assertEmpty($result['deleted']);
    }

    public function test_s3_prune_handles_deletion_failure_gracefully(): void
    {
        $now = 1700000000;
        $mock = new MockHandler();

        $prefix = 'pos-backups/test-pos-01/database/';
        $expiredKey = $prefix . 'pos_backup_test-pos-01_20230901_120000.sql.gz';

        $mock->append(new Result([
            'IsTruncated' => false,
            'Contents' => [
                [
                    'Key' => $expiredKey,
                    'LastModified' => new DateTimeImmutable('@' . ($now - (60 * 86400))),
                ],
            ],
        ]));

        // Simulate S3 delete failure (e.g. 403 AccessDenied)
        $mock->append(function (CommandInterface $cmd) {
            return new AwsException('Access Denied', $cmd);
        });

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'MOCK_KEY', 'secret' => 'MOCK_SECRET'],
        ]);

        $result = $this->pruner->pruneS3(
            ['bucket' => 'test-bucket', 'prefix' => 'pos-backups'],
            'test-pos-01',
            30,
            true,
            $now,
            $s3Client
        );

        $this->assertEmpty($result['deleted']);
        $this->assertCount(1, $result['failed']);
        $this->assertEquals($expiredKey, $result['failed'][0]);
    }

    public function test_s3_prune_handles_listing_failure(): void
    {
        $mock = new MockHandler();
        $mock->append(function (CommandInterface $cmd) {
            return new AwsException('Listing Forbidden', $cmd);
        });

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'MOCK_KEY', 'secret' => 'MOCK_SECRET'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to list S3 backup objects');

        $this->pruner->pruneS3(
            ['bucket' => 'test-bucket', 'prefix' => 'pos-backups'],
            'test-pos-01',
            30,
            false,
            null,
            $s3Client
        );
    }
}

