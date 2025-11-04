-- Phase 4 UP Migration: Orders invoice-ready logic
-- Run this to add the invoice_ready column to orders table

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS invoice_ready TINYINT(1) NOT NULL DEFAULT 0,
    ADD INDEX IF NOT EXISTS idx_orders_invoice_ready (invoice_ready);
