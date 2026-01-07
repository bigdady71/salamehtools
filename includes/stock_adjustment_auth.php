<?php

declare(strict_types=1);

/**
 * Two-factor authentication system for stock adjustments.
 * Requires both initiator (admin/warehouse) and sales rep to confirm via OTP.
 */

/**
 * Generate a 6-digit OTP code
 */
function generate_otp(): string
{
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Create a new stock adjustment request with OTPs
 *
 * @param PDO $pdo
 * @param int $initiatorId User ID of admin or warehouse manager
 * @param string $initiatorType 'admin' or 'warehouse_manager'
 * @param int $salesRepId Sales rep whose stock is being adjusted
 * @param int $productId Product being adjusted
 * @param float $deltaQty Change in quantity (positive or negative)
 * @param string $reason Reason for adjustment
 * @param string|null $note Optional note
 * @return array{adjustment_id: string, initiator_otp: string, sales_rep_otp: string}
 */
function create_stock_adjustment_request(
    PDO $pdo,
    int $initiatorId,
    string $initiatorType,
    int $salesRepId,
    int $productId,
    float $deltaQty,
    string $reason,
    ?string $note = null
): array {
    $adjustmentId = bin2hex(random_bytes(32));
    $initiatorOtp = generate_otp();
    $salesRepOtp = generate_otp();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmt = $pdo->prepare("
        INSERT INTO stock_adjustment_otps (
            adjustment_id, initiator_id, initiator_type, sales_rep_id,
            product_id, delta_qty, reason, note,
            initiator_otp, sales_rep_otp, expires_at
        ) VALUES (
            :adjustment_id, :initiator_id, :initiator_type, :sales_rep_id,
            :product_id, :delta_qty, :reason, :note,
            :initiator_otp, :sales_rep_otp, :expires_at
        )
    ");

    $stmt->execute([
        ':adjustment_id' => $adjustmentId,
        ':initiator_id' => $initiatorId,
        ':initiator_type' => $initiatorType,
        ':sales_rep_id' => $salesRepId,
        ':product_id' => $productId,
        ':delta_qty' => $deltaQty,
        ':reason' => $reason,
        ':note' => $note,
        ':initiator_otp' => $initiatorOtp,
        ':sales_rep_otp' => $salesRepOtp,
        ':expires_at' => $expiresAt,
    ]);

    return [
        'adjustment_id' => $adjustmentId,
        'initiator_otp' => $initiatorOtp,
        'sales_rep_otp' => $salesRepOtp,
    ];
}

/**
 * Confirm adjustment by initiator (admin/warehouse)
 *
 * @param PDO $pdo
 * @param string $adjustmentId
 * @param string $otp
 * @return bool True if OTP is valid and confirmed
 */
function confirm_adjustment_by_initiator(PDO $pdo, string $adjustmentId, string $otp): bool
{
    $stmt = $pdo->prepare("
        SELECT id, initiator_otp, expires_at, sales_rep_confirmed, completed_at
        FROM stock_adjustment_otps
        WHERE adjustment_id = :adjustment_id
    ");
    $stmt->execute([':adjustment_id' => $adjustmentId]);
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

    if ($record['initiator_otp'] !== $otp) {
        return false; // Invalid OTP
    }

    // Mark as confirmed by initiator
    $updateStmt = $pdo->prepare("
        UPDATE stock_adjustment_otps
        SET initiator_confirmed = 1
        WHERE adjustment_id = :adjustment_id
    ");
    $updateStmt->execute([':adjustment_id' => $adjustmentId]);

    // If both parties have confirmed, process the adjustment
    if ($record['sales_rep_confirmed'] == 1) {
        return process_stock_adjustment($pdo, $adjustmentId);
    }

    return true;
}

/**
 * Confirm adjustment by sales rep
 *
 * @param PDO $pdo
 * @param string $adjustmentId
 * @param string $otp
 * @return bool True if OTP is valid and confirmed
 */
function confirm_adjustment_by_sales_rep(PDO $pdo, string $adjustmentId, string $otp): bool
{
    $stmt = $pdo->prepare("
        SELECT id, sales_rep_otp, expires_at, initiator_confirmed, completed_at
        FROM stock_adjustment_otps
        WHERE adjustment_id = :adjustment_id
    ");
    $stmt->execute([':adjustment_id' => $adjustmentId]);
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

    if ($record['sales_rep_otp'] !== $otp) {
        return false; // Invalid OTP
    }

    // Mark as confirmed by sales rep
    $updateStmt = $pdo->prepare("
        UPDATE stock_adjustment_otps
        SET sales_rep_confirmed = 1
        WHERE adjustment_id = :adjustment_id
    ");
    $updateStmt->execute([':adjustment_id' => $adjustmentId]);

    // If both parties have confirmed, process the adjustment
    if ($record['initiator_confirmed'] == 1) {
        return process_stock_adjustment($pdo, $adjustmentId);
    }

    return true;
}

/**
 * Process the stock adjustment after both parties confirm
 *
 * @param PDO $pdo
 * @param string $adjustmentId
 * @return bool
 */
function process_stock_adjustment(PDO $pdo, string $adjustmentId): bool
{
    try {
        $pdo->beginTransaction();

        // Get adjustment details
        $stmt = $pdo->prepare("
            SELECT sales_rep_id, product_id, delta_qty, reason, note
            FROM stock_adjustment_otps
            WHERE adjustment_id = :adjustment_id
              AND initiator_confirmed = 1
              AND sales_rep_confirmed = 1
              AND completed_at IS NULL
        ");
        $stmt->execute([':adjustment_id' => $adjustmentId]);
        $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$adjustment) {
            $pdo->rollBack();
            return false;
        }

        $salesRepId = (int)$adjustment['sales_rep_id'];
        $productId = (int)$adjustment['product_id'];
        $deltaQty = (float)$adjustment['delta_qty'];
        $reason = $adjustment['reason'];
        $note = $adjustment['note'];

        // Check current stock
        $checkStmt = $pdo->prepare("
            SELECT qty_on_hand FROM s_stock
            WHERE salesperson_id = :rep_id AND product_id = :product_id
        ");
        $checkStmt->execute([':rep_id' => $salesRepId, ':product_id' => $productId]);
        $currentStock = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $newQty = $currentStock ? (float)$currentStock['qty_on_hand'] + $deltaQty : $deltaQty;

        if ($newQty < 0) {
            $pdo->rollBack();
            return false;
        }

        // Log movement
        $movementStmt = $pdo->prepare("
            INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
            VALUES (:rep_id, :product_id, :delta_qty, :reason, :note, NOW())
        ");
        $movementStmt->execute([
            ':rep_id' => $salesRepId,
            ':product_id' => $productId,
            ':delta_qty' => $deltaQty,
            ':reason' => $reason,
            ':note' => $note,
        ]);

        // Update or insert stock record
        if ($currentStock) {
            $updateStmt = $pdo->prepare("
                UPDATE s_stock
                SET qty_on_hand = qty_on_hand + :delta_qty, updated_at = NOW()
                WHERE salesperson_id = :rep_id AND product_id = :product_id
            ");
            $updateStmt->execute([
                ':delta_qty' => $deltaQty,
                ':rep_id' => $salesRepId,
                ':product_id' => $productId,
            ]);
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand, created_at)
                VALUES (:rep_id, :product_id, :qty, NOW())
            ");
            $insertStmt->execute([
                ':rep_id' => $salesRepId,
                ':product_id' => $productId,
                ':qty' => $newQty,
            ]);
        }

        // Mark adjustment as completed
        $completeStmt = $pdo->prepare("
            UPDATE stock_adjustment_otps
            SET completed_at = NOW()
            WHERE adjustment_id = :adjustment_id
        ");
        $completeStmt->execute([':adjustment_id' => $adjustmentId]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Stock adjustment processing failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get pending adjustments for a sales rep
 *
 * @param PDO $pdo
 * @param int $salesRepId
 * @return array
 */
function get_pending_adjustments_for_sales_rep(PDO $pdo, int $salesRepId): array
{
    $stmt = $pdo->prepare("
        SELECT
            oa.adjustment_id,
            oa.product_id,
            p.item_name as product_name,
            p.sku,
            oa.delta_qty,
            oa.reason,
            oa.note,
            oa.initiator_type,
            u.name as initiator_name,
            oa.initiator_confirmed,
            oa.sales_rep_confirmed,
            oa.expires_at,
            oa.created_at
        FROM stock_adjustment_otps oa
        INNER JOIN products p ON p.id = oa.product_id
        LEFT JOIN users u ON u.id = oa.initiator_id
        WHERE oa.sales_rep_id = :sales_rep_id
          AND oa.completed_at IS NULL
          AND oa.expires_at > NOW()
        ORDER BY oa.created_at DESC
    ");
    $stmt->execute([':sales_rep_id' => $salesRepId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get pending adjustments initiated by admin/warehouse
 *
 * @param PDO $pdo
 * @param int $initiatorId
 * @param string $initiatorType
 * @return array
 */
function get_pending_adjustments_for_initiator(PDO $pdo, int $initiatorId, string $initiatorType): array
{
    $stmt = $pdo->prepare("
        SELECT
            oa.adjustment_id,
            oa.sales_rep_id,
            sr.name as sales_rep_name,
            oa.product_id,
            p.item_name as product_name,
            p.sku,
            oa.delta_qty,
            oa.reason,
            oa.note,
            oa.initiator_confirmed,
            oa.sales_rep_confirmed,
            oa.expires_at,
            oa.created_at
        FROM stock_adjustment_otps oa
        INNER JOIN products p ON p.id = oa.product_id
        INNER JOIN users sr ON sr.id = oa.sales_rep_id
        WHERE oa.initiator_id = :initiator_id
          AND oa.initiator_type = :initiator_type
          AND oa.completed_at IS NULL
          AND oa.expires_at > NOW()
        ORDER BY oa.created_at DESC
    ");
    $stmt->execute([
        ':initiator_id' => $initiatorId,
        ':initiator_type' => $initiatorType,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
