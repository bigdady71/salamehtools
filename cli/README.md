# CLI Scripts

This directory contains command-line scripts for automated tasks and background jobs.

## Import Watcher

### Overview
`import_watch.php` monitors a configured Excel file for changes and automatically imports product data when the file is modified.

### Features
- **Idempotent**: Uses SHA-256 checksums to detect file changes - won't re-import unchanged files
- **Safe**: All imports run within transactions with automatic rollback on errors
- **Auditable**: Logs every run to `import_runs` table with detailed statistics
- **Feature Flag**: Can be quickly disabled via Settings UI without modifying cron

### Setup

#### 1. Configure Settings
Navigate to **Admin > Settings** and configure:
- **Watched File Path**: Absolute path to Excel file (e.g., `C:\imports\products.xlsx`)
- **Enable Auto-Import**: Check this box to enable automatic imports

#### 2. Set Up Cron Job

**Linux/Unix:**
```bash
# Run every 5 minutes
*/5 * * * * php /var/www/salamehtools/cli/import_watch.php >> /var/log/salamehtools/import_watch.log 2>&1
```

**Windows Task Scheduler:**
1. Open Task Scheduler
2. Create Basic Task
3. Trigger: Daily, repeat every 5 minutes
4. Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\salamehtools\cli\import_watch.php`
5. Save

#### 3. Create Log Directory (Linux/Unix)
```bash
sudo mkdir -p /var/log/salamehtools
sudo chown www-data:www-data /var/log/salamehtools
```

**Windows:**
Logs will output to stdout. To capture them, redirect output in Task Scheduler or use:
```cmd
php C:\xampp\htdocs\salamehtools\cli\import_watch.php >> C:\xampp\htdocs\salamehtools\logs\import_watch.log 2>&1
```

### Manual Testing

Run the script manually to test:
```bash
php cli/import_watch.php
```

Expected output (JSON):
```json
{
    "timestamp": "2025-11-03 14:30:00",
    "status": "success",
    "message": "Import completed successfully",
    "details": {
        "watch_path": "C:\\imports\\products.xlsx",
        "checksum": "a1b2c3...",
        "run_id": 123,
        "inserted": 50,
        "updated": 200,
        "skipped": 5,
        "total": 255
    }
}
```

### Exit Codes

- `0` = Success (imported or skipped because unchanged/disabled)
- `1` = Error (file not found, import failed, etc.)

### Monitoring

Check import status in **Admin > Settings** - displays last run with:
- Success/failure status
- Timestamp
- File checksum
- Row counts (inserted/updated/skipped)
- Error messages if failed

### Troubleshooting

**Import not running:**
1. Check Settings page - is "Enable Auto-Import" checked?
2. Verify cron job is configured and running
3. Check file path is absolute and accessible
4. Review cron logs for errors

**Duplicate imports:**
- Should not happen due to checksum checking
- If occurring, check `import_runs` table for duplicate checksums
- Verify file is not being modified mid-import

**Import failing:**
1. Run manually: `php cli/import_watch.php`
2. Check output for specific error message
3. Verify Excel file format matches expected headers
4. Check database permissions
5. Review Settings page for last error message

### Disabling Auto-Import

To quickly disable without removing cron job:
1. Go to Admin > Settings
2. Uncheck "Enable Auto-Import"
3. Save

The cron will continue running but immediately exit with status "skipped".
