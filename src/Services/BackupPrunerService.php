<?php

namespace Tiger\PosBackup\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class BackupPrunerService
{
    /**
     * @var S3BackupUploader
     */
    protected $s3Uploader;

    public function __construct(?S3BackupUploader $s3Uploader = null)
    {
        $this->s3Uploader = $s3Uploader ?: new S3BackupUploader();
    }

    /**
     * Validate retention days configuration.
     *
     * @param mixed $retentionDays
     * @throws InvalidArgumentException
     */
    public function validateRetentionDays($retentionDays): int
    {
        if ($retentionDays === null || $retentionDays === '' || !is_numeric($retentionDays)) {
            throw new InvalidArgumentException("Retention days must be a valid positive integer.");
        }

        $days = (int) $retentionDays;
        if ($days <= 0) {
            throw new InvalidArgumentException("Retention days must be greater than zero.");
        }

        return $days;
    }

    /**
     * Calculate cutoff timestamp (Unix epoch in UTC).
     * Backups older than this timestamp are eligible for pruning.
     */
    public function calculateCutoffTimestamp(int $retentionDays, ?int $now = null): int
    {
        $current = $now !== null ? $now : time();
        return $current - ($retentionDays * 86400);
    }

    /**
     * Build regex matching standard backup archive filenames for the given POS ID.
     * Format: pos_backup_{sanitized_pos_id}_YYYYMMDD_HHMMSS.sql.gz
     */
    public function getFilenameRegex(string $posId): string
    {
        $cleanPosId = $this->sanitizeIdentifier($posId);
        return '/^pos_backup_' . preg_quote($cleanPosId, '/') . '_(\d{8})_(\d{6})\.sql\.gz$/';
    }

    /**
     * Prune local backup archives in the configured directory.
     *
     * In dry-run mode ($execute = false), returns candidates without deleting.
     * In execution mode ($execute = true), safely deletes eligible files with pre-validation.
     *
     * @return array{eligible: list<string>, deleted: list<string>, failed: list<string>}
     */
    public function pruneLocal(
        string $tempDir,
        string $posId,
        int $retentionDays,
        bool $execute = false,
        ?int $now = null
    ): array {
        $validDays = $this->validateRetentionDays($retentionDays);
        $cutoff = $this->calculateCutoffTimestamp($validDays, $now);

        $result = [
            'eligible' => [],
            'deleted' => [],
            'failed' => [],
        ];

        // Safely handle missing directory
        if (!is_dir($tempDir)) {
            return $result;
        }

        $realTempDir = realpath($tempDir);
        if ($realTempDir === false || !is_dir($realTempDir)) {
            return $result;
        }

        $entries = @scandir($realTempDir);
        if (!is_array($entries)) {
            return $result;
        }

        $filenameRegex = $this->getFilenameRegex($posId);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $filePath = $realTempDir . DIRECTORY_SEPARATOR . $entry;

            // Strict safety check 1: Never follow or delete symlinks
            if (is_link($filePath)) {
                continue;
            }

            // Strict safety check 2: Never delete directories or non-regular files
            if (!is_file($filePath)) {
                continue;
            }

            // Strict safety check 3: Path containment verification
            $realFilePath = realpath($filePath);
            if ($realFilePath === false || !str_starts_with($realFilePath, $realTempDir . DIRECTORY_SEPARATOR)) {
                continue;
            }

            // Strict safety check 4: Filename must strictly match the POS backup pattern
            if (!preg_match($filenameRegex, $entry, $matches)) {
                continue;
            }

            // Strict safety check 5: Dual timestamp evaluation (both mtime and filename timestamp)
            $fileMtime = @filemtime($filePath);
            if ($fileMtime === false) {
                continue;
            }

            $datePart = $matches[1] . '_' . $matches[2];
            $dt = DateTimeImmutable::createFromFormat('Ymd_His', $datePart);
            $filenameTimestamp = $dt instanceof DateTimeInterface ? $dt->getTimestamp() : 0;

            // A file is only eligible if BOTH its mtime and filename date are older than the cutoff.
            // If either indicates it was created within the retention window, preserve it.
            if ($fileMtime >= $cutoff || $filenameTimestamp >= $cutoff) {
                continue;
            }

            if (!$execute) {
                $result['eligible'][] = $filePath;
                continue;
            }

            // Pre-validation immediately before deletion to minimize race conditions
            if (
                file_exists($filePath) &&
                is_file($filePath) &&
                !is_link($filePath) &&
                realpath($filePath) === $filePath &&
                (@filemtime($filePath) < $cutoff)
            ) {
                if (@unlink($filePath)) {
                    $result['deleted'][] = $filePath;
                } else {
                    $result['failed'][] = $filePath;
                }
            } else {
                $result['failed'][] = $filePath;
            }
        }

        return $result;
    }

    /**
     * Prune remote backup archives in Amazon S3 for the configured POS ID and prefix.
     *
     * In dry-run mode ($execute = false), returns candidates without deleting.
     * In execution mode ($execute = true), safely deletes eligible S3 objects.
     *
     * @param array<string, mixed> $s3Config
     * @return array{eligible: list<string>, deleted: list<string>, failed: list<string>}
     */
    public function pruneS3(
        array $s3Config,
        string $posId,
        int $retentionDays,
        bool $execute = false,
        ?int $now = null,
        ?S3Client $customClient = null
    ): array {
        $validDays = $this->validateRetentionDays($retentionDays);
        $cutoff = $this->calculateCutoffTimestamp($validDays, $now);

        $bucket = (string) ($s3Config['bucket'] ?? '');
        if ($bucket === '') {
            throw new RuntimeException("S3 bucket name is not configured (tigerpos-backup.s3.bucket).");
        }

        $cleanPrefix = $this->sanitizePathSegment((string) ($s3Config['prefix'] ?? 'pos-backups'));
        $cleanPosId = $this->sanitizeIdentifier($posId);
        if ($cleanPosId === '') {
            throw new RuntimeException("Invalid POS identifier provided for S3 backup pruning.");
        }

        // Exact POS database key prefix: {prefix}/{pos-id}/database/
        $expectedPrefix = ($cleanPrefix !== '' ? $cleanPrefix . '/' : '') . $cleanPosId . '/database/';

        $client = $customClient ?: $this->s3Uploader->getS3Client($s3Config);
        $filenameRegex = $this->getFilenameRegex($posId);

        $result = [
            'eligible' => [],
            'deleted' => [],
            'failed' => [],
        ];

        $continuationToken = null;

        do {
            $params = [
                'Bucket' => $bucket,
                'Prefix' => $expectedPrefix,
            ];
            if ($continuationToken !== null) {
                $params['ContinuationToken'] = $continuationToken;
            }

            try {
                $response = $client->listObjectsV2($params);
            } catch (AwsException $e) {
                $msg = $e->getAwsErrorMessage() ?: $e->getMessage();
                throw new RuntimeException("Failed to list S3 backup objects in [{$bucket}]: " . $msg, 0, $e);
            } catch (Throwable $e) {
                throw new RuntimeException("Failed to list S3 backup objects in [{$bucket}]: " . $e->getMessage(), 0, $e);
            }

            $contents = $response['Contents'] ?? [];
            if (is_array($contents)) {
                foreach ($contents as $object) {
                    $key = (string) ($object['Key'] ?? '');

                    // Strict safety check 1: Must strictly reside inside the POS database prefix
                    if (!str_starts_with($key, $expectedPrefix)) {
                        continue;
                    }

                    // Strict safety check 2: Object filename must match expected backup pattern
                    $filename = basename($key);
                    if (!preg_match($filenameRegex, $filename)) {
                        continue;
                    }

                    // Strict safety check 3: S3 LastModified timestamp check
                    $lastModified = $object['LastModified'] ?? null;
                    if ($lastModified instanceof DateTimeInterface) {
                        $lastModifiedTimestamp = $lastModified->getTimestamp();
                    } elseif (is_string($lastModified)) {
                        $lastModifiedTimestamp = (int) strtotime($lastModified);
                    } else {
                        // If LastModified is absent or unknown, skip deletion for safety
                        continue;
                    }

                    if ($lastModifiedTimestamp >= $cutoff) {
                        // Younger than retention period; preserve
                        continue;
                    }

                    $result['eligible'][] = $key;
                }
            }

            $isTruncated = !empty($response['IsTruncated']);
            $continuationToken = $isTruncated ? ($response['NextContinuationToken'] ?? null) : null;
        } while ($continuationToken !== null);

        // If dry run, return eligible keys without deletion
        if (!$execute) {
            return $result;
        }

        // Perform single-object deletions with isolated error tracking
        foreach ($result['eligible'] as $key) {
            try {
                $client->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ]);
                $result['deleted'][] = $key;
            } catch (Throwable $e) {
                $result['failed'][] = $key;
            }
        }

        return $result;
    }

    /**
     * Sanitize key prefix to prevent path traversal.
     */
    public function sanitizePathSegment(string $segment): string
    {
        $trimmed = trim($segment, "/ \t\n\r\0\x0B");
        if ($trimmed === '') {
            return '';
        }

        $parts = explode('/', $trimmed);
        $cleanParts = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $part);
            $clean = preg_replace('/_+/', '_', (string) $clean);
            $clean = trim((string) $clean, '_');
            if ($clean !== '') {
                $cleanParts[] = $clean;
            }
        }

        return implode('/', $cleanParts);
    }

    /**
     * Sanitize POS identifier to safe characters.
     */
    public function sanitizeIdentifier(string $identifier): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $identifier);
        $clean = preg_replace('/_+/', '_', (string) $clean);
        return trim((string) $clean, '_');
    }
}
