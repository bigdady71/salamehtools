<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';
require_once __DIR__ . '/../../includes/csv_export.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

// Get threshold settings (default: 10 for low stock, 0 for out of stock)
$lowStockThreshold = 10;
$criticalThreshold = 3;

// Get filter from URL
$filter = $_GET['filter'] ?? 'all'; // all, out_of_stock, critical, low

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $whereClause = "p.is_active = 1 AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
    if ($filter === 'out_of_stock') {
        $whereClause .= " AND p.quantity_on_hand <= 0";
    } elseif ($filter === 'critical') {
        $whereClause .= " AND p.quantity_on_hand > 0 AND p.quantity_on_hand <= {$criticalThreshold}";
    } elseif ($filter === 'low') {
        $whereClause .= " AND p.quantity_on_hand > {$criticalThreshold} AND p.quantity_on_hand <= {$lowStockThreshold}";
    } else {
        $whereClause .= " AND p.quantity_on_hand <= {$lowStockThreshold}";
    }

    $exportData = $pdo->query("
        SELECT
            p.sku as 'SKU',
            p.item_name as 'Product Name',
            p.topcat_name as 'Category',
            p.quantity_on_hand as 'Qty in Stock',
            CASE 
                WHEN p.quantity_on_hand <= 0 THEN 'Out of Stock'
                WHEN p.quantity_on_hand <= {$criticalThreshold} THEN 'Critical'
                ELSE 'Low Stock'
            END as 'Status',
            p.unit as 'Unit'
        FROM products p
        WHERE {$whereClause}
        ORDER BY p.quantity_on_hand ASC, p.item_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'low_stock_alerts_' . date('Y-m-d_His');
    exportToCSV($exportData, $filename);
}

// Build WHERE clause based on filter
$whereClause = "p.is_active = 1 AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
if ($filter === 'out_of_stock') {
    $whereClause .= " AND p.quantity_on_hand <= 0";
} elseif ($filter === 'critical') {
    $whereClause .= " AND p.quantity_on_hand > 0 AND p.quantity_on_hand <= {$criticalThreshold}";
} elseif ($filter === 'low') {
    $whereClause .= " AND p.quantity_on_hand > {$criticalThreshold} AND p.quantity_on_hand <= {$lowStockThreshold}";
} else {
    // Show all low stock (including out of stock)
    $whereClause .= " AND p.quantity_on_hand <= {$lowStockThreshold}";
}

// Get low stock products (warehouse stock from products.quantity_on_hand)
$lowStockProducts = $pdo->query("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.topcat_name,
        p.unit,
        p.reorder_point,
        p.quantity_on_hand as qty_on_hand
    FROM products p
    WHERE {$whereClause}
    ORDER BY p.quantity_on_hand ASC, p.item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get counts for summary
$countStmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN quantity_on_hand <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity_on_hand > 0 AND quantity_on_hand <= {$criticalThreshold} THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN quantity_on_hand > {$criticalThreshold} AND quantity_on_hand <= {$lowStockThreshold} THEN 1 ELSE 0 END) as low_stock
    FROM products 
    WHERE is_active = 1 AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
");
$counts = $countStmt->fetch(PDO::FETCH_ASSOC);
$outOfStockCount = (int)$counts['out_of_stock'];
$criticalCount = (int)$counts['critical'];
$lowStockCount = (int)$counts['low_stock'];
$totalAlerts = $outOfStockCount + $criticalCount + $lowStockCount;

$title = 'Low Stock Alerts - Warehouse Portal';

warehouse_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Low Stock Alerts',
    'subtitle' => 'Products that need attention',
    'user' => $user,
    'active' => 'low_stock',
]);
?>

<style>
    .alert-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .alert-card {
        background: var(--bg-panel);
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        border: 2px solid var(--border);
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        color: inherit;
    }

    .alert-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
    }

    .alert-card.active {
        border-color: #3b82f6;
        background: rgba(59, 130, 246, 0.05);
    }

    .alert-card.out-of-stock {
        border-left: 4px solid #dc2626;
    }

    .alert-card.critical {
        border-left: 4px solid #f59e0b;
    }

    .alert-card.low {
        border-left: 4px solid #3b82f6;
    }

    .alert-card .count {
        font-size: 2.5rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 8px;
    }

    .alert-card.out-of-stock .count {
        color: #dc2626;
    }

    .alert-card.critical .count {
        color: #f59e0b;
    }

    .alert-card.low .count {
        color: #3b82f6;
    }

    .alert-card .label {
        font-size: 0.9rem;
        color: var(--muted);
        font-weight: 600;
    }

    .product-row {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        border-bottom: 1px solid var(--border);
        transition: background 0.2s;
    }

    .product-row:hover {
        background: var(--bg-panel-alt);
    }

    .product-row:last-child {
        border-bottom: none;
    }

    .product-row.out-of-stock {
        background: #fef2f2;
    }

    .product-row.critical {
        background: #fffbeb;
    }

    .stock-badge {
        padding: 6px 14px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 0.85rem;
        min-width: 100px;
        text-align: center;
    }

    .stock-badge.out-of-stock {
        background: #dc2626;
        color: white;
    }

    .stock-badge.critical {
        background: #f59e0b;
        color: white;
    }

    .stock-badge.low {
        background: #3b82f6;
        color: white;
    }

    @media (max-width: 768px) {
        .product-row {
            flex-wrap: wrap;
        }

        .product-row>div:first-child {
            width: 100%;
            margin-bottom: 8px;
        }
    }
</style>

<!-- Summary Cards -->
<div class="alert-summary">
    <a href="?filter=all" class="alert-card <?= $filter === 'all' ? 'active' : '' ?>">
        <div class="count" style="color:#6b7280;"><?= $totalAlerts ?></div>
        <div class="label">Total Alerts</div>
    </a>
    <a href="?filter=out_of_stock" class="alert-card out-of-stock <?= $filter === 'out_of_stock' ? 'active' : '' ?>">
        <div class="count"><?= $outOfStockCount ?></div>
        <div class="label">Out of Stock</div>
    </a>
    <a href="?filter=critical" class="alert-card critical <?= $filter === 'critical' ? 'active' : '' ?>">
        <div class="count"><?= $criticalCount ?></div>
        <div class="label">Critical (1-<?= $criticalThreshold ?>)</div>
    </a>
    <a href="?filter=low" class="alert-card low <?= $filter === 'low' ? 'active' : '' ?>">
        <div class="count"><?= $lowStockCount ?></div>
        <div class="label">Low Stock (<?= $criticalThreshold + 1 ?>-<?= $lowStockThreshold ?>)</div>
    </a>
</div>

<div class="card">
    <div
        style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <h2 style="margin:0;">
            <?php
            $filterLabels = [
                'all' => '⚠️ All Low Stock Products',
                'out_of_stock' => '🚫 Out of Stock Products',
                'critical' => '🔴 Critical Stock Products',
                'low' => '🟡 Low Stock Products'
            ];
            echo $filterLabels[$filter] ?? $filterLabels['all'];
            ?>
            <span style="font-weight:400;color:var(--muted);font-size:1rem;">(<?= count($lowStockProducts) ?>)</span>
        </h2>
        <?php if (count($lowStockProducts) > 0): ?>
            <a href="?export=csv&filter=<?= urlencode($filter) ?>" class="btn"
                style="background:#059669;color:white;border-color:#059669;">
                📥 Export to CSV
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($lowStockProducts)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--success);">
            <div style="font-size:4rem;margin-bottom:16px;">✓</div>
            <h2 style="color:var(--success);margin:0 0 8px;">All Stock Levels Good!</h2>
            <p style="color:var(--muted);margin:0;">No products match this filter criteria</p>
        </div>
    <?php else: ?>
        <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;">
            <?php foreach ($lowStockProducts as $product): ?>
                <?php
                $qtyOnHand = (float)$product['qty_on_hand'];

                if ($qtyOnHand <= 0) {
                    $statusClass = 'out-of-stock';
                    $statusLabel = 'OUT OF STOCK';
                } elseif ($qtyOnHand <= $criticalThreshold) {
                    $statusClass = 'critical';
                    $statusLabel = 'CRITICAL';
                } else {
                    $statusClass = 'low';
                    $statusLabel = 'LOW STOCK';
                }
                ?>
                <div class="product-row <?= $statusClass ?>">
                    <div style="flex:0 0 110px;">
                        <span class="stock-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;font-size:1rem;margin-bottom:2px;">
                            <?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div style="font-size:0.85rem;color:var(--muted);">
                            <span style="font-family:monospace;font-weight:600;color:#3b82f6;">
                                <?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            &nbsp;•&nbsp;<?= htmlspecialchars($product['topcat_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div style="text-align:center;min-width:80px;">
                        <div
                            style="font-size:1.8rem;font-weight:800;color:<?= $qtyOnHand <= 0 ? '#dc2626' : ($qtyOnHand <= $criticalThreshold ? '#f59e0b' : '#3b82f6') ?>;">
                            <?= number_format($qtyOnHand, 0) ?>
                        </div>
                        <div style="font-size:0.75rem;color:var(--muted);">
                            <?= htmlspecialchars($product['unit'] ?? 'units', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a href="receiving.php?product_id=<?= $product['id'] ?>" class="btn"
                            style="background:#059669;color:white;border-color:#059669;padding:8px 16px;font-size:0.85rem;">
                            📥 Receive
                        </a>
                        <a href="adjustments.php?product_id=<?= $product['id'] ?>" class="btn"
                            style="padding:8px 16px;font-size:0.85rem;">
                            🔧 Adjust
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
warehouse_portal_render_layout_end();
