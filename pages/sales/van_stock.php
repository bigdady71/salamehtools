<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';

use SalamehTools\Middleware\RBACMiddleware;

require_login();
RBACMiddleware::requireRole('sales_rep', 'Access denied. Sales representatives only.');

$user = auth_user();
$pdo = db();
$title = 'Van Stock';

$repId = $user['id'];

// Get van stock with product details
$vanStock = $pdo->prepare("
    SELECT
        s.id,
        s.product_id,
        s.qty_on_hand as quantity,
        p.sku,
        p.item_name,
        p.sale_price_usd,
        p.quantity_on_hand as warehouse_stock,
        (s.qty_on_hand * p.sale_price_usd) as stock_value
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id AND s.qty_on_hand > 0
    ORDER BY p.item_name ASC
");
$vanStock->execute([':rep_id' => $repId]);
$items = $vanStock->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalItems = count($items);
$totalQuantity = array_sum(array_column($items, 'quantity'));
$totalValue = array_sum(array_column($items, 'stock_value'));

// Recent movements (last 20)
$movements = $pdo->prepare("
    SELECT
        m.id,
        m.delta_qty,
        m.reason,
        m.created_at,
        p.item_name,
        p.sku
    FROM s_stock_movements m
    INNER JOIN products p ON p.id = m.product_id
    WHERE m.salesperson_id = :rep_id
    ORDER BY m.created_at DESC
    LIMIT 20
");
$movements->execute([':rep_id' => $repId]);
$recentMovements = $movements->fetchAll(PDO::FETCH_ASSOC);

admin_render_layout_start(['title' => $title, 'user' => $user]);
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
}
.stat-card .value {
    font-size: 32px;
    font-weight: 700;
    color: #1e293b;
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
.badge-danger { background: #fee2e2; color: #991b1b; }
.empty-state {
    text-align: center;
    padding: 40px;
    color: #64748b;
}
</style>

<h1>Van Stock</h1>
<p style="color: #64748b; margin-bottom: 30px;">Your current van inventory</p>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <h3>Products</h3>
        <div class="value"><?= $totalItems ?></div>
    </div>
    <div class="stat-card">
        <h3>Total Units</h3>
        <div class="value"><?= number_format($totalQuantity, 0) ?></div>
    </div>
    <div class="stat-card">
        <h3>Stock Value</h3>
        <div class="value">$<?= number_format($totalValue, 0) ?></div>
    </div>
</div>

<!-- Current Stock -->
<div class="section">
    <h2>Current Inventory</h2>
    <?php if (empty($items)): ?>
        <div class="empty-state">
            <p>No stock currently in your van.</p>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Van Qty</th>
                    <th>Warehouse Stock</th>
                    <th>Unit Price</th>
                    <th>Stock Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                    <td><?= htmlspecialchars($item['sku']) ?></td>
                    <td style="font-weight: 600;"><?= number_format($item['quantity'], 1) ?></td>
                    <td><?= number_format($item['warehouse_stock'], 0) ?></td>
                    <td>$<?= number_format($item['sale_price_usd'], 2) ?></td>
                    <td style="font-weight: 600;">$<?= number_format($item['stock_value'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Recent Movements -->
<?php if (!empty($recentMovements)): ?>
<div class="section">
    <h2>Recent Stock Movements</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Change</th>
                <th>Reason</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentMovements as $movement): ?>
            <tr>
                <td><?= date('M j, Y H:i', strtotime($movement['created_at'])) ?></td>
                <td>
                    <?= htmlspecialchars($movement['item_name']) ?>
                    <br><small style="color: #64748b;"><?= htmlspecialchars($movement['sku']) ?></small>
                </td>
                <td>
                    <?php
                    $delta = (float)$movement['delta_qty'];
                    $badgeClass = $delta > 0 ? 'badge-success' : 'badge-danger';
                    $sign = $delta > 0 ? '+' : '';
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= $sign ?><?= number_format($delta, 1) ?></span>
                </td>
                <td><?= htmlspecialchars($movement['reason'] ?? 'N/A') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php admin_render_layout_end(); ?>
