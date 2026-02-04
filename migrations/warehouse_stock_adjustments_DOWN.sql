-- ============================================================================
-- WAREHOUSE STOCK ADJUSTMENTS - ROLLBACK
-- ============================================================================
-- Drops tables created by warehouse_stock_adjustments_UP.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS warehouse_stock_adjustments;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- NOTES
-- ============================================================================
-- This will permanently delete all warehouse adjustment history!
-- Make sure to backup the database before running this migration.
-- ============================================================================
