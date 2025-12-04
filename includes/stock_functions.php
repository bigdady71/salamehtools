<?php

declare(strict_types=1);

/**
 * Stock Management Functions
 * Handles automatic stock deductions and movements
 */

/**
 * Deduct stock when an order is shipped
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @param int $userId User performing the action
 * @return bool Success status
 */
function deductStockForOrder(PDO $pdo, int $orderId, int $userId): bool
{
    try {
        $pdo->beginTransaction();

        // Get order details
        $orderStmt = $pdo->prepare("
            SELECT o.id, o.order_type, o.sales_rep_id
            FROM orders o
            WHERE o.id = ?
        ");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order not found");
        }

        // Get all order items
        $itemsStmt = $pdo->prepare("
            SELECT oi.product_id, oi.quantity, p.sku, p.item_name
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];

            // Check if this is a van stock sale
            if ($order['order_type'] === 'van_stock_sale' && $order['sales_rep_id']) {
                // Deduct from van stock
                $deductVanStmt = $pdo->prepare("
                    UPDATE van_stock_items
                    SET quantity = quantity - ?,
                        updated_at = NOW()
                    WHERE sales_rep_id = ? AND product_id = ?
                ");
                $deductVanStmt->execute([$quantity, $order['sales_rep_id'], $productId]);

                // Create stock movement record for van
                $movementStmt = $pdo->prepare("
                    INSERT INTO s_stock_movements (
                        salesperson_id,
                        product_id,
                        delta_qty,
                        reason,
                        order_item_id,
                        note,
                        created_at
                    ) VALUES (?, ?, ?, 'sale', ?, ?, NOW())
                ");
                $movementStmt->execute([
                    $order['sales_rep_id'],
                    $productId,
                    -$quantity,
                    null,
                    "Van sale - Order #" . $orderId
                ]);
            } else {
                // Deduct from warehouse stock (salesperson_id = 0)
                $deductStmt = $pdo->prepare("
                    UPDATE s_stock
                    SET qty_on_hand = qty_on_hand - ?,
                        updated_at = NOW()
                    WHERE product_id = ? AND salesperson_id = 0
                ");
                $deductStmt->execute([$quantity, $productId]);

                // Create stock movement record
                $movementStmt = $pdo->prepare("
                    INSERT INTO s_stock_movements (
                        salesperson_id,
                        product_id,
                        delta_qty,
                        reason,
                        order_item_id,
                        note,
                        created_at
                    ) VALUES (?, ?, ?, 'sale', ?, ?, NOW())
                ");
                $movementStmt->execute([
                    0, // Warehouse stock (salesperson_id = 0)
                    $productId,
                    -$quantity,
                    null,
                    "Shipped - Order #" . $orderId
                ]);
            }
        }

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Stock deduction failed for order {$orderId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Reserve stock when order is created/approved
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @return bool Success status
 */
function reserveStockForOrder(PDO $pdo, int $orderId): bool
{
    try {
        $pdo->beginTransaction();

        // Get order items
        $itemsStmt = $pdo->prepare("
            SELECT product_id, quantity
            FROM order_items
            WHERE order_id = ?
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if qty_reserved column exists, if not skip
        $checkColumn = $pdo->query("SHOW COLUMNS FROM s_stock LIKE 'qty_reserved'")->fetch();

        if ($checkColumn) {
            foreach ($items as $item) {
                $reserveStmt = $pdo->prepare("
                    UPDATE s_stock
                    SET qty_reserved = qty_reserved + ?
                    WHERE product_id = ?
                ");
                $reserveStmt->execute([$item['quantity'], $item['product_id']]);
            }
        }

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Stock reservation failed for order {$orderId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Release reserved stock (when order is cancelled)
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @return bool Success status
 */
function releaseStockReservation(PDO $pdo, int $orderId): bool
{
    try {
        $pdo->beginTransaction();

        // Get order items
        $itemsStmt = $pdo->prepare("
            SELECT product_id, quantity
            FROM order_items
            WHERE order_id = ?
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if qty_reserved column exists
        $checkColumn = $pdo->query("SHOW COLUMNS FROM s_stock LIKE 'qty_reserved'")->fetch();

        if ($checkColumn) {
            foreach ($items as $item) {
                $releaseStmt = $pdo->prepare("
                    UPDATE s_stock
                    SET qty_reserved = GREATEST(0, qty_reserved - ?)
                    WHERE product_id = ?
                ");
                $releaseStmt->execute([$item['quantity'], $item['product_id']]);
            }
        }

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Stock release failed for order {$orderId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if sufficient stock available for order
 *
 * @param PDO $pdo Database connection
 * @param int $orderId Order ID
 * @return array [available => bool, shortage => array of products]
 */
function checkStockAvailability(PDO $pdo, int $orderId): array
{
    $itemsStmt = $pdo->prepare("
        SELECT
            oi.product_id,
            oi.quantity as qty_needed,
            p.sku,
            p.item_name,
            COALESCE(s.qty_on_hand, 0) as qty_on_hand
        FROM order_items oi
        INNER JOIN products p ON p.id = oi.product_id
        LEFT JOIN s_stock s ON s.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $shortage = [];
    foreach ($items as $item) {
        if ((float)$item['qty_on_hand'] < (float)$item['qty_needed']) {
            $shortage[] = [
                'sku' => $item['sku'],
                'name' => $item['item_name'],
                'needed' => $item['qty_needed'],
                'available' => $item['qty_on_hand'],
                'short' => $item['qty_needed'] - $item['qty_on_hand']
            ];
        }
    }

    return [
        'available' => empty($shortage),
        'shortage' => $shortage
    ];
}
