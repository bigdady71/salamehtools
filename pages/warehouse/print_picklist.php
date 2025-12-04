<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Get order ID from URL
$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    die('Invalid order ID');
}

// Get order details
$orderStmt = $pdo->prepare("
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
        sr.name as sales_rep_name
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users sr ON sr.id = o.sales_rep_id
    WHERE o.id = ?
");
$orderStmt->execute([$orderId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die('Order not found');
}

// Get order items
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
    LEFT JOIN s_stock s ON s.product_id = p.id
    WHERE oi.order_id = ?
    ORDER BY p.item_name ASC
");
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalItems = count($items);
$totalQuantity = array_sum(array_column($items, 'qty_ordered'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pick List - <?= htmlspecialchars($order['order_number'] ?? 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../../css/print.css">
    <style>
        /* Screen-only styles */
        @media screen {
            body {
                font-family: Arial, sans-serif;
                max-width: 900px;
                margin: 0 auto;
                padding: 20px;
                background: #f5f5f5;
            }
            .printable {
                background: white;
                padding: 40px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
        }
    </style>
</head>
<body>

<button class="print-button no-print" onclick="window.print()">🖨️ Print Pick List</button>

<div class="printable">
    <!-- Header -->
    <div class="pick-list-header">
        <h1>PICK LIST</h1>
        <div class="order-info">
            <strong>Order:</strong> <?= htmlspecialchars($order['order_number'] ?? 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?><br>
            <strong>Customer:</strong> <?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($order['customer_location']): ?>
                - <?= htmlspecialchars($order['customer_location'], ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?><br>
            <?php if ($order['customer_phone']): ?>
                <strong>Phone:</strong> <?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?><br>
            <?php endif; ?>
            <?php if ($order['sales_rep_name']): ?>
                <strong>Sales Rep:</strong> <?= htmlspecialchars($order['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?><br>
            <?php endif; ?>
            <strong>Type:</strong> <?= $order['order_type'] === 'van_stock_sale' ? 'Van Sale' : 'Company Order' ?><br>
            <strong>Order Date:</strong> <?= date('l, F d, Y', strtotime($order['created_at'])) ?><br>
            <strong>Printed:</strong> <?= date('F d, Y - h:i A') ?><br>
            <?php if (!empty($order['order_notes'])): ?>
                <div style="margin-top:10px;padding:10px;background:#fffbeb;border:1px solid #f59e0b;border-radius:4px;">
                    <strong style="color:#92400e;">📝 Order Notes:</strong><br>
                    <span style="color:#78350f;"><?= nl2br(htmlspecialchars($order['order_notes'], ENT_QUOTES, 'UTF-8')) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary -->
    <div class="pick-summary">
        <div class="total-items">
            Total Items: <?= $totalItems ?> | Total Quantity: <?= number_format($totalQuantity, 2) ?>
        </div>
    </div>

    <!-- Items Table -->
    <table class="pick-list-items">
        <thead>
            <tr>
                <th class="checkbox">Done</th>
                <th>Image</th>
                <th>SKU</th>
                <th>Product Name</th>
                <th class="text-center">Qty Needed</th>
                <th class="text-center">In Stock</th>
                <th>Unit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <?php
                $qtyOrdered = (float)$item['qty_ordered'];
                $qtyInStock = (float)$item['qty_in_stock'];
                $hasStock = $qtyInStock >= $qtyOrdered;
                ?>
                <tr>
                    <td class="checkbox"></td>
                    <td style="text-align:center;">
                        <?php if (!empty($item['image_url'])): ?>
                            <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                 style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #ddd;">
                        <?php else: ?>
                            <span style="color:#999;font-size:1.5rem;">📦</span>
                        <?php endif; ?>
                    </td>
                    <td class="sku"><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <strong><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($item['description']): ?>
                            <br><small style="color:#666;"><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="quantity"><?= number_format($qtyOrdered, 0) ?></td>
                    <td class="text-center <?= !$hasStock ? 'stock-warning' : '' ?>">
                        <?= number_format($qtyInStock, 0) ?>
                        <?php if (!$hasStock): ?>
                            <br><strong>⚠️ SHORT</strong>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Notes Section -->
    <div class="pick-notes">
        <!-- Warehouse staff can write notes here -->
    </div>

    <!-- Footer -->
    <div class="pick-list-footer">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;">
            <div>
                <strong>Picked By:</strong>
                <div class="signature-line"></div>
            </div>
            <div>
                <strong>Date/Time:</strong>
                <div class="signature-line"></div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-print on page load (optional - can remove if not desired)
// window.onload = function() {
//     setTimeout(function() {
//         window.print();
//     }, 500);
// };
</script>

</body>
</html>
