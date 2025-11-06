-- Week 2 Performance Optimization: Rollback Indexes
-- Purpose: Remove performance indexes if needed
-- Use: Only if indexes cause issues (rare)

-- =====================================================
-- DROP ORDERS INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_orders_status_created ON orders;
-- Keep idx_orders_invoice_ready (from Phase 4)

-- =====================================================
-- DROP INVOICES INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_invoices_status_created ON invoices;
DROP INDEX IF EXISTS idx_invoices_issued_at ON invoices;

-- =====================================================
-- DROP PAYMENTS INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_payments_invoice_received ON payments;
DROP INDEX IF EXISTS idx_payments_received_at ON payments;

-- =====================================================
-- DROP ORDER_ITEMS INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_order_items_product ON order_items;
DROP INDEX IF EXISTS idx_order_items_order_product ON order_items;

-- =====================================================
-- DROP PRODUCTS INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_products_active ON products;
DROP INDEX IF EXISTS idx_products_active_name ON products;

-- =====================================================
-- DROP CUSTOMERS INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_customers_sales_rep ON customers;

-- =====================================================
-- DROP AR_FOLLOWUPS INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_ar_followups_due_at ON ar_followups;
DROP INDEX IF EXISTS idx_ar_followups_assigned_due ON ar_followups;

-- =====================================================
-- DROP WAREHOUSE_MOVEMENTS INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_warehouse_movements_product_date ON warehouse_movements;
DROP INDEX IF EXISTS idx_warehouse_movements_created ON warehouse_movements;

-- =====================================================
-- DROP EXCHANGE_RATES INDEXES
-- =====================================================

DROP INDEX IF EXISTS idx_exchange_rates_currencies_date ON exchange_rates;

-- Verify indexes removed
-- SHOW INDEX FROM orders;
-- SHOW INDEX FROM invoices;
-- SHOW INDEX FROM payments;
