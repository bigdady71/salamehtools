-- Phase 4 DOWN Migration: Rollback invoice-ready column
-- Run this to undo Phase 4 changes

ALTER TABLE orders
    DROP INDEX IF EXISTS idx_orders_invoice_ready,
    DROP COLUMN IF EXISTS invoice_ready;
