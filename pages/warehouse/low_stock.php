<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/csv_export.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportData = $pdo->query("
        SELECT
            p.sku as 'SKU',
            p.item_name as 'Product Name',
            p.topcat_name as 'Category',
            s.qty_on_hand as 'Qty in Stock',
            p.reorder_point as 'Reorder Point',
            (p.reorder_point - s.qty_on_hand) as 'Qty Needed',
            p.unit as 'Unit'
        FROM products p
        INNER JOIN s_stock s ON s.product_id = p.id AND s.salesperson_id = 0
        WHERE p.is_active = 1
        AND p.reorder_point > 0
        AND s.qty_on_hand <= p.reorder_point
        ORDER BY (s.qty_on_hand / p.reorder_point) ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'low_stock_alerts_' . date('Y-m-d_His');
    exportToCSV($exportData, $filename);
}

// Test the query first
$testQuery = "
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.topcat_name,
        p.unit,
        p.reorder_point,
        s.qty_on_hand
    FROM products p
    INNER JOIN s_stock s ON s.product_id = p.id AND s.salesperson_id = 0
    WHERE p.is_active = 1
    AND p.reorder_point > 0
    AND s.qty_on_hand <= p.reorder_point
    ORDER BY (s.qty_on_hand / p.reorder_point) ASC, p.item_name ASC
";

$lowStockProducts = $pdo->query($testQuery)->fetchAll(PDO::FETCH_ASSOC);

$title = 'Low Stock Alerts - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Low Stock Alerts',
    'subtitle' => 'Products that need reordering',
    'user' => $user,
    'active' => 'low_stock',
]);
?>

<div class="card" style="margin-bottom:24px;background:#fef3c7;border-color:#f59e0b;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:16px;">
            <div style="font-size:3rem;">⚠️</div>
            <div>
                <h2 style="margin:0 0 8px;color:#92400e;">
                    <?= count($lowStockProducts) ?> Products Need Attention
                </h2>
                <p style="margin:0;color:#92400e;">
                    These products are at or below their reorder point. Consider restocking soon.
                </p>
            </div>
        </div>
        <?php if (count($lowStockProducts) > 0): ?>
            <a href="?export=csv" class="btn btn-secondary" style="background:#059669;color:white;border-color:#059669;">
                📥 Export to CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <?php if (empty($lowStockProducts)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--success);">
            <div style="font-size:4rem;margin-bottom:16px;">✓</div>
            <h2 style="color:var(--success);margin:0 0 8px;">All Stock Levels Good!</h2>
            <p style="color:var(--muted);margin:0;">No products are below their reorder point</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Priority</th>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th class="text-right">Current Stock</th>
                        <th class="text-right">Reorder Point</th>
                        <th class="text-right">Shortage</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStockProducts as $idx => $product): ?>
                    <?php
                    $qtyOnHand = (float)$product['qty_on_hand'];
                    $reorderPoint = (float)$product['reorder_point'];
                    $shortage = $reorderPoint - $qtyOnHand;
                    $percentOfReorder = $qtyOnHand / $reorderPoint * 100;

                    if ($qtyOnHand <= 0) {
                        $priorityLabel = 'CRITICAL';
                        $priorityColor = 'background:#991b1b;color:white;';
                        $statusLabel = 'Out of Stock';
                        $statusClass = 'badge-danger';
                    } elseif ($percentOfReorder <= 25) {
                        $priorityLabel = 'HIGH';
                        $priorityColor = 'background:#dc2626;color:white;';
                        $statusLabel = 'Very Low';
                        $statusClass = 'badge-danger';
                    } elseif ($percentOfReorder <= 50) {
                        $priorityLabel = 'MEDIUM';
                        $priorityColor = 'background:#f59e0b;color:white;';
                        $statusLabel = 'Low';
                        $statusClass = 'badge-warning';
                    } else {
                        $priorityLabel = 'LOW';
                        $priorityColor = 'background:#fef3c7;color:#92400e;';
                        $statusLabel = 'Below Reorder';
                        $statusClass = 'badge-warning';
                    }
                    ?>
                    <tr style="<?= $qtyOnHand <= 0 ? 'background:#fee2e2;' : '' ?>">
                        <td>
                            <span class="badge" style="<?= $priorityColor ?>;font-weight:700;">
                                <?= $priorityLabel ?>
                            </span>
                        </td>
                        <td><strong><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td>
                            <div style="font-weight:500;"><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div style="font-size:0.85rem;color:var(--muted);">Unit: <?= htmlspecialchars($product['unit'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td><?= htmlspecialchars($product['topcat_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-right">
                            <strong style="color:<?= $qtyOnHand <= 0 ? 'var(--danger)' : 'var(--warning)' ?>;">
                                <?= number_format($qtyOnHand, 2) ?>
                            </strong>
                        </td>
                        <td class="text-right"><?= number_format($reorderPoint, 2) ?></td>
                        <td class="text-right">
                            <strong style="color:var(--danger);">
                                -<?= number_format($shortage, 2) ?>
                            </strong>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
warehouse_portal_render_layout_end();
