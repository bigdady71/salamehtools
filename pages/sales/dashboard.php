<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';

use SalamehTools\Middleware\RBACMiddleware;

require_login();
RBACMiddleware::requireRole('sales_rep', 'Access denied. Sales representatives only.');

$user = auth_user();
$pdo = db();
$title = 'Sales Dashboard';

// Get sales rep stats
$repId = $user['id'];

// Today's orders
$todayOrders = $pdo->prepare("
    SELECT COUNT(*) as count, COALESCE(SUM(total_usd), 0) as total_usd
    FROM orders
    WHERE sales_rep_id = :rep_id AND DATE(created_at) = CURRENT_DATE()
");
$todayOrders->execute([':rep_id' => $repId]);
$today = $todayOrders->fetch(PDO::FETCH_ASSOC);

// This month's stats
$monthStats = $pdo->prepare("
    SELECT
        COUNT(*) as order_count,
        COALESCE(SUM(total_usd), 0) as total_usd,
        COALESCE(SUM(total_lbp), 0) as total_lbp
    FROM orders
    WHERE sales_rep_id = :rep_id
      AND YEAR(created_at) = YEAR(CURRENT_DATE())
      AND MONTH(created_at) = MONTH(CURRENT_DATE())
");
$monthStats->execute([':rep_id' => $repId]);
$month = $monthStats->fetch(PDO::FETCH_ASSOC);

// My customers
$customerCount = $pdo->prepare("
    SELECT COUNT(*) FROM customers WHERE assigned_sales_rep_id = :rep_id AND is_active = 1
");
$customerCount->execute([':rep_id' => $repId]);
$myCustomers = $customerCount->fetchColumn();

// My van stock value
$vanStockValue = $pdo->prepare("
    SELECT COALESCE(SUM(s.qty_on_hand * p.sale_price_usd), 0) as value
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id AND s.qty_on_hand > 0
");
$vanStockValue->execute([':rep_id' => $repId]);
$vanValue = $vanStockValue->fetchColumn();

// Recent orders
$recentOrders = $pdo->prepare("
    SELECT
        o.id,
        o.order_number,
        o.created_at,
        o.total_usd,
        o.total_lbp,
        c.name as customer_name,
        (SELECT status FROM order_status_events WHERE order_id = o.id ORDER BY id DESC LIMIT 1) as current_status
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.sales_rep_id = :rep_id
    ORDER BY o.created_at DESC
    LIMIT 10
");
$recentOrders->execute([':rep_id' => $repId]);
$orders = $recentOrders->fetchAll(PDO::FETCH_ASSOC);

// Pending collections (invoices not fully paid)
$pendingCollections = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.total_usd,
        i.total_lbp,
        c.name as customer_name,
        COALESCE(paid.paid_usd, 0) as paid_usd,
        COALESCE(paid.paid_lbp, 0) as paid_lbp,
        (i.total_usd - COALESCE(paid.paid_usd, 0)) as balance_usd,
        (i.total_lbp - COALESCE(paid.paid_lbp, 0)) as balance_lbp
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    WHERE o.sales_rep_id = :rep_id
      AND i.status IN ('issued', 'paid')
      AND (i.total_usd > COALESCE(paid.paid_usd, 0) OR i.total_lbp > COALESCE(paid.paid_lbp, 0))
    ORDER BY i.created_at DESC
    LIMIT 5
");
$pendingCollections->execute([':rep_id' => $repId]);
$collections = $pendingCollections->fetchAll(PDO::FETCH_ASSOC);

admin_render_layout_start(['title' => $title, 'user' => $user]);
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
}
.stat-card h3 {
    margin: 0 0 8px 0;
    font-size: 14px;
    font-weight: 500;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.stat-card .value {
    font-size: 32px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}
.stat-card .subvalue {
    font-size: 14px;
    color: #64748b;
    margin-top: 4px;
}
.action-buttons {
    display: flex;
    gap: 12px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}
.btn {
    padding: 12px 24px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-primary {
    background: #3b82f6;
    color: white;
}
.btn-primary:hover {
    background: #2563eb;
}
.btn-secondary {
    background: #64748b;
    color: white;
}
.btn-secondary:hover {
    background: #475569;
}
.section {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
}
.section h2 {
    margin: 0 0 20px 0;
    font-size: 18px;
    font-weight: 600;
    color: #1e293b;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th {
    text-align: left;
    padding: 12px;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
}
.table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 14px;
}
.table tr:hover {
    background: #f8fafc;
}
.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.badge-success { background: #d1fae5; color: #065f46; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-info { background: #dbeafe; color: #1e40af; }
.empty-state {
    text-align: center;
    padding: 40px;
    color: #64748b;
}
</style>

<h1>Sales Dashboard</h1>
<p style="color: #64748b; margin-bottom: 30px;">Welcome back, <?= htmlspecialchars($user['name']) ?>!</p>

<!-- Quick Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <h3>Today's Orders</h3>
        <div class="value"><?= (int)$today['count'] ?></div>
        <div class="subvalue">$<?= number_format($today['total_usd'], 2) ?></div>
    </div>

    <div class="stat-card">
        <h3>This Month</h3>
        <div class="value"><?= (int)$month['order_count'] ?></div>
        <div class="subvalue">$<?= number_format($month['total_usd'], 2) ?></div>
    </div>

    <div class="stat-card">
        <h3>My Customers</h3>
        <div class="value"><?= (int)$myCustomers ?></div>
        <div class="subvalue">Active accounts</div>
    </div>

    <div class="stat-card">
        <h3>Van Stock Value</h3>
        <div class="value">$<?= number_format($vanValue, 0) ?></div>
        <div class="subvalue">Current inventory</div>
    </div>
</div>

<!-- Quick Actions -->
<div class="action-buttons">
    <a href="create_order.php" class="btn btn-primary">+ Create Order</a>
    <a href="van_stock.php" class="btn btn-secondary">Van Stock</a>
    <a href="collections.php" class="btn btn-secondary">Collections</a>
</div>

<!-- Recent Orders -->
<div class="section">
    <h2>Recent Orders</h2>
    <?php if (empty($orders)): ?>
        <div class="empty-state">
            <p>No orders yet. Create your first order to get started!</p>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                <tr>
                    <td>
                        <a href="../admin/orders.php?id=<?= $order['id'] ?>" style="color: #3b82f6; text-decoration: none;">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($order['customer_name'] ?? 'N/A') ?></td>
                    <td><?= date('M j, Y', strtotime($order['created_at'])) ?></td>
                    <td>
                        $<?= number_format($order['total_usd'], 2) ?>
                        <?php if ($order['total_lbp'] > 0): ?>
                            <br><small style="color: #64748b;"><?= number_format($order['total_lbp'], 0) ?> LBP</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $status = $order['current_status'] ?? 'pending';
                        $badgeClass = 'badge-info';
                        if (in_array($status, ['delivered', 'completed'])) $badgeClass = 'badge-success';
                        if (in_array($status, ['on_hold', 'pending'])) $badgeClass = 'badge-warning';
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Pending Collections -->
<?php if (!empty($collections)): ?>
<div class="section">
    <h2>Pending Collections</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($collections as $inv): ?>
            <tr>
                <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                <td><?= htmlspecialchars($inv['customer_name'] ?? 'N/A') ?></td>
                <td>
                    $<?= number_format($inv['total_usd'], 2) ?>
                    <?php if ($inv['total_lbp'] > 0): ?>
                        <br><small style="color: #64748b;"><?= number_format($inv['total_lbp'], 0) ?> LBP</small>
                    <?php endif; ?>
                </td>
                <td>
                    $<?= number_format($inv['paid_usd'], 2) ?>
                    <?php if ($inv['paid_lbp'] > 0): ?>
                        <br><small style="color: #64748b;"><?= number_format($inv['paid_lbp'], 0) ?> LBP</small>
                    <?php endif; ?>
                </td>
                <td style="font-weight: 600; color: #dc2626;">
                    $<?= number_format($inv['balance_usd'], 2) ?>
                    <?php if ($inv['balance_lbp'] > 0): ?>
                        <br><small><?= number_format($inv['balance_lbp'], 0) ?> LBP</small>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="collections.php?invoice_id=<?= $inv['id'] ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                        Record Payment
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php admin_render_layout_end(); ?>
