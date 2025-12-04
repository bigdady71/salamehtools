<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Get completed orders
$historyOrders = $pdo->query("
    SELECT
        o.id,
        o.order_number,
        o.status,
        o.order_type,
        o.created_at,
        o.updated_at,
        c.name as customer_name,
        COUNT(oi.id) as item_count
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.status IN ('delivered', 'cancelled', 'returned', 'in_transit')
    GROUP BY o.id, o.order_number, o.status, o.order_type, o.created_at, o.updated_at, c.name
    ORDER BY o.updated_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$title = 'Order History - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Order History',
    'subtitle' => 'Completed and shipped orders',
    'user' => $user,
    'active' => 'history',
]);

$statusLabels = [
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'returned' => 'Returned',
];

$statusStyles = [
    'in_transit' => 'background:#dbeafe;color:#1e40af;',
    'delivered' => 'background:#d1fae5;color:#065f46;',
    'cancelled' => 'background:#fee2e2;color:#991b1b;',
    'returned' => 'background:#fef3c7;color:#92400e;',
];
?>

<div class="card">
    <h2>Completed Orders (<?= count($historyOrders) ?>)</h2>

    <?php if (empty($historyOrders)): ?>
        <p style="text-align:center;color:var(--muted);padding:40px 0;">
            No completed orders found
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
                        <th>Completed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historyOrders as $order): ?>
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
                        <td><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
                        <td><?= date('M d, Y H:i', strtotime($order['updated_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
warehouse_portal_render_layout_end();
