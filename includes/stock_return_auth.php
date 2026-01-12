<?php

declare(strict_types=1);

/**
 * Two-factor authentication system for stock returns.
 * Requires both sales rep and warehouse manager to confirm via OTP
 * before stock is transferred back to the warehouse.
 *
 * This is an INTERNAL INVENTORY MOVEMENT, not a financial return.
 * It does not affect sales revenue or create refunds.
 */

/**
 * Generate a 6-digit OTP code
 */
function generate_stock_return_otp(): string
{
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Create a new stock return request with OTPs
 * Initiated by sales rep to return items from their van stock
 *
 * @param PDO $pdo
 * @param int $salesRepId Sales rep returning stock
 * @param array $items Array of ['product_id' => int, 'quantity' => float]
 * @param string|null $note Optional note
 * @return array{return_id: string, sales_rep_otp: string, warehouse_otp: string}
 */
function create_stock_return_request(
    PDO $pdo,
    int $salesRepId,
    array $items,
    ?string $note = null
): array {
    $returnId = bin2hex(random_bytes(32));
    $salesRepOtp = generate_stock_return_otp();
    $warehouseOtp = generate_stock_return_otp();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+2 hours'));

    $pdo->beginTransaction();
    try {
        // Validate all items have sufficient van stock
        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];

            $stockCheck = $pdo->prepare("
                SELECT qty_on_hand FROM s_stock
                WHERE salesperson_id = :sales_rep_id AND product_id = :product_id
            ");
            $stockCheck->execute([
                ':sales_rep_id' => $salesRepId,
                ':product_id' => $productId,
            ]);
            $currentStock = (float)$stockCheck->fetchColumn();

            if ($currentStock < $quantity) {
                throw new Exception("Insufficient van stock for product ID {$productId}. Available: {$currentStock}, Requested: {$quantity}");
            }
        }

        // Create main return request
        $stmt = $pdo->prepare("
            INSERT INTO stock_return_requests (
                return_id, sales_rep_id,
                sales_rep_otp, warehouse_otp, note, expires_at
            ) VALUES (
                :return_id, :sales_rep_id,
                :sales_rep_otp, :warehouse_otp, :note, :expires_at
            )
        ");

        $stmt->execute([
            ':return_id' => $returnId,
            ':sales_rep_id' => $salesRepId,
            ':sales_rep_otp' => $salesRepOtp,
            ':warehouse_otp' => $warehouseOtp,
            ':note' => $note,
            ':expires_at' => $expiresAt,
        ]);

        // Insert return items
        $itemStmt = $pdo->prepare("
            INSERT INTO stock_return_items (return_id, product_id, quantity)
            VALUES (:return_id, :product_id, :quantity)
        ");

        foreach ($items as $item) {
            $itemStmt->execute([
                ':return_id' => $returnId,
                ':product_id' => (int)$item['product_id'],
                ':quantity' => (float)$item['quantity'],
            ]);
        }

        // Log creation
        log_stock_return_action($pdo, $returnId, 'created', $salesRepId, 'Stock return request created by sales rep');

        $pdo->commit();

        return [
            'return_id' => $returnId,
            'sales_rep_otp' => $salesRepOtp,
            'warehouse_otp' => $warehouseOtp,
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Confirm return by sales rep
 *
 * @param PDO $pdo
 * @param string $returnId
 * @param string $otp
 * @param int $salesRepId
 * @return bool True if OTP is valid and confirmed
 */
function confirm_return_by_sales_rep(PDO $pdo, string $returnId, string $otp, int $salesRepId): bool
{
    $stmt = $pdo->prepare("
        SELECT id, sales_rep_otp, sales_rep_id, expires_at, warehouse_confirmed, completed_at
        FROM stock_return_requests
        WHERE return_id = :return_id
    ");
    $stmt->execute([':return_id' => $returnId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        return false;
    }

    // Verify this is the correct sales rep
    if ((int)$record['sales_rep_id'] !== $salesRepId) {
        return false;
    }

    if ($record['completed_at'] !== null) {
        return false; // Already completed
    }

    if (strtotime($record['expires_at']) < time()) {
        return false; // Expired
    }

    if ($record['sales_rep_otp'] !== $otp) {
        return false; // Invalid OTP
    }

    // Mark as confirmed by sales rep
    $updateStmt = $pdo->prepare("
        UPDATE stock_return_requests
        SET sales_rep_confirmed = 1, updated_at = NOW()
        WHERE return_id = :return_id
    ");
    $updateStmt->execute([':return_id' => $returnId]);

    log_stock_return_action($pdo, $returnId, 'sales_rep_confirmed', $salesRepId, 'Sales rep confirmed OTP');

    // If both parties have confirmed, process the return
    if ($record['warehouse_confirmed'] == 1) {
        return process_stock_return($pdo, $returnId);
    }

    return true;
}

/**
 * Confirm return by warehouse manager
 *
 * @param PDO $pdo
 * @param string $returnId
 * @param string $otp
 * @param int $warehouseUserId
 * @return bool True if OTP is valid and confirmed
 */
function confirm_return_by_warehouse(PDO $pdo, string $returnId, string $otp, int $warehouseUserId): bool
{
    $stmt = $pdo->prepare("
        SELECT id, warehouse_otp, expires_at, sales_rep_confirmed, completed_at
        FROM stock_return_requests
        WHERE return_id = :return_id
    ");
    $stmt->execute([':return_id' => $returnId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        return false;
    }

    if ($record['completed_at'] !== null) {
        return false; // Already completed
    }

    if (strtotime($record['expires_at']) < time()) {
        return false; // Expired
    }

    if ($record['warehouse_otp'] !== $otp) {
        return false; // Invalid OTP
    }

    // Mark as confirmed by warehouse and assign warehouse user
    $updateStmt = $pdo->prepare("
        UPDATE stock_return_requests
        SET warehouse_confirmed = 1, warehouse_user_id = :warehouse_user_id, updated_at = NOW()
        WHERE return_id = :return_id
    ");
    $updateStmt->execute([
        ':warehouse_user_id' => $warehouseUserId,
        ':return_id' => $returnId,
    ]);

    log_stock_return_action($pdo, $returnId, 'warehouse_confirmed', $warehouseUserId, 'Warehouse confirmed OTP');

    // If both parties have confirmed, process the return
    if ($record['sales_rep_confirmed'] == 1) {
        return process_stock_return($pdo, $returnId);
    }

    return true;
}

/**
 * Process the stock return after both parties confirm
 *
 * @param PDO $pdo
 * @param string $returnId
 * @return bool
 */
function process_stock_return(PDO $pdo, string $returnId): bool
{
    try {
        $pdo->beginTransaction();

        // Get return request details
        $stmt = $pdo->prepare("
            SELECT id, sales_rep_id, warehouse_user_id, note
            FROM stock_return_requests
            WHERE return_id = :return_id
              AND sales_rep_confirmed = 1
              AND warehouse_confirmed = 1
              AND completed_at IS NULL
        ");
        $stmt->execute([':return_id' => $returnId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            return false;
        }

        $salesRepId = (int)$request['sales_rep_id'];
        $warehouseUserId = (int)$request['warehouse_user_id'];

        // Get items to return
        $itemsStmt = $pdo->prepare("
            SELECT product_id, quantity
            FROM stock_return_items
            WHERE return_id = :return_id
        ");
        $itemsStmt->execute([':return_id' => $returnId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];

            // Check van stock one more time
            $stockCheckStmt = $pdo->prepare("
                SELECT qty_on_hand FROM s_stock
                WHERE salesperson_id = :sales_rep_id AND product_id = :product_id
                FOR UPDATE
            ");
            $stockCheckStmt->execute([
                ':sales_rep_id' => $salesRepId,
                ':product_id' => $productId,
            ]);
            $vanStock = (float)$stockCheckStmt->fetchColumn();

            if ($vanStock < $quantity) {
                $pdo->rollBack();
                return false; // Insufficient van stock
            }

            // Deduct from van stock (s_stock table)
            $deductVanStmt = $pdo->prepare("
                UPDATE s_stock
                SET qty_on_hand = qty_on_hand - :quantity, updated_at = NOW()
                WHERE salesperson_id = :sales_rep_id AND product_id = :product_id
            ");
            $deductVanStmt->execute([
                ':quantity' => $quantity,
                ':sales_rep_id' => $salesRepId,
                ':product_id' => $productId,
            ]);

            // Add to warehouse stock (products table)
            $addWarehouseStmt = $pdo->prepare("
                UPDATE products
                SET quantity_on_hand = quantity_on_hand + :quantity
                WHERE id = :product_id
            ");
            $addWarehouseStmt->execute([
                ':quantity' => $quantity,
                ':product_id' => $productId,
            ]);

            // Log van stock movement (outgoing from van)
            $vanMovementStmt = $pdo->prepare("
                INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                VALUES (:sales_rep_id, :product_id, :delta_qty, :reason, :note, NOW())
            ");
            $vanMovementStmt->execute([
                ':sales_rep_id' => $salesRepId,
                ':product_id' => $productId,
                ':delta_qty' => -$quantity, // Negative because leaving van
                ':reason' => 'return',
                ':note' => 'Stock return to warehouse (return:' . substr($returnId, 0, 16) . ')',
            ]);

            // Log warehouse movement (incoming to warehouse)
            $warehouseMovementStmt = $pdo->prepare("
                INSERT INTO warehouse_movements (
                    product_id, kind, qty, reason, ref, created_by, created_at
                ) VALUES (
                    :product_id, 'in', :qty, :reason, :ref, :created_by, NOW()
                )
            ");
            $warehouseMovementStmt->execute([
                ':product_id' => $productId,
                ':qty' => $quantity,
                ':reason' => 'van_return',
                ':ref' => 'return:' . substr($returnId, 0, 32),
                ':created_by' => $warehouseUserId,
            ]);
        }

        // Mark return as completed
        $completeStmt = $pdo->prepare("
            UPDATE stock_return_requests
            SET status = 'completed', completed_at = NOW(), updated_at = NOW()
            WHERE return_id = :return_id
        ");
        $completeStmt->execute([':return_id' => $returnId]);

        log_stock_return_action($pdo, $returnId, 'completed', $warehouseUserId, 'Stock return processed successfully');

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Stock return processing failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Cancel a stock return request
 *
 * @param PDO $pdo
 * @param string $returnId
 * @param int $userId
 * @param string $reason
 * @return bool
 */
function cancel_stock_return(PDO $pdo, string $returnId, int $userId, string $reason = ''): bool
{
    $stmt = $pdo->prepare("
        UPDATE stock_return_requests
        SET status = 'cancelled', updated_at = NOW()
        WHERE return_id = :return_id AND completed_at IS NULL
    ");
    $stmt->execute([':return_id' => $returnId]);

    if ($stmt->rowCount() > 0) {
        log_stock_return_action($pdo, $returnId, 'cancelled', $userId, $reason ?: 'Stock return cancelled');
        return true;
    }

    return false;
}

/**
 * Log stock return action for audit trail
 *
 * @param PDO $pdo
 * @param string $returnId
 * @param string $action
 * @param int|null $performedBy
 * @param string|null $details
 */
function log_stock_return_action(PDO $pdo, string $returnId, string $action, ?int $performedBy, ?string $details = null): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO stock_return_audit_log (return_id, action, performed_by, details)
            VALUES (:return_id, :action, :performed_by, :details)
        ");
        $stmt->execute([
            ':return_id' => $returnId,
            ':action' => $action,
            ':performed_by' => $performedBy,
            ':details' => $details,
        ]);
    } catch (Exception $e) {
        error_log("Failed to log stock return action: " . $e->getMessage());
    }
}

/**
 * Get pending return requests for a sales rep
 *
 * @param PDO $pdo
 * @param int $salesRepId
 * @return array
 */
function get_pending_returns_for_sales_rep(PDO $pdo, int $salesRepId): array
{
    $stmt = $pdo->prepare("
        SELECT
            srr.return_id,
            srr.note,
            srr.sales_rep_confirmed,
            srr.warehouse_confirmed,
            srr.status,
            srr.expires_at,
            srr.created_at,
            (SELECT COUNT(*) FROM stock_return_items WHERE return_id = srr.return_id) as item_count,
            (SELECT SUM(quantity) FROM stock_return_items WHERE return_id = srr.return_id) as total_quantity
        FROM stock_return_requests srr
        WHERE srr.sales_rep_id = :sales_rep_id
          AND srr.status = 'pending'
          AND srr.completed_at IS NULL
          AND srr.expires_at > NOW()
        ORDER BY srr.created_at DESC
    ");
    $stmt->execute([':sales_rep_id' => $salesRepId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get pending return requests for warehouse
 *
 * @param PDO $pdo
 * @return array
 */
function get_pending_returns_for_warehouse(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT
            srr.return_id,
            srr.sales_rep_id,
            sr.name as sales_rep_name,
            sr.phone as sales_rep_phone,
            srr.note,
            srr.sales_rep_confirmed,
            srr.warehouse_confirmed,
            srr.status,
            srr.expires_at,
            srr.created_at,
            (SELECT COUNT(*) FROM stock_return_items WHERE return_id = srr.return_id) as item_count,
            (SELECT SUM(quantity) FROM stock_return_items WHERE return_id = srr.return_id) as total_quantity
        FROM stock_return_requests srr
        INNER JOIN users sr ON sr.id = srr.sales_rep_id
        WHERE srr.status = 'pending'
          AND srr.completed_at IS NULL
          AND srr.expires_at > NOW()
        ORDER BY srr.created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get return items by return ID
 *
 * @param PDO $pdo
 * @param string $returnId
 * @return array
 */
function get_return_items(PDO $pdo, string $returnId): array
{
    $stmt = $pdo->prepare("
        SELECT
            sri.product_id,
            sri.quantity,
            p.sku,
            p.item_name,
            p.unit,
            p.image_url
        FROM stock_return_items sri
        INNER JOIN products p ON p.id = sri.product_id
        WHERE sri.return_id = :return_id
        ORDER BY p.item_name
    ");
    $stmt->execute([':return_id' => $returnId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get stock return request details
 *
 * @param PDO $pdo
 * @param string $returnId
 * @return array|null
 */
function get_stock_return_request(PDO $pdo, string $returnId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            srr.*,
            sr.name as sales_rep_name,
            sr.phone as sales_rep_phone,
            wu.name as warehouse_user_name
        FROM stock_return_requests srr
        INNER JOIN users sr ON sr.id = srr.sales_rep_id
        LEFT JOIN users wu ON wu.id = srr.warehouse_user_id
        WHERE srr.return_id = :return_id
    ");
    $stmt->execute([':return_id' => $returnId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

/**
 * Get stock return history for a sales rep
 *
 * @param PDO $pdo
 * @param int $salesRepId
 * @param int $limit
 * @return array
 */
function get_return_history_for_sales_rep(PDO $pdo, int $salesRepId, int $limit = 20): array
{
    $stmt = $pdo->prepare("
        SELECT
            srr.return_id,
            srr.note,
            srr.status,
            srr.completed_at,
            srr.created_at,
            (SELECT COUNT(*) FROM stock_return_items WHERE return_id = srr.return_id) as item_count,
            (SELECT SUM(quantity) FROM stock_return_items WHERE return_id = srr.return_id) as total_quantity
        FROM stock_return_requests srr
        WHERE srr.sales_rep_id = :sales_rep_id
        ORDER BY srr.created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':sales_rep_id', $salesRepId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
