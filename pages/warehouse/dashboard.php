<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Get dashboard statistics

// Total products and stock quantity (warehouse only - uses products.quantity_on_hand)
$stockStats = $pdo->query("
    SELECT
        COUNT(*) as total_products,
        SUM(quantity_on_hand) as total_quantity
    FROM products
    WHERE quantity_on_hand > 0
    AND is_active = 1
")->fetch(PDO::FETCH_ASSOC);

// Low stock count (products below reorder point - warehouse only)
$lowStockCount = $pdo->query("
    SELECT COUNT(*) as count
    FROM products p
    WHERE p.is_active = 1
    AND p.quantity_on_hand > 0
    AND p.quantity_on_hand <= p.reorder_point
")->fetch(PDO::FETCH_ASSOC);

// Orders to prepare (pending, approved, preparing)
$ordersToPrepare = $pdo->query("
    SELECT COUNT(*) as count
    FROM orders
    WHERE status IN ('pending', 'on_hold', 'approved', 'preparing')
")->fetch(PDO::FETCH_ASSOC);

// Orders ready for shipment
$ordersReady = $pdo->query("
    SELECT COUNT(*) as count
    FROM orders
    WHERE status = 'ready'
")->fetch(PDO::FETCH_ASSOC);

// Recent stock movements (last 7 days)
$recentMovements = $pdo->query("
    SELECT COUNT(*) as count
    FROM s_stock_movements
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetch(PDO::FETCH_ASSOC);

// Sales rep stocks summary
$salesRepStocks = $pdo->query("
    SELECT
        COUNT(DISTINCT sales_rep_id) as total_reps,
        COUNT(*) as total_items,
        SUM(quantity) as total_quantity
    FROM van_stock_items
    WHERE quantity > 0
")->fetch(PDO::FETCH_ASSOC);

// Recent orders (last 10)
$recentOrders = $pdo->query("
    SELECT
        o.id,
        o.order_number,
        o.status,
        o.order_type,
        o.total_usd,
        o.created_at,
        c.name as customer_name,
        COUNT(oi.id) as item_count
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.status IN ('pending', 'on_hold', 'approved', 'preparing', 'ready')
    GROUP BY o.id, o.order_number, o.status, o.order_type, o.total_usd, o.created_at, c.name
    ORDER BY o.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Top moving products (most orders in last 30 days - warehouse stock)
$topProducts = $pdo->query("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.image_url,
        SUM(oi.quantity) as total_ordered,
        p.quantity_on_hand as qty_on_hand,
        COUNT(DISTINCT oi.order_id) as order_count
    FROM order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY p.id, p.sku, p.item_name, p.image_url, p.quantity_on_hand
    ORDER BY total_ordered DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Critical stock items (out of stock - warehouse only)
$criticalStock = $pdo->query("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.quantity_on_hand as qty_on_hand,
        p.reorder_point
    FROM products p
    WHERE p.is_active = 1
    AND p.quantity_on_hand <= 0
    ORDER BY p.item_name ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Warehouse Dashboard - Salameh Tools';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Warehouse Dashboard',
    'subtitle' => 'Overview of warehouse operations and inventory status',
    'user' => $user,
    'active' => 'dashboard',
]);

// Critical Stock Alert Banner
if (!empty($criticalStock)):
?>
<div style="background:#fee2e2;border:2px solid #dc2626;border-radius:8px;padding:20px;margin-bottom:24px;animation:pulse 2s ease-in-out infinite;">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="font-size:2.5rem;">🚨</div>
        <div style="flex:1;">
            <h2 style="margin:0 0 8px;color:#991b1b;font-size:1.3rem;">
                CRITICAL: <?= count($criticalStock) ?> Products Out of Stock!
            </h2>
            <p style="margin:0 0 12px;color:#7f1d1d;">
                The following products have zero inventory and need immediate attention:
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach (array_slice($criticalStock, 0, 5) as $item): ?>
                    <span style="background:#fecaca;color:#7f1d1d;padding:4px 10px;border-radius:4px;font-size:0.9rem;font-weight:600;">
                        <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endforeach; ?>
                <?php if (count($criticalStock) > 5): ?>
                    <span style="color:#991b1b;font-weight:600;">
                        +<?= count($criticalStock) - 5 ?> more
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <a href="low_stock.php" class="btn" style="background:#dc2626;color:white;border-color:#dc2626;white-space:nowrap;">
            View All →
        </a>
    </div>
</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.85; }
}
</style>
<?php endif; ?>

<?php
$statusLabels = [
    'pending' => 'Pending',
    'on_hold' => 'On Hold',
    'approved' => 'Approved',
    'preparing' => 'Preparing',
    'ready' => 'Ready',
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'returned' => 'Returned',
];

$statusStyles = [
    'pending' => 'background:#fef3c7;color:#92400e;',
    'on_hold' => 'background:#e0e7ff;color:#3730a3;',
    'approved' => 'background:#dbeafe;color:#1e40af;',
    'preparing' => 'background:#fef3c7;color:#92400e;',
    'ready' => 'background:#d1fae5;color:#065f46;',
    'in_transit' => 'background:#dbeafe;color:#1e40af;',
    'delivered' => 'background:#d1fae5;color:#065f46;',
    'cancelled' => 'background:#fee2e2;color:#991b1b;',
    'returned' => 'background:#fef3c7;color:#92400e;',
];
?>
    
<!-- Key Metrics -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:24px;margin-bottom:32px;">
    <!-- Total Products -->
    <div class="card" style="background:linear-gradient(135deg,#2563eb 0%,#1e40af 100%);color:white;border:none;">
        <div style="font-size:2.5rem;font-weight:700;margin-bottom:8px;">
            <?= number_format((int)$stockStats['total_products']) ?>
        </div>
        <div style="font-size:0.95rem;opacity:0.9;">Total Products in Stock</div>
        <div style="font-size:0.85rem;opacity:0.7;margin-top:8px;">
            <?= number_format((float)$stockStats['total_quantity'], 0) ?> units
        </div>
    </div>

    <!-- Total Units -->
    <div class="card" style="background:linear-gradient(135deg,#10b981 0%,#059669 100%);color:white;border:none;">
        <div style="font-size:2.5rem;font-weight:700;margin-bottom:8px;">
            <?= number_format((float)$stockStats['total_quantity'], 0) ?>
        </div>
        <div style="font-size:0.95rem;opacity:0.9;">Total Units in Stock</div>
        <div style="font-size:0.85rem;opacity:0.7;margin-top:8px;">
            Across all products
        </div>
    </div>

    <!-- Orders to Prepare -->
    <div class="card" style="background:linear-gradient(135deg,#f59e0b 0%,#d97706 100%);color:white;border:none;">
        <div style="font-size:2.5rem;font-weight:700;margin-bottom:8px;">
            <?= (int)$ordersToPrepare['count'] ?>
        </div>
        <div style="font-size:0.95rem;opacity:0.9;">Orders to Prepare</div>
        <div style="font-size:0.85rem;opacity:0.7;margin-top:8px;">
            <?= (int)$ordersReady['count'] ?> ready for shipment
        </div>
    </div>

    <!-- Low Stock Alerts -->
    <div class="card" style="background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%);color:white;border:none;">
        <div style="font-size:2.5rem;font-weight:700;margin-bottom:8px;">
            <?= (int)$lowStockCount['count'] ?>
        </div>
        <div style="font-size:0.95rem;opacity:0.9;">Low Stock Alerts</div>
        <div style="font-size:0.85rem;opacity:0.7;margin-top:8px;">
            Products need reordering
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;margin-bottom:32px;">
    <!-- Sales Rep Stocks -->
    <div class="card">
        <h2>🚚 Sales Rep Stocks</h2>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div>
                <div style="font-size:1.5rem;font-weight:600;color:var(--primary);">
                    <?= (int)$salesRepStocks['total_reps'] ?>
                </div>
                <div style="font-size:0.85rem;color:var(--muted);">Active Sales Reps</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:1.5rem;font-weight:600;color:var(--primary);">
                    <?= number_format((float)$salesRepStocks['total_quantity'], 0) ?>
                </div>
                <div style="font-size:0.85rem;color:var(--muted);">Units in Vans</div>
            </div>
        </div>
        <a href="sales_reps_stocks.php" class="btn btn-secondary" style="width:100%;text-align:center;">Manage Van Stocks</a>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <h2>🔄 Recent Activity</h2>
        <div style="margin-bottom:12px;">
            <div style="font-size:1.5rem;font-weight:600;color:var(--primary);">
                <?= (int)$recentMovements['count'] ?>
            </div>
            <div style="font-size:0.85rem;color:var(--muted);">Stock movements (last 7 days)</div>
        </div>
        <a href="stock_movements.php" class="btn btn-secondary" style="width:100%;text-align:center;">View Movements</a>
    </div>
</div>

<!-- Recent Orders to Prepare -->
<div class="card">
    <h2>📋 Orders to Prepare</h2>
    <?php if (empty($recentOrders)): ?>
        <p style="color:var(--muted);text-align:center;padding:40px 0;">
            No orders waiting to be prepared
        </p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Type</th>
                        <th>Customer</th>
                        <th class="text-center">Items</th>
                        <th class="text-center">Status</th>
                        <th>Created</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($order['order_number'] ?? 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </td>
                        <td>
                            <?= $order['order_type'] === 'van_stock_sale' ? '🚚 Van' : '🏢 Company' ?>
                        </td>
                        <td><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-center"><?= (int)$order['item_count'] ?></td>
                        <td class="text-center">
                            <span class="badge" style="<?= $statusStyles[$order['status']] ?? '' ?>">
                                <?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td><?= date('M d, H:i', strtotime($order['created_at'])) ?></td>
                        <td class="text-center">
                            <a href="orders.php?order_id=<?= $order['id'] ?>" class="btn btn-sm">Prepare</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px;text-align:center;">
            <a href="orders.php" class="btn btn-secondary">View All Orders</a>
        </div>
    <?php endif; ?>
</div>

<!-- Top Moving Products -->
<div class="card">
    <h2>📊 Top Moving Products (Last 30 Days)</h2>
    <?php if (empty($topProducts)): ?>
        <p style="color:var(--muted);text-align:center;padding:40px 0;">
            No product movement data available
        </p>
    <?php else: ?>
     <div style="display:flex;flex-direction:column;gap:12px;">
         <?php foreach ($topProducts as $product): ?>
             <?php
             $stockOnHand = (float)($product['qty_on_hand'] ?? 0);
             $totalOrdered = (float)$product['total_ordered'];
             $stockColor = '';
             if ($stockOnHand <= 0) {
                 $stockColor = '#dc2626';
             } elseif ($stockOnHand < $totalOrdered * 0.2) {
                 $stockColor = '#f59e0b';
             } else {
                 $stockColor = '#059669';
             }
             ?>
             <div style="background:white;border:2px solid #e5e7eb;border-radius:8px;padding:12px;display:flex;gap:12px;align-items:center;">
                 <!-- Product Image -->
                 <?php if (!empty($product['image_url'])): ?>
                     <img src="<?= htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                          alt="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                          style="width:50px;height:50px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                 <?php else: ?>
                     <div style="width:50px;height:50px;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:1.3rem;">
                         📦
                     </div>
                 <?php endif; ?>
                 <!-- Product Info -->
                 <div style="flex:1;min-width:0;">
                     <div style="font-weight:600;font-size:0.95rem;">
                         <?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>
                     </div>
                     <div style="font-size:0.8rem;color:#6b7280;">
                         <span style="font-family:monospace;font-weight:600;">
                             <?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>
                         </span>
                         &nbsp;•&nbsp;<?= (int)$product['order_count'] ?> orders
                     </div>
                 </div>
                 <!-- Stats -->
                 <div style="text-align:center;padding:8px 12px;background:#f3f4f6;border-radius:6px;min-width:80px;">
                     <div style="font-size:1.2rem;font-weight:700;color:#1f2937;">
                         <?= number_format($totalOrdered, 0) ?>
                     </div>
                     <div style="font-size:0.7rem;color:#6b7280;">ordered</div>
                 </div>
                 <!-- Stock Status -->
                 <div style="text-align:center;min-width:70px;">
                     <div style="font-weight:700;font-size:1.1rem;color:<?= $stockColor ?>;">
                         <?= number_format($stockOnHand, 0) ?>
                     </div>
                     <div style="font-size:0.7rem;color:#6b7280;">in stock</div>
                 </div>
             </div>
         <?php endforeach; ?>
     </div>
 <?php endif; ?>
</div>

<?php
warehouse_portal_render_layout_end();
