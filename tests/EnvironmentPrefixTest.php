<?php

namespace Tiger\PosBackup\Tests;

class EnvironmentPrefixTest extends TestCase
{
    protected array $trackedEnvKeys = [
        'POS_BACKUP_ENABLED',
        'POS_BACKUP_POS_ID',
        'POS_BACKUP_AWS_REGION',
        'POS_BACKUP_AWS_BUCKET',
        'POS_BACKUP_S3_PREFIX',
        'POS_BACKUP_AWS_KMS_KEY_ID',
        'POS_BACKUP_RETENTION_DAYS',
        'POS_BACKUP_TEMP_DIR',
        'POS_BACKUP_SCHEDULE_ENABLED',
        'POS_BACKUP_SCHEDULE_CRON',
        'POS_BACKUP_PRUNE_ENABLED',
        'POS_BACKUP_PRUNE_CRON',
        'APP_NAME',
        'AWS_DEFAULT_REGION',
        'AWS_REGION',
        'AWS_BUCKET',
    ];

    protected function tearDown(): void
    {
        foreach ($this->trackedEnvKeys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    protected function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    protected function loadConfigFile(): array
    {
        return require __DIR__ . '/../config/tigerpos-backup.php';
    }

    public function test_pos_backup_variables_are_recognized(): void
    {
        $this->setEnv('POS_BACKUP_ENABLED', 'true');
        $this->setEnv('POS_BACKUP_POS_ID', 'terminal-pos-042');
        $this->setEnv('POS_BACKUP_AWS_REGION', 'ap-southeast-1');
        $this->setEnv('POS_BACKUP_AWS_BUCKET', 'acme-pos-backups-bucket');
        $this->setEnv('POS_BACKUP_S3_PREFIX', 'stores/ap');
        $this->setEnv('POS_BACKUP_AWS_KMS_KEY_ID', 'arn:aws:kms:ap-southeast-1:123456789012:key/test-kms-id');
        $this->setEnv('POS_BACKUP_RETENTION_DAYS', '45');
        $this->setEnv('POS_BACKUP_TEMP_DIR', '/custom/backup/dir');
        $this->setEnv('POS_BACKUP_SCHEDULE_ENABLED', 'true');
        $this->setEnv('POS_BACKUP_SCHEDULE_CRON', '0 */4 * * *');
        $this->setEnv('POS_BACKUP_PRUNE_ENABLED', 'true');
        $this->setEnv('POS_BACKUP_PRUNE_CRON', '0 4 * * *');

        $config = $this->loadConfigFile();

        $this->assertTrue($config['enabled']);
        $this->assertEquals('terminal-pos-042', $config['pos_id']);
        $this->assertEquals('ap-southeast-1', $config['s3']['region']);
        $this->assertEquals('acme-pos-backups-bucket', $config['s3']['bucket']);
        $this->assertEquals('stores/ap', $config['s3']['prefix']);
        $this->assertEquals('arn:aws:kms:ap-southeast-1:123456789012:key/test-kms-id', $config['s3']['kms_key_id']);
        $this->assertSame(45, $config['retention_days']);
        $this->assertEquals('/custom/backup/dir', $config['temp_dir']);
        $this->assertTrue($config['schedule']['enabled']);
        $this->assertEquals('0 */4 * * *', $config['schedule']['cron']);
        $this->assertTrue($config['prune']['enabled']);
        $this->assertEquals('0 4 * * *', $config['prune']['cron']);
    }

    public function test_empty_optional_variables_preserve_default_fallbacks(): void
    {
        // Variables set to empty strings in environment
        $this->setEnv('POS_BACKUP_ENABLED', '');
        $this->setEnv('POS_BACKUP_POS_ID', '');
        $this->setEnv('APP_NAME', '');

        $this->setEnv('POS_BACKUP_AWS_REGION', '');
        $this->setEnv('AWS_DEFAULT_REGION', '');
        $this->setEnv('AWS_REGION', '');

        $this->setEnv('POS_BACKUP_AWS_BUCKET', '');
        $this->setEnv('AWS_BUCKET', '');

        $this->setEnv('POS_BACKUP_S3_PREFIX', '');
        $this->setEnv('POS_BACKUP_AWS_KMS_KEY_ID', '');
        $this->setEnv('POS_BACKUP_RETENTION_DAYS', '');
        $this->setEnv('POS_BACKUP_TEMP_DIR', '');
        $this->setEnv('POS_BACKUP_SCHEDULE_ENABLED', '');
        $this->setEnv('POS_BACKUP_SCHEDULE_CRON', '');
        $this->setEnv('POS_BACKUP_PRUNE_ENABLED', '');
        $this->setEnv('POS_BACKUP_PRUNE_CRON', '');

        $config = $this->loadConfigFile();

        $this->assertTrue($config['enabled']);
        $this->assertEquals('pos-default', $config['pos_id']);
        $this->assertEquals('us-east-1', $config['s3']['region']);
        $this->assertEquals('', $config['s3']['bucket']);
        $this->assertEquals('pos-backups', $config['s3']['prefix']);
        $this->assertNull($config['s3']['kms_key_id']);
        $this->assertSame(30, $config['retention_days']);
        $this->assertEquals(storage_path('app/backup-temp'), $config['temp_dir']);
        $this->assertTrue($config['schedule']['enabled']);
        $this->assertEquals('0 */6 * * *', $config['schedule']['cron']);
        $this->assertFalse($config['prune']['enabled']);
        $this->assertEquals('30 2 * * *', $config['prune']['cron']);
    }

    public function test_standard_laravel_and_aws_fallbacks(): void
    {
        // When POS-specific variables are omitted, standard AWS and Laravel envs take effect
        $this->setEnv('APP_NAME', 'PointOfSale-Main');
        $this->setEnv('AWS_DEFAULT_REGION', 'sa-east-1');
        $this->setEnv('AWS_BUCKET', 'standard-aws-pos-bucket');

        $config = $this->loadConfigFile();

        $this->assertEquals('PointOfSale-Main', $config['pos_id']);
        $this->assertEquals('sa-east-1', $config['s3']['region']);
        $this->assertEquals('standard-aws-pos-bucket', $config['s3']['bucket']);
    }

    public function test_boolean_casting_and_disabling(): void
    {
        $this->setEnv('POS_BACKUP_ENABLED', 'false');
        $this->setEnv('POS_BACKUP_SCHEDULE_ENABLED', '0');
        $this->setEnv('POS_BACKUP_PRUNE_ENABLED', 'true');

        $config = $this->loadConfigFile();

        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['schedule']['enabled']);
        $this->assertTrue($config['prune']['enabled']);
    }
}
