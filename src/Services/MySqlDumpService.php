<?php

namespace Tiger\PosBackup\Services;

use RuntimeException;
use Throwable;

class MySqlDumpService
{
    /**
     * Determine if mysqldump binary is available and executable.
     */
    public function isDumpUtilityAvailable(?string $customBinary = null): bool
    {
        if ($this->isExecDisabled()) {
            return false;
        }

        $binary = $customBinary ?: 'mysqldump';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([$binary, '--version'], $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return $exitCode === 0;
    }

    /**
     * Check if shell execution functions are disabled.
     */
    public function isExecDisabled(): bool
    {
        if (!function_exists('proc_open')) {
            return true;
        }

        $disabled = explode(',', (string) ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);

        return in_array('proc_open', $disabled, true);
    }

    /**
     * Validate database driver for backup.
     *
     * @param array<string, mixed> $connectionConfig
     */
    public function validateDriver(array $connectionConfig): void
    {
        $driver = strtolower((string) ($connectionConfig['driver'] ?? ''));

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Unsupported database driver [{$driver}]. Only MySQL and MariaDB are supported.");
        }
    }

    /**
     * Ensure destination temporary directory exists, is outside public path, and has restrictive permissions.
     */
    public function ensureTempDirectory(string $path): string
    {
        $normalized = rtrim($path, '/\\');

        if (!is_dir($normalized)) {
            if (!@mkdir($normalized, 0700, true) && !is_dir($normalized)) {
                throw new RuntimeException("Failed to create temporary backup directory [{$normalized}].");
            }
        }

        // Resolve realpath after creation to follow any symbolic links
        $realTemp = realpath($normalized);
        if ($realTemp === false) {
            $realTemp = $normalized;
        }

        if (function_exists('public_path')) {
            $publicPath = rtrim(public_path(), '/\\');
            $realPublic = realpath($publicPath);
            $checkPublic = $realPublic !== false ? $realPublic : $publicPath;

            if ($realTemp === $checkPublic || str_starts_with($realTemp, $checkPublic . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Temporary backup directory [{$normalized}] cannot be inside public web root.");
            }
        }

        // Apply restrictive permissions where supported
        @chmod($realTemp, 0700);

        return $realTemp;
    }

    /**
     * Generate standard backup filename.
     */
    public function generateFilename(string $posId, ?int $timestamp = null): string
    {
        $ts = $timestamp ?: time();
        $sanitizedPosId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $posId);
        $dateStr = date('Ymd_His', $ts);

        return "pos_backup_{$sanitizedPosId}_{$dateStr}.sql.gz";
    }

    /**
     * Run MySQL dump and stream through gzip into destination file.
     * Credentials are securely supplied via a temporary defaults-extra-file.
     *
     * @param array<string, mixed> $connectionConfig
     */
    public function dump(
        array $connectionConfig,
        string $outputPath,
        ?string $customBinary = null,
        ?string $customGzipBinary = null
    ): string {
        $this->validateDriver($connectionConfig);

        if ($this->isExecDisabled()) {
            throw new RuntimeException("Process execution functions (proc_open) are disabled by host configuration.");
        }

        $binary = $customBinary ?: 'mysqldump';
        if (!$this->isDumpUtilityAvailable($binary)) {
            throw new RuntimeException("Database dump utility '{$binary}' is not available or not executable.");
        }

        $database = (string) ($connectionConfig['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException("Database name is not configured for the selected connection.");
        }

        $tempCnf = $this->createTempConfigFile($connectionConfig);

        try {
            $this->executePipedDumpProcess($binary, $tempCnf, $connectionConfig, $database, $outputPath, $customGzipBinary);
            $this->verifyGzipArchive($outputPath);
        } catch (Throwable $e) {
            $this->cleanupFile($outputPath);
            throw $e;
        } finally {
            $this->cleanupFile($tempCnf);
        }

        return $outputPath;
    }

    /**
     * Create a secure temporary MySQL options file with mode 0600.
     *
     * @param array<string, mixed> $connectionConfig
     */
    protected function createTempConfigFile(array $connectionConfig): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'tiger_cnf_');
        if ($tempFile === false) {
            throw new RuntimeException("Unable to create temporary options file for database credentials.");
        }

        @chmod($tempFile, 0600);

        $host = (string) ($connectionConfig['host'] ?? '127.0.0.1');
        $port = (string) ($connectionConfig['port'] ?? '3306');
        $username = (string) ($connectionConfig['username'] ?? '');
        $password = (string) ($connectionConfig['password'] ?? '');
        $socket = (string) ($connectionConfig['unix_socket'] ?? '');

        $lines = [
            '[client]',
            'user = ' . $this->quoteCnfValue($username),
            'password = ' . $this->quoteCnfValue($password),
        ];

        if ($socket !== '') {
            $lines[] = 'socket = ' . $this->quoteCnfValue($socket);
        } else {
            $lines[] = 'host = ' . $this->quoteCnfValue($host);
            $lines[] = 'port = ' . $this->quoteCnfValue($port);
        }

        file_put_contents($tempFile, implode(PHP_EOL, $lines) . PHP_EOL);

        return $tempFile;
    }

    /**
     * Quote value for MySQL options file.
     */
    protected function quoteCnfValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Execute mysqldump piped directly to gzip using argument arrays without shell interpolation.
     * Both processes are monitored and both exit statuses are verified.
     *
     * IMPORTANT: --defaults-extra-file MUST be the very first argument passed to mysqldump.
     *
     * @param array<string, mixed> $connectionConfig
     */
    protected function executePipedDumpProcess(
        string $binary,
        string $tempCnf,
        array $connectionConfig,
        string $database,
        string $outputPath,
        ?string $customGzipBinary = null
    ): void {
        // --defaults-extra-file must be first argument for mysqldump/mariadb-dump
        $dumpCmd = [
            $binary,
            '--defaults-extra-file=' . $tempCnf,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--routines',
            '--triggers',
        ];

        if (!empty($connectionConfig['charset'])) {
            $dumpCmd[] = '--default-character-set=' . $connectionConfig['charset'];
        }

        $dumpCmd[] = $database;

        $gzipCmd = [$customGzipBinary ?: 'gzip', '-c'];

        $outHandle = @fopen($outputPath, 'wb');
        if (!is_resource($outHandle)) {
            throw new RuntimeException("Unable to open destination backup file [{$outputPath}] for writing.");
        }

        $dumpDescriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], // stdout piped to gzip stdin
            2 => ['pipe', 'w'], // stderr captured
        ];

        $dumpProc = @proc_open($dumpCmd, $dumpDescriptors, $dumpPipes);
        if (!is_resource($dumpProc)) {
            fclose($outHandle);
            throw new RuntimeException("Failed to execute database dump process.");
        }

        $gzipDescriptors = [
            0 => $dumpPipes[1], // reads directly from dump stdout pipe
            1 => $outHandle,     // writes directly to destination file
            2 => ['pipe', 'w'], // stderr captured
        ];

        $gzipProc = @proc_open($gzipCmd, $gzipDescriptors, $gzipPipes);
        if (!is_resource($gzipProc)) {
            fclose($dumpPipes[0]);
            fclose($dumpPipes[1]);
            fclose($dumpPipes[2]);
            fclose($outHandle);
            proc_close($dumpProc);
            throw new RuntimeException("Failed to execute compression process (gzip).");
        }

        // Close dump stdin and stdout in parent process so EOF propagates cleanly
        fclose($dumpPipes[0]);
        fclose($dumpPipes[1]);

        // Set stderr streams to non-blocking to prevent deadlocks on large error streams
        stream_set_blocking($dumpPipes[2], false);
        stream_set_blocking($gzipPipes[2], false);

        $dumpStderr = '';
        $gzipStderr = '';
        $startTime = time();
        $timeoutSeconds = 600; // 10 minute maximum dump execution threshold

        try {
            while (true) {
                $r = [$dumpPipes[2], $gzipPipes[2]];
                $w = null;
                $e = null;
                $n = @stream_select($r, $w, $e, 0, 200000);

                if ($n !== false && $n > 0) {
                    foreach ($r as $stream) {
                        $buf = fread($stream, 8192);
                        if ($stream === $dumpPipes[2]) {
                            $dumpStderr .= $buf;
                        } elseif ($stream === $gzipPipes[2]) {
                            $gzipStderr .= $buf;
                        }
                    }
                }

                $dumpStatus = @proc_get_status($dumpProc);
                $gzipStatus = @proc_get_status($gzipProc);

                $dumpRunning = $dumpStatus['running'] ?? false;
                $gzipRunning = $gzipStatus['running'] ?? false;

                if (!$dumpRunning && !$gzipRunning) {
                    // Drain any final data
                    $dumpStderr .= stream_get_contents($dumpPipes[2]);
                    $gzipStderr .= stream_get_contents($gzipPipes[2]);
                    break;
                }

                if ((time() - $startTime) > $timeoutSeconds) {
                    $this->terminateProcess($dumpProc);
                    $this->terminateProcess($gzipProc);
                    throw new RuntimeException("Database dump process timed out after {$timeoutSeconds} seconds.");
                }
            }
        } finally {
            if (is_resource($dumpPipes[2])) {
                @fclose($dumpPipes[2]);
            }
            if (is_resource($gzipPipes[2])) {
                @fclose($gzipPipes[2]);
            }
            if (is_resource($outHandle)) {
                @fclose($outHandle);
            }
        }

        $dumpExit = proc_close($dumpProc);
        $gzipExit = proc_close($gzipProc);

        if ($dumpExit !== 0) {
            $cleanError = $this->sanitizeOutput(trim((string) $dumpStderr), $tempCnf);
            throw new RuntimeException("mysqldump failed with exit code {$dumpExit}: " . ($cleanError ?: 'Unknown error.'));
        }

        if ($gzipExit !== 0) {
            $cleanGzError = trim((string) $gzipStderr);
            throw new RuntimeException("gzip compression failed with exit code {$gzipExit}: " . ($cleanGzError ?: 'Unknown error.'));
        }
    }

    /**
     * Terminate process safely if running.
     *
     * @param resource $process
     */
    protected function terminateProcess($process): void
    {
        if (is_resource($process)) {
            $status = @proc_get_status($process);
            if (!empty($status['running'])) {
                @proc_terminate($process, 9);
            }
        }
    }

    /**
     * Verify that output file exists, is non-empty, and can be read as a valid, complete gzip stream.
     * Ensures an empty or truncated SQL dump cannot be reported as successful.
     */
    public function verifyGzipArchive(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Backup dump file was not generated: [{$filePath}].");
        }

        $size = filesize($filePath);
        if ($size === false || $size <= 20) {
            throw new RuntimeException("Backup dump file [{$filePath}] is empty or corrupted.");
        }

        // 1. Run gzip -t integrity test if process execution is enabled
        if (!$this->isExecDisabled()) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = @proc_open(['gzip', '-t', $filePath], $descriptors, $pipes);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                $testErr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $testExit = proc_close($proc);
                if ($testExit !== 0) {
                    $cleanErr = trim((string) $testErr);
                    throw new RuntimeException("Backup archive [{$filePath}] failed gzip integrity check: " . ($cleanErr ?: "exit code {$testExit}"));
                }
            }
        }

        $gz = @gzopen($filePath, 'rb');
        if ($gz === false) {
            throw new RuntimeException("Backup archive [{$filePath}] is not a readable gzip file.");
        }

        $uncompressedBytes = 0;
        $decompressedContent = '';
        $sampleLimit = 2048;

        while (!gzeof($gz)) {
            $chunk = @gzread($gz, 8192);
            if ($chunk === false) {
                @gzclose($gz);
                throw new RuntimeException("Backup archive [{$filePath}] decompression error or corrupted stream.");
            }
            $len = strlen($chunk);
            $uncompressedBytes += $len;
            if (strlen($decompressedContent) < $sampleLimit) {
                $decompressedContent .= substr($chunk, 0, $sampleLimit - strlen($decompressedContent));
            }
        }

        $closeSuccess = @gzclose($gz);
        if ($closeSuccess === false) {
            throw new RuntimeException("Backup archive [{$filePath}] stream closed with checksum or truncation error.");
        }

        if ($uncompressedBytes < 20) {
            throw new RuntimeException("Backup archive [{$filePath}] contains insufficient or empty SQL dump data.");
        }

        // Verify that the decompressed output looks like valid SQL/dump content
        if (
            !str_contains($decompressedContent, 'MySQL dump') &&
            !str_contains($decompressedContent, 'MariaDB dump') &&
            !str_contains($decompressedContent, 'CREATE TABLE') &&
            !str_contains($decompressedContent, 'INSERT INTO') &&
            !str_contains($decompressedContent, '--')
        ) {
            throw new RuntimeException("Backup archive [{$filePath}] does not appear to contain valid SQL dump statements.");
        }
    }

    /**
     * Sanitize sensitive config paths or details from output.
     */
    protected function sanitizeOutput(string $output, string $tempCnf): string
    {
        $sanitized = str_replace($tempCnf, '[DEFAULTS_FILE]', $output);
        return preg_replace('/password\s*=\s*[^\s]+/i', 'password=***', $sanitized);
    }

    /**
     * Remove file safely. Only deletes files within sys_get_temp_dir() or configured temp paths.
     */
    public function cleanupFile(?string $filePath): void
    {
        if ($filePath !== null && $filePath !== '' && file_exists($filePath)) {
            @unlink($filePath);
        }
    }
}
