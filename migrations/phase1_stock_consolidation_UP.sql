-- ============================================================================
-- PHASE 1: STOCK CONSOLIDATION & SOFT DELETE MIGRATION
-- Date: 2026-02-01
-- Description: Consolidates stock tables, adds soft delete, improves integrity
-- ============================================================================

-- ============================================================================
-- PART 0: CREATE MISSING TABLES
-- ============================================================================

-- Customer favorites table (for customer portal)
CREATE TABLE IF NOT EXISTS customer_favorites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_customer_product (customer_id, product_id),
    INDEX idx_customer (customer_id),
    INDEX idx_product (product_id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- PART 1: ADD SOFT DELETE COLUMNS
-- ============================================================================

-- Add deleted_at to products table
ALTER TABLE `products` 
ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;

-- Add index for soft delete queries
CREATE INDEX `idx_products_deleted` ON `products`(`deleted_at`);

-- Add deleted_at to customers table
ALTER TABLE `customers` 
ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `last_login_at`;

-- Add index for soft delete queries
CREATE INDEX `idx_customers_deleted` ON `customers`(`deleted_at`);

-- Add deleted_at to s_stock table (sales rep van stock)
ALTER TABLE `s_stock` 
ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;

-- Add index for soft delete queries
CREATE INDEX `idx_s_stock_deleted` ON `s_stock`(`deleted_at`);

-- ============================================================================
-- PART 2: STOCK TABLE CONSOLIDATION
-- ============================================================================

-- The system has two stock tables: s_stock and van_stock_items
-- We will consolidate to use ONLY s_stock as the source of truth
-- van_stock_items will be deprecated but kept for backward compatibility

-- First, migrate any data from van_stock_items to s_stock that doesn't exist
INSERT INTO `s_stock` (`salesperson_id`, `product_id`, `qty_on_hand`, `created_at`, `updated_at`)
SELECT 
    vsi.sales_rep_id,
    vsi.product_id,
    vsi.quantity,
    COALESCE(vsi.created_at, NOW()),
    COALESCE(vsi.updated_at, NOW())
FROM `van_stock_items` vsi
LEFT JOIN `s_stock` ss ON ss.salesperson_id = vsi.sales_rep_id AND ss.product_id = vsi.product_id
WHERE ss.id IS NULL
ON DUPLICATE KEY UPDATE 
    qty_on_hand = s_stock.qty_on_hand + VALUES(qty_on_hand),
    updated_at = NOW();

-- Add a deprecated flag to van_stock_items for documentation
-- (We don't delete it to maintain backward compatibility)
ALTER TABLE `van_stock_items` 
ADD COLUMN `migrated_to_s_stock` TINYINT(1) NOT NULL DEFAULT 1 AFTER `updated_at`,
ADD COLUMN `migration_note` VARCHAR(255) DEFAULT 'Deprecated: Use s_stock table instead' AFTER `migrated_to_s_stock`;

-- ============================================================================
-- PART 3: FIX NEGATIVE STOCK AND ADD INTEGRITY CONSTRAINTS
-- ============================================================================

-- First, fix any negative stock values (set to 0)
UPDATE `products` SET `quantity_on_hand` = 0 WHERE `quantity_on_hand` < 0;
UPDATE `s_stock` SET `qty_on_hand` = 0 WHERE `qty_on_hand` < 0;

-- Add check constraint to prevent negative stock (MariaDB 10.2.1+)
-- Note: MariaDB supports CHECK constraints but they are enforced from 10.2.1+
ALTER TABLE `s_stock` 
ADD CONSTRAINT `chk_s_stock_non_negative` CHECK (`qty_on_hand` >= 0);

ALTER TABLE `products` 
ADD CONSTRAINT `chk_products_stock_non_negative` CHECK (`quantity_on_hand` >= 0);

-- ============================================================================
-- PART 4: CREATE UNIFIED STOCK VIEW
-- ============================================================================

-- Drop view if exists
DROP VIEW IF EXISTS `v_unified_stock`;

-- Create unified stock view for easy querying across warehouse and van stock
CREATE VIEW `v_unified_stock` AS
SELECT 
    'warehouse' as location_type,
    NULL as salesperson_id,
    NULL as salesperson_name,
    p.id as product_id,
    p.sku,
    p.item_name,
    p.quantity_on_hand as qty_available,
    p.wholesale_price_usd,
    p.is_active,
    p.deleted_at
FROM products p
WHERE p.deleted_at IS NULL

UNION ALL

SELECT 
    'van' as location_type,
    ss.salesperson_id,
    u.name as salesperson_name,
    ss.product_id,
    p.sku,
    p.item_name,
    ss.qty_on_hand as qty_available,
    p.wholesale_price_usd,
    p.is_active,
    ss.deleted_at
FROM s_stock ss
INNER JOIN products p ON p.id = ss.product_id
INNER JOIN users u ON u.id = ss.salesperson_id
WHERE ss.deleted_at IS NULL AND p.deleted_at IS NULL;

-- ============================================================================
-- PART 5: CREATE STOCK AVAILABILITY FUNCTION VIEW
-- ============================================================================

-- Drop view if exists
DROP VIEW IF EXISTS `v_product_availability`;

-- Create view for product availability (used by customer portal, admin, sales)
CREATE VIEW `v_product_availability` AS
SELECT 
    p.id as product_id,
    p.sku,
    p.item_name,
    p.wholesale_price_usd,
    p.sale_price_usd,
    p.quantity_on_hand as warehouse_stock,
    COALESCE(van_totals.total_van_stock, 0) as total_van_stock,
    p.quantity_on_hand + COALESCE(van_totals.total_van_stock, 0) as total_system_stock,
    p.is_active,
    p.topcat,
    p.midcat,
    p.image_url,
    p.deleted_at
FROM products p
LEFT JOIN (
    SELECT 
        product_id,
        SUM(qty_on_hand) as total_van_stock
    FROM s_stock
    WHERE deleted_at IS NULL
    GROUP BY product_id
) van_totals ON van_totals.product_id = p.id
WHERE p.deleted_at IS NULL;

-- ============================================================================
-- PART 6: ADD INDEXES FOR PERFORMANCE
-- ============================================================================

-- Index for stock lookups by salesperson
CREATE INDEX IF NOT EXISTS `idx_s_stock_salesperson` ON `s_stock`(`salesperson_id`, `deleted_at`);

-- Index for stock movements
CREATE INDEX IF NOT EXISTS `idx_s_stock_movements_created` ON `s_stock_movements`(`created_at`);
CREATE INDEX IF NOT EXISTS `idx_s_stock_movements_reason` ON `s_stock_movements`(`reason`);

-- Index for warehouse movements
CREATE INDEX IF NOT EXISTS `idx_warehouse_movements_created` ON `warehouse_movements`(`created_at`);

-- Index for product filtering
CREATE INDEX IF NOT EXISTS `idx_products_wholesale_price` ON `products`(`wholesale_price_usd`);
CREATE INDEX IF NOT EXISTS `idx_products_stock_price` ON `products`(`quantity_on_hand`, `wholesale_price_usd`);

-- ============================================================================
-- PART 7: CREATE SETTINGS FOR PRODUCT FILTERING
-- ============================================================================

-- Add settings for product visibility filtering
INSERT INTO `settings` (`k`, `v`) VALUES 
('product_filter.hide_zero_stock', '0'),
('product_filter.hide_zero_price', '0'),
('product_filter.min_quantity_threshold', '0'),
('product_filter.hide_zero_stock_and_price', '0')
ON DUPLICATE KEY UPDATE `k` = `k`;

-- ============================================================================
-- ============================================================================
-- 7. CREATE WAREHOUSE STOCK ADJUSTMENTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `warehouse_stock_adjustments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `performed_by` BIGINT UNSIGNED NULL,
    `adjustment_type` ENUM('add', 'remove', 'set') NOT NULL,
    `delta_qty` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `previous_qty` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `new_qty` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `reason` VARCHAR(50) NOT NULL,
    `note` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_wsa_product` (`product_id`),
    INDEX `idx_wsa_performed_by` (`performed_by`),
    INDEX `idx_wsa_created` (`created_at`),
    INDEX `idx_wsa_reason` (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- VERIFICATION QUERIES (Run these to verify migration)
-- ============================================================================

-- Check soft delete columns exist
-- SELECT COUNT(*) FROM information_schema.columns 
-- WHERE table_schema = 'salamaehtools' AND column_name = 'deleted_at';

-- Check stock consolidation
-- SELECT 'van_stock_items' as tbl, COUNT(*) as cnt FROM van_stock_items
-- UNION ALL
-- SELECT 's_stock' as tbl, COUNT(*) as cnt FROM s_stock;

-- Check unified view works
-- SELECT location_type, COUNT(*) FROM v_unified_stock GROUP BY location_type;
