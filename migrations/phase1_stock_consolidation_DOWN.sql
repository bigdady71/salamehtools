-- ============================================================================
-- PHASE 1: STOCK CONSOLIDATION & SOFT DELETE ROLLBACK
-- Date: 2026-02-01
-- Description: Rollback script for phase 1 migration
-- ============================================================================

-- Drop views
DROP VIEW IF EXISTS `v_product_availability`;
DROP VIEW IF EXISTS `v_unified_stock`;

-- Remove check constraints (if supported)
ALTER TABLE `s_stock` DROP CONSTRAINT IF EXISTS `chk_s_stock_non_negative`;
ALTER TABLE `products` DROP CONSTRAINT IF EXISTS `chk_products_stock_non_negative`;

-- Remove indexes
DROP INDEX IF EXISTS `idx_products_deleted` ON `products`;
DROP INDEX IF EXISTS `idx_customers_deleted` ON `customers`;
DROP INDEX IF EXISTS `idx_s_stock_deleted` ON `s_stock`;
DROP INDEX IF EXISTS `idx_s_stock_salesperson` ON `s_stock`;
DROP INDEX IF EXISTS `idx_s_stock_movements_created` ON `s_stock_movements`;
DROP INDEX IF EXISTS `idx_s_stock_movements_reason` ON `s_stock_movements`;
DROP INDEX IF EXISTS `idx_warehouse_movements_created` ON `warehouse_movements`;
DROP INDEX IF EXISTS `idx_products_wholesale_price` ON `products`;
DROP INDEX IF EXISTS `idx_products_stock_price` ON `products`;

-- Remove soft delete columns
ALTER TABLE `products` DROP COLUMN IF EXISTS `deleted_at`;
ALTER TABLE `customers` DROP COLUMN IF EXISTS `deleted_at`;
ALTER TABLE `s_stock` DROP COLUMN IF EXISTS `deleted_at`;

-- Remove van_stock_items migration columns
ALTER TABLE `van_stock_items` DROP COLUMN IF EXISTS `migrated_to_s_stock`;
ALTER TABLE `van_stock_items` DROP COLUMN IF EXISTS `migration_note`;

-- Remove settings
DELETE FROM `settings` WHERE `k` LIKE 'product_filter.%';
