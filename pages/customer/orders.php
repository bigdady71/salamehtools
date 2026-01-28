<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

// Handle AJAX reorder request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder') {
    header('Content-Type: application/json');
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
        exit;
    }

    try {
        // Verify order belongs to customer
        $orderCheck = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND customer_id = ?");
        $orderCheck->execute([$orderId, $customerId]);
        if (!$orderCheck->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
            exit;
        }

        // Get order items
        $itemsStmt = $pdo->prepare("
            SELECT oi.product_id, oi.quantity, p.item_name, p.is_active
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            echo json_encode(['success' => false, 'error' => 'Order has no items']);
            exit;
        }

        $addedCount = 0;
        $skippedCount = 0;
        $skippedProducts = [];

        foreach ($items as $item) {
            // Skip inactive products
            if (!$item['is_active']) {
                $skippedCount++;
                $skippedProducts[] = $item['item_name'];
                continue;
            }

            $productId = (int)$item['product_id'];
            $quantity = (float)$item['quantity'];

            // Check if product already in cart
            $cartCheck = $pdo->prepare("SELECT id, quantity FROM customer_cart WHERE customer_id = ? AND product_id = ?");
            $cartCheck->execute([$customerId, $productId]);
            $existingCart = $cartCheck->fetch(PDO::FETCH_ASSOC);

            if ($existingCart) {
                // Update quantity
                $newQty = (float)$existingCart['quantity'] + $quantity;
                $updateStmt = $pdo->prepare("UPDATE customer_cart SET quantity = ? WHERE id = ?");
                $updateStmt->execute([$newQty, $existingCart['id']]);
            } else {
                // Insert new item
                $insertStmt = $pdo->prepare("INSERT INTO customer_cart (customer_id, product_id, quantity) VALUES (?, ?, ?)");
                $insertStmt->execute([$customerId, $productId, $quantity]);
            }
            $addedCount++;
        }

        $message = "تمت إضافة {$addedCount} منتج إلى السلة";
        if ($skippedCount > 0) {
            $message .= " ({$skippedCount} منتج غير متوفر)";
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'added' => $addedCount,
            'skipped' => $skippedCount,
            'skipped_products' => $skippedProducts
        ]);
    } catch (PDOException $e) {
        error_log("Reorder error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// Get filters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = ['o.customer_id = ?'];
$params = [$customerId];

if ($status !== '' && $status !== 'all') {
    $where[] = 'o.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = '(o.id = ? OR p.item_name LIKE ?)';
    // Try to parse as order ID
    $orderId = is_numeric($search) ? (int)$search : 0;
    $params[] = $orderId;
    $params[] = '%' . $search . '%';
}

$whereClause = implode(' AND ', $where);

// Get total count for pagination
$countQuery = "SELECT COUNT(DISTINCT o.id) FROM orders o WHERE {$whereClause}";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalOrders / $perPage);

// Fetch orders
$ordersQuery = "
    SELECT
        o.id,
        o.created_at as order_date,
        o.order_type,
        o.status,
        o.total_usd as total_amount_usd,
        o.total_lbp as total_amount_lbp,
        o.notes,
        COUNT(DISTINCT oi.id) as item_count,
        GROUP_CONCAT(DISTINCT p.item_name SEPARATOR ', ') as product_names
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE {$whereClause}
    GROUP BY o.id
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$ordersStmt = $pdo->prepare($ordersQuery);
$ordersStmt->execute($params);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get order statistics
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_orders,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_orders,
        COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_orders,
        COUNT(CASE WHEN status = 'shipped' THEN 1 END) as shipped_orders,
        COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered_orders,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders
    FROM orders
    WHERE customer_id = ?
");
$statsStmt->execute([$customerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
foreach ($stats as $key => $value) {
    $stats[$key] = (int)$value;
}

$title = 'My Orders - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'My Orders',
    'subtitle' => 'View and track your order history',
    'customer' => $customer,
    'active' => 'orders',
    'actions' => [
        ['label' => '🛍️ Browse Products', 'href' => 'products.php', 'variant' => 'primary'],
    ]
]);

?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.stat-badge {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    transition: all 0.2s;
}
.stat-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.12);
}
.stat-badge .value {
    font-size: 2rem;
    font-weight: 800;
    color: var(--accent);
    margin: 0;
}
.stat-badge .label {
    font-size: 0.8rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 4px;
}
.filters-card {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    margin-bottom: 24px;
}
.filters-grid {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 16px;
    align-items: end;
}
@media (max-width: 900px) {
    .filters-grid {
        grid-template-columns: 1fr;
    }
}
.form-group {
    margin: 0;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 0.92rem;
    color: var(--text);
}
.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.2s;
}
.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.orders-table {
    width: 100%;
    border-collapse: collapse;
}
.orders-table th {
    text-align: left;
    padding: 14px 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
    font-size: 0.9rem;
}
.orders-table td {
    padding: 18px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.orders-table tr:hover {
    background: var(--bg-panel-alt);
}
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.badge-pending {
    background: #fef3c7;
    color: #92400e;
}
.badge-approved {
    background: #dbeafe;
    color: #1e40af;
}
.badge-processing {
    background: #e0e7ff;
    color: #3730a3;
}
.badge-shipped {
    background: #d1fae5;
    color: #065f46;
}
.badge-delivered {
    background: #d1fae5;
    color: #065f46;
}
.badge-cancelled {
    background: #fee2e2;
    color: #991b1b;
}
.badge-customer_order {
    background: #e0e7ff;
    color: #3730a3;
}
.badge-van_stock_sale {
    background: #d1fae5;
    color: #065f46;
}
.badge-company_order {
    background: #fef3c7;
    color: #92400e;
}
.empty-state {
    text-align: center;
    padding: 64px 24px;
    color: var(--muted);
}
.empty-state h3 {
    font-size: 1.4rem;
    margin: 0 0 12px;
    color: var(--text);
}
.order-id-link {
    font-weight: 600;
    color: var(--accent);
    text-decoration: none;
    font-size: 1.05rem;
}
.order-id-link:hover {
    text-decoration: underline;
}
.product-preview {
    font-size: 0.85rem;
    color: var(--muted);
    margin-top: 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<!-- Order Statistics -->
<div class="stats-grid">
    <div class="stat-badge">
        <p class="value"><?= $stats['total_orders'] ?></p>
        <p class="label">Total Orders</p>
    </div>
    <div class="stat-badge">
        <p class="value"><?= $stats['pending_orders'] ?></p>
        <p class="label">Pending</p>
    </div>
    <div class="stat-badge">
        <p class="value"><?= $stats['processing_orders'] ?></p>
        <p class="label">Processing</p>
    </div>
    <div class="stat-badge">
        <p class="value"><?= $stats['shipped_orders'] ?></p>
        <p class="label">Shipped</p>
    </div>
    <div class="stat-badge">
        <p class="value"><?= $stats['delivered_orders'] ?></p>
        <p class="label">Delivered</p>
    </div>
</div>

<!-- Filters -->
<div class="filters-card">
    <form method="get" action="orders.php">
        <div class="filters-grid">
            <div class="form-group">
                <label for="search">Search Orders</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    placeholder="Search by order # or product name..."
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label for="status">Order Status</label>
                <select id="status" name="status">
                    <option value="all" <?= $status === 'all' || $status === '' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="shipped" <?= $status === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                    <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
        </div>
    </form>
</div>

<!-- Orders Table -->
<div class="card">
    <h2>Order History</h2>
    <?php if (count($orders) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $orderId = (int)$order['id'];
                        $orderDate = date('M d, Y', strtotime($order['order_date']));
                        $itemCount = (int)$order['item_count'];
                        $orderType = htmlspecialchars($order['order_type'], ENT_QUOTES, 'UTF-8');
                        $status = htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8');
                        $total = (float)$order['total_amount_usd'];
                        $productNames = htmlspecialchars($order['product_names'] ?? 'N/A', ENT_QUOTES, 'UTF-8');

                        $statusBadgeClass = 'badge-' . strtolower($status);
                        $typeBadgeClass = 'badge-' . strtolower(str_replace(' ', '_', $orderType));

                        // Simplify order type display
                        $orderTypeDisplay = match($orderType) {
                            'customer_order' => 'Online Order',
                            'van_stock_sale' => 'Van Sale',
                            'company_order' => 'Company Order',
                            default => ucwords(str_replace('_', ' ', $orderType))
                        };
                        ?>
                        <tr>
                            <td>
                                <a href="order_details.php?id=<?= $orderId ?>" class="order-id-link">
                                    #<?= $orderId ?>
                                </a>
                                <div class="product-preview"><?= $productNames ?></div>
                            </td>
                            <td><?= $orderDate ?></td>
                            <td><?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?></td>
                            <td><span class="badge <?= $typeBadgeClass ?>"><?= $orderTypeDisplay ?></span></td>
                            <td><span class="badge <?= $statusBadgeClass ?>"><?= ucfirst($status) ?></span></td>
                            <td><strong style="color: var(--accent);">$<?= number_format($total, 2) ?></strong></td>
                            <td>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <a href="order_details.php?id=<?= $orderId ?>" class="btn" style="padding: 8px 14px; font-size: 0.85rem;">
                                        عرض
                                    </a>
                                    <button type="button" class="btn btn-reorder" onclick="reorderItems(<?= $orderId ?>)" style="padding: 8px 14px; font-size: 0.85rem; background: #10b981; color: white; border: none;">
                                        🔄 إعادة الطلب
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 32px; flex-wrap: wrap;">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="btn">« First</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn">‹ Previous</a>
                <?php endif; ?>

                <span style="padding: 8px 16px; color: var(--muted); font-weight: 600;">
                    Page <?= $page ?> of <?= $totalPages ?> (<?= $totalOrders ?> total)
                </span>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn">Next ›</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="btn">Last »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state">
            <h3>📦 No Orders Found</h3>
            <?php if ($status !== '' || $search !== ''): ?>
                <p>Try adjusting your search or filters.</p>
                <a href="orders.php" class="btn btn-primary">Clear Filters</a>
            <?php else: ?>
                <p>You haven't placed any orders yet. Start shopping to create your first order!</p>
                <a href="products.php" class="btn btn-primary">Browse Products</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Reorder Toast Notification -->
<div id="reorderToast" style="display: none; position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 16px 24px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); z-index: 9999; max-width: 350px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 1.5rem;">✓</span>
        <div>
            <strong id="toastTitle" style="display: block; margin-bottom: 4px;">تمت الإضافة</strong>
            <span id="toastMessage" style="font-size: 0.9rem; opacity: 0.9;"></span>
        </div>
    </div>
    <a href="cart.php" style="display: inline-block; margin-top: 12px; background: white; color: #10b981; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
        🛒 عرض السلة
    </a>
</div>

<script>
function reorderItems(orderId) {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '⏳ جاري...';

    const formData = new FormData();
    formData.append('action', 'reorder');
    formData.append('order_id', orderId);

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;

        if (data.success) {
            showReorderToast(data.message, true);
        } else {
            showReorderToast(data.error || 'حدث خطأ', false);
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        showReorderToast('حدث خطأ في الاتصال', false);
    });
}

function showReorderToast(message, success) {
    const toast = document.getElementById('reorderToast');
    const toastTitle = document.getElementById('toastTitle');
    const toastMessage = document.getElementById('toastMessage');

    toast.style.background = success ? '#10b981' : '#ef4444';
    toastTitle.textContent = success ? 'تمت الإضافة بنجاح!' : 'خطأ';
    toastMessage.textContent = message;

    toast.style.display = 'block';
    toast.style.animation = 'slideIn 0.3s ease-out';

    setTimeout(() => {
        toast.style.display = 'none';
    }, 5000);
}
</script>

<style>
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.btn-reorder:hover {
    background: #059669 !important;
    transform: translateY(-1px);
}
</style>

<?php

customer_portal_render_layout_end();
