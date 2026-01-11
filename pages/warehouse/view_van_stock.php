<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;

// Get sales rep info
$salesRep = null;
if ($repId > 0) {
    $repStmt = $pdo->prepare("SELECT id, name, phone FROM users WHERE id = ? AND role = 'sales_rep'");
    $repStmt->execute([$repId]);
    $salesRep = $repStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$salesRep) {
    header('Location: sales_reps_stocks.php');
    exit;
}

// Get van stock items
$stockStmt = $pdo->prepare("
    SELECT
        vs.id,
        vs.quantity,
        vs.loaded_at,
        p.id as product_id,
        p.sku,
        p.item_name,
        p.unit,
        p.image_url,
        p.sale_price_usd,
        p.wholesale_price_usd
    FROM van_stock_items vs
    INNER JOIN products p ON p.id = vs.product_id
    WHERE vs.sales_rep_id = ? AND vs.quantity > 0
    ORDER BY p.item_name ASC
");
$stockStmt->execute([$repId]);
$stockItems = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalProducts = count($stockItems);
$totalQuantity = array_sum(array_column($stockItems, 'quantity'));
$totalValue = 0;
foreach ($stockItems as $item) {
    $totalValue += (float)$item['quantity'] * (float)($item['wholesale_price_usd'] ?? 0);
}

warehouse_portal_render_layout_start([
    'title' => 'Van Stock - ' . $salesRep['name'],
    'heading' => 'Van Stock Details',
    'subtitle' => htmlspecialchars($salesRep['name'], ENT_QUOTES, 'UTF-8'),
    'user' => $user,
    'active' => 'sales_reps_stocks',
    'actions' => [
        ['label' => '← Back', 'href' => 'sales_reps_stocks.php', 'class' => 'btn btn-secondary'],
        ['label' => 'Load More Stock', 'href' => 'load_van.php?rep_id=' . $repId, 'class' => 'btn btn-success'],
    ],
]);
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 32px;
    }
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .stat-card .value {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .stat-card .label {
        font-size: 0.9rem;
        color: var(--muted);
    }
    .stat-card.products .value { color: var(--primary); }
    .stat-card.units .value { color: var(--success); }
    .stat-card.value .value { color: var(--warning); }
    .stock-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 12px;
        overflow: hidden;
    }
    .stock-table th {
        background: var(--bg);
        padding: 14px 12px;
        text-align: left;
        font-weight: 600;
        color: var(--text);
        border-bottom: 2px solid var(--border);
    }
    .stock-table td {
        padding: 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    .stock-table tr:last-child td {
        border-bottom: none;
    }
    .stock-table tr:hover {
        background: var(--bg);
    }
    .product-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .product-image {
        width: 50px;
        height: 50px;
        object-fit: contain;
        background: #f9fafb;
        border-radius: 6px;
        border: 1px solid var(--border);
    }
    .product-image-placeholder {
        width: 50px;
        height: 50px;
        background: #f3f4f6;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: #9ca3af;
    }
    .product-info {
        flex: 1;
    }
    .product-name {
        font-weight: 600;
        margin-bottom: 2px;
    }
    .product-sku {
        font-size: 0.85rem;
        color: var(--muted);
    }
    .qty-badge {
        display: inline-block;
        padding: 6px 14px;
        background: #d1fae5;
        color: #065f46;
        border-radius: 20px;
        font-weight: 600;
    }
    .search-bar {
        margin-bottom: 20px;
    }
    .search-bar input {
        width: 100%;
        max-width: 400px;
        padding: 12px 16px;
        border: 2px solid var(--border);
        border-radius: 8px;
        font-size: 1rem;
    }
    .search-bar input:focus {
        outline: none;
        border-color: var(--primary);
    }
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--muted);
    }
    .empty-state-icon {
        font-size: 4rem;
        margin-bottom: 16px;
        opacity: 0.3;
    }
</style>

<div class="stats-grid">
    <div class="stat-card products">
        <div class="value"><?= $totalProducts ?></div>
        <div class="label">Products</div>
    </div>
    <div class="stat-card units">
        <div class="value"><?= number_format($totalQuantity, 0) ?></div>
        <div class="label">Total Units</div>
    </div>
    <div class="stat-card value">
        <div class="value">$<?= number_format($totalValue, 2) ?></div>
        <div class="label">Wholesale Value</div>
    </div>
</div>

<?php if (empty($stockItems)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-state-icon">📦</div>
            <h3>No Stock Loaded</h3>
            <p>This sales rep has no stock loaded on their van.</p>
            <a href="load_van.php?rep_id=<?= $repId ?>" class="btn btn-success" style="margin-top:16px;">Load Van</a>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search products..." autocomplete="off">
        </div>

        <table class="stock-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th style="text-align:center;">Quantity</th>
                    <th style="text-align:right;">Wholesale Price</th>
                    <th style="text-align:right;">Total Value</th>
                    <th>Last Loaded</th>
                </tr>
            </thead>
            <tbody id="stockTableBody">
                <?php foreach ($stockItems as $item): ?>
                    <?php $itemValue = (float)$item['quantity'] * (float)($item['wholesale_price_usd'] ?? 0); ?>
                    <tr data-search="<?= strtolower(htmlspecialchars($item['item_name'] . ' ' . ($item['sku'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>">
                        <td>
                            <div class="product-cell">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                                         class="product-image">
                                <?php else: ?>
                                    <div class="product-image-placeholder">📦</div>
                                <?php endif; ?>
                                <div class="product-info">
                                    <div class="product-name"><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="product-sku"><?= htmlspecialchars($item['sku'] ?? 'No SKU', ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:center;">
                            <span class="qty-badge">
                                <?= number_format((float)$item['quantity'], 0) ?> <?= htmlspecialchars($item['unit'] ?? 'pcs', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td style="text-align:right;">
                            $<?= number_format((float)($item['wholesale_price_usd'] ?? 0), 2) ?>
                        </td>
                        <td style="text-align:right;font-weight:600;">
                            $<?= number_format($itemValue, 2) ?>
                        </td>
                        <td style="font-size:0.9rem;color:var(--muted);">
                            <?= $item['loaded_at'] ? date('M j, Y', strtotime($item['loaded_at'])) : 'N/A' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
    document.getElementById('searchInput')?.addEventListener('input', function(e) {
        const search = e.target.value.toLowerCase();
        document.querySelectorAll('#stockTableBody tr').forEach(row => {
            const text = row.dataset.search;
            if (text.includes(search)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
</script>

<?php
warehouse_portal_render_layout_end();
