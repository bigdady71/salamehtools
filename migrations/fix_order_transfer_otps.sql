-- ============================================================================
-- FIX: Create order_transfer_otps table if missing
-- Run this BEFORE using the order OTP verification system
-- ============================================================================

-- Create the order_transfer_otps table for OTP verification during order handover
CREATE TABLE IF NOT EXISTS order_transfer_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    
    -- OTP codes (6 digits each)
    warehouse_otp VARCHAR(6) NOT NULL,
    sales_rep_otp VARCHAR(6) NOT NULL,
    
    -- Verification timestamps
    warehouse_verified_at TIMESTAMP NULL DEFAULT NULL,
    warehouse_verified_by BIGINT UNSIGNED NULL,
    sales_rep_verified_at TIMESTAMP NULL DEFAULT NULL,
    sales_rep_verified_by BIGINT UNSIGNED NULL,
    
    -- Expiration (OTPs expire after set time)
    expires_at DATETIME NOT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_order_id (order_id),
    INDEX idx_expires_at (expires_at),
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Also ensure warehouse_movements and s_stock_movements exist with correct structure
-- These are needed for stock transfer logging

CREATE TABLE IF NOT EXISTS warehouse_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('in','out','adjust') NOT NULL,
    qty DECIMAL(12,3) NOT NULL,
    reason VARCHAR(120) NULL,
    ref VARCHAR(120) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id),
    INDEX idx_created_at (created_at),
    INDEX idx_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create inventory_movements table for tracking all movements
CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_type ENUM(
        'order_fulfillment',
        'order_cancellation',
        'van_loading',
        'van_return',
        'adjustment_in',
        'adjustment_out',
        'receiving',
        'damage',
        'transfer'
    ) NOT NULL,

    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12, 3) NOT NULL,

    from_location_type ENUM('warehouse', 'sales_rep', 'supplier', 'adjustment') NOT NULL,
    from_location_id BIGINT UNSIGNED DEFAULT NULL,
    to_location_type ENUM('warehouse', 'sales_rep', 'customer', 'adjustment') NOT NULL,
    to_location_id BIGINT UNSIGNED DEFAULT NULL,

    warehouse_stock_before DECIMAL(12, 3) DEFAULT NULL,
    warehouse_stock_after DECIMAL(12, 3) DEFAULT NULL,
    sales_rep_stock_before DECIMAL(12, 3) DEFAULT NULL,
    sales_rep_stock_after DECIMAL(12, 3) DEFAULT NULL,

    order_id BIGINT UNSIGNED DEFAULT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id VARCHAR(100) DEFAULT NULL,

    performed_by BIGINT UNSIGNED NOT NULL,
    reason TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_movement_type (movement_type),
    INDEX idx_product_id (product_id),
    INDEX idx_order_id (order_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create order_handovers table for tracking handover process
CREATE TABLE IF NOT EXISTS order_handovers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,

    prepared_by BIGINT UNSIGNED NOT NULL,
    prepared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    warehouse_otp VARCHAR(6) DEFAULT NULL,
    warehouse_confirmed_at TIMESTAMP NULL DEFAULT NULL,

    sales_rep_id BIGINT UNSIGNED NOT NULL,
    sales_rep_otp VARCHAR(6) DEFAULT NULL,
    sales_rep_confirmed_at TIMESTAMP NULL DEFAULT NULL,

    stock_transferred_at TIMESTAMP NULL DEFAULT NULL,
    stock_movement_ids JSON DEFAULT NULL,

    status ENUM('pending', 'warehouse_ready', 'sales_rep_accepted', 'completed', 'cancelled') DEFAULT 'pending',
    completed_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    cancellation_reason TEXT DEFAULT NULL,

    expires_at DATETIME NOT NULL,

    INDEX idx_order_id (order_id),
    INDEX idx_sales_rep_id (sales_rep_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DONE - All tables created
-- ============================================================================
