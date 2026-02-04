-- ============================================================================
-- ORDER LIFECYCLE & MOVEMENT TRACKING SYSTEM
-- ============================================================================
-- This migration creates tables and constraints for comprehensive order
-- tracking, audit logging, and inventory movement management.
--
-- Order Status Lifecycle:
--   pending → on_hold → ready_for_handover → handed_to_sales_rep → completed
--                    ↘ cancelled (terminal state)
--
-- Stock Movement Rules:
--   - Stock is NOT deducted when order is created or marked ready
--   - Stock is ONLY deducted when sales rep accepts the order
--   - All movements are atomic and logged
-- ============================================================================

-- ============================================================================
-- 1. ORDER ACTIONS LOG (Audit Trail)
-- ============================================================================
-- Every action on an order is logged here for full auditability

CREATE TABLE IF NOT EXISTS order_actions_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM(
        'created',
        'viewed',
        'stock_checked',
        'put_on_hold',
        'resumed',
        'cancelled',
        'marked_ready',
        'assigned_to_rep',
        'accepted_by_rep',
        'rejected_by_rep',
        'handed_over',
        'completed',
        'notes_updated',
        'status_changed'
    ) NOT NULL,
    previous_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) DEFAULT NULL,
    performed_by BIGINT UNSIGNED NOT NULL,
    performed_by_role ENUM('warehouse', 'sales_rep', 'admin', 'system') NOT NULL,
    reason TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_order_id (order_id),
    INDEX idx_action_type (action_type),
    INDEX idx_performed_by (performed_by),
    INDEX idx_created_at (created_at),
    INDEX idx_order_action_time (order_id, created_at),

    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. INVENTORY MOVEMENTS TABLE
-- ============================================================================
-- Tracks all stock movements between warehouse and sales reps
-- This is the source of truth for inventory reconciliation

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_type ENUM(
        'order_fulfillment',      -- Stock moved to sales rep for order
        'order_cancellation',     -- Stock returned due to order cancel (if already moved)
        'van_loading',            -- Direct van loading (OTP flow)
        'van_return',             -- Stock returned from van to warehouse
        'adjustment_in',          -- Manual adjustment (increase)
        'adjustment_out',         -- Manual adjustment (decrease)
        'receiving',              -- Stock received from supplier
        'damage',                 -- Stock written off as damaged
        'transfer'                -- Transfer between locations
    ) NOT NULL,

    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12, 3) NOT NULL,

    -- Source/Destination
    from_location_type ENUM('warehouse', 'sales_rep', 'supplier', 'adjustment') NOT NULL,
    from_location_id BIGINT UNSIGNED DEFAULT NULL,
    to_location_type ENUM('warehouse', 'sales_rep', 'customer', 'adjustment') NOT NULL,
    to_location_id BIGINT UNSIGNED DEFAULT NULL,

    -- Stock levels BEFORE this movement (for reconciliation)
    warehouse_stock_before DECIMAL(12, 3) DEFAULT NULL,
    warehouse_stock_after DECIMAL(12, 3) DEFAULT NULL,
    sales_rep_stock_before DECIMAL(12, 3) DEFAULT NULL,
    sales_rep_stock_after DECIMAL(12, 3) DEFAULT NULL,

    -- Reference to related entities
    order_id BIGINT UNSIGNED DEFAULT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    reference_id VARCHAR(100) DEFAULT NULL,

    -- Audit
    performed_by BIGINT UNSIGNED NOT NULL,
    reason TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_movement_type (movement_type),
    INDEX idx_product_id (product_id),
    INDEX idx_order_id (order_id),
    INDEX idx_from_location (from_location_type, from_location_id),
    INDEX idx_to_location (to_location_type, to_location_id),
    INDEX idx_created_at (created_at),
    INDEX idx_reference (reference_type, reference_id),

    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. ORDER STATUS TRANSITIONS TABLE
-- ============================================================================
-- Defines valid status transitions to enforce at the application level

CREATE TABLE IF NOT EXISTS order_status_transitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_status VARCHAR(50) NOT NULL,
    to_status VARCHAR(50) NOT NULL,
    allowed_roles SET('warehouse', 'sales_rep', 'admin') NOT NULL,
    requires_reason TINYINT(1) DEFAULT 0,
    description VARCHAR(255) DEFAULT NULL,

    UNIQUE KEY unique_transition (from_status, to_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert valid transitions
INSERT INTO order_status_transitions (from_status, to_status, allowed_roles, requires_reason, description) VALUES
-- From pending
('pending', 'on_hold', 'warehouse,admin', 1, 'Put order on hold'),
('pending', 'cancelled', 'warehouse,admin', 1, 'Cancel pending order'),
('pending', 'ready_for_handover', 'warehouse,admin', 0, 'Mark order as ready for handover'),

-- From on_hold
('on_hold', 'pending', 'warehouse,admin', 0, 'Resume order from hold'),
('on_hold', 'cancelled', 'warehouse,admin', 1, 'Cancel held order'),
('on_hold', 'ready_for_handover', 'warehouse,admin', 0, 'Mark held order as ready'),

-- From ready_for_handover
('ready_for_handover', 'on_hold', 'warehouse,admin', 1, 'Put ready order on hold'),
('ready_for_handover', 'cancelled', 'warehouse,admin', 1, 'Cancel ready order'),
('ready_for_handover', 'handed_to_sales_rep', 'sales_rep,warehouse,admin', 0, 'Sales rep accepts order'),

-- From handed_to_sales_rep
('handed_to_sales_rep', 'completed', 'sales_rep,warehouse,admin', 0, 'Complete the order'),
('handed_to_sales_rep', 'cancelled', 'admin', 1, 'Admin cancels handed order (requires stock reversal)'),

-- From approved (legacy status support)
('approved', 'on_hold', 'warehouse,admin', 1, 'Put approved order on hold'),
('approved', 'cancelled', 'warehouse,admin', 1, 'Cancel approved order'),
('approved', 'ready_for_handover', 'warehouse,admin', 0, 'Mark approved order as ready'),

-- From preparing (legacy status support)
('preparing', 'on_hold', 'warehouse,admin', 1, 'Put preparing order on hold'),
('preparing', 'cancelled', 'warehouse,admin', 1, 'Cancel preparing order'),
('preparing', 'ready_for_handover', 'warehouse,admin', 0, 'Mark preparing order as ready')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- ============================================================================
-- 4. ORDER HANDOVER RECORDS
-- ============================================================================
-- Tracks the handover process between warehouse and sales rep

CREATE TABLE IF NOT EXISTS order_handovers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,

    -- Warehouse side
    prepared_by BIGINT UNSIGNED NOT NULL,
    prepared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    warehouse_otp VARCHAR(6) DEFAULT NULL,
    warehouse_confirmed_at TIMESTAMP NULL DEFAULT NULL,

    -- Sales rep side
    sales_rep_id BIGINT UNSIGNED NOT NULL,
    sales_rep_otp VARCHAR(6) DEFAULT NULL,
    sales_rep_confirmed_at TIMESTAMP NULL DEFAULT NULL,

    -- Stock movement
    stock_transferred_at TIMESTAMP NULL DEFAULT NULL,
    stock_movement_ids JSON DEFAULT NULL,

    -- Completion
    status ENUM('pending', 'warehouse_ready', 'sales_rep_accepted', 'completed', 'cancelled') DEFAULT 'pending',
    completed_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    cancellation_reason TEXT DEFAULT NULL,

    -- Expiry
    expires_at DATETIME NOT NULL,

    INDEX idx_order_id (order_id),
    INDEX idx_sales_rep_id (sales_rep_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at),

    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (sales_rep_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. ORDER TRANSFER OTPs (Two-Factor Verification for Handovers)
-- ============================================================================
-- Tracks OTP codes for warehouse-to-sales-rep handover verification

CREATE TABLE IF NOT EXISTS order_transfer_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    
    -- OTP codes
    warehouse_otp VARCHAR(6) NOT NULL,
    sales_rep_otp VARCHAR(6) NOT NULL,
    
    -- Verification timestamps
    warehouse_verified_at TIMESTAMP NULL DEFAULT NULL,
    warehouse_verified_by BIGINT UNSIGNED NULL,
    sales_rep_verified_at TIMESTAMP NULL DEFAULT NULL,
    sales_rep_verified_by BIGINT UNSIGNED NULL,
    
    -- Expiration
    expires_at DATETIME NOT NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_order_id (order_id),
    INDEX idx_expires_at (expires_at),
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_verified_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (sales_rep_verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. ADD NEW STATUS VALUES TO ORDERS (if needed)
-- ============================================================================
-- Modify the orders table to support new statuses

-- First check and add 'ready_for_handover' and 'handed_to_sales_rep' if they don't exist
-- Note: This is handled programmatically since ALTER ENUM is tricky

-- ============================================================================
-- 6. CREATE VIEWS FOR REPORTING
-- ============================================================================

-- View: Order with last action
CREATE OR REPLACE VIEW v_order_last_action AS
SELECT
    o.id AS order_id,
    o.order_number,
    o.status,
    oal.action_type AS last_action,
    oal.created_at AS last_action_at,
    oal.performed_by AS last_action_by,
    u.name AS last_action_by_name
FROM orders o
LEFT JOIN order_actions_log oal ON oal.id = (
    SELECT id FROM order_actions_log
    WHERE order_id = o.id
    ORDER BY created_at DESC
    LIMIT 1
)
LEFT JOIN users u ON u.id = oal.performed_by;

-- View: Order action summary
CREATE OR REPLACE VIEW v_order_action_summary AS
SELECT
    order_id,
    COUNT(*) AS total_actions,
    MIN(created_at) AS first_action_at,
    MAX(created_at) AS last_action_at,
    SUM(CASE WHEN action_type = 'put_on_hold' THEN 1 ELSE 0 END) AS times_on_hold,
    SUM(CASE WHEN action_type = 'stock_checked' THEN 1 ELSE 0 END) AS times_stock_checked
FROM order_actions_log
GROUP BY order_id;

-- ============================================================================
-- 7. INDEXES FOR PERFORMANCE
-- ============================================================================

-- Add index on orders.status if not exists (for filtering)
-- CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);

-- ============================================================================
-- NOTES FOR IMPLEMENTATION
-- ============================================================================
--
-- 1. Order Status Lifecycle:
--    pending → on_hold (reversible)
--    pending → cancelled (terminal)
--    pending → ready_for_handover → handed_to_sales_rep → completed
--
-- 2. Stock Movement Rules:
--    - NO stock deduction on 'marked_ready'
--    - Stock deduction ONLY on 'accepted_by_rep' (handed_to_sales_rep)
--    - If order cancelled AFTER stock transfer, must reverse the movement
--
-- 3. Concurrency Handling:
--    - Use SELECT ... FOR UPDATE when checking/modifying stock
--    - Wrap stock operations in transactions
--    - Use optimistic locking (check status before update)
--
-- 4. Double-click Prevention:
--    - Check current status before allowing transition
--    - Use unique constraints where applicable
--    - Return idempotent responses
-- ============================================================================
