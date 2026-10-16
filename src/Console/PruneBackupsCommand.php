<?php

namespace Tiger\PosBackup\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Tiger\PosBackup\Services\BackupPrunerService;
use Throwable;

class PruneBackupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tigerpos:prune-backups {--execute : Actually delete expired backup archives instead of running a dry-run} {--local-only : Prune local archives only and skip S3 pruning}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune expired local and Amazon S3 backups for TigerPOS application';

    /**
     * @var BackupPrunerService
     */
    protected $prunerService;

    public function __construct(BackupPrunerService $prunerService)
    {
        parent::__construct();
        $this->prunerService = $prunerService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $enabled = config('tigerpos-backup.enabled', true);
        if (!$enabled) {
            $this->warn('TigerPOS backup is disabled via configuration (tigerpos-backup.enabled). Pruning skipped.');
            return Command::SUCCESS;
        }

        $posId = (string) config('tigerpos-backup.pos_id', 'pos-default');

        // Prevent concurrent pruning runs using a non-blocking process file lock
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tigerpos_prune_' . md5($posId) . '.lock';
        $lockHandle = @fopen($lockFile, 'c+');

        if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->warn("Another pruning operation is currently running for POS [{$posId}]. Skipping.");
            if (is_resource($lockHandle)) {
                @fclose($lockHandle);
            }
            return Command::SUCCESS;
        }

        try {
            return $this->executePruning($posId);
        } finally {
            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        }
    }

    /**
     * Execute the main pruning flow under concurrency lock.
     */
    protected function executePruning(string $posId): int
    {
        $rawRetentionDays = config('tigerpos-backup.retention_days', 30);

        try {
            $retentionDays = $this->prunerService->validateRetentionDays($rawRetentionDays);
        } catch (InvalidArgumentException $e) {
            $this->error("Pruning configuration error: " . $e->getMessage());
            return Command::FAILURE;
        }

        $cutoff = $this->prunerService->calculateCutoffTimestamp($retentionDays);
        $cutoffUtc = (new DateTimeImmutable('@' . $cutoff))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s \U\T\C');

        $isExecute = (bool) $this->option('execute');
        $localOnly = (bool) $this->option('local-only');

        $this->line("=================================================");
        $this->line("TigerPOS Backup Retention & Pruning");
        $this->line("POS Identifier : {$posId}");
        $this->line("Retention Period: {$retentionDays} day(s)");
        $this->line("Cutoff Timestamp: {$cutoffUtc}");
        if ($isExecute) {
            $this->warn("Execution Mode : DESTRUCTIVE (--execute specified)");
        } else {
            $this->info("Execution Mode : DRY-RUN (simulation only)");
            $this->line("No files or remote objects will be deleted.");
            $this->line("Run with --execute to perform actual deletion.");
        }
        $this->line("=================================================");

        $hasFailure = false;

        // 1. Local backup pruning
        $tempDir = (string) config('tigerpos-backup.temp_dir', storage_path('app/backup-temp'));
        $this->line('');
        $this->info("--- Local Backup Pruning ---");
        $this->line("Directory: {$tempDir}");

        try {
            $localResult = $this->prunerService->pruneLocal($tempDir, $posId, $retentionDays, $isExecute);

            if ($isExecute) {
                $deletedCount = count($localResult['deleted']);
                $failedCount = count($localResult['failed']);

                $this->info("Local Pruning Results: {$deletedCount} deleted, {$failedCount} failed.");
                foreach ($localResult['deleted'] as $file) {
                    $this->line("  [DELETED] " . basename($file));
                }
                foreach ($localResult['failed'] as $file) {
                    $this->error("  [FAILED]  " . basename($file));
                    $hasFailure = true;
                }
            } else {
                $eligibleCount = count($localResult['eligible']);
                $this->info("Local Candidates: {$eligibleCount} archive(s) eligible for pruning.");
                foreach ($localResult['eligible'] as $file) {
                    $this->line("  [WOULD DELETE] " . basename($file));
                }
            }
        } catch (Throwable $e) {
            $this->error("Local pruning failed: " . $e->getMessage());
            $hasFailure = true;
        }

        // 2. Amazon S3 pruning (unless --local-only specified)
        if ($localOnly) {
            $this->line('');
            $this->info("Skipping Amazon S3 pruning (--local-only specified).");
        } else {
            $s3Config = config('tigerpos-backup.s3', []);
            $bucket = (string) ($s3Config['bucket'] ?? '');

            $this->line('');
            $this->info("--- Amazon S3 Backup Pruning ---");

            if ($bucket === '') {
                $this->warn("S3 bucket is not configured (tigerpos-backup.s3.bucket). Skipping remote pruning.");
            } else {
                $this->line("Bucket: {$bucket}");

                try {
                    $s3Result = $this->prunerService->pruneS3($s3Config, $posId, $retentionDays, $isExecute);

                    if ($isExecute) {
                        $deletedCount = count($s3Result['deleted']);
                        $failedCount = count($s3Result['failed']);

                        $this->info("S3 Pruning Results: {$deletedCount} deleted, {$failedCount} failed.");
                        foreach ($s3Result['deleted'] as $key) {
                            $this->line("  [DELETED] " . $key);
                        }
                        foreach ($s3Result['failed'] as $key) {
                            $this->error("  [FAILED]  " . $key);
                            $hasFailure = true;
                        }
                    } else {
                        $eligibleCount = count($s3Result['eligible']);
                        $this->info("S3 Candidates: {$eligibleCount} object(s) eligible for pruning.");
                        foreach ($s3Result['eligible'] as $key) {
                            $this->line("  [WOULD DELETE] " . $key);
                        }
                    }
                } catch (Throwable $e) {
                    $this->error("S3 pruning encountered an error: " . $e->getMessage());
                    $hasFailure = true;
                }
            }
        }

        $this->line('');
        if ($hasFailure) {
            $this->error("Pruning finished with errors.");
            return Command::FAILURE;
        }

        $this->info("Pruning finished successfully.");
        return Command::SUCCESS;
    }
}
