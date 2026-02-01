-- Van Loading OTP Authentication System
-- This migration creates the tables needed for two-factor authentication
-- when transferring stock from warehouse to sales rep vans.

-- ============================================
-- Table: van_loading_requests
-- Main table for van loading requests with OTP codes
-- ============================================
CREATE TABLE IF NOT EXISTS van_loading_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loading_id VARCHAR(64) NOT NULL UNIQUE,
    warehouse_user_id INT NOT NULL,
    sales_rep_id INT NOT NULL,
    warehouse_otp VARCHAR(6) NOT NULL,
    sales_rep_otp VARCHAR(6) NOT NULL,
    warehouse_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    sales_rep_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT NULL,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_loading_id (loading_id),
    INDEX idx_warehouse_user (warehouse_user_id),
    INDEX idx_sales_rep (sales_rep_id),
    INDEX idx_expires (expires_at),
    INDEX idx_completed (completed_at),
    
    FOREIGN KEY (warehouse_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: van_loading_items
-- Items included in each van loading request
-- ============================================
CREATE TABLE IF NOT EXISTS van_loading_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loading_id VARCHAR(64) NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_loading_id (loading_id),
    INDEX idx_product (product_id),
    
    FOREIGN KEY (loading_id) REFERENCES van_loading_requests(loading_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: stock_adjustment_otps
-- OTP authentication for stock adjustments
-- ============================================
CREATE TABLE IF NOT EXISTS stock_adjustment_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    adjustment_id VARCHAR(64) NOT NULL UNIQUE,
    initiator_id INT NOT NULL,
    initiator_type ENUM('admin', 'warehouse_manager') NOT NULL,
    sales_rep_id INT NOT NULL,
    product_id INT NOT NULL,
    delta_qty DECIMAL(10,2) NOT NULL,
    reason VARCHAR(100) NOT NULL,
    note TEXT NULL,
    initiator_otp VARCHAR(6) NOT NULL,
    sales_rep_otp VARCHAR(6) NOT NULL,
    initiator_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    sales_rep_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_adjustment_id (adjustment_id),
    INDEX idx_initiator (initiator_id),
    INDEX idx_sales_rep (sales_rep_id),
    INDEX idx_product (product_id),
    INDEX idx_expires (expires_at),
    INDEX idx_completed (completed_at),
    
    FOREIGN KEY (initiator_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: s_stock_movements (if not exists)
-- Track all van stock movements
-- ============================================
CREATE TABLE IF NOT EXISTS s_stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    salesperson_id INT NOT NULL,
    product_id INT NOT NULL,
    delta_qty DECIMAL(10,2) NOT NULL,
    reason VARCHAR(50) NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_salesperson (salesperson_id),
    INDEX idx_product (product_id),
    INDEX idx_created (created_at),
    
    FOREIGN KEY (salesperson_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Table: warehouse_movements (if not exists)
-- Track warehouse stock movements
-- ============================================
CREATE TABLE IF NOT EXISTS warehouse_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    kind ENUM('in', 'out') NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    reason VARCHAR(100) NOT NULL,
    ref VARCHAR(100) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_product (product_id),
    INDEX idx_kind (kind),
    INDEX idx_created (created_at),
    
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
