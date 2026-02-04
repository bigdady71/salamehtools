-- Commission Calculations Table
-- Tracks sales rep commissions for reporting and payroll

CREATE TABLE IF NOT EXISTS commission_calculations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sales_rep_id INT NOT NULL,
    order_id INT NULL,
    invoice_id INT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    order_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
    order_total_lbp DECIMAL(15,2) NOT NULL DEFAULT 0,
    commission_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    commission_amount_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
    commission_amount_lbp DECIMAL(15,2) NOT NULL DEFAULT 0,
    -- Track actual amounts collected by currency (for commission based on collections)
    collected_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
    collected_lbp DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('calculated', 'approved', 'paid') NOT NULL DEFAULT 'calculated',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_sales_rep (sales_rep_id),
    INDEX idx_period (period_start, period_end),
    INDEX idx_status (status),
    INDEX idx_order (order_id),
    INDEX idx_invoice (invoice_id),

    FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
