-- ============================================================================
-- CUSTOMER RETURNS SYSTEM - ROLLBACK
-- ============================================================================
-- Drops tables created by customer_returns_UP.sql
-- Run this BEFORE running customer_returns_UP.sql to reset
-- ============================================================================

-- Drop foreign key constraints first (if they exist)
SET FOREIGN_KEY_CHECKS = 0;

-- Drop return items table
DROP TABLE IF EXISTS customer_return_items;

-- Drop main returns table
DROP TABLE IF EXISTS customer_returns;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- NOTES
-- ============================================================================
-- This will permanently delete all customer return data!
-- Make sure to backup the database before running this migration.
-- ============================================================================
