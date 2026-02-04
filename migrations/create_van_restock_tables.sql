-- Van Restock Requests Table
-- Stores restock requests from sales reps to warehouse

CREATE TABLE IF NOT EXISTS van_restock_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_rep_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'submitted', 'approved', 'fulfilled', 'rejected') DEFAULT 'pending',
    notes TEXT NULL,
    rejection_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    approved_by BIGINT UNSIGNED NULL,
    fulfilled_at TIMESTAMP NULL,
    fulfilled_by BIGINT UNSIGNED NULL,
    FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (fulfilled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sales_rep_status (sales_rep_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Van Restock Items Table
-- Stores individual items in each restock request

CREATE TABLE IF NOT EXISTS van_restock_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    fulfilled_quantity DECIMAL(10,2) NULL DEFAULT NULL,
    FOREIGN KEY (request_id) REFERENCES van_restock_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY unique_request_product (request_id, product_id),
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
