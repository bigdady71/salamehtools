-- Invoice Change Requests Table
-- Stores requests from sales reps to update or void invoices
-- Requires admin approval before changes are applied

CREATE TABLE IF NOT EXISTS invoice_change_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Reference to the invoice
    invoice_id BIGINT UNSIGNED NOT NULL,
    
    -- Who submitted the request
    requested_by BIGINT UNSIGNED NOT NULL,
    
    -- Type of request: 'edit' or 'void'
    request_type ENUM('edit', 'void') NOT NULL,
    
    -- Status of the request
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    
    -- Reason for the request (required)
    reason TEXT NOT NULL,
    
    -- For edit requests: JSON containing the proposed changes
    -- e.g. {"items": [...], "notes": "...", "discount": 5}
    proposed_changes JSON NULL,
    
    -- Original invoice data snapshot (for audit trail)
    original_data JSON NULL,
    
    -- Admin who processed the request
    processed_by BIGINT UNSIGNED NULL,
    processed_at DATETIME NULL,
    
    -- Admin's notes when approving/rejecting
    admin_notes TEXT NULL,
    
    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign keys
    CONSTRAINT fk_icr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_icr_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_icr_processed_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    
    -- Indexes
    INDEX idx_icr_invoice (invoice_id),
    INDEX idx_icr_status (status),
    INDEX idx_icr_requested_by (requested_by),
    INDEX idx_icr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View for pending requests count (useful for admin dashboard)
CREATE OR REPLACE VIEW v_pending_invoice_requests AS
SELECT 
    COUNT(*) AS total_pending,
    SUM(CASE WHEN request_type = 'edit' THEN 1 ELSE 0 END) AS pending_edits,
    SUM(CASE WHEN request_type = 'void' THEN 1 ELSE 0 END) AS pending_voids
FROM invoice_change_requests
WHERE status = 'pending';
