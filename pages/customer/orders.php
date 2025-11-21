<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get filters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

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

// Fetch orders
$ordersQuery = "
    SELECT
        o.id,
        o.order_date,
        o.order_type,
        o.status,
        o.total_amount_usd,
        o.total_amount_lbp,
        o.notes,
        COUNT(DISTINCT oi.id) as item_count,
        GROUP_CONCAT(DISTINCT p.item_name SEPARATOR ', ') as product_names
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE {$whereClause}
    GROUP BY o.id
    ORDER BY o.order_date DESC, o.id DESC
    LIMIT 50
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
                                <a href="order_details.php?id=<?= $orderId ?>" class="btn" style="padding: 8px 14px; font-size: 0.85rem;">
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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

<?php

customer_portal_render_layout_end();
