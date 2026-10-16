<?php

namespace Tiger\PosBackup\Services;

use Aws\S3\S3Client;
use Aws\S3\ObjectUploader;
use Aws\Exception\AwsException;
use RuntimeException;
use Throwable;

class S3BackupUploader
{
    /**
     * @var S3Client|null
     */
    protected $s3Client;

    public function __construct(?S3Client $s3Client = null)
    {
        $this->s3Client = $s3Client;
    }

    /**
     * Get or initialize S3Client using the standard AWS SDK credential provider chain.
     *
     * @param array<string, mixed> $s3Config
     */
    public function getS3Client(array $s3Config): S3Client
    {
        if ($this->s3Client !== null) {
            return $this->s3Client;
        }

        $region = (string) ($s3Config['region'] ?? 'us-east-1');
        if ($region === '') {
            $region = 'us-east-1';
        }

        $clientConfig = [
            'version' => 'latest',
            'region' => $region,
        ];

        // Standard AWS SDK credentials resolution:
        // Automatically checks AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY, ECS role, or EC2 instance profile.
        return new S3Client($clientConfig);
    }

    /**
     * Build and validate the isolated S3 object key.
     * Format: {sanitized-prefix}/{sanitized-pos-id}/database/{sanitized-filename}
     */
    public function buildObjectKey(string $prefix, string $posId, string $filename): string
    {
        $cleanPrefix = $this->sanitizePathSegment($prefix);
        $cleanPosId = $this->sanitizeIdentifier($posId);
        $cleanFilename = basename($filename);

        if ($cleanPosId === '') {
            throw new RuntimeException("Invalid POS identifier provided for S3 backup key.");
        }

        if ($cleanFilename === '' || !str_ends_with($cleanFilename, '.sql.gz')) {
            throw new RuntimeException("Invalid backup filename for S3 upload: [{$filename}]. Must be a .sql.gz archive.");
        }

        $segments = [];
        if ($cleanPrefix !== '') {
            $segments[] = $cleanPrefix;
        }
        $segments[] = $cleanPosId;
        $segments[] = 'database';
        $segments[] = $cleanFilename;

        return implode('/', $segments);
    }

    /**
     * Sanitize key prefix to prevent path traversal or malformed keys.
     */
    protected function sanitizePathSegment(string $segment): string
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
            $clean = preg_replace('/_+/', '_', $clean);
            $clean = trim($clean, '_');
            if ($clean !== '') {
                $cleanParts[] = $clean;
            }
        }

        return implode('/', $cleanParts);
    }

    /**
     * Sanitize POS identifier to safe characters.
     */
    protected function sanitizeIdentifier(string $identifier): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $identifier);
        $clean = preg_replace('/_+/', '_', $clean);
        return trim($clean, '_');
    }

    /**
     * Validate local file before upload.
     */
    public function validateLocalFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Backup file does not exist: [{$filePath}].");
        }

        if (!is_readable($filePath)) {
            throw new RuntimeException("Backup file is not readable: [{$filePath}].");
        }

        $size = filesize($filePath);
        if ($size === false || $size <= 20) {
            throw new RuntimeException("Backup file [{$filePath}] is empty or corrupted.");
        }

        if (!str_ends_with($filePath, '.sql.gz')) {
            throw new RuntimeException("Backup file must have .sql.gz extension: [{$filePath}].");
        }
    }

    /**
     * Upload backup archive to private Amazon S3 with server-side encryption and multipart support.
     *
     * @param array<string, mixed> $s3Config
     * @return array{key: string, bucket: string, size: int, sha256: string}
     */
    public function upload(string $localFilePath, string $posId, array $s3Config): array
    {
        $this->validateLocalFile($localFilePath);

        $bucket = (string) ($s3Config['bucket'] ?? '');
        if ($bucket === '') {
            throw new RuntimeException("S3 bucket name is not configured (tigerpos-backup.s3.bucket).");
        }

        $prefix = (string) ($s3Config['prefix'] ?? 'pos-backups');
        $filename = basename($localFilePath);
        $s3Key = $this->buildObjectKey($prefix, $posId, $filename);

        $fileSize = filesize($localFilePath);
        if ($fileSize === false) {
            throw new RuntimeException("Unable to read backup file size: [{$localFilePath}].");
        }

        $sha256 = hash_file('sha256', $localFilePath);
        if ($sha256 === false) {
            throw new RuntimeException("Failed to calculate SHA-256 checksum for [{$localFilePath}].");
        }

        $kmsKeyId = !empty($s3Config['kms_key_id']) ? (string) $s3Config['kms_key_id'] : null;

        $client = $this->getS3Client($s3Config);

        $fileStream = @fopen($localFilePath, 'rb');
        if (!is_resource($fileStream)) {
            throw new RuntimeException("Unable to open backup file for streaming: [{$localFilePath}].");
        }

        $uploadOptions = [
            'Bucket' => $bucket,
            'Key' => $s3Key,
            'Body' => $fileStream,
            'ContentType' => 'application/gzip',
            'ChecksumAlgorithm' => 'SHA256',
            'Metadata' => [
                'pos-id' => $this->sanitizeIdentifier($posId),
                'sha256' => $sha256,
                'source-filename' => $filename,
            ],
        ];

        // Server-Side Encryption (default SSE-S3 AES256 or SSE-KMS)
        if ($kmsKeyId !== null && $kmsKeyId !== '') {
            $uploadOptions['ServerSideEncryption'] = 'aws:kms';
            $uploadOptions['SSEKMSKeyId'] = $kmsKeyId;
        } else {
            $uploadOptions['ServerSideEncryption'] = 'AES256';
        }

        try {
            // ObjectUploader automatically handles standard PutObject for smaller files
            // and multipart upload for larger files (default threshold: 16MB).
            // ACL is omitted (null) so modern S3 buckets with Object Ownership "Bucket owner enforced"
            // (ACLs disabled) do not reject uploads with AccessControlListNotSupported.
            $uploader = new ObjectUploader($client, $bucket, $s3Key, $fileStream, null, [
                'params' => $uploadOptions,
            ]);

            $result = $uploader->upload();

            // Verify uploaded object exists, size matches, and SHA-256 integrity is valid
            $this->verifyRemoteObject($client, $bucket, $s3Key, $fileSize, $sha256);

            return [
                'key' => $s3Key,
                'bucket' => $bucket,
                'size' => $fileSize,
                'sha256' => $sha256,
            ];
        } catch (AwsException $e) {
            $errorMsg = $e->getAwsErrorMessage() ?: $e->getMessage();
            throw new RuntimeException("AWS S3 upload failed for [{$s3Key}]: " . $errorMsg, 0, $e);
        } catch (Throwable $e) {
            throw new RuntimeException("S3 upload failed for [{$s3Key}]: " . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($fileStream)) {
                @fclose($fileStream);
            }
        }
    }

    /**
     * Verify that the uploaded object exists in S3 and its size and checksum match the local backup.
     *
     * Verification strategy:
     * 1. Check HeadObject ContentLength matches local file size.
     * 2. If S3 returns a single-part native ChecksumSHA256 (does not contain '-'), assert it matches the local SHA-256.
     * 3. If S3 uploaded via multipart (ChecksumSHA256 has a part count suffix like '-1' or is missing a full-object checksum),
     *    stream the object from S3 using GetObject, compute its SHA-256 on the fly, and assert it matches the local digest.
     * 4. Verify user metadata sha256 as an additional layer of provenance.
     */
    public function verifyRemoteObject(
        S3Client $client,
        string $bucket,
        string $key,
        int $expectedSize,
        string $expectedSha256Hex
    ): void {
        try {
            $head = $client->headObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'ChecksumMode' => 'ENABLED',
            ]);

            $contentLength = (int) ($head['ContentLength'] ?? -1);
            if ($contentLength !== $expectedSize) {
                throw new RuntimeException("Remote object size mismatch: S3 reported {$contentLength} bytes, expected {$expectedSize} bytes.");
            }

            // Strategy A: Provenance metadata check
            $remoteSha256Meta = $head['Metadata']['sha256'] ?? null;
            if ($remoteSha256Meta === null) {
                throw new RuntimeException("Remote object missing expected sha256 metadata.");
            }

            if (strtolower((string) $remoteSha256Meta) !== strtolower($expectedSha256Hex)) {
                throw new RuntimeException("Remote object SHA-256 metadata mismatch: S3 reported {$remoteSha256Meta}, expected {$expectedSha256Hex}.");
            }

            // Strategy B: Native S3 single-part checksum verification
            $remoteChecksumB64 = $head['ChecksumSHA256'] ?? null;
            $expectedSha256B64 = base64_encode(hex2bin($expectedSha256Hex) ?: '');
            $fullChecksumVerified = false;

            if ($remoteChecksumB64 !== null && $remoteChecksumB64 !== '') {
                if (!str_contains($remoteChecksumB64, '-')) {
                    if ($remoteChecksumB64 !== $expectedSha256B64) {
                        throw new RuntimeException("Remote object SHA-256 checksum mismatch: S3 reported {$remoteChecksumB64}, expected {$expectedSha256B64}.");
                    }
                    $fullChecksumVerified = true;
                }
            }

            // Strategy C: For multipart uploads (where S3 provides a composite part checksum) or when
            // S3 did not return a single-part full-object checksum, stream the object to verify raw bytes
            if (!$fullChecksumVerified) {
                $this->verifyRemoteObjectStreamChecksum($client, $bucket, $key, $expectedSha256Hex);
            }
        } catch (AwsException $e) {
            throw new RuntimeException("Verification of uploaded object [{$key}] failed: " . ($e->getAwsErrorMessage() ?: $e->getMessage()), 0, $e);
        }
    }

    /**
     * Stream object bytes directly from S3 to compute and assert full-object SHA-256 integrity.
     */
    protected function verifyRemoteObjectStreamChecksum(
        S3Client $client,
        string $bucket,
        string $key,
        string $expectedSha256Hex
    ): void {
        try {
            $response = $client->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            $body = $response['Body'] ?? null;
            if (!is_object($body) || !method_exists($body, 'read')) {
                throw new RuntimeException("Unable to read remote object stream for checksum verification.");
            }

            $hashCtx = hash_init('sha256');
            while (!$body->eof()) {
                $chunk = $body->read(16384);
                if ($chunk === false) {
                    break;
                }
                hash_update($hashCtx, $chunk);
            }

            $actualSha256 = hash_final($hashCtx);
            if (strtolower($actualSha256) !== strtolower($expectedSha256Hex)) {
                throw new RuntimeException("Remote object streamed SHA-256 mismatch: computed {$actualSha256}, expected {$expectedSha256Hex}.");
            }
        } catch (AwsException $e) {
            throw new RuntimeException("Streaming verification failed for [{$key}]: " . ($e->getAwsErrorMessage() ?: $e->getMessage()), 0, $e);
        }
    }
}
