<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/stock_functions.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Handle OTP verification from warehouse side
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp_warehouse') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $salesRepOtpInput = trim($_POST['sales_rep_otp'] ?? '');

    if ($orderId > 0 && $salesRepOtpInput !== '') {
        try {
            $pdo->beginTransaction();

            // Get OTP record
            $otpStmt = $pdo->prepare("
                SELECT * FROM order_transfer_otps
                WHERE order_id = ? AND expires_at > NOW()
            ");
            $otpStmt->execute([$orderId]);
            $otpRecord = $otpStmt->fetch(PDO::FETCH_ASSOC);

            if (!$otpRecord) {
                $_SESSION['error'] = 'OTP expired or not found. Please regenerate.';
            } elseif ($otpRecord['sales_rep_otp'] !== $salesRepOtpInput) {
                $_SESSION['error'] = 'Invalid Sales Rep OTP code!';
            } else {
                // Mark warehouse as verified
                $updateStmt = $pdo->prepare("
                    UPDATE order_transfer_otps
                    SET warehouse_verified_at = NOW(),
                        warehouse_verified_by = ?
                    WHERE order_id = ?
                ");
                $updateStmt->execute([(int)$user['id'], $orderId]);

                // Check if both parties have verified
                $checkStmt = $pdo->prepare("
                    SELECT * FROM order_transfer_otps
                    WHERE order_id = ?
                    AND warehouse_verified_at IS NOT NULL
                    AND sales_rep_verified_at IS NOT NULL
                ");
                $checkStmt->execute([$orderId]);
                $bothVerified = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($bothVerified) {
                    // Both verified - transfer stock
                    $pdo->commit();
                    $transferSuccess = transferStockToSalesRep($pdo, $orderId, (int)$user['id']);

                    if ($transferSuccess) {
                        $_SESSION['success'] = 'OTP verified! Stock transferred to sales rep\'s van successfully.';
                    } else {
                        $_SESSION['error'] = 'OTP verified but stock transfer failed.';
                    }
                } else {
                    $pdo->commit();
                    $_SESSION['success'] = 'Warehouse OTP verified! Waiting for sales rep verification...';
                }
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
    }

    header('Location: orders.php');
    exit;
}

// Handle mark as prepared
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_prepared') {
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId > 0) {
        try {
            $pdo->beginTransaction();

            // Check stock availability first
            $stockCheck = checkStockAvailability($pdo, $orderId);

            if (!$stockCheck['available']) {
                $pdo->rollBack();
                $shortageList = [];
                foreach ($stockCheck['shortage'] as $item) {
                    $shortageList[] = "{$item['sku']} ({$item['name']}): need {$item['needed']}, have {$item['available']}";
                }
                $_SESSION['error'] = 'Insufficient stock for this order: ' . implode('; ', $shortageList);
                header('Location: orders.php');
                exit;
            }

            // Update order status to 'ready'
            $updateStmt = $pdo->prepare("
                UPDATE orders
                SET status = 'ready', updated_at = NOW()
                WHERE id = ? AND status IN ('pending', 'on_hold', 'approved', 'preparing')
            ");
            $updateStmt->execute([$orderId]);

            if ($updateStmt->rowCount() > 0) {
                // Get order details for notification
                $orderDetailsStmt = $pdo->prepare("
                    SELECT o.order_number, o.sales_rep_id, c.name as customer_name
                    FROM orders o
                    LEFT JOIN customers c ON c.id = o.customer_id
                    WHERE o.id = ?
                ");
                $orderDetailsStmt->execute([$orderId]);
                $orderDetails = $orderDetailsStmt->fetch(PDO::FETCH_ASSOC);

                // Generate OTP codes for secure handoff
                $warehouseOtp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $salesRepOtp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

                // Store OTP codes
                try {
                    $otpStmt = $pdo->prepare("
                        INSERT INTO order_transfer_otps (order_id, warehouse_otp, sales_rep_otp, expires_at)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            warehouse_otp = VALUES(warehouse_otp),
                            sales_rep_otp = VALUES(sales_rep_otp),
                            expires_at = VALUES(expires_at),
                            warehouse_verified_at = NULL,
                            sales_rep_verified_at = NULL,
                            warehouse_verified_by = NULL,
                            sales_rep_verified_by = NULL
                    ");
                    $otpStmt->execute([$orderId, $warehouseOtp, $salesRepOtp, $expiresAt]);
                } catch (Exception $otpEx) {
                    error_log("Failed to generate OTP: " . $otpEx->getMessage());
                }

                // Commit the status update
                $pdo->commit();

                // Create notification for sales rep
                if ($orderDetails && $orderDetails['sales_rep_id']) {
                    try {
                        $notificationStmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, type, payload, created_at)
                            VALUES (?, 'order_ready', ?, NOW())
                        ");
                        $payload = json_encode([
                            'order_id' => $orderId,
                            'order_number' => $orderDetails['order_number'],
                            'customer_name' => $orderDetails['customer_name'],
                            'message' => 'Order ' . $orderDetails['order_number'] . ' for ' . $orderDetails['customer_name'] . ' is ready for pickup - OTP verification required'
                        ]);
                        $notificationStmt->execute([$orderDetails['sales_rep_id'], $payload]);
                    } catch (Exception $notifException) {
                        error_log("Failed to create notification: " . $notifException->getMessage());
                    }
                }

                $_SESSION['success'] = 'Order marked as ready! OTP codes generated. Both parties must verify to complete stock transfer.';

            } else {
                $pdo->rollBack();
                $_SESSION['error'] = 'Could not update order status. Order may already be prepared.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = 'Error: ' . $e->getMessage();
        }
    }

    header('Location: orders.php');
    exit;
}

// Get filters
$statusFilter = $_GET['status'] ?? 'to_prepare';
$salesRepFilter = (int)($_GET['sales_rep'] ?? 0);
$searchQuery = trim($_GET['search'] ?? '');

// Build WHERE clause
$where = [];
$params = [];

if ($statusFilter === 'to_prepare') {
    $where[] = "o.status IN ('pending', 'on_hold', 'approved', 'preparing')";
} elseif ($statusFilter === 'ready') {
    $where[] = "o.status = 'ready'";
} else {
    $where[] = "o.status IN ('pending', 'on_hold', 'approved', 'preparing', 'ready')";
}

if ($salesRepFilter > 0) {
    $where[] = "o.sales_rep_id = :sales_rep";
    $params[':sales_rep'] = $salesRepFilter;
}

// Search filter
if ($searchQuery !== '') {
    $where[] = "(
        o.order_number LIKE :search
        OR c.name LIKE :search
        OR o.id IN (
            SELECT DISTINCT oi.order_id
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            WHERE p.sku LIKE :search OR p.item_name LIKE :search
        )
    )";
    $params[':search'] = '%' . $searchQuery . '%';
}

$whereClause = implode(' AND ', $where);

// Get orders - warehouse needs: sales rep, customer name & phone, date, type, notes, item count
$ordersStmt = $pdo->prepare("
    SELECT
        o.id,
        o.order_number,
        o.status,
        o.order_type,
        o.created_at,
        o.notes as order_notes,
        c.name as customer_name,
        c.phone as customer_phone,
        c.location as customer_location,
        sr.name as sales_rep_name,
        o.sales_rep_id,
        COUNT(oi.id) as item_count
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users sr ON sr.id = o.sales_rep_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE {$whereClause}
    GROUP BY o.id, o.order_number, o.status, o.order_type, o.created_at, o.notes, c.name, c.phone, c.location, sr.name, o.sales_rep_id
    ORDER BY o.created_at ASC
");
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get sales reps for filter
$salesReps = $pdo->query("
    SELECT DISTINCT u.id, u.name
    FROM users u
    INNER JOIN orders o ON o.sales_rep_id = u.id
    WHERE u.role = 'sales_rep'
    ORDER BY u.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Orders to Prepare - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Orders to Prepare',
    'subtitle' => 'Pick and pack orders for shipment',
    'user' => $user,
    'active' => 'orders',
]);

$statusLabels = [
    'pending' => 'Pending',
    'on_hold' => 'On Hold',
    'approved' => 'Approved',
    'preparing' => 'Preparing',
    'ready' => 'Ready',
];

$statusStyles = [
    'pending' => 'background:#fef3c7;color:#92400e;',
    'on_hold' => 'background:#e0e7ff;color:#3730a3;',
    'approved' => 'background:#dbeafe;color:#1e40af;',
    'preparing' => 'background:#fef3c7;color:#92400e;',
    'ready' => 'background:#d1fae5;color:#065f46;',
];

// Display flash messages
if (isset($_SESSION['success'])) {
    echo '<div style="background:#d1fae5;color:#065f46;padding:16px;border-radius:8px;margin-bottom:24px;">';
    echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    echo '<div style="background:#fee2e2;color:#991b1b;padding:16px;border-radius:8px;margin-bottom:24px;">';
    echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
    unset($_SESSION['error']);
}
?>

<!-- Search and Filters -->
<div class="card">
    <form method="GET" style="display:flex;flex-direction:column;gap:16px;">
        <!-- Search Bar -->
        <div>
            <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Search Orders</label>
            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="Search by order number, customer name, or product SKU..."
                style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;font-size:1rem;"
            >
            <small style="color:var(--muted);font-size:0.85rem;margin-top:4px;display:block;">
                Try: order number (ORD-000010), customer name, or product SKU (MKS006-29)
            </small>
        </div>

        <!-- Filter Row -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;align-items:end;">
            <div>
                <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Order Status</label>
                <select name="status" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
                    <option value="to_prepare" <?= $statusFilter === 'to_prepare' ? 'selected' : '' ?>>To Prepare</option>
                    <option value="ready" <?= $statusFilter === 'ready' ? 'selected' : '' ?>>Ready for Shipment</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:500;margin-bottom:8px;font-size:0.9rem;">Sales Rep</label>
                <select name="sales_rep" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:6px;">
                    <option value="0">All Sales Reps</option>
                    <?php foreach ($salesReps as $rep): ?>
                        <option value="<?= $rep['id'] ?>" <?= $salesRepFilter === $rep['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn" style="flex:1;">
                    <?= $searchQuery ? '🔍 Search' : 'Filter' ?>
                </button>
                <a href="orders.php" class="btn btn-secondary">Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- Results Summary -->
<?php if ($searchQuery || $salesRepFilter > 0): ?>
    <div style="background:var(--bg);padding:12px 20px;border-radius:8px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <strong><?= count($orders) ?></strong> order(s) found
            <?php if ($searchQuery): ?>
                matching "<strong><?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?></strong>"
            <?php endif; ?>
        </div>
        <a href="orders.php" style="color:var(--primary);font-size:0.9rem;text-decoration:none;">
            Clear filters &times;
        </a>
    </div>
<?php endif; ?>

<!-- Orders List -->
<?php if (empty($orders)): ?>
    <div class="card">
        <p style="text-align:center;color:var(--muted);padding:40px 0;">
            <?php if ($searchQuery): ?>
                No orders found matching "<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
            <?php else: ?>
                No orders to prepare at this time
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <?php foreach ($orders as $order): ?>
        <?php
        // Get order items - warehouse only needs: SKU, product name, description, quantity, stock level, image
        $itemsStmt = $pdo->prepare("
            SELECT
                oi.id,
                oi.quantity as qty_ordered,
                p.id as product_id,
                p.sku,
                p.item_name,
                p.description,
                p.unit,
                p.image_url,
                COALESCE(p.quantity_on_hand, 0) as qty_in_stock
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            ORDER BY p.item_name ASC
        ");
        $itemsStmt->execute([$order['id']]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if order has OTP (status = ready)
        $otpData = null;
        if ($order['status'] === 'ready') {
            $otpStmt = $pdo->prepare("
                SELECT * FROM order_transfer_otps
                WHERE order_id = ? AND expires_at > NOW()
            ");
            $otpStmt->execute([$order['id']]);
            $otpData = $otpStmt->fetch(PDO::FETCH_ASSOC);
        }
        ?>

        <!-- Minimal Order Card -->
        <div style="border:1px solid #000;margin-bottom:30px;background:#fff;">
            <!-- Order Header -->
            <div style="border-bottom:2px solid #000;padding:12px 16px;background:#f9f9f9;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div style="display:flex;gap:20px;align-items:center;font-size:0.9rem;">
                        <strong style="font-size:1.1rem;"><?= htmlspecialchars($order['order_number'] ?? 'ORD-' . str_pad($order['id'], 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($order['sales_rep_name']): ?>
                            <span>Rep: <?= htmlspecialchars($order['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <span>Customer: <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($order['customer_phone']): ?>
                            <span>Tel: <?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <span><?= date('d/m/Y', strtotime($order['created_at'])) ?></span>
                        <span><?= $order['order_type'] === 'van_stock_sale' ? 'Van' : 'Company' ?></span>
                    </div>
                    <div>
                        <strong style="text-transform:uppercase;font-size:0.85rem;"><?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>
                <?php if (!empty($order['order_notes'])): ?>
                    <div style="margin-top:8px;padding:8px;background:#fff;border:1px solid #ccc;font-size:0.85rem;">
                        <strong>Notes:</strong> <?= nl2br(htmlspecialchars($order['order_notes'], ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Order Items Table -->
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f5f5f5;border-bottom:1px solid #000;">
                        <th style="padding:10px;text-align:left;border-right:1px solid #ddd;width:80px;">Image</th>
                        <th style="padding:10px;text-align:left;border-right:1px solid #ddd;">SKU</th>
                        <th style="padding:10px;text-align:left;border-right:1px solid #ddd;">Product</th>
                        <th style="padding:10px;text-align:center;border-right:1px solid #ddd;width:100px;">Ordered</th>
                        <th style="padding:10px;text-align:center;border-right:1px solid #ddd;width:100px;">In Stock</th>
                        <th style="padding:10px;text-align:center;width:120px;">To Ship</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $qtyOrdered = (float)$item['qty_ordered'];
                        $qtyInStock = (float)$item['qty_in_stock'];
                        $hasStock = $qtyInStock >= $qtyOrdered;
                        ?>
                        <tr style="border-bottom:1px solid #ddd;">
                            <td style="padding:10px;text-align:center;border-right:1px solid #ddd;">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                         style="width:60px;height:60px;object-fit:cover;border:1px solid #ccc;">
                                <?php else: ?>
                                    <div style="width:60px;height:60px;background:#f5f5f5;display:flex;align-items:center;justify-content:center;border:1px solid #ccc;">-</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;border-right:1px solid #ddd;">
                                <strong><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </td>
                            <td style="padding:10px;border-right:1px solid #ddd;">
                                <div style="font-weight:600;"><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if ($item['description']): ?>
                                    <div style="font-size:0.85rem;color:#666;"><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;text-align:center;border-right:1px solid #ddd;">
                                <strong><?= number_format($qtyOrdered, 0) ?></strong>
                                <div style="font-size:0.8rem;color:#666;"><?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td style="padding:10px;text-align:center;border-right:1px solid #ddd;<?= !$hasStock ? 'background:#ffe5e5;' : '' ?>">
                                <strong><?= number_format($qtyInStock, 0) ?></strong>
                                <?php if (!$hasStock): ?>
                                    <div style="font-size:0.75rem;color:#d00;font-weight:600;">SHORT</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px;text-align:center;">
                                <input type="number"
                                       name="qty_to_ship_<?= $item['id'] ?>"
                                       value="<?= min($qtyOrdered, $qtyInStock) ?>"
                                       min="0"
                                       max="<?= $qtyInStock ?>"
                                       style="width:80px;padding:6px;text-align:center;border:1px solid #000;font-size:1rem;font-weight:600;"
                                       data-item-id="<?= $item['id'] ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Actions -->
            <div style="padding:16px;background:#f9f9f9;border-top:1px solid #000;display:flex;justify-content:space-between;align-items:center;">
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="checkStock(<?= $order['id'] ?>)" style="padding:8px 16px;background:#fff;border:1px solid #000;cursor:pointer;font-weight:600;">
                        CHECK STOCK
                    </button>
                    <a href="print_picklist.php?order_id=<?= $order['id'] ?>" target="_blank"
                       style="padding:8px 16px;background:#fff;border:1px solid #000;text-decoration:none;color:#000;font-weight:600;display:inline-block;">
                        PRINT
                    </a>
                </div>
                <?php if ($order['status'] !== 'ready'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this order as ready for shipment?');">
                        <input type="hidden" name="action" value="mark_prepared">
                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                        <button type="submit" style="padding:10px 24px;background:#000;color:#fff;border:none;cursor:pointer;font-weight:600;font-size:0.95rem;">
                            MARK AS READY
                        </button>
                    </form>
                <?php else: ?>
                    <?php if ($otpData): ?>
                        <?php
                        $warehouseVerified = $otpData['warehouse_verified_at'] !== null;
                        $salesRepVerified = $otpData['sales_rep_verified_at'] !== null;
                        $bothVerified = $warehouseVerified && $salesRepVerified;
                        ?>
                        <div style="display:flex;flex-direction:column;gap:12px;padding:12px;background:#fff;border:2px solid #000;border-radius:4px;">
                            <?php if ($bothVerified): ?>
                                <div style="text-align:center;padding:8px;background:#22c55e;color:#fff;font-weight:700;border-radius:4px;">
                                    ✓ STOCK TRANSFERRED
                                </div>
                            <?php else: ?>
                                <!-- Warehouse OTP Display -->
                                <div style="background:#fef3c7;padding:12px;border-radius:4px;border:2px solid #f59e0b;">
                                    <div style="font-size:0.75rem;font-weight:600;color:#92400e;margin-bottom:4px;">YOUR OTP (Show to Sales Rep):</div>
                                    <div style="font-size:2rem;font-weight:700;letter-spacing:8px;color:#000;text-align:center;font-family:monospace;">
                                        <?= htmlspecialchars($otpData['warehouse_otp'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>

                                <!-- Sales Rep OTP Input -->
                                <form method="POST" style="display:flex;flex-direction:column;gap:8px;">
                                    <input type="hidden" name="action" value="verify_otp_warehouse">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <div>
                                        <label style="font-size:0.75rem;font-weight:600;display:block;margin-bottom:4px;">Enter Sales Rep OTP:</label>
                                        <input type="text" name="sales_rep_otp" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required
                                               style="width:100%;padding:12px;font-size:1.5rem;text-align:center;letter-spacing:8px;font-family:monospace;border:2px solid #000;font-weight:700;"
                                               <?= $warehouseVerified ? 'disabled' : '' ?>>
                                    </div>
                                    <?php if (!$warehouseVerified): ?>
                                        <button type="submit" style="padding:12px;background:#000;color:#fff;border:none;cursor:pointer;font-weight:600;font-size:0.95rem;">
                                            VERIFY & COMPLETE TRANSFER
                                        </button>
                                    <?php else: ?>
                                        <div style="padding:8px;background:#22c55e;color:#fff;font-weight:600;text-align:center;border-radius:4px;">
                                            ✓ Warehouse Verified - Waiting for Sales Rep
                                        </div>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="padding:10px 24px;background:#dc2626;color:#fff;font-weight:600;">
                            OTP EXPIRED - RE-MARK AS READY
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
function checkStock(orderId) {
    // Get all input fields in the order
    const inputs = document.querySelectorAll('input[type="number"]');
    let hasIssues = false;
    let message = 'Stock Check:\n\n';

    inputs.forEach(input => {
        const toShip = parseInt(input.value) || 0;
        const max = parseInt(input.max) || 0;
        const row = input.closest('tr');

        if (toShip > max) {
            hasIssues = true;
            const sku = row.querySelector('td:nth-child(2)').textContent.trim();
            message += `${sku}: Cannot ship ${toShip}, only ${max} in stock\n`;
            row.style.background = '#ffe5e5';
        } else if (toShip === 0) {
            const sku = row.querySelector('td:nth-child(2)').textContent.trim();
            message += `${sku}: Quantity set to 0 (will not ship)\n`;
            row.style.background = '#fff9e5';
        } else {
            row.style.background = '';
        }
    });

    if (!hasIssues) {
        message += 'All quantities are within available stock.';
    }

    alert(message);
}
</script>

<?php
warehouse_portal_render_layout_end();
