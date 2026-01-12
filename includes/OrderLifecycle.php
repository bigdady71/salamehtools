<?php
/**
 * OrderLifecycle - Manages order state transitions and inventory movements
 *
 * Order Status Lifecycle:
 *   pending → on_hold → ready_for_handover → handed_to_sales_rep → completed
 *                    ↘ cancelled (terminal state)
 *
 * Stock Movement Rules:
 *   - Stock is NOT deducted when order is created or marked ready
 *   - Stock is ONLY deducted when sales rep accepts the order
 *   - All movements are atomic and logged
 */

class OrderLifecycle {
    private PDO $pdo;
    private ?int $userId = null;
    private ?string $userRole = null;

    // Valid order statuses
    const STATUS_PENDING = 'pending';
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_READY = 'ready_for_handover';
    const STATUS_HANDED = 'handed_to_sales_rep';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Legacy statuses (for backward compatibility)
    const STATUS_APPROVED = 'approved';
    const STATUS_PREPARING = 'preparing';

    // Action types
    const ACTION_CREATED = 'created';
    const ACTION_VIEWED = 'viewed';
    const ACTION_STOCK_CHECKED = 'stock_checked';
    const ACTION_PUT_ON_HOLD = 'put_on_hold';
    const ACTION_RESUMED = 'resumed';
    const ACTION_CANCELLED = 'cancelled';
    const ACTION_MARKED_READY = 'marked_ready';
    const ACTION_ASSIGNED_TO_REP = 'assigned_to_rep';
    const ACTION_ACCEPTED_BY_REP = 'accepted_by_rep';
    const ACTION_REJECTED_BY_REP = 'rejected_by_rep';
    const ACTION_HANDED_OVER = 'handed_over';
    const ACTION_COMPLETED = 'completed';
    const ACTION_NOTES_UPDATED = 'notes_updated';
    const ACTION_STATUS_CHANGED = 'status_changed';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Set the current user context
     */
    public function setUser(int $userId, string $role): self {
        $this->userId = $userId;
        $this->userRole = $role;
        return $this;
    }

    /**
     * Get valid transitions for a given status
     */
    public function getValidTransitions(string $fromStatus): array {
        $stmt = $this->pdo->prepare("
            SELECT to_status, allowed_roles, requires_reason, description
            FROM order_status_transitions
            WHERE from_status = :from_status
        ");
        $stmt->execute(['from_status' => $fromStatus]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a transition is valid
     */
    public function isTransitionValid(string $fromStatus, string $toStatus, string $role): bool {
        $stmt = $this->pdo->prepare("
            SELECT id FROM order_status_transitions
            WHERE from_status = :from_status
            AND to_status = :to_status
            AND FIND_IN_SET(:role, allowed_roles) > 0
        ");
        $stmt->execute([
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'role' => $role
        ]);
        return $stmt->fetch() !== false;
    }

    /**
     * Check if transition requires a reason
     */
    public function transitionRequiresReason(string $fromStatus, string $toStatus): bool {
        $stmt = $this->pdo->prepare("
            SELECT requires_reason FROM order_status_transitions
            WHERE from_status = :from_status AND to_status = :to_status
        ");
        $stmt->execute(['from_status' => $fromStatus, 'to_status' => $toStatus]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['requires_reason'] == 1;
    }

    /**
     * Log an action on an order
     */
    public function logAction(
        int $orderId,
        string $actionType,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $reason = null,
        ?string $notes = null,
        ?array $metadata = null
    ): int {
        if (!$this->userId) {
            throw new Exception('User context not set. Call setUser() first.');
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO order_actions_log
            (order_id, action_type, previous_status, new_status, performed_by, performed_by_role, reason, notes, metadata, ip_address)
            VALUES
            (:order_id, :action_type, :previous_status, :new_status, :performed_by, :performed_by_role, :reason, :notes, :metadata, :ip_address)
        ");

        $stmt->execute([
            'order_id' => $orderId,
            'action_type' => $actionType,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'performed_by' => $this->userId,
            'performed_by_role' => $this->userRole,
            'reason' => $reason,
            'notes' => $notes,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Get order with lock for update (prevents concurrent modifications)
     */
    public function getOrderForUpdate(int $orderId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT o.*, u.name as sales_rep_name
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE o.id = :id
            FOR UPDATE
        ");
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get order items
     */
    public function getOrderItems(int $orderId): array {
        $stmt = $this->pdo->prepare("
            SELECT oi.*, p.name as product_name, p.quantity_on_hand as warehouse_stock
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check stock availability for an order
     */
    public function checkStockAvailability(int $orderId): array {
        $items = $this->getOrderItems($orderId);
        $result = [
            'all_available' => true,
            'items' => []
        ];

        foreach ($items as $item) {
            $available = $item['warehouse_stock'] >= $item['quantity'];
            $result['items'][] = [
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'required' => $item['quantity'],
                'available' => $item['warehouse_stock'],
                'is_available' => $available,
                'shortage' => $available ? 0 : $item['quantity'] - $item['warehouse_stock']
            ];
            if (!$available) {
                $result['all_available'] = false;
            }
        }

        return $result;
    }

    /**
     * Put order on hold
     */
    public function putOnHold(int $orderId, string $reason): array {
        $this->pdo->beginTransaction();

        try {
            $order = $this->getOrderForUpdate($orderId);

            if (!$order) {
                throw new Exception('Order not found');
            }

            if (!$this->isTransitionValid($order['status'], self::STATUS_ON_HOLD, $this->userRole)) {
                throw new Exception("Cannot put order on hold from status: {$order['status']}");
            }

            // Update order status
            $stmt = $this->pdo->prepare("
                UPDATE orders SET status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['status' => self::STATUS_ON_HOLD, 'id' => $orderId]);

            // Log action
            $this->logAction(
                $orderId,
                self::ACTION_PUT_ON_HOLD,
                $order['status'],
                self::STATUS_ON_HOLD,
                $reason
            );

            $this->pdo->commit();

            return ['success' => true, 'message' => 'Order put on hold'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Resume order from hold
     */
    public function resumeOrder(int $orderId): array {
        $this->pdo->beginTransaction();

        try {
            $order = $this->getOrderForUpdate($orderId);

            if (!$order) {
                throw new Exception('Order not found');
            }

            if ($order['status'] !== self::STATUS_ON_HOLD) {
                throw new Exception('Order is not on hold');
            }

            // Update order status back to pending
            $stmt = $this->pdo->prepare("
                UPDATE orders SET status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['status' => self::STATUS_PENDING, 'id' => $orderId]);

            // Log action
            $this->logAction(
                $orderId,
                self::ACTION_RESUMED,
                self::STATUS_ON_HOLD,
                self::STATUS_PENDING
            );

            $this->pdo->commit();

            return ['success' => true, 'message' => 'Order resumed'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Cancel order
     */
    public function cancelOrder(int $orderId, string $reason): array {
        $this->pdo->beginTransaction();

        try {
            $order = $this->getOrderForUpdate($orderId);

            if (!$order) {
                throw new Exception('Order not found');
            }

            if (!$this->isTransitionValid($order['status'], self::STATUS_CANCELLED, $this->userRole)) {
                throw new Exception("Cannot cancel order from status: {$order['status']}");
            }

            // If order was handed to sales rep, we need to reverse stock movement
            if ($order['status'] === self::STATUS_HANDED) {
                $this->reverseStockMovement($orderId);
            }

            // Update order status
            $stmt = $this->pdo->prepare("
                UPDATE orders SET status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['status' => self::STATUS_CANCELLED, 'id' => $orderId]);

            // Log action
            $this->logAction(
                $orderId,
                self::ACTION_CANCELLED,
                $order['status'],
                self::STATUS_CANCELLED,
                $reason
            );

            $this->pdo->commit();

            return ['success' => true, 'message' => 'Order cancelled'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Mark order as ready for handover (NO STOCK DEDUCTION)
     */
    public function markAsReady(int $orderId, ?string $notes = null): array {
        $this->pdo->beginTransaction();

        try {
            $order = $this->getOrderForUpdate($orderId);

            if (!$order) {
                throw new Exception('Order not found');
            }

            // Allow from pending, on_hold, approved, preparing
            $allowedStatuses = [self::STATUS_PENDING, self::STATUS_ON_HOLD, self::STATUS_APPROVED, self::STATUS_PREPARING];
            if (!in_array($order['status'], $allowedStatuses)) {
                throw new Exception("Cannot mark order as ready from status: {$order['status']}");
            }

            // Check stock availability (but DO NOT deduct)
            $stockCheck = $this->checkStockAvailability($orderId);
            if (!$stockCheck['all_available']) {
                $shortage = array_filter($stockCheck['items'], fn($i) => !$i['is_available']);
                throw new Exception('Insufficient stock for some items: ' . implode(', ', array_column($shortage, 'product_name')));
            }

            // Update order status
            $stmt = $this->pdo->prepare("
                UPDATE orders SET status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['status' => self::STATUS_READY, 'id' => $orderId]);

            // Log action with stock check metadata
            $this->logAction(
                $orderId,
                self::ACTION_MARKED_READY,
                $order['status'],
                self::STATUS_READY,
                null,
                $notes,
                ['stock_check' => $stockCheck]
            );

            $this->pdo->commit();

            return ['success' => true, 'message' => 'Order marked as ready for handover'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sales rep accepts order - THIS IS WHERE STOCK IS DEDUCTED
     */
    public function acceptBySalesRep(int $orderId, int $salesRepId): array {
        $this->pdo->beginTransaction();

        try {
            $order = $this->getOrderForUpdate($orderId);

            if (!$order) {
                throw new Exception('Order not found');
            }

            if ($order['status'] !== self::STATUS_READY) {
                throw new Exception('Order is not ready for handover');
            }

            // Verify stock one more time before deducting
            $stockCheck = $this->checkStockAvailability($orderId);
            if (!$stockCheck['all_available']) {
                throw new Exception('Stock no longer available. Order cannot be accepted.');
            }

            // Deduct from warehouse stock and add to sales rep van stock
            $movementIds = $this->transferStockToSalesRep($orderId, $salesRepId);

            // Update order status
            $stmt = $this->pdo->prepare("
                UPDATE orders SET status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['status' => self::STATUS_HANDED, 'id' => $orderId]);

            // Create or update handover record
            $this->createHandoverRecord($orderId, $salesRepId, $movementIds);

            // Log action
            $this->logAction(
                $orderId,
                self::ACTION_ACCEPTED_BY_REP,
                self::STATUS_READY,
                self::STATUS_HANDED,
                null,
                null,
                ['sales_rep_id' => $salesRepId, 'movement_ids' => $movementIds]
            );

            $this->pdo->commit();

            return ['success' => true, 'message' => 'Order accepted and stock transferred'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Transfer stock from warehouse to sales rep
     */
    private function transferStockToSalesRep(int $orderId, int $salesRepId): array {
        $items = $this->getOrderItems($orderId);
        $movementIds = [];

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];

            // Get current stock levels
            $warehouseStockBefore = $this->getWarehouseStock($productId);
            $salesRepStockBefore = $this->getSalesRepStock($salesRepId, $productId);

            // Deduct from warehouse (products table)
            $stmt = $this->pdo->prepare("
                UPDATE products
                SET quantity_on_hand = quantity_on_hand - :qty
                WHERE id = :product_id AND quantity_on_hand >= :qty
            ");
            $stmt->execute(['qty' => $quantity, 'product_id' => $productId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Failed to deduct stock for product: {$item['product_name']}");
            }

            // Add to sales rep van stock (s_stock table)
            $existingStmt = $this->pdo->prepare("
                SELECT id FROM s_stock WHERE salesperson_id = :rep_id AND product_id = :product_id
            ");
            $existingStmt->execute(['rep_id' => $salesRepId, 'product_id' => $productId]);

            if ($existingStmt->fetch()) {
                $updateStmt = $this->pdo->prepare("
                    UPDATE s_stock SET qty_on_hand = qty_on_hand + :qty
                    WHERE salesperson_id = :rep_id AND product_id = :product_id
                ");
                $updateStmt->execute(['qty' => $quantity, 'rep_id' => $salesRepId, 'product_id' => $productId]);
            } else {
                $insertStmt = $this->pdo->prepare("
                    INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand, created_at, updated_at)
                    VALUES (:rep_id, :product_id, :qty, NOW(), NOW())
                ");
                $insertStmt->execute(['rep_id' => $salesRepId, 'product_id' => $productId, 'qty' => $quantity]);
            }

            // Log warehouse movement
            $warehouseMovementStmt = $this->pdo->prepare("
                INSERT INTO warehouse_movements (product_id, delta_qty, movement_type, reason, note, created_by, created_at)
                VALUES (:product_id, :delta_qty, 'order_fulfillment', 'Order fulfillment', :note, :user_id, NOW())
            ");
            $warehouseMovementStmt->execute([
                'product_id' => $productId,
                'delta_qty' => -$quantity,
                'note' => "Order #{$orderId} - Transferred to sales rep",
                'user_id' => $this->userId
            ]);

            // Log van stock movement
            $vanMovementStmt = $this->pdo->prepare("
                INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                VALUES (:rep_id, :product_id, :delta_qty, 'order_fulfillment', :note, NOW())
            ");
            $vanMovementStmt->execute([
                'rep_id' => $salesRepId,
                'product_id' => $productId,
                'delta_qty' => $quantity,
                'note' => "Order #{$orderId} - Received from warehouse"
            ]);

            // Get after stock levels
            $warehouseStockAfter = $this->getWarehouseStock($productId);
            $salesRepStockAfter = $this->getSalesRepStock($salesRepId, $productId);

            // Log to inventory_movements table
            $movementStmt = $this->pdo->prepare("
                INSERT INTO inventory_movements
                (movement_type, product_id, quantity, from_location_type, from_location_id,
                 to_location_type, to_location_id, warehouse_stock_before, warehouse_stock_after,
                 sales_rep_stock_before, sales_rep_stock_after, order_id, performed_by)
                VALUES
                ('order_fulfillment', :product_id, :quantity, 'warehouse', NULL,
                 'sales_rep', :rep_id, :ws_before, :ws_after, :sr_before, :sr_after, :order_id, :user_id)
            ");
            $movementStmt->execute([
                'product_id' => $productId,
                'quantity' => $quantity,
                'rep_id' => $salesRepId,
                'ws_before' => $warehouseStockBefore,
                'ws_after' => $warehouseStockAfter,
                'sr_before' => $salesRepStockBefore,
                'sr_after' => $salesRepStockAfter,
                'order_id' => $orderId,
                'user_id' => $this->userId
            ]);

            $movementIds[] = $this->pdo->lastInsertId();
        }

        return $movementIds;
    }

    /**
     * Reverse stock movement (for cancelled orders after handover)
     */
    private function reverseStockMovement(int $orderId): void {
        $items = $this->getOrderItems($orderId);

        // Get sales rep ID from order
        $orderStmt = $this->pdo->prepare("SELECT user_id FROM orders WHERE id = :id");
        $orderStmt->execute(['id' => $orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        $salesRepId = $order['user_id'];

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $quantity = $item['quantity'];

            // Get current stock levels
            $warehouseStockBefore = $this->getWarehouseStock($productId);
            $salesRepStockBefore = $this->getSalesRepStock($salesRepId, $productId);

            // Add back to warehouse
            $stmt = $this->pdo->prepare("
                UPDATE products SET quantity_on_hand = quantity_on_hand + :qty WHERE id = :product_id
            ");
            $stmt->execute(['qty' => $quantity, 'product_id' => $productId]);

            // Deduct from sales rep van stock
            $stmt = $this->pdo->prepare("
                UPDATE s_stock SET qty_on_hand = qty_on_hand - :qty
                WHERE salesperson_id = :rep_id AND product_id = :product_id
            ");
            $stmt->execute(['qty' => $quantity, 'rep_id' => $salesRepId, 'product_id' => $productId]);

            // Log warehouse movement
            $warehouseMovementStmt = $this->pdo->prepare("
                INSERT INTO warehouse_movements (product_id, delta_qty, movement_type, reason, note, created_by, created_at)
                VALUES (:product_id, :delta_qty, 'order_cancellation', 'Order cancelled', :note, :user_id, NOW())
            ");
            $warehouseMovementStmt->execute([
                'product_id' => $productId,
                'delta_qty' => $quantity,
                'note' => "Order #{$orderId} - Cancelled, stock returned",
                'user_id' => $this->userId
            ]);

            // Log van stock movement
            $vanMovementStmt = $this->pdo->prepare("
                INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                VALUES (:rep_id, :product_id, :delta_qty, 'order_cancellation', :note, NOW())
            ");
            $vanMovementStmt->execute([
                'rep_id' => $salesRepId,
                'product_id' => $productId,
                'delta_qty' => -$quantity,
                'note' => "Order #{$orderId} - Cancelled, stock returned to warehouse"
            ]);

            // Get after stock levels
            $warehouseStockAfter = $this->getWarehouseStock($productId);
            $salesRepStockAfter = $this->getSalesRepStock($salesRepId, $productId);

            // Log to inventory_movements
            $movementStmt = $this->pdo->prepare("
                INSERT INTO inventory_movements
                (movement_type, product_id, quantity, from_location_type, from_location_id,
                 to_location_type, to_location_id, warehouse_stock_before, warehouse_stock_after,
                 sales_rep_stock_before, sales_rep_stock_after, order_id, performed_by, reason)
                VALUES
                ('order_cancellation', :product_id, :quantity, 'sales_rep', :rep_id,
                 'warehouse', NULL, :ws_before, :ws_after, :sr_before, :sr_after, :order_id, :user_id, 'Order cancelled')
            ");
            $movementStmt->execute([
                'product_id' => $productId,
                'quantity' => $quantity,
                'rep_id' => $salesRepId,
                'ws_before' => $warehouseStockBefore,
                'ws_after' => $warehouseStockAfter,
                'sr_before' => $salesRepStockBefore,
                'sr_after' => $salesRepStockAfter,
                'order_id' => $orderId,
                'user_id' => $this->userId
            ]);
        }
    }

    /**
     * Create handover record
     */
    private function createHandoverRecord(int $orderId, int $salesRepId, array $movementIds): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO order_handovers
            (order_id, prepared_by, sales_rep_id, stock_transferred_at, stock_movement_ids, status, expires_at)
            VALUES
            (:order_id, :prepared_by, :sales_rep_id, NOW(), :movement_ids, 'sales_rep_accepted', DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ON DUPLICATE KEY UPDATE
            sales_rep_confirmed_at = NOW(),
            stock_transferred_at = NOW(),
            stock_movement_ids = :movement_ids2,
            status = 'sales_rep_accepted'
        ");
        $stmt->execute([
            'order_id' => $orderId,
            'prepared_by' => $this->userId,
            'sales_rep_id' => $salesRepId,
            'movement_ids' => json_encode($movementIds),
            'movement_ids2' => json_encode($movementIds)
        ]);
    }

    /**
     * Complete order
     */
    public function completeOrder(int $orderId): array {
        $this->pdo->beginTransaction();

        try {
            $order = $this->getOrderForUpdate($orderId);

            if (!$order) {
                throw new Exception('Order not found');
            }

            if ($order['status'] !== self::STATUS_HANDED) {
                throw new Exception('Order must be handed to sales rep before completing');
            }

            // Update order status
            $stmt = $this->pdo->prepare("
                UPDATE orders SET status = :status, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute(['status' => self::STATUS_COMPLETED, 'id' => $orderId]);

            // Update handover record
            $handoverStmt = $this->pdo->prepare("
                UPDATE order_handovers SET status = 'completed', completed_at = NOW()
                WHERE order_id = :order_id
            ");
            $handoverStmt->execute(['order_id' => $orderId]);

            // Log action
            $this->logAction(
                $orderId,
                self::ACTION_COMPLETED,
                self::STATUS_HANDED,
                self::STATUS_COMPLETED
            );

            $this->pdo->commit();

            return ['success' => true, 'message' => 'Order completed'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get warehouse stock for a product
     */
    private function getWarehouseStock(int $productId): float {
        $stmt = $this->pdo->prepare("SELECT quantity_on_hand FROM products WHERE id = :id");
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float)$row['quantity_on_hand'] : 0;
    }

    /**
     * Get sales rep van stock for a product
     */
    private function getSalesRepStock(int $salesRepId, int $productId): float {
        $stmt = $this->pdo->prepare("
            SELECT qty_on_hand FROM s_stock
            WHERE salesperson_id = :rep_id AND product_id = :product_id
        ");
        $stmt->execute(['rep_id' => $salesRepId, 'product_id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float)$row['qty_on_hand'] : 0;
    }

    /**
     * Get order action history
     */
    public function getOrderHistory(int $orderId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                oal.*,
                u.name as performed_by_name
            FROM order_actions_log oal
            LEFT JOIN users u ON oal.performed_by = u.id
            WHERE oal.order_id = :order_id
            ORDER BY oal.created_at DESC
        ");
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get inventory movements for an order
     */
    public function getOrderMovements(int $orderId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                im.*,
                p.name as product_name,
                u.name as performed_by_name
            FROM inventory_movements im
            LEFT JOIN products p ON im.product_id = p.id
            LEFT JOIN users u ON im.performed_by = u.id
            WHERE im.order_id = :order_id
            ORDER BY im.created_at DESC
        ");
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get orders ready for a sales rep to accept
     */
    public function getOrdersReadyForSalesRep(int $salesRepId): array {
        $stmt = $this->pdo->prepare("
            SELECT o.*,
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count,
                   (SELECT SUM(quantity * unit_price) FROM order_items WHERE order_id = o.id) as total_value
            FROM orders o
            WHERE o.status = :status AND o.user_id = :sales_rep_id
            ORDER BY o.created_at ASC
        ");
        $stmt->execute([
            'status' => self::STATUS_READY,
            'sales_rep_id' => $salesRepId
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
