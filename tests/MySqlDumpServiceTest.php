<?php

namespace Tiger\PosBackup\Tests;

use RuntimeException;
use Tiger\PosBackup\Services\MySqlDumpService;

class MySqlDumpServiceTest extends TestCase
{
    protected MySqlDumpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MySqlDumpService();
    }

    public function test_validate_driver_accepts_mysql_and_mariadb(): void
    {
        $this->expectNotToPerformAssertions();
        $this->service->validateDriver(['driver' => 'mysql']);
        $this->service->validateDriver(['driver' => 'mariadb']);
        $this->service->validateDriver(['driver' => 'MySQL']);
    }

    public function test_validate_driver_rejects_unsupported_driver(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported database driver [sqlite]');

        $this->service->validateDriver(['driver' => 'sqlite']);
    }

    public function test_generate_filename_formats_correctly(): void
    {
        $filename = $this->service->generateFilename('pos#99 branch/1', 1700000000);
        $this->assertStringStartsWith('pos_backup_pos_99_branch_1_', $filename);
        $this->assertStringEndsWith('.sql.gz', $filename);
    }

    public function test_ensure_temp_directory_creates_and_returns_path(): void
    {
        $target = sys_get_temp_dir() . '/tiger_dir_test_' . uniqid();
        $this->assertDirectoryDoesNotExist($target);

        $resolved = $this->service->ensureTempDirectory($target);
        $this->assertDirectoryExists($resolved);
        $this->assertEquals($target, $resolved);

        @rmdir($target);
    }

    public function test_verify_gzip_archive_rejects_non_existent_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup dump file was not generated');

        $this->service->verifyGzipArchive('/non/existent/path/file.sql.gz');
    }

    public function test_verify_gzip_archive_rejects_empty_file(): void
    {
        $file = sys_get_temp_dir() . '/empty_test_' . uniqid() . '.sql.gz';
        file_put_contents($file, '');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('empty or corrupted');
            $this->service->verifyGzipArchive($file);
        } finally {
            @unlink($file);
        }
    }

    public function test_verify_gzip_archive_accepts_valid_gzip(): void
    {
        $file = sys_get_temp_dir() . '/valid_test_' . uniqid() . '.sql.gz';
        $gz = gzopen($file, 'wb9');
        gzwrite($gz, "-- Dump content\nCREATE TABLE test (id INT);");
        gzclose($gz);

        $this->expectNotToPerformAssertions();
        try {
            $this->service->verifyGzipArchive($file);
        } finally {
            @unlink($file);
        }
    }

    public function test_cleanup_file_removes_existing_file(): void
    {
        $file = sys_get_temp_dir() . '/cleanup_' . uniqid() . '.tmp';
        file_put_contents($file, 'test');
        $this->assertFileExists($file);

        $this->service->cleanupFile($file);
        $this->assertFileDoesNotExist($file);
    }

    public function test_dump_fails_and_cleans_up_when_dump_process_errors(): void
    {
        $fakeScript = sys_get_temp_dir() . '/fake_dump_fail_' . uniqid() . '.sh';
        file_put_contents($fakeScript, "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'fake dump v1.0'; exit 0; fi\necho 'Access denied for user' >&2\nexit 2\n");
        chmod($fakeScript, 0755);

        $targetOut = sys_get_temp_dir() . '/failed_dump_' . uniqid() . '.sql.gz';

        $connConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'sample_db',
            'username' => 'sample_user',
            'password' => 'secret_pass',
        ];

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('mysqldump failed with exit code 2');
            $this->service->dump($connConfig, $targetOut, $fakeScript);
        } finally {
            $this->assertFileDoesNotExist($targetOut);
            @unlink($fakeScript);
        }
    }

    public function test_dump_creates_valid_compressed_archive(): void
    {
        $fakeScript = sys_get_temp_dir() . '/fake_dump_success_' . uniqid() . '.sh';
        file_put_contents($fakeScript, "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'fake dump v1.0'; exit 0; fi\necho '-- Dump file header'\necho 'CREATE TABLE pos_orders (id INT);'\nexit 0\n");
        chmod($fakeScript, 0755);

        $targetOut = sys_get_temp_dir() . '/success_dump_' . uniqid() . '.sql.gz';

        $connConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'sample_db',
            'username' => 'sample_user',
            'password' => 'secret_pass',
        ];

        try {
            $result = $this->service->dump($connConfig, $targetOut, $fakeScript);
            $this->assertEquals($targetOut, $result);
            $this->assertFileExists($targetOut);
            $this->assertGreaterThan(20, filesize($targetOut));

            // Verify decompressed stream content
            $gz = gzopen($targetOut, 'rb');
            $decompressed = gzread($gz, 4096);
            gzclose($gz);

            $this->assertStringContainsString('CREATE TABLE pos_orders', $decompressed);
        } finally {
            $this->service->cleanupFile($targetOut);
            @unlink($fakeScript);
        }
    }

    public function test_options_file_is_first_argument_passed_to_dump(): void
    {
        $logFile = sys_get_temp_dir() . '/dump_args_' . uniqid() . '.log';
        $fakeScript = sys_get_temp_dir() . '/fake_dump_args_' . uniqid() . '.sh';
        file_put_contents($fakeScript, "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'fake dump v1.0'; exit 0; fi\necho \"\$1\" > " . escapeshellarg($logFile) . "\necho 'CREATE TABLE test (id INT);'\nexit 0\n");
        chmod($fakeScript, 0755);

        $targetOut = sys_get_temp_dir() . '/args_test_' . uniqid() . '.sql.gz';
        $connConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'sample_db',
            'username' => 'sample_user',
            'password' => 'secret_pass',
        ];

        try {
            $this->service->dump($connConfig, $targetOut, $fakeScript);
            $firstArg = trim((string) file_get_contents($logFile));
            $this->assertStringStartsWith('--defaults-extra-file=', $firstArg);
        } finally {
            $this->service->cleanupFile($targetOut);
            @unlink($logFile);
            @unlink($fakeScript);
        }
    }

    public function test_dump_fails_when_gzip_process_fails(): void
    {
        $dumpScript = sys_get_temp_dir() . '/dump_script_' . uniqid() . '.sh';
        file_put_contents($dumpScript, "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo 'fake dump v1.0'; exit 0; fi\necho 'CREATE TABLE test (id INT);'\nexit 0\n");
        chmod($dumpScript, 0755);

        $failGzipScript = sys_get_temp_dir() . '/fail_gzip_' . uniqid() . '.sh';
        file_put_contents($failGzipScript, "#!/bin/sh\necho 'gzip disk error' >&2\nexit 1\n");
        chmod($failGzipScript, 0755);

        $targetOut = sys_get_temp_dir() . '/fail_gzip_out_' . uniqid() . '.sql.gz';
        $connConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'sample_db',
            'username' => 'sample_user',
            'password' => 'secret_pass',
        ];

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('gzip compression failed with exit code 1');
            $this->service->dump($connConfig, $targetOut, $dumpScript, $failGzipScript);
        } finally {
            $this->assertFileDoesNotExist($targetOut);
            @unlink($dumpScript);
            @unlink($failGzipScript);
        }
    }

    public function test_verify_gzip_archive_rejects_truncated_stream(): void
    {
        $file = sys_get_temp_dir() . '/truncated_' . uniqid() . '.sql.gz';
        $gz = gzopen($file, 'wb9');
        gzwrite($gz, "-- Header\nCREATE TABLE large_table (id INT);\n" . str_repeat('INSERT INTO large_table VALUES (1);' . PHP_EOL, 200));
        gzclose($gz);

        $raw = file_get_contents($file);
        // Truncate bytes in the middle of gzip stream
        file_put_contents($file, substr($raw, 0, (int) (strlen($raw) / 2)));

        try {
            $this->expectException(RuntimeException::class);
            $this->service->verifyGzipArchive($file);
        } finally {
            @unlink($file);
        }
    }

    public function test_ensure_temp_directory_rejects_public_web_root(): void
    {
        $publicDir = sys_get_temp_dir() . '/test_public_root_' . uniqid();
        @mkdir($publicDir, 0755, true);
        $subTemp = $publicDir . '/backups';
         if (method_exists($this->app, 'usePublicPath')) {
            $this->app->usePublicPath($publicDir);
        } else {
            $this->app->instance('path.public', $publicDir);
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cannot be inside public web root');
            $this->service->ensureTempDirectory($subTemp);
        } finally {
            @rmdir($subTemp);
            @rmdir($publicDir);
        }
    }
}
