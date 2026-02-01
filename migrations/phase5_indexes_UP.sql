-- Phase 5: Database Optimization Indexes
-- Run this script to add missing indexes for improved query performance
-- Safe to run multiple times (uses IF NOT EXISTS pattern)

-- ============================================
-- PRODUCTS TABLE INDEXES
-- ============================================

-- Index for filtering by wholesale price (used in product filtering)
-- Helps queries like: WHERE wholesale_price_usd > 0
CREATE INDEX IF NOT EXISTS idx_products_wholesale_price 
ON products(wholesale_price_usd);

-- Index for filtering by stock quantity (used in product filtering)
-- Helps queries like: WHERE quantity_on_hand >= X
CREATE INDEX IF NOT EXISTS idx_products_quantity 
ON products(quantity_on_hand);

-- Composite index for common product listing queries
-- Helps queries like: WHERE is_active = 1 AND wholesale_price_usd > 0 AND quantity_on_hand >= X
CREATE INDEX IF NOT EXISTS idx_products_active_price_stock 
ON products(is_active, wholesale_price_usd, quantity_on_hand);

-- Index for category filtering
-- Helps queries like: WHERE topcat_name = 'X'
CREATE INDEX IF NOT EXISTS idx_products_topcat 
ON products(topcat_name);

-- Composite index for soft delete + active filtering
-- Helps queries like: WHERE deleted_at IS NULL AND is_active = 1
CREATE INDEX IF NOT EXISTS idx_products_deleted_active 
ON products(deleted_at, is_active);

-- ============================================
-- ORDERS TABLE INDEXES
-- ============================================

-- Composite index for sales rep dashboard queries
-- Helps queries like: WHERE sales_rep_id = X AND status = 'Y' ORDER BY created_at DESC
CREATE INDEX IF NOT EXISTS idx_orders_rep_status_date 
ON orders(sales_rep_id, status, created_at);

-- Index for order type filtering
-- Helps queries like: WHERE order_type = 'van_sale'
CREATE INDEX IF NOT EXISTS idx_orders_type 
ON orders(order_type);

-- ============================================
-- INVOICES TABLE INDEXES
-- ============================================

-- Index for sales rep invoice lookups
-- Helps queries like: WHERE sales_rep_id = X ORDER BY issued_at DESC
CREATE INDEX IF NOT EXISTS idx_invoices_rep_issued 
ON invoices(sales_rep_id, issued_at);

-- Index for due date filtering
-- Helps queries like: WHERE due_date < NOW() AND status = 'issued'
CREATE INDEX IF NOT EXISTS idx_invoices_due_status 
ON invoices(due_date, status);

-- ============================================
-- PAYMENTS TABLE INDEXES
-- ============================================

-- Index for payment method filtering
-- Helps queries like: WHERE method = 'cash'
CREATE INDEX IF NOT EXISTS idx_payments_method 
ON payments(method);

-- ============================================
-- S_STOCK TABLE INDEXES
-- ============================================

-- Index for stock quantity filtering
-- Helps queries like: WHERE qty_on_hand > 0
CREATE INDEX IF NOT EXISTS idx_s_stock_qty 
ON s_stock(qty_on_hand);

-- Composite index for soft delete + salesperson filtering
-- Helps queries like: WHERE deleted_at IS NULL AND salesperson_id = X
CREATE INDEX IF NOT EXISTS idx_s_stock_deleted_rep 
ON s_stock(deleted_at, salesperson_id);

-- ============================================
-- CUSTOMERS TABLE INDEXES
-- ============================================

-- Index for customer name search
-- Helps queries like: WHERE name LIKE 'X%'
CREATE INDEX IF NOT EXISTS idx_customers_name 
ON customers(name(100));

-- Index for city filtering
-- Helps queries like: WHERE city = 'X'
CREATE INDEX IF NOT EXISTS idx_customers_city 
ON customers(city);

-- Composite index for soft delete + sales rep filtering
-- Helps queries like: WHERE deleted_at IS NULL AND assigned_sales_rep_id = X
CREATE INDEX IF NOT EXISTS idx_customers_deleted_rep 
ON customers(deleted_at, assigned_sales_rep_id);

-- ============================================
-- VAN_RESTOCK_REQUESTS TABLE INDEXES
-- ============================================

-- Index for date-based queries
-- Helps queries like: ORDER BY submitted_at DESC
CREATE INDEX IF NOT EXISTS idx_restock_submitted 
ON van_restock_requests(submitted_at);

-- Index for fulfilled requests
-- Helps queries like: ORDER BY fulfilled_at DESC
CREATE INDEX IF NOT EXISTS idx_restock_fulfilled 
ON van_restock_requests(fulfilled_at);

-- ============================================
-- USERS TABLE INDEXES
-- ============================================

-- Index for role-based queries
-- Helps queries like: WHERE role = 'sales_rep'
CREATE INDEX IF NOT EXISTS idx_users_role 
ON users(role);

-- Index for active user filtering
-- Helps queries like: WHERE is_active = 1 AND role = 'X'
CREATE INDEX IF NOT EXISTS idx_users_active_role 
ON users(is_active, role);

-- ============================================
-- SETTINGS TABLE INDEXES
-- ============================================

-- Index for settings key lookup (if not already indexed)
CREATE INDEX IF NOT EXISTS idx_settings_key 
ON settings(k);

-- ============================================
-- ANALYZE TABLES (Update statistics for optimizer)
-- ============================================

ANALYZE TABLE products;
ANALYZE TABLE orders;
ANALYZE TABLE order_items;
ANALYZE TABLE invoices;
ANALYZE TABLE payments;
ANALYZE TABLE customers;
ANALYZE TABLE s_stock;
ANALYZE TABLE s_stock_movements;
ANALYZE TABLE warehouse_movements;
ANALYZE TABLE van_restock_requests;
ANALYZE TABLE van_restock_items;
ANALYZE TABLE users;
