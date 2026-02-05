<?php
/**
 * Quick migration runner - Run this once then delete it
 * URL: http://localhost/salamehtools/run_migration.php
 */

require_once __DIR__ . '/includes/db.php';

$pdo = db();

echo "<h2>Running Invoice Change Requests Migration</h2>";
echo "<pre>";

try {
    // Create the table (using BIGINT to match existing tables)
    $sql = "
    CREATE TABLE IF NOT EXISTS invoice_change_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_id BIGINT UNSIGNED NOT NULL,
        requested_by BIGINT UNSIGNED NOT NULL,
        request_type ENUM('edit', 'void') NOT NULL,
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        reason TEXT NOT NULL,
        proposed_changes JSON NULL,
        original_data JSON NULL,
        processed_by BIGINT UNSIGNED NULL,
        processed_at DATETIME NULL,
        admin_notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_icr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        CONSTRAINT fk_icr_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_icr_processed_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_icr_invoice (invoice_id),
        INDEX idx_icr_status (status),
        INDEX idx_icr_requested_by (requested_by),
        INDEX idx_icr_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($sql);
    echo "✅ Table 'invoice_change_requests' created successfully!\n";

    // Create the view
    $viewSql = "
    CREATE OR REPLACE VIEW v_pending_invoice_requests AS
    SELECT 
        COUNT(*) AS total_pending,
        SUM(CASE WHEN request_type = 'edit' THEN 1 ELSE 0 END) AS pending_edits,
        SUM(CASE WHEN request_type = 'void' THEN 1 ELSE 0 END) AS pending_voids
    FROM invoice_change_requests
    WHERE status = 'pending'
    ";
    
    $pdo->exec($viewSql);
    echo "✅ View 'v_pending_invoice_requests' created successfully!\n";

    echo "\n<strong style='color: green;'>Migration completed successfully!</strong>\n";
    echo "\n⚠️ You can now delete this file (run_migration.php) for security.\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
