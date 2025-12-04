<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/stock_functions.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

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
                // Automatically deduct stock
                $deductSuccess = deductStockForOrder($pdo, $orderId, (int)$user['id']);

                if ($deductSuccess) {
                    $pdo->commit();
                    $_SESSION['success'] = 'Order marked as prepared! Stock has been automatically deducted.';
                } else {
                    $pdo->rollBack();
                    $_SESSION['error'] = 'Order status updated but stock deduction failed. Please check stock movements.';
                }
            } else {
                $pdo->rollBack();
                $_SESSION['error'] = 'Could not update order status. Order may already be prepared.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
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
                COALESCE(s.qty_on_hand, 0) as qty_in_stock
            FROM order_items oi
            INNER JOIN products p ON p.id = oi.product_id
            LEFT JOIN s_stock s ON s.product_id = p.id AND s.salesperson_id = ?
            WHERE oi.order_id = ?
            ORDER BY p.item_name ASC
        ");
        $itemsStmt->execute([$order['sales_rep_id'] ?? 0, $order['id']]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="card" style="margin-bottom:24px;">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:20px;flex-wrap:wrap;gap:16px;">
                <div>
                    <h2 style="margin:0 0 8px;">
                        <?= htmlspecialchars($order['order_number'] ?? 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.95rem;color:var(--text-light);">
                        <?php if ($order['sales_rep_name']): ?>
                            <div>
                                <strong>Sales Rep:</strong> <?= htmlspecialchars($order['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <strong>Customer:</strong> <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php if ($order['customer_phone']): ?>
                            <div>
                                <strong>Phone:</strong> <?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <strong>Date:</strong> <?= date('M d, Y', strtotime($order['created_at'])) ?>
                        </div>
                        <div>
                            <strong>Type:</strong> <?= $order['order_type'] === 'van_stock_sale' ? '🚚 Van Sale' : '🏢 Company Order' ?>
                        </div>
                        <div>
                            <strong>Items:</strong> <?= (int)$order['item_count'] ?>
                        </div>
                    </div>
                    <?php if (!empty($order['order_notes'])): ?>
                        <div style="margin-top:12px;padding:12px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:4px;">
                            <strong style="color:#92400e;font-size:0.9rem;">📝 Order Notes:</strong>
                            <div style="color:#78350f;font-size:0.9rem;margin-top:4px;">
                                <?= nl2br(htmlspecialchars($order['order_notes'], ENT_QUOTES, 'UTF-8')) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <span class="badge" style="<?= $statusStyles[$order['status']] ?? '' ?>;font-size:1rem;padding:8px 16px;">
                        <?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </div>

            <!-- Order Items - Simplified -->
            <div style="margin-bottom:20px;">
                <h3 style="margin:0 0 12px;font-size:1rem;color:var(--muted);">📦 Items to Pick (<?= count($items) ?>)</h3>

                <div style="display:flex;flex-direction:column;gap:12px;">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $qtyOrdered = (float)$item['qty_ordered'];
                        $qtyInStock = (float)$item['qty_in_stock'];
                        $hasStock = $qtyInStock >= $qtyOrdered;
                        ?>
                        <div style="background:white;border-radius:8px;padding:12px;border:2px solid <?= $hasStock ? '#e5e7eb' : '#fca5a5' ?>;display:flex;gap:12px;align-items:center;">
                            <!-- Product Image -->
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                     style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                            <?php else: ?>
                                <div style="width:60px;height:60px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:1.5rem;">
                                    📦
                                </div>
                            <?php endif; ?>

                            <!-- Product Info -->
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:600;font-size:1rem;margin-bottom:2px;">
                                    <?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div style="font-size:0.85rem;color:#6b7280;">
                                    <span style="font-family:monospace;font-weight:600;color:#3b82f6;">
                                        <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ($item['description']): ?>
                                        &nbsp;•&nbsp;<?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Quantity Badge -->
                            <div style="text-align:center;padding:8px 16px;background:#f3f4f6;border-radius:6px;min-width:80px;">
                                <div style="font-size:2rem;font-weight:700;line-height:1;color:#1f2937;">
                                    <?= number_format($qtyOrdered, 0) ?>
                                </div>
                                <div style="font-size:0.75rem;color:#6b7280;margin-top:2px;">
                                    <?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>

                            <!-- Stock Status -->
                            <div style="text-align:center;min-width:80px;">
                                <?php if ($hasStock): ?>
                                    <div style="color:#059669;font-size:2rem;line-height:1;">✓</div>
                                    <div style="font-size:0.7rem;color:#6b7280;margin-top:2px;">
                                        <?= number_format($qtyInStock, 0) ?> available
                                    </div>
                                <?php else: ?>
                                    <div style="color:#dc2626;font-size:1.5rem;line-height:1;">⚠️</div>
                                    <div style="font-size:0.7rem;color:#dc2626;font-weight:600;margin-top:2px;">
                                        Only <?= number_format($qtyInStock, 0) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Actions - Simplified -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($order['status'] !== 'ready'): ?>
                    <a href="scan_order.php?order_id=<?= $order['id'] ?>"
                       style="flex:1;min-width:200px;padding:14px 20px;background:#10b981;color:white;text-align:center;border-radius:8px;text-decoration:none;font-weight:600;font-size:1rem;">
                        📱 Start Picking
                    </a>
                    <a href="print_picklist.php?order_id=<?= $order['id'] ?>"
                       target="_blank"
                       style="padding:14px 20px;background:#f3f4f6;color:#374151;text-align:center;border-radius:8px;text-decoration:none;font-weight:500;">
                        🖨️ Print
                    </a>
                    <form method="POST" style="flex:1;min-width:200px;" onsubmit="return confirm('Mark this order as prepared and ready for shipment?');">
                        <input type="hidden" name="action" value="mark_prepared">
                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                        <button type="submit" style="width:100%;padding:14px 20px;background:#3b82f6;color:white;border:none;border-radius:8px;font-weight:600;font-size:1rem;cursor:pointer;">
                            ✓ Mark Ready
                        </button>
                    </form>
                <?php else: ?>
                    <div style="flex:1;padding:14px 20px;background:#d1fae5;color:#059669;text-align:center;border-radius:8px;font-weight:600;font-size:1rem;border:2px solid #059669;">
                        ✓ Ready for Shipment
                    </div>
                    <a href="print_picklist.php?order_id=<?= $order['id'] ?>"
                       target="_blank"
                       style="padding:14px 20px;background:#f3f4f6;color:#374151;text-align:center;border-radius:8px;text-decoration:none;font-weight:500;">
                        🖨️ Print
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
warehouse_portal_render_layout_end();
