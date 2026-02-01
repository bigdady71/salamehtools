-- Phase 5: Database Optimization Indexes - ROLLBACK
-- Run this script to remove indexes added in phase5_indexes_UP.sql

-- Products indexes
DROP INDEX IF EXISTS idx_products_wholesale_price ON products;
DROP INDEX IF EXISTS idx_products_quantity ON products;
DROP INDEX IF EXISTS idx_products_active_price_stock ON products;
DROP INDEX IF EXISTS idx_products_topcat ON products;
DROP INDEX IF EXISTS idx_products_deleted_active ON products;

-- Orders indexes
DROP INDEX IF EXISTS idx_orders_rep_status_date ON orders;
DROP INDEX IF EXISTS idx_orders_type ON orders;

-- Invoices indexes
DROP INDEX IF EXISTS idx_invoices_rep_issued ON invoices;
DROP INDEX IF EXISTS idx_invoices_due_status ON invoices;

-- Payments indexes
DROP INDEX IF EXISTS idx_payments_method ON payments;

-- S_stock indexes
DROP INDEX IF EXISTS idx_s_stock_qty ON s_stock;
DROP INDEX IF EXISTS idx_s_stock_deleted_rep ON s_stock;

-- Customers indexes
DROP INDEX IF EXISTS idx_customers_name ON customers;
DROP INDEX IF EXISTS idx_customers_city ON customers;
DROP INDEX IF EXISTS idx_customers_deleted_rep ON customers;

-- Van restock indexes
DROP INDEX IF EXISTS idx_restock_submitted ON van_restock_requests;
DROP INDEX IF EXISTS idx_restock_fulfilled ON van_restock_requests;

-- Users indexes
DROP INDEX IF EXISTS idx_users_role ON users;
DROP INDEX IF EXISTS idx_users_active_role ON users;

-- Settings indexes
DROP INDEX IF EXISTS idx_settings_key ON settings;
