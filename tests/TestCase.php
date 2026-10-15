<?php

namespace Tiger\PosBackup\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Tiger\PosBackup\TigerPosBackupServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            TigerPosBackupServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('tigerpos-backup.enabled', true);
        $app['config']->set('tigerpos-backup.pos_id', 'test-pos-01');
        $app['config']->set('tigerpos-backup.temp_dir', sys_get_temp_dir() . '/tigerpos-tests');

        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'pos_test_db',
            'username' => 'pos_user',
            'password' => 'secret_db_pass',
            'charset' => 'utf8mb4',
        ]);
    }

    protected function tearDown(): void
    {
        $testDir = sys_get_temp_dir() . '/tigerpos-tests';
        if (is_dir($testDir)) {
            $files = glob($testDir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($testDir);
        }

        parent::tearDown();
    }
}
