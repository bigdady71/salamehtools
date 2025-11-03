#!/usr/bin/env php
<?php
/**
 * Product Import Watcher - Cron Job
 *
 * This script checks if the configured import file has changed (via checksum)
 * and imports it if needed. Designed to be safe and idempotent.
 *
 * Usage:
 *   php cli/import_watch.php
 *
 * Cron (every 5 minutes) - example:
 *   star-slash-5 star star star star php /var/www/salamehtools/cli/import_watch.php
 *
 * Exit codes:
 *   0 = Success (imported or skipped because no change)
 *   1 = Error (file not found, import failed, etc.)
 */

// Bootstrap
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/import_products.php';

// Helper to get setting
function get_setting_cli(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT v FROM settings WHERE k = :key");
    $stmt->execute([':key' => $key]);
    $result = $stmt->fetchColumn();
    return $result !== false ? (string)$result : $default;
}

// Helper to get last successful checksum for products
function get_last_successful_checksum(PDO $pdo): ?string
{
    $stmt = $pdo->query("
        SELECT checksum
        FROM import_runs
        WHERE kind = 'products' AND ok = 1
        ORDER BY started_at DESC
        LIMIT 1
    ");
    $result = $stmt->fetchColumn();
    return $result !== false ? (string)$result : null;
}

// Main execution
function main(): int
{
    $output = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'unknown',
        'message' => '',
        'details' => []
    ];

    try {
        $pdo = db();

        // Check if import is enabled
        $enabled = get_setting_cli($pdo, 'import.products.enabled', '0');
        if ($enabled !== '1') {
            $output['status'] = 'skipped';
            $output['message'] = 'Auto-import is disabled in settings';
            echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
            return 0;
        }

        // Get watched file path
        $watchPath = get_setting_cli($pdo, 'import.products.watch_path', '');
        if ($watchPath === '') {
            $output['status'] = 'skipped';
            $output['message'] = 'No watch path configured';
            echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
            return 0;
        }

        $output['details']['watch_path'] = $watchPath;

        // Check if file exists
        if (!file_exists($watchPath)) {
            $output['status'] = 'error';
            $output['message'] = 'Watched file does not exist';
            echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
            return 1;
        }

        if (!is_readable($watchPath)) {
            $output['status'] = 'error';
            $output['message'] = 'Watched file is not readable';
            echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
            return 1;
        }

        // Compute checksum
        $checksum = hash_file('sha256', $watchPath);
        if ($checksum === false) {
            $output['status'] = 'error';
            $output['message'] = 'Failed to compute file checksum';
            echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
            return 1;
        }

        $output['details']['checksum'] = $checksum;

        // Check if this file has already been imported successfully
        $lastChecksum = get_last_successful_checksum($pdo);
        if ($lastChecksum === $checksum) {
            $output['status'] = 'skipped';
            $output['message'] = 'File unchanged since last successful import';
            $output['details']['last_checksum'] = $lastChecksum;
            echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
            return 0;
        }

        // Create import run record
        $startedAt = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO import_runs (kind, source_path, checksum, started_at, ok)
            VALUES ('products', :path, :checksum, :started_at, 0)
        ");
        $stmt->execute([
            ':path' => $watchPath,
            ':checksum' => $checksum,
            ':started_at' => $startedAt
        ]);
        $runId = (int)$pdo->lastInsertId();

        $output['details']['run_id'] = $runId;

        // Begin transaction and run import
        $pdo->beginTransaction();

        try {
            $result = import_products_from_path($pdo, $watchPath, $runId);

            if ($result['ok']) {
                // Import succeeded - commit and update run record
                $pdo->commit();

                $finishedAt = date('Y-m-d H:i:s');
                $stmt = $pdo->prepare("
                    UPDATE import_runs
                    SET finished_at = :finished_at,
                        rows_ok = :rows_ok,
                        rows_updated = :rows_updated,
                        rows_skipped = :rows_skipped,
                        ok = 1,
                        message = :message
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $runId,
                    ':finished_at' => $finishedAt,
                    ':rows_ok' => $result['inserted'],
                    ':rows_updated' => $result['updated'],
                    ':rows_skipped' => $result['skipped'],
                    ':message' => $result['message']
                ]);

                $output['status'] = 'success';
                $output['message'] = 'Import completed successfully';
                $output['details']['inserted'] = $result['inserted'];
                $output['details']['updated'] = $result['updated'];
                $output['details']['skipped'] = $result['skipped'];
                $output['details']['total'] = $result['total'];

                if (!empty($result['warnings'])) {
                    $output['details']['warnings'] = $result['warnings'];
                }

                echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
                return 0;

            } else {
                // Import failed - rollback and update run record
                $pdo->rollBack();

                $finishedAt = date('Y-m-d H:i:s');
                $errorMsg = implode('; ', $result['errors']);
                $stmt = $pdo->prepare("
                    UPDATE import_runs
                    SET finished_at = :finished_at,
                        ok = 0,
                        message = :message
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $runId,
                    ':finished_at' => $finishedAt,
                    ':message' => $errorMsg
                ]);

                $output['status'] = 'error';
                $output['message'] = 'Import failed';
                $output['details']['errors'] = $result['errors'];

                echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
                return 1;
            }

        } catch (Exception $e) {
            $pdo->rollBack();

            $finishedAt = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                UPDATE import_runs
                SET finished_at = :finished_at,
                    ok = 0,
                    message = :message
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $runId,
                ':finished_at' => $finishedAt,
                ':message' => $e->getMessage()
            ]);

            throw $e;
        }

    } catch (Exception $e) {
        $output['status'] = 'error';
        $output['message'] = 'Unexpected error: ' . $e->getMessage();
        $output['details']['exception'] = get_class($e);
        $output['details']['trace'] = $e->getTraceAsString();

        echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
        return 1;
    }
}

// Run and exit with appropriate code
exit(main());
