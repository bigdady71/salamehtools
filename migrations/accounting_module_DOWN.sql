-- Accounting Module DOWN Migration
-- Run this to rollback the accounting module tables

-- Drop views first
DROP VIEW IF EXISTS v_customer_outstanding;
DROP VIEW IF EXISTS v_sales_rep_commission_summary;
DROP VIEW IF EXISTS v_inventory_valuation;

-- Drop tables in reverse order of creation (respecting foreign keys)
DROP TABLE IF EXISTS commission_payment_items;
DROP TABLE IF EXISTS commission_payments;
DROP TABLE IF EXISTS commission_calculations;
DROP TABLE IF EXISTS commission_rates;
DROP TABLE IF EXISTS customer_balance_adjustments;

-- Note: We do NOT remove the columns added to customers table
-- as that could cause data loss. If you need to remove them:
-- ALTER TABLE customers DROP COLUMN credit_limit_usd;
-- ALTER TABLE customers DROP COLUMN credit_limit_lbp;
-- ALTER TABLE customers DROP COLUMN payment_terms_days;
-- ALTER TABLE customers DROP COLUMN credit_hold;
