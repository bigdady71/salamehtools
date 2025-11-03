-- Phase 3 DOWN Migration: Rollback warehouse tables and columns
-- Run this to undo Phase 3 changes

DROP TABLE IF EXISTS warehouse_movements;

ALTER TABLE products
    DROP INDEX IF EXISTS idx_products_safety,
    DROP INDEX IF EXISTS idx_products_reorder,
    DROP COLUMN IF EXISTS safety_stock,
    DROP COLUMN IF EXISTS reorder_point;
