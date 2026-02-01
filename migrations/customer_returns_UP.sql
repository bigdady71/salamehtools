-- ============================================================================
-- CUSTOMER RETURNS SYSTEM
-- ============================================================================
-- Tracks product returns from customers to sales reps
-- - Credit refunds: Immediate credit to customer account
-- - Cash refunds: Require admin approval
-- - Returned items go back to sales rep van stock
-- ============================================================================

-- Customer Returns Table
CREATE TABLE IF NOT EXISTS customer_returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(50) NOT NULL UNIQUE,
    customer_id BIGINT UNSIGNED NOT NULL,
    sales_rep_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED DEFAULT NULL,
    order_id BIGINT UNSIGNED DEFAULT NULL,

    -- Amounts
    total_usd DECIMAL(12, 2) NOT NULL DEFAULT 0,
    total_lbp DECIMAL(15, 2) NOT NULL DEFAULT 0,
    exchange_rate_id BIGINT UNSIGNED DEFAULT NULL,

    -- Refund handling
    refund_method ENUM('credit', 'cash') NOT NULL DEFAULT 'credit',
    status ENUM('completed', 'pending_cash_approval', 'cash_approved', 'cash_rejected', 'cash_paid') NOT NULL DEFAULT 'completed',

    -- For credit refunds (immediate)
    credit_applied_at DATETIME DEFAULT NULL,

    -- For cash refunds (requires approval)
    cash_requested_at DATETIME DEFAULT NULL,
    approved_by BIGINT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    cash_paid_by BIGINT UNSIGNED DEFAULT NULL,
    cash_paid_at DATETIME DEFAULT NULL,

    -- General
    reason TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_customer_id (customer_id),
    INDEX idx_sales_rep_id (sales_rep_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_refund_method (refund_method),

    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (cash_paid_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Return Items Table
CREATE TABLE IF NOT EXISTS customer_return_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10, 3) NOT NULL,
    unit_price_usd DECIMAL(12, 2) NOT NULL,
    unit_price_lbp DECIMAL(15, 2) NOT NULL,
    line_total_usd DECIMAL(12, 2) NOT NULL,
    line_total_lbp DECIMAL(15, 2) NOT NULL,
    reason VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    stock_returned TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_return_id (return_id),
    INDEX idx_product_id (product_id),

    FOREIGN KEY (return_id) REFERENCES customer_returns(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- NOTES
-- ============================================================================
-- Status Flow:
--   Credit refund: created → completed (immediate)
--   Cash refund: created → pending_cash_approval → cash_approved/cash_rejected → cash_paid
--
-- Stock Movement:
--   When return created, items go back to s_stock (van inventory)
--   s_stock_movements logged with reason='customer_return'
--
-- Credit Application:
--   For credit refunds, customers.account_balance_lbp is increased
-- ============================================================================
