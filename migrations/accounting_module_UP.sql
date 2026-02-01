-- Accounting Module UP Migration
-- Run this to create all tables needed for the accounting module

-- 1. Commission Rates Table
CREATE TABLE IF NOT EXISTS commission_rates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_rep_id BIGINT UNSIGNED NULL,
    commission_type ENUM('direct_sale', 'assigned_customer') NOT NULL,
    rate_percentage DECIMAL(5,2) NOT NULL DEFAULT 4.00,
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_sales_rep_id (sales_rep_id),
    INDEX idx_effective_dates (effective_from, effective_until),
    INDEX idx_commission_type (commission_type),

    CONSTRAINT fk_commission_rates_sales_rep FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_commission_rates_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Commission Calculations Table
CREATE TABLE IF NOT EXISTS commission_calculations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_rep_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NULL,
    commission_type ENUM('direct_sale', 'assigned_customer') NOT NULL,
    order_total_usd DECIMAL(14,2) NOT NULL,
    order_total_lbp DECIMAL(18,2) NOT NULL DEFAULT 0,
    rate_percentage DECIMAL(5,2) NOT NULL,
    commission_amount_usd DECIMAL(14,2) NOT NULL,
    commission_amount_lbp DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('calculated', 'approved', 'paid', 'cancelled') DEFAULT 'calculated',
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    calculation_notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_order_commission (order_id, commission_type),
    INDEX idx_sales_rep_id (sales_rep_id),
    INDEX idx_status (status),
    INDEX idx_period (period_start, period_end),
    INDEX idx_invoice_id (invoice_id),

    CONSTRAINT fk_commission_calc_sales_rep FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commission_calc_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commission_calc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_commission_calc_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Commission Payments Table
CREATE TABLE IF NOT EXISTS commission_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_rep_id BIGINT UNSIGNED NOT NULL,
    payment_reference VARCHAR(50) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_amount_usd DECIMAL(14,2) NOT NULL,
    total_amount_lbp DECIMAL(18,2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash', 'bank_transfer', 'check', 'other') NOT NULL,
    payment_date DATE NOT NULL,
    bank_reference VARCHAR(100) NULL,
    notes TEXT NULL,
    paid_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_sales_rep_id (sales_rep_id),
    INDEX idx_payment_date (payment_date),
    INDEX idx_period (period_start, period_end),

    CONSTRAINT fk_commission_pay_sales_rep FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commission_pay_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Commission Payment Items Table
CREATE TABLE IF NOT EXISTS commission_payment_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    calculation_id BIGINT UNSIGNED NOT NULL,
    amount_usd DECIMAL(14,2) NOT NULL,
    amount_lbp DECIMAL(18,2) NOT NULL DEFAULT 0,

    INDEX idx_payment_id (payment_id),
    INDEX idx_calculation_id (calculation_id),

    CONSTRAINT fk_commission_pay_item_payment FOREIGN KEY (payment_id) REFERENCES commission_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_commission_pay_item_calc FOREIGN KEY (calculation_id) REFERENCES commission_calculations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Customer Balance Adjustments Table (Audit Trail)
CREATE TABLE IF NOT EXISTS customer_balance_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    adjustment_type ENUM('credit', 'debit', 'correction', 'write_off', 'opening_balance') NOT NULL,
    amount_usd DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_lbp DECIMAL(18,2) NOT NULL DEFAULT 0,
    previous_balance_usd DECIMAL(14,2) NOT NULL DEFAULT 0,
    previous_balance_lbp DECIMAL(18,2) NOT NULL DEFAULT 0,
    new_balance_usd DECIMAL(14,2) NOT NULL DEFAULT 0,
    new_balance_lbp DECIMAL(18,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    performed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_customer_id (customer_id),
    INDEX idx_adjustment_type (adjustment_type),
    INDEX idx_created_at (created_at),
    INDEX idx_reference (reference_type, reference_id),

    CONSTRAINT fk_balance_adj_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_balance_adj_performed_by FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Modify Customers Table - Add Credit Fields (only if columns don't exist)
-- Check and add credit_limit_usd
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'credit_limit_usd');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN credit_limit_usd DECIMAL(14,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add credit_limit_lbp
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'credit_limit_lbp');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN credit_limit_lbp DECIMAL(18,2) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add payment_terms_days
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'payment_terms_days');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN payment_terms_days INT DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add credit_hold
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'credit_hold');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE customers ADD COLUMN credit_hold TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. Insert default commission rates (4% for both types)
INSERT INTO commission_rates (sales_rep_id, commission_type, rate_percentage, effective_from, created_by)
SELECT NULL, 'direct_sale', 4.00, CURDATE(), 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM commission_rates WHERE sales_rep_id IS NULL AND commission_type = 'direct_sale'
);

INSERT INTO commission_rates (sales_rep_id, commission_type, rate_percentage, effective_from, created_by)
SELECT NULL, 'assigned_customer', 4.00, CURDATE(), 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM commission_rates WHERE sales_rep_id IS NULL AND commission_type = 'assigned_customer'
);

-- 8. Create Inventory Valuation View
CREATE OR REPLACE VIEW v_inventory_valuation AS
SELECT
    p.id,
    p.sku,
    p.item_name,
    p.topcat_name AS category,
    p.quantity_on_hand,
    COALESCE(p.cost_price_usd, 0) AS cost_price_usd,
    COALESCE(p.sale_price_usd, 0) AS sale_price_usd,
    COALESCE(p.wholesale_price_usd, p.sale_price_usd, 0) AS wholesale_price_usd,
    (p.quantity_on_hand * COALESCE(p.cost_price_usd, 0)) AS total_cost_value,
    (p.quantity_on_hand * COALESCE(p.sale_price_usd, 0)) AS total_retail_value,
    (p.quantity_on_hand * COALESCE(p.wholesale_price_usd, p.sale_price_usd, 0)) AS total_wholesale_value,
    p.is_active
FROM products p;

-- 9. Create Sales Rep Commission Summary View
CREATE OR REPLACE VIEW v_sales_rep_commission_summary AS
SELECT
    u.id AS sales_rep_id,
    u.name AS sales_rep_name,
    u.is_active,
    COUNT(DISTINCT cc.order_id) AS total_orders,
    COALESCE(SUM(cc.commission_amount_usd), 0) AS total_commission_usd,
    COALESCE(SUM(cc.commission_amount_lbp), 0) AS total_commission_lbp,
    COALESCE(SUM(CASE WHEN cc.status = 'calculated' THEN cc.commission_amount_usd ELSE 0 END), 0) AS pending_usd,
    COALESCE(SUM(CASE WHEN cc.status = 'approved' THEN cc.commission_amount_usd ELSE 0 END), 0) AS approved_usd,
    COALESCE(SUM(CASE WHEN cc.status = 'paid' THEN cc.commission_amount_usd ELSE 0 END), 0) AS paid_usd
FROM users u
LEFT JOIN commission_calculations cc ON cc.sales_rep_id = u.id
WHERE u.role = 'sales_rep'
GROUP BY u.id, u.name, u.is_active;

-- 10. Create Customer Outstanding Balance View
CREATE OR REPLACE VIEW v_customer_outstanding AS
SELECT
    c.id AS customer_id,
    c.name AS customer_name,
    c.phone,
    c.assigned_sales_rep_id,
    COALESCE(u.name, 'Unassigned') AS sales_rep_name,
    COALESCE(c.credit_limit_usd, 0) AS credit_limit_usd,
    COALESCE(c.credit_limit_lbp, 0) AS credit_limit_lbp,
    COALESCE(c.account_balance_usd, 0) AS account_balance_usd,
    COALESCE(c.account_balance_lbp, 0) AS account_balance_lbp,
    COALESCE(c.credit_hold, 0) AS credit_hold,
    COALESCE(c.payment_terms_days, 0) AS payment_terms_days,
    c.is_active
FROM customers c
LEFT JOIN users u ON u.id = c.assigned_sales_rep_id;
