<?php

namespace Tiger\PosBackup\Console;

use Illuminate\Console\Command;
use Tiger\PosBackup\Services\MySqlDumpService;
use Throwable;

class BackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tigerpos:backup {--connection= : Specify database connection to backup} {--local-only : Skip AWS S3 upload and retain local backup only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform MySQL database backup and upload to AWS S3 for TigerPOS application';

    /**
     * @var MySqlDumpService
     */
    protected $dumpService;

    /**
     * @var \Tiger\PosBackup\Services\S3BackupUploader
     */
    protected $s3Uploader;

    public function __construct(
        MySqlDumpService $dumpService,
        \Tiger\PosBackup\Services\S3BackupUploader $s3Uploader
    ) {
        parent::__construct();
        $this->dumpService = $dumpService;
        $this->s3Uploader = $s3Uploader;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $enabled = config('tigerpos-backup.enabled', true);
        if (!$enabled) {
            $this->warn('TigerPOS backup is disabled via configuration (tigerpos-backup.enabled).');
            return Command::SUCCESS;
        }

        $connectionName = $this->option('connection') ?: config('database.default');
        $connectionConfig = config("database.connections.{$connectionName}");

        if (!is_array($connectionConfig)) {
            $this->error("Database connection [{$connectionName}] is not properly configured.");
            return Command::FAILURE;
        }

        // Validate driver
        try {
            $this->dumpService->validateDriver($connectionConfig);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        // Check mysqldump utility and process execution capability
        if ($this->dumpService->isExecDisabled()) {
            $this->error('Process execution functions (proc_open) are disabled by host configuration.');
            return Command::FAILURE;
        }

        if (!$this->dumpService->isDumpUtilityAvailable()) {
            $this->error("The 'mysqldump' utility is not installed, not found in PATH, or not executable.");
            return Command::FAILURE;
        }

        // Prepare destination temporary directory
        $tempDir = config('tigerpos-backup.temp_dir', storage_path('app/backup-temp'));
        try {
            $resolvedTempDir = $this->dumpService->ensureTempDirectory($tempDir);
        } catch (Throwable $e) {
            $this->error("Failed to prepare backup directory: " . $e->getMessage());
            return Command::FAILURE;
        }

        $posId = (string) config('tigerpos-backup.pos_id', 'pos-default');
        $filename = $this->dumpService->generateFilename($posId);
        $targetPath = $resolvedTempDir . DIRECTORY_SEPARATOR . $filename;

        $this->info("Initiating TigerPOS database backup for POS [{$posId}]...");

        try {
            $this->dumpService->dump($connectionConfig, $targetPath);
            $sizeBytes = filesize($targetPath);
            $formattedSize = $this->formatBytes($sizeBytes ?: 0);

            $this->info("Database backup created successfully.");
            $this->line("Local Archive: {$targetPath}");
            $this->line("Size: {$formattedSize} ({$sizeBytes} bytes)");
        } catch (Throwable $e) {
            $this->error("Backup failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        if ($this->option('local-only')) {
            $this->info("Skipping S3 upload (--local-only specified).");
            return Command::SUCCESS;
        }

        // Proceed to secure Amazon S3 upload
        $s3Config = config('tigerpos-backup.s3', []);
        $bucket = (string) ($s3Config['bucket'] ?? '');

        if ($bucket === '') {
            $this->error("S3 upload failed: S3 bucket is not configured (tigerpos-backup.s3.bucket).");
            return Command::FAILURE;
        }

        $this->info("Uploading backup to AWS S3 bucket [{$bucket}]...");

        try {
            $uploadResult = $this->s3Uploader->upload($targetPath, $posId, $s3Config);
            $this->info("Backup successfully uploaded and verified in AWS S3.");
            $this->line("S3 Key: {$uploadResult['key']}");
            $this->line("Bucket: {$uploadResult['bucket']}");
            $this->line("SHA-256: {$uploadResult['sha256']}");

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error("AWS S3 upload failed: " . $e->getMessage());
            $this->warn("Local archive preserved at: {$targetPath}");
            return Command::FAILURE;
        }
    }

    /**
     * Format bytes to human readable format.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
