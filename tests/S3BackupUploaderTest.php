<?php

namespace Tiger\PosBackup\Tests;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use RuntimeException;
use Tiger\PosBackup\Services\S3BackupUploader;

class S3BackupUploaderTest extends TestCase
{
    protected S3BackupUploader $uploader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploader = new S3BackupUploader();
    }

    public function test_build_object_key_generates_isolated_path(): void
    {
        $key = $this->uploader->buildObjectKey('pos-backups', 'store#01 / branch 2', 'pos_backup_store_01_20261015.sql.gz');
        $this->assertEquals('pos-backups/store_01_branch_2/database/pos_backup_store_01_20261015.sql.gz', $key);
    }

    public function test_build_object_key_prevents_path_traversal(): void
    {
        $key = $this->uploader->buildObjectKey('../../../malicious/prefix/..', '../../pos-123', 'pos_backup_123.sql.gz');
        $this->assertEquals('malicious/prefix/pos-123/database/pos_backup_123.sql.gz', $key);
    }

    public function test_build_object_key_rejects_non_gz_files(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Must be a .sql.gz archive');

        $this->uploader->buildObjectKey('prefix', 'pos-1', 'malicious.php');
    }

    public function test_validate_local_file_rejects_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup file does not exist');

        $this->uploader->validateLocalFile('/tmp/non_existent_file.sql.gz');
    }

    public function test_validate_local_file_rejects_empty_file(): void
    {
        $file = sys_get_temp_dir() . '/empty_' . uniqid() . '.sql.gz';
        file_put_contents($file, '');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('empty or corrupted');
            $this->uploader->validateLocalFile($file);
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_fails_when_bucket_missing(): void
    {
        $file = sys_get_temp_dir() . '/test_' . uniqid() . '.sql.gz';
        file_put_contents($file, str_repeat('X', 50));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('S3 bucket name is not configured');

            $this->uploader->upload($file, 'pos-01', ['bucket' => '']);
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_successful_with_server_side_encryption_and_verification(): void
    {
        $file = sys_get_temp_dir() . '/upload_test_' . uniqid() . '.sql.gz';
        $content = '-- MySQL Dump Content -- ' . str_repeat('1234567890', 20);
        file_put_contents($file, $content);
        $fileSize = filesize($file);
        $expectedSha256 = hash_file('sha256', $file);

        $mock = new MockHandler();
        // 1. PutObject response
        $mock->append(new Result(['ETag' => '"dummy-etag"']));
        // 2. HeadObject response for verification
        $mock->append(new Result([
            'ContentLength' => $fileSize,
            'Metadata' => [
                'sha256' => $expectedSha256,
                'pos-id' => 'pos_store_1',
            ],
            'ServerSideEncryption' => 'AES256',
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => [
                'key' => 'MOCK_KEY',
                'secret' => 'MOCK_SECRET',
            ],
        ]);

        $uploader = new S3BackupUploader($s3Client);

        try {
            $result = $uploader->upload($file, 'pos-store-1', [
                'bucket' => 'my-pos-backup-bucket',
                'prefix' => 'pos-backups',
            ]);

            $this->assertEquals('my-pos-backup-bucket', $result['bucket']);
            $this->assertEquals('pos-backups/pos-store-1/database/' . basename($file), $result['key']);
            $this->assertEquals($fileSize, $result['size']);
            $this->assertEquals($expectedSha256, $result['sha256']);
            $this->assertFileExists($file, 'Local backup file must remain after upload');

            $lastCommand = $mock->getLastCommand();
            $this->assertEquals('HeadObject', $lastCommand->getName());
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_with_kms_key(): void
    {
        $file = sys_get_temp_dir() . '/upload_kms_' . uniqid() . '.sql.gz';
        file_put_contents($file, str_repeat('KMS TEST CONTENT ', 10));
        $fileSize = filesize($file);
        $expectedSha256 = hash_file('sha256', $file);

        $mock = new MockHandler();
        $mock->append(function (CommandInterface $cmd) {
            $this->assertEquals('aws:kms', $cmd['ServerSideEncryption']);
            $this->assertEquals('arn:aws:kms:us-east-1:123456789012:key/test-key', $cmd['SSEKMSKeyId']);
            return new Result(['ETag' => '"kms-etag"']);
        });
        $mock->append(new Result([
            'ContentLength' => $fileSize,
            'Metadata' => ['sha256' => $expectedSha256],
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'K', 'secret' => 'S'],
        ]);

        $uploader = new S3BackupUploader($s3Client);

        try {
            $result = $uploader->upload($file, 'pos-kms', [
                'bucket' => 'kms-bucket',
                'kms_key_id' => 'arn:aws:kms:us-east-1:123456789012:key/test-key',
            ]);

            $this->assertEquals('kms-bucket', $result['bucket']);
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_fails_when_aws_rejects_put(): void
    {
        $file = sys_get_temp_dir() . '/upload_err_' . uniqid() . '.sql.gz';
        file_put_contents($file, str_repeat('ERROR DATA ', 10));

        $mock = new MockHandler();
        $mock->append(new AwsException(
            'Access Denied to S3 bucket',
            new \Aws\Command('PutObject')
        ));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'K', 'secret' => 'S'],
        ]);

        $uploader = new S3BackupUploader($s3Client);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('AWS S3 upload failed');

            $uploader->upload($file, 'pos-err', ['bucket' => 'forbidden-bucket']);
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_omits_acl_and_sends_checksum_algorithm(): void
    {
        $file = sys_get_temp_dir() . '/upload_no_acl_' . uniqid() . '.sql.gz';
        file_put_contents($file, str_repeat('DATA ', 50));
        $fileSize = filesize($file);
        $expectedSha256 = hash_file('sha256', $file);

        $mock = new MockHandler();
        $mock->append(function (CommandInterface $cmd) {
            $this->assertNull($cmd['ACL'], 'ACL must be omitted to support Object Ownership: Bucket owner enforced');
            $this->assertEquals('SHA256', $cmd['ChecksumAlgorithm']);
            return new Result(['ETag' => '"dummy-etag"']);
        });
        $mock->append(new Result([
            'ContentLength' => $fileSize,
            'ChecksumSHA256' => base64_encode(hex2bin($expectedSha256) ?: ''),
            'Metadata' => ['sha256' => $expectedSha256],
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'K', 'secret' => 'S'],
        ]);

        $uploader = new S3BackupUploader($s3Client);

        try {
            $result = $uploader->upload($file, 'pos-no-acl', ['bucket' => 'no-acl-bucket']);
            $this->assertEquals('no-acl-bucket', $result['bucket']);
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_fails_when_native_s3_checksum_sha256_mismatches(): void
    {
        $file = sys_get_temp_dir() . '/upload_chk_mismatch_' . uniqid() . '.sql.gz';
        file_put_contents($file, str_repeat('DATA ', 50));
        $fileSize = filesize($file);
        $expectedSha256 = hash_file('sha256', $file);

        $mock = new MockHandler();
        $mock->append(new Result(['ETag' => '"etag"']));
        $mock->append(new Result([
            'ContentLength' => $fileSize,
            'ChecksumSHA256' => base64_encode(str_repeat('A', 32)), // Corrupted remote checksum
            'Metadata' => ['sha256' => $expectedSha256],
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'K', 'secret' => 'S'],
        ]);

        $uploader = new S3BackupUploader($s3Client);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Remote object SHA-256 checksum mismatch');

            $uploader->upload($file, 'pos-chk-mismatch', ['bucket' => 'test-bucket']);
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_fails_when_metadata_sha256_missing(): void
    {
        $file = sys_get_temp_dir() . '/upload_meta_missing_' . uniqid() . '.sql.gz';
        file_put_contents($file, str_repeat('DATA ', 50));
        $fileSize = filesize($file);

        $mock = new MockHandler();
        $mock->append(new Result(['ETag' => '"etag"']));
        $mock->append(new Result([
            'ContentLength' => $fileSize,
            'Metadata' => [], // Missing sha256
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'K', 'secret' => 'S'],
        ]);

        $uploader = new S3BackupUploader($s3Client);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('missing expected sha256 metadata');

            $uploader->upload($file, 'pos-meta-missing', ['bucket' => 'test-bucket']);
        } finally {
            @unlink($file);
        }
    }

    public function test_upload_fails_when_metadata_sha256_mismatches(): void
    {
        $file = sys_get_temp_dir() . '/upload_meta_mismatch_' . uniqid() . '.sql.gz';
        file_put_contents($file, str_repeat('DATA ', 50));
        $fileSize = filesize($file);

        $mock = new MockHandler();
        $mock->append(new Result(['ETag' => '"etag"']));
        $mock->append(new Result([
            'ContentLength' => $fileSize,
            'Metadata' => ['sha256' => 'tampered_hash_value'],
        ]));

        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'handler' => $mock,
            'credentials' => ['key' => 'K', 'secret' => 'S'],
        ]);

        $uploader = new S3BackupUploader($s3Client);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Remote object SHA-256 metadata mismatch');

            $uploader->upload($file, 'pos-meta-mismatch', ['bucket' => 'test-bucket']);
        } finally {
            @unlink($file);
        }
    }
}
