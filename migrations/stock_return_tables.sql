-- Stock Return Tables
-- For internal inventory movements from sales rep van stock back to warehouse
-- NOT financial returns - purely inventory movement

-- Main stock return request table
CREATE TABLE IF NOT EXISTS stock_return_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id VARCHAR(64) NOT NULL UNIQUE,
    sales_rep_id BIGINT UNSIGNED NOT NULL,
    warehouse_user_id BIGINT UNSIGNED DEFAULT NULL,
    sales_rep_otp VARCHAR(6) NOT NULL,
    warehouse_otp VARCHAR(6) NOT NULL,
    note TEXT DEFAULT NULL,
    status ENUM('pending', 'approved', 'completed', 'cancelled', 'expired') DEFAULT 'pending',
    sales_rep_confirmed TINYINT(1) DEFAULT 0,
    warehouse_confirmed TINYINT(1) DEFAULT 0,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_return_id (return_id),
    INDEX idx_sales_rep_id (sales_rep_id),
    INDEX idx_warehouse_user_id (warehouse_user_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at),

    FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (warehouse_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock return items table
CREATE TABLE IF NOT EXISTS stock_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id VARCHAR(64) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_return_id (return_id),
    INDEX idx_product_id (product_id),

    FOREIGN KEY (return_id) REFERENCES stock_return_requests(return_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock return audit log
CREATE TABLE IF NOT EXISTS stock_return_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id VARCHAR(64) NOT NULL,
    action VARCHAR(50) NOT NULL,
    performed_by BIGINT UNSIGNED DEFAULT NULL,
    details TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_return_id (return_id),
    INDEX idx_action (action),
    INDEX idx_performed_by (performed_by),

    FOREIGN KEY (return_id) REFERENCES stock_return_requests(return_id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
