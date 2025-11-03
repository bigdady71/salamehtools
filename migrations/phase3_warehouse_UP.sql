-- Phase 3 UP Migration: Warehouse dashboard (stock health + movements + alerts)
-- Run this to add warehouse tracking fields

-- Add safety stock and reorder point columns to products
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS safety_stock DECIMAL(12,3) NULL,
    ADD COLUMN IF NOT EXISTS reorder_point DECIMAL(12,3) NULL,
    ADD INDEX IF NOT EXISTS idx_products_safety (safety_stock),
    ADD INDEX IF NOT EXISTS idx_products_reorder (reorder_point);

-- Create warehouse movements table
CREATE TABLE IF NOT EXISTS warehouse_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('in','out','adjust') NOT NULL,
    qty DECIMAL(12,3) NOT NULL,
    reason VARCHAR(120) NULL,
    ref VARCHAR(120) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id),
    INDEX idx_created_at (created_at),
    INDEX idx_kind (kind),
    CONSTRAINT fk_warehouse_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_warehouse_movements_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
