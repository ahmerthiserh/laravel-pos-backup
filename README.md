# TigerPOS Backup (`tiger/laravel-pos-backup`)

A private Laravel package designed for multi-instance POS applications to perform automated MySQL database backups to a private Amazon S3 bucket.

## Purpose

Provides a lightweight, reliable, and configurable backup solution supporting:
- Multi-branch POS environments (unique `pos_id` tagging)
- Shared hosting constraints
- Laravel 9 through Laravel 13 compatibility
- Direct upload to secure AWS S3 storage with retention policy management

## Compatibility Matrix

The package is designed to support the POS fleet running across both older and current Laravel versions:

| Framework | Supported Versions | Required PHP Version |
|---|---|---|
| **Laravel 9** | `^9.0` | PHP `^8.0 \|\| ^8.1 \|\| ^8.2` |
| **Laravel 10** | `^10.0` | PHP `^8.1 \|\| ^8.2 \|\| ^8.3` |
| **Laravel 11** | `^11.0` | PHP `^8.2 \|\| ^8.3` |
| **Laravel 12** | `^12.0` | PHP `^8.2 \|\| ^8.3 \|\| ^8.4` |
| **Laravel 13** | `^13.0` | PHP `^8.3 \|\| ^8.4` |

> Package PHP requirement: `^8.0 || ^8.1 || ^8.2 || ^8.3 || ^8.4`. Internal implementations avoid PHP 8.2+-only language syntax to maintain runtime compatibility for Laravel 9 applications running on PHP 8.0/8.1, while fully supporting PHP 8.3/8.4 runtimes required by Laravel 13.

## Current Development Status

- **Phase 1 (Initial Package Foundation)** - Complete
- **Phase 2 (Local MySQL Backup Engine)** - Complete
- **Phase 3 (Secure Amazon S3 Uploads)** - Complete
- **Phase 4, Step 1 (Automated Scheduling Integration)** - Complete:
  - [x] Native integration with Laravel's Console Scheduler (`0 */6 * * *`)
  - [x] Configuration-driven scheduling (`schedule.enabled` and `schedule.cron`)
  - [x] Global package disabled toggle enforcement (`tigerpos-backup.enabled`)
  - [x] Idempotent registration preventing duplicate schedule events
- **Phase 4, Step 2 (Automated Retention Pruning)** - Complete:
  - [x] Dual-scope pruning (local archives and Amazon S3 objects)
  - [x] Dry-run simulation by default (`--execute` required for deletion)
  - [x] Strict prefix, POS isolation, and regex filename pattern validation
  - [x] Non-blocking file lock concurrency protection
  - [x] Opt-in automated pruning scheduling (`prune.enabled`)


## Automated Scheduling & Cron Configuration

The package automatically registers `tigerpos:backup` with Laravel's Console Scheduler to execute every six hours by default (`0 */6 * * *`).

### Host Cron Requirement

Laravel's scheduler does not run on its own; it requires the host operating system to execute `schedule:run` every minute. Ensure the host server has a single cron job configured for the web user:

```bash
* * * * * cd /path-to-your-pos-project && php artisan schedule:run >> /dev/null 2>&1
```

On shared hosting with cPanel or Plesk, add a Cron Job in the control panel targeting `php /home/username/path/artisan schedule:run` every minute (`* * * * *`).

### Host Application Manual Scheduling (Alternative)

If your host application prefers explicitly declaring scheduled commands in its own scheduler (e.g. `routes/console.php` in Laravel 11 or `app/Console/Kernel.php` in Laravel 9), disable package auto-scheduling:

```env
TIGERPOS_BACKUP_SCHEDULE_ENABLED=false
```

And manually register the command in your host scheduler:

```php
// In app/Console/Kernel.php (Laravel 9/10) or routes/console.php (Laravel 11/12/13)
use Illuminate\Support\Facades\Schedule;

Schedule::command('tigerpos:backup')->cron('0 */6 * * *');
```

## Requirements & Shared Hosting Considerations

- **Database Engine**: MySQL (>= 5.7 / 8.0) or MariaDB (>= 10.3).
- **Dump Utility**: The system `mysqldump` CLI utility must be installed and executable in PATH.
- **PHP Process Functions**: The host environment must permit process execution (`proc_open`). On restricted shared hosting where `proc_open` is listed in `disable_functions`, the backup command exits gracefully with a clear error.
- **Dump Execution Timeout**: The dump process execution is subject to a hard timeout of 600 seconds (10 minutes). If a database dump and compression process exceeds this threshold, the command automatically sends SIGKILL to child processes (`mysqldump` and `gzip`), drains and closes open pipes, cleans up any partial archive, and exits with a failure status.
- **AWS S3 Access & Modern Bucket Compatibility**: Outbound HTTPS (port 443) connectivity to AWS S3 endpoints. The package omits explicit ACLs during upload to ensure full compatibility with modern S3 buckets configured with Object Ownership set to *Bucket owner enforced* (ACLs disabled).

## Database Consistency & Dump Scope

To guarantee predictable backups across diverse POS applications, `tiger/laravel-pos-backup` executes `mysqldump` with explicit consistency options:

- **Transactional Consistency (`--single-transaction`)**: Consistent snapshot backups for transactional tables (InnoDB). Reads data within an isolated transaction without locking the entire database during the dump.
- **Non-Transactional Tables (MyISAM / MEMORY)**: `--single-transaction` does **not** protect non-transactional tables against concurrent writes. For applications with legacy MyISAM tables, active writes during backup may result in state inconsistencies.
- **Routines & Triggers**: `--routines` (stored functions and procedures) and `--triggers` (table triggers) are explicitly included.
- **Events & Privileges**: MySQL events (`--events`) require the `EVENT` privilege on the database user. If your database user lacks global administrative or event privileges, events are omitted by default to prevent permission denials.
- **Fast Streaming (`--quick`)**: Rows are retrieved row-by-row rather than buffering entire tables in client memory, preventing host out-of-memory errors on large transaction tables.

## Usage

Perform a full backup (database dump, verification, and S3 upload):

```bash
php artisan tigerpos:backup
```

Target a specific configured database connection:

```bash
php artisan tigerpos:backup --connection=mysql_pos
```

Generate and verify local backup archive only (skip S3 upload):

```bash
php artisan tigerpos:backup --local-only
```

### Retention Pruning Command (`tigerpos:prune-backups`)

Expired local backup archives and S3 objects exceeding `tigerpos-backup.retention_days` can be pruned using the `tigerpos:prune-backups` Artisan command.

#### Dry-Run Mode (Default)
By default, the command runs in **safe simulation mode** without deleting any files or remote objects:

```bash
php artisan tigerpos:prune-backups
```

This outputs candidates that would be deleted, along with their timestamps and paths.

#### Execution Mode (Destructive)
To actually delete eligible archives, supply the `--execute` flag:

```bash
php artisan tigerpos:prune-backups --execute
```

To restrict pruning solely to local storage (skipping Amazon S3):

```bash
php artisan tigerpos:prune-backups --execute --local-only
```

#### Retention Cutoff Logic & Safety Guarantees
- **Cutoff Calculation**: Calculated in UTC as `time() - (retention_days * 86400)`. If `retention_days` is invalid, zero, or negative, the command aborts safely without deleting anything.
- **Local Storage Pruning**:
  - Scoped strictly to the resolved canonical `temp_dir`.
  - Never traverses outside the directory or follows symbolic links.
  - Matches only files with the exact POS archive pattern: `pos_backup_{pos_id}_YYYYMMDD_HHMMSS.sql.gz`.
  - Requires **both** the filesystem modification time (`filemtime`) and the embedded filename timestamp to be older than the cutoff timestamp. Recent archives, temporary `.sql` dumps, subdirectories, and files belonging to other POS instances are strictly preserved.
  - Re-evaluates each file immediately prior to deletion to minimize race conditions.
- **Amazon S3 Pruning**:
  - Scoped strictly to `{s3.prefix}/{pos_id}/database/`. Objects outside this prefix are never listed or touched.
  - Paginates seamlessly across S3 `ListObjectsV2` results.
  - Evaluates the object's `LastModified` UTC timestamp against the cutoff.
  - Never modifies bucket ACLs, public access block configurations, or encryption settings.
- **Concurrency Protection**: Uses a non-blocking process file lock (`flock`) in the system temporary directory to prevent overlapping pruning operations on the same station. Note: `flock` provides host-local process isolation and is not a distributed lock across independent servers or containers.

## Automated Pruning Scheduling (Opt-In)

For operational security, automated pruning is **disabled by default**. To have Laravel's Console Scheduler automatically execute `tigerpos:prune-backups --execute` on a schedule, set:

```env
TIGERPOS_BACKUP_PRUNE_ENABLED=true
TIGERPOS_BACKUP_PRUNE_CRON="30 2 * * *"
```

> **Note**: Automated backup creation (`TIGERPOS_BACKUP_SCHEDULE_ENABLED`) and automated pruning (`TIGERPOS_BACKUP_PRUNE_ENABLED`) operate completely independently.

## S3 Object Key Structure

Backups are uploaded to an isolated, multi-tenant path hierarchy:

```
{s3.prefix}/{pos_id}/database/pos_backup_{pos_id}_{timestamp}.sql.gz
```

*Example*:
`pos-backups/store-01/database/pos_backup_store_01_20261015_234500.sql.gz`

## Required AWS IAM Permissions

### 1. Standard Backup Permissions (Least-Privilege)

For POS stations that only perform backups and uploads, grant standard read/write permissions on the bucket prefix:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowPOSBackupUploads",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:AbortMultipartUpload"
            ],
            "Resource": "arn:aws:s3:::your-backup-bucket-name/pos-backups/*"
        }
    ]
}
```

> **IAM Action Notes**:
> - `s3:HeadObject` is an API operation, but in AWS IAM policies it is authorized under the `s3:GetObject` permission.
> - `s3:AbortMultipartUpload` allows the AWS SDK's `ObjectUploader` to clean up incomplete multipart upload parts if a network failure or error interrupts large uploads.
> - If using AWS KMS for customer-managed server-side encryption (`TIGERPOS_BACKUP_AWS_KMS_KEY_ID`), the IAM entity also requires `kms:GenerateDataKey` and `kms:Decrypt` on the specific KMS key ARN.

### 2. Pruning Permissions (Destructive — Use with Care)

If you enable remote pruning from the POS application via `tigerpos:prune-backups --execute` or `TIGERPOS_BACKUP_PRUNE_ENABLED=true`, the station's IAM role must also be granted `s3:ListBucket` and `s3:DeleteObject`, strictly scoped to the POS station's path:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "AllowPOSBackupUploads",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:AbortMultipartUpload"
            ],
            "Resource": "arn:aws:s3:::your-backup-bucket-name/pos-backups/store-01/*"
        },
        {
            "Sid": "AllowPOSBackupPruningList",
            "Effect": "Allow",
            "Action": [
                "s3:ListBucket"
            ],
            "Resource": "arn:aws:s3:::your-backup-bucket-name",
            "Condition": {
                "StringLike": {
                    "s3:prefix": [
                        "pos-backups/store-01/database/*"
                    ]
                }
            }
        },
        {
            "Sid": "AllowPOSBackupPruningDelete",
            "Effect": "Allow",
            "Action": [
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::your-backup-bucket-name/pos-backups/store-01/database/*"
        }
    ]
}
```

> [!WARNING]
> **Operational Security Consideration**: Enabling S3 pruning grants permanent deletion privileges (`s3:DeleteObject`) to POS endpoint credentials. If POS endpoints are in physically vulnerable locations, consider keeping pruning disabled on the client and instead using **AWS S3 Lifecycle Rules** directly on the bucket to automatically expire objects older than 30 days. This achieves remote retention without granting delete permissions to POS stations.

## Upload Integrity & Verification Model

The package guarantees cryptographic and structural backup integrity before reporting success:
1. **Local Pre-Upload Integrity**: Before upload, the `.sql.gz` dump is tested with `gzip -t` and uncompressed in-memory to verify valid MySQL/MariaDB syntax signatures and non-zero contents. The full local file's SHA-256 digest is calculated and attached to the upload request as user metadata (`x-amz-meta-sha256`).
2. **Native S3 SHA-256 Request**: Uploads specify `ChecksumAlgorithm=SHA256`, instructing AWS S3 to compute a server-side cryptographic checksum.
3. **Single-Part Upload Verification**: For single-part uploads, a post-upload `HeadObject` with `ChecksumMode=ENABLED` retrieves AWS S3's native `ChecksumSHA256` and asserts it equals the Base64 SHA-256 digest of the local file.
4. **Multipart Upload Verification**: For multipart uploads, AWS S3 produces a composite checksum per part (appended with `-N`) rather than a full-file digest. In this scenario, the uploader streams the object directly from S3 via `GetObject`, computes the full-object SHA-256 digest on the fly, and asserts that the remote byte stream strictly equals the local file's SHA-256 digest. User-defined metadata alone is never treated as standalone proof of remote byte integrity.

## Installation

### Private Git Repository (Composer VCS)

To install the package into a host POS application from the private Git repository, add the repository configuration to your application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:ahmerthiserh/laravel-pos-backup.git"
    }
]
```

Then install the package via Composer (use `^1.1` for Laravel 12 and 13 support):

```bash
composer require tiger/laravel-pos-backup:^1.1
```

## Configuration Publishing

Publish the configuration file using Artisan:

```bash
php artisan vendor:publish --tag=tigerpos-backup-config
```

This creates `config/tigerpos-backup.php` where you can customize backup parameters or provide environment variables:

## Recommended Deployment Smoke-Test Procedure

Before deploying or enabling automated backups on production POS endpoints, execute this isolated smoke-test procedure on a staging or test terminal:

1. **Configure Isolated Staging Scope**:
   Configure dedicated test values in `.env` to prevent any possibility of touching production data:
   ```env
   TIGERPOS_BACKUP_POS_ID="smoke-test-pos"
   TIGERPOS_BACKUP_S3_PREFIX="pos-backups-staging"
   ```
2. **Test Local Backup Isolation**:
   Execute a local-only backup targeting a disposable test database:
   ```bash
   php artisan tigerpos:backup --connection=mysql_test --local-only
   ```
   Verify that the archive is created in `temp_dir`, passes internal integrity checks, and exits with code `0`.
3. **Test S3 Upload & Cryptographic Integrity**:
   Perform a full backup to the staging S3 prefix:
   ```bash
   php artisan tigerpos:backup --connection=mysql_test
   ```
   Verify that the upload succeeds, server-side encryption is confirmed, and SHA-256 integrity verification completes.
4. **Test Safe Dry-Run Pruning**:
   Execute the pruning command in default simulation mode:
   ```bash
   php artisan tigerpos:prune-backups
   ```
   Confirm that candidate detection works as expected and **no** files or S3 objects are deleted.
5. **Verify Multi-Tenant Isolation**:
   Confirm in AWS S3 console that all test objects are confined to `{prefix}/smoke-test-pos/database/` and that production prefixes remain untouched.


| Environment Variable | Description | Default |
|---|---|---|
| `TIGERPOS_BACKUP_ENABLED` | Toggle backup execution | `true` |
| `TIGERPOS_BACKUP_POS_ID` | Identifier for the POS station | `APP_NAME` / `pos-default` |
| `TIGERPOS_BACKUP_AWS_REGION` | AWS S3 region | `us-east-1` |
| `TIGERPOS_BACKUP_AWS_BUCKET` | Target AWS S3 bucket name | `""` |
| `TIGERPOS_BACKUP_S3_PREFIX` | S3 folder key prefix | `pos-backups` |
| `TIGERPOS_BACKUP_AWS_KMS_KEY_ID` | Optional KMS Key ID / ARN for SSE-KMS | `null` (uses SSE-S3 `AES256`) |
| `TIGERPOS_BACKUP_SCHEDULE_ENABLED` | Toggle automated six-hour scheduling | `true` |
| `TIGERPOS_BACKUP_SCHEDULE_CRON` | Cron expression for automated backup | `0 */6 * * *` |
| `TIGERPOS_BACKUP_PRUNE_ENABLED` | Toggle automated retention pruning | `false` |
| `TIGERPOS_BACKUP_PRUNE_CRON` | Cron expression for automated pruning | `30 2 * * *` |
| `TIGERPOS_BACKUP_RETENTION_DAYS` | Backup retention window in days | `30` |
| `TIGERPOS_BACKUP_TEMP_DIR` | Local temporary directory for dumps | `storage_path('app/backup-temp')` |