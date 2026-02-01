-- ============================================================================
-- WAREHOUSE STOCK ADJUSTMENTS TABLE
-- ============================================================================
-- Tracks all inventory corrections made to warehouse stock (products.quantity_on_hand)
-- This is different from s_stock_movements which tracks sales rep van stock
-- ============================================================================

CREATE TABLE IF NOT EXISTS warehouse_stock_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    performed_by BIGINT UNSIGNED NOT NULL,
    adjustment_type ENUM('add', 'remove', 'set') NOT NULL,
    delta_qty DECIMAL(12, 3) NOT NULL,
    previous_qty DECIMAL(12, 3) NOT NULL,
    new_qty DECIMAL(12, 3) NOT NULL,
    reason VARCHAR(50) NOT NULL,
    note TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_product_id (product_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_reason (reason),
    INDEX idx_created_at (created_at),

    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- NOTES
-- ============================================================================
-- adjustment_type values:
--   'add'    - Adding stock to warehouse
--   'remove' - Removing stock from warehouse
--   'set'    - Setting stock to exact value
--
-- reason values:
--   'count_correction' - Physical count differs from system
--   'damaged'          - Damaged or expired stock
--   'found'            - Found items previously untracked
--   'receiving'        - Stock received from supplier
--   'transfer'         - Internal transfer
--   'other'            - Other reason
--
-- delta_qty:
--   Positive = stock increased
--   Negative = stock decreased
-- ============================================================================
