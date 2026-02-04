-- ============================================================================
-- COMMISSION CALCULATIONS - ROLLBACK
-- ============================================================================
-- Drops tables created by commission_calculations_UP.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS commission_calculations;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- NOTES
-- ============================================================================
-- This will permanently delete all commission calculation data!
-- Make sure to backup the database before running this migration.
-- ============================================================================
