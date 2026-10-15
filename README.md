# TigerPOS Backup (`tiger/laravel-pos-backup`)

A private Laravel package designed for multi-instance POS applications to perform automated MySQL database backups to a private Amazon S3 bucket.

## Purpose

Provides a lightweight, reliable, and configurable backup solution supporting:
- Multi-branch POS environments (unique `pos_id` tagging)
- Shared hosting constraints
- Laravel 9 and Laravel 11 compatibility
- Direct upload to secure AWS S3 storage with retention policy management

## Compatibility Matrix

The package is designed to support the POS fleet running across both older and current Laravel versions:

| Framework | Supported Versions | Required PHP Version |
|---|---|---|
| **Laravel 9** | `^9.0` | PHP `^8.0 \|\| ^8.1 \|\| ^8.2` |
| **Laravel 10** | `^10.0` | PHP `^8.1 \|\| ^8.2 \|\| ^8.3` |
| **Laravel 11** | `^11.0` | PHP `^8.2 \|\| ^8.3` |

> Package PHP requirement: `^8.0 || ^8.1 || ^8.2 || ^8.3`. Internal implementations avoid PHP 8.2+-only language syntax to maintain runtime compatibility for Laravel 9 applications running on PHP 8.0/8.1.

## Current Development Status

- **Phase 1 (Initial Package Foundation)** - Complete
- **Phase 2 (Local MySQL Backup Engine)** - Complete
- **Phase 3 (Secure Amazon S3 Uploads)** - Complete:
  - [x] Dedicated S3 upload service [`S3BackupUploader`](file:///home/tiger/Project/laravel-pos-backup/src/Services/S3BackupUploader.php)
  - [x] Official AWS SDK for PHP (`aws/aws-sdk-php: ^3.200`) integration
  - [x] Standard AWS SDK credential provider chain (IAM Roles, ECS Task Roles, standard env credentials)
  - [x] Isolated S3 object key structure: `{prefix}/{pos_id}/database/{filename}`
  - [x] Path traversal and segment injection sanitization
  - [x] Private bucket enforcement (private ACL) and Server-Side Encryption (default SSE-S3 `AES256` or SSE-KMS via `kms_key_id`)
  - [x] Streaming multipart upload support (`Aws\S3\ObjectUploader`) for large files
  - [x] Remote object existence, size, and SHA-256 metadata verification
  - [x] Local archive retention and `--local-only` command option
  - [x] Sanitized error messages with non-secret logging

> **Upcoming Phases**: Automated retention pruning and scheduled background execution.

## Requirements & Shared Hosting Considerations

- **Database Engine**: MySQL (>= 5.7 / 8.0) or MariaDB (>= 10.3).
- **Dump Utility**: The system `mysqldump` CLI utility must be installed and executable in PATH.
- **PHP Process Functions**: The host environment must permit process execution (`proc_open`). On restricted shared hosting where `proc_open` is listed in `disable_functions`, the backup command exits gracefully with a clear error.
- **AWS S3 Access**: Outbound HTTPS (port 443) connectivity to AWS S3 endpoints.

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

## S3 Object Key Structure

Backups are uploaded to an isolated, multi-tenant path hierarchy:

```
{s3.prefix}/{pos_id}/database/pos_backup_{pos_id}_{timestamp}.sql.gz
```

*Example*:
`pos-backups/store-01/database/pos_backup_store_01_20261015_234500.sql.gz`

## Required AWS IAM Permissions

To allow POS applications to upload and verify backups, the IAM user or role requires minimum least-privilege permissions on the target bucket and prefix:

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
                "s3:HeadObject"
            ],
            "Resource": "arn:aws:s3:::your-backup-bucket-name/pos-backups/*"
        },
        {
            "Sid": "AllowBucketLocationCheck",
            "Effect": "Allow",
            "Action": [
                "s3:GetBucketLocation"
            ],
            "Resource": "arn:aws:s3:::your-backup-bucket-name"
        }
    ]
}
```

> If using AWS KMS for server-side encryption (`kms_key_id`), the role also requires `kms:GenerateDataKey` and `kms:Decrypt` permissions on the KMS key.

## Installation

Add this package to your Laravel application's `composer.json` or private repository registry:

```bash
composer require tiger/laravel-pos-backup
```

## Configuration Publishing

Publish the configuration file using Artisan:

```bash
php artisan vendor:publish --tag=tigerpos-backup-config
```

This creates `config/tigerpos-backup.php` where you can customize backup parameters or provide environment variables:

| Environment Variable | Description | Default |
|---|---|---|
| `TIGERPOS_BACKUP_ENABLED` | Toggle backup execution | `true` |
| `TIGERPOS_BACKUP_POS_ID` | Identifier for the POS station | `APP_NAME` / `pos-default` |
| `TIGERPOS_BACKUP_AWS_REGION` | AWS S3 region | `us-east-1` |
| `TIGERPOS_BACKUP_AWS_BUCKET` | Target AWS S3 bucket name | `""` |
| `TIGERPOS_BACKUP_S3_PREFIX` | S3 folder key prefix | `pos-backups` |
| `TIGERPOS_BACKUP_AWS_KMS_KEY_ID` | Optional KMS Key ID / ARN for SSE-KMS | `null` (uses SSE-S3 `AES256`) |
| `TIGERPOS_BACKUP_RETENTION_DAYS` | Remote backup retention window | `30` |
| `TIGERPOS_BACKUP_TEMP_DIR` | Local temporary directory for dumps | `storage_path('app/backup-temp')` |
