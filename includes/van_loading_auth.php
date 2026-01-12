<?php

declare(strict_types=1);

/**
 * Two-factor authentication system for van loading.
 * Requires both warehouse manager and sales rep to confirm via OTP
 * before stock is transferred to the van.
 */

/**
 * Generate a 6-digit OTP code
 */
function generate_van_loading_otp(): string
{
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Create a new van loading request with OTPs
 *
 * @param PDO $pdo
 * @param int $warehouseUserId User ID of warehouse manager
 * @param int $salesRepId Sales rep receiving stock
 * @param array $items Array of ['product_id' => int, 'quantity' => float]
 * @param string|null $note Optional note
 * @return array{loading_id: string, warehouse_otp: string, sales_rep_otp: string}
 */
function create_van_loading_request(
    PDO $pdo,
    int $warehouseUserId,
    int $salesRepId,
    array $items,
    ?string $note = null
): array {
    $loadingId = bin2hex(random_bytes(32));
    $warehouseOtp = generate_van_loading_otp();
    $salesRepOtp = generate_van_loading_otp();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $pdo->beginTransaction();
    try {
        // Create main loading request
        $stmt = $pdo->prepare("
            INSERT INTO van_loading_requests (
                loading_id, warehouse_user_id, sales_rep_id,
                warehouse_otp, sales_rep_otp, note, expires_at
            ) VALUES (
                :loading_id, :warehouse_user_id, :sales_rep_id,
                :warehouse_otp, :sales_rep_otp, :note, :expires_at
            )
        ");

        $stmt->execute([
            ':loading_id' => $loadingId,
            ':warehouse_user_id' => $warehouseUserId,
            ':sales_rep_id' => $salesRepId,
            ':warehouse_otp' => $warehouseOtp,
            ':sales_rep_otp' => $salesRepOtp,
            ':note' => $note,
            ':expires_at' => $expiresAt,
        ]);

        // Insert loading items
        $itemStmt = $pdo->prepare("
            INSERT INTO van_loading_items (loading_id, product_id, quantity)
            VALUES (:loading_id, :product_id, :quantity)
        ");

        foreach ($items as $item) {
            $itemStmt->execute([
                ':loading_id' => $loadingId,
                ':product_id' => (int)$item['product_id'],
                ':quantity' => (float)$item['quantity'],
            ]);
        }

        $pdo->commit();

        return [
            'loading_id' => $loadingId,
            'warehouse_otp' => $warehouseOtp,
            'sales_rep_otp' => $salesRepOtp,
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Confirm loading by warehouse manager
 *
 * @param PDO $pdo
 * @param string $loadingId
 * @param string $otp
 * @return bool True if OTP is valid and confirmed
 */
function confirm_loading_by_warehouse(PDO $pdo, string $loadingId, string $otp): bool
{
    $stmt = $pdo->prepare("
        SELECT id, warehouse_otp, expires_at, sales_rep_confirmed, completed_at
        FROM van_loading_requests
        WHERE loading_id = :loading_id
    ");
    $stmt->execute([':loading_id' => $loadingId]);
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

    // Mark as confirmed by warehouse
    $updateStmt = $pdo->prepare("
        UPDATE van_loading_requests
        SET warehouse_confirmed = 1
        WHERE loading_id = :loading_id
    ");
    $updateStmt->execute([':loading_id' => $loadingId]);

    // If both parties have confirmed, process the loading
    if ($record['sales_rep_confirmed'] == 1) {
        return process_van_loading($pdo, $loadingId);
    }

    return true;
}

/**
 * Confirm loading by sales rep
 *
 * @param PDO $pdo
 * @param string $loadingId
 * @param string $otp
 * @return bool True if OTP is valid and confirmed
 */
function confirm_loading_by_sales_rep(PDO $pdo, string $loadingId, string $otp): bool
{
    $stmt = $pdo->prepare("
        SELECT id, sales_rep_otp, expires_at, warehouse_confirmed, completed_at
        FROM van_loading_requests
        WHERE loading_id = :loading_id
    ");
    $stmt->execute([':loading_id' => $loadingId]);
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
        UPDATE van_loading_requests
        SET sales_rep_confirmed = 1
        WHERE loading_id = :loading_id
    ");
    $updateStmt->execute([':loading_id' => $loadingId]);

    // If both parties have confirmed, process the loading
    if ($record['warehouse_confirmed'] == 1) {
        return process_van_loading($pdo, $loadingId);
    }

    return true;
}

/**
 * Process the van loading after both parties confirm
 *
 * @param PDO $pdo
 * @param string $loadingId
 * @return bool
 */
function process_van_loading(PDO $pdo, string $loadingId): bool
{
    try {
        $pdo->beginTransaction();

        // Get loading request details
        $stmt = $pdo->prepare("
            SELECT id, sales_rep_id, warehouse_user_id, note
            FROM van_loading_requests
            WHERE loading_id = :loading_id
              AND warehouse_confirmed = 1
              AND sales_rep_confirmed = 1
              AND completed_at IS NULL
        ");
        $stmt->execute([':loading_id' => $loadingId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            return false;
        }

        $salesRepId = (int)$request['sales_rep_id'];
        $warehouseUserId = (int)$request['warehouse_user_id'];

        // Get items to load
        $itemsStmt = $pdo->prepare("
            SELECT product_id, quantity
            FROM van_loading_items
            WHERE loading_id = :loading_id
        ");
        $itemsStmt->execute([':loading_id' => $loadingId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];

            // Check warehouse stock
            $stockCheckStmt = $pdo->prepare("
                SELECT quantity_on_hand FROM products WHERE id = :product_id FOR UPDATE
            ");
            $stockCheckStmt->execute([':product_id' => $productId]);
            $warehouseStock = (float)$stockCheckStmt->fetchColumn();

            if ($warehouseStock < $quantity) {
                $pdo->rollBack();
                return false; // Insufficient stock
            }

            // Deduct from warehouse
            $deductStmt = $pdo->prepare("
                UPDATE products
                SET quantity_on_hand = quantity_on_hand - :quantity
                WHERE id = :product_id
            ");
            $deductStmt->execute([
                ':quantity' => $quantity,
                ':product_id' => $productId,
            ]);

            // Add to van stock (s_stock table)
            $existingStmt = $pdo->prepare("
                SELECT id FROM s_stock
                WHERE salesperson_id = :sales_rep_id AND product_id = :product_id
            ");
            $existingStmt->execute([
                ':sales_rep_id' => $salesRepId,
                ':product_id' => $productId,
            ]);
            $existing = $existingStmt->fetch();

            if ($existing) {
                $updateVanStmt = $pdo->prepare("
                    UPDATE s_stock
                    SET qty_on_hand = qty_on_hand + :quantity, updated_at = NOW()
                    WHERE salesperson_id = :sales_rep_id AND product_id = :product_id
                ");
                $updateVanStmt->execute([
                    ':quantity' => $quantity,
                    ':sales_rep_id' => $salesRepId,
                    ':product_id' => $productId,
                ]);
            } else {
                $insertVanStmt = $pdo->prepare("
                    INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand, created_at, updated_at)
                    VALUES (:sales_rep_id, :product_id, :quantity, NOW(), NOW())
                ");
                $insertVanStmt->execute([
                    ':sales_rep_id' => $salesRepId,
                    ':product_id' => $productId,
                    ':quantity' => $quantity,
                ]);
            }

            // Log van stock movement (s_stock_movements table)
            $vanMovementStmt = $pdo->prepare("
                INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                VALUES (:sales_rep_id, :product_id, :delta_qty, :reason, :note, NOW())
            ");
            $vanMovementStmt->execute([
                ':sales_rep_id' => $salesRepId,
                ':product_id' => $productId,
                ':delta_qty' => $quantity,
                ':reason' => 'load',
                ':note' => 'Van loading via OTP auth (loading:' . substr($loadingId, 0, 16) . ')',
            ]);

            // Log warehouse movement
            $movementStmt = $pdo->prepare("
                INSERT INTO warehouse_movements (
                    product_id, kind, qty, reason, ref, created_by, created_at
                ) VALUES (
                    :product_id, 'out', :qty, :reason, :ref, :created_by, NOW()
                )
            ");
            $movementStmt->execute([
                ':product_id' => $productId,
                ':qty' => $quantity,
                ':reason' => 'van_loading',
                ':ref' => 'loading:' . substr($loadingId, 0, 32),
                ':created_by' => $warehouseUserId,
            ]);
        }

        // Mark loading as completed
        $completeStmt = $pdo->prepare("
            UPDATE van_loading_requests
            SET completed_at = NOW()
            WHERE loading_id = :loading_id
        ");
        $completeStmt->execute([':loading_id' => $loadingId]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Van loading processing failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get pending loading requests for a sales rep
 *
 * @param PDO $pdo
 * @param int $salesRepId
 * @return array
 */
function get_pending_loadings_for_sales_rep(PDO $pdo, int $salesRepId): array
{
    $stmt = $pdo->prepare("
        SELECT
            vlr.loading_id,
            vlr.warehouse_user_id,
            u.name as warehouse_user_name,
            vlr.note,
            vlr.warehouse_confirmed,
            vlr.sales_rep_confirmed,
            vlr.expires_at,
            vlr.created_at,
            (SELECT COUNT(*) FROM van_loading_items WHERE loading_id = vlr.loading_id) as item_count,
            (SELECT SUM(quantity) FROM van_loading_items WHERE loading_id = vlr.loading_id) as total_quantity
        FROM van_loading_requests vlr
        INNER JOIN users u ON u.id = vlr.warehouse_user_id
        WHERE vlr.sales_rep_id = :sales_rep_id
          AND vlr.completed_at IS NULL
          AND vlr.expires_at > NOW()
        ORDER BY vlr.created_at DESC
    ");
    $stmt->execute([':sales_rep_id' => $salesRepId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get pending loading requests for warehouse manager
 *
 * @param PDO $pdo
 * @param int $warehouseUserId
 * @return array
 */
function get_pending_loadings_for_warehouse(PDO $pdo, int $warehouseUserId): array
{
    $stmt = $pdo->prepare("
        SELECT
            vlr.loading_id,
            vlr.sales_rep_id,
            sr.name as sales_rep_name,
            vlr.note,
            vlr.warehouse_confirmed,
            vlr.sales_rep_confirmed,
            vlr.expires_at,
            vlr.created_at,
            (SELECT COUNT(*) FROM van_loading_items WHERE loading_id = vlr.loading_id) as item_count,
            (SELECT SUM(quantity) FROM van_loading_items WHERE loading_id = vlr.loading_id) as total_quantity
        FROM van_loading_requests vlr
        INNER JOIN users sr ON sr.id = vlr.sales_rep_id
        WHERE vlr.warehouse_user_id = :warehouse_user_id
          AND vlr.completed_at IS NULL
          AND vlr.expires_at > NOW()
        ORDER BY vlr.created_at DESC
    ");
    $stmt->execute([':warehouse_user_id' => $warehouseUserId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get loading items by loading ID
 *
 * @param PDO $pdo
 * @param string $loadingId
 * @return array
 */
function get_loading_items(PDO $pdo, string $loadingId): array
{
    $stmt = $pdo->prepare("
        SELECT
            vli.product_id,
            vli.quantity,
            p.sku,
            p.item_name,
            p.unit,
            p.image_url
        FROM van_loading_items vli
        INNER JOIN products p ON p.id = vli.product_id
        WHERE vli.loading_id = :loading_id
        ORDER BY p.item_name
    ");
    $stmt->execute([':loading_id' => $loadingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
