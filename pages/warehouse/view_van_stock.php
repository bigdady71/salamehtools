<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/warehouse_portal.php';

$user = warehouse_portal_bootstrap();
$pdo = db();

$repId = isset($_GET['rep_id']) ? (int)$_GET['rep_id'] : 0;
$viewMode = trim($_GET['view'] ?? 'stock'); // 'stock' or 'movements'

// Date filters for movements
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$reasonFilter = trim($_GET['reason'] ?? '');

// Get sales rep info
$salesRep = null;
if ($repId > 0) {
    $repStmt = $pdo->prepare("SELECT id, name, phone, email FROM users WHERE id = ? AND role = 'sales_rep'");
    $repStmt->execute([$repId]);
    $salesRep = $repStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$salesRep) {
    header('Location: sales_reps_stocks.php');
    exit;
}

// Get van stock items (using s_stock table)
$stockStmt = $pdo->prepare("
    SELECT
        ss.id,
        ss.qty_on_hand as quantity,
        ss.updated_at as loaded_at,
        p.id as product_id,
        p.sku,
        p.item_name,
        p.unit,
        p.wholesale_price_usd
    FROM s_stock ss
    INNER JOIN products p ON p.id = ss.product_id
    WHERE ss.salesperson_id = ? AND ss.qty_on_hand > 0
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

// Get stock movements with filters
$movementConditions = ['sm.salesperson_id = :rep_id'];
$movementParams = [':rep_id' => $repId];

if ($dateFrom !== '') {
    $movementConditions[] = 'DATE(sm.created_at) >= :date_from';
    $movementParams[':date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $movementConditions[] = 'DATE(sm.created_at) <= :date_to';
    $movementParams[':date_to'] = $dateTo;
}

if ($reasonFilter !== '') {
    $movementConditions[] = 'sm.reason = :reason';
    $movementParams[':reason'] = $reasonFilter;
}

$movementWhereClause = implode(' AND ', $movementConditions);

$movementsStmt = $pdo->prepare("
    SELECT
        sm.id,
        sm.product_id,
        sm.delta_qty,
        sm.reason,
        sm.note,
        sm.order_item_id,
        sm.created_at,
        p.sku,
        p.item_name,
        o.order_number
    FROM s_stock_movements sm
    INNER JOIN products p ON p.id = sm.product_id
    LEFT JOIN order_items oi ON oi.id = sm.order_item_id
    LEFT JOIN orders o ON o.id = oi.order_id
    WHERE $movementWhereClause
    ORDER BY sm.created_at DESC
    LIMIT 500
");
$movementsStmt->execute($movementParams);
$movements = $movementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get movement summary
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_movements,
        COALESCE(SUM(CASE WHEN delta_qty > 0 THEN delta_qty ELSE 0 END), 0) as total_added,
        COALESCE(SUM(CASE WHEN delta_qty < 0 THEN ABS(delta_qty) ELSE 0 END), 0) as total_removed
    FROM s_stock_movements
    WHERE salesperson_id = ?
");
$summaryStmt->execute([$repId]);
$movementSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Get distinct reasons for filter
$reasonsStmt = $pdo->prepare("SELECT DISTINCT reason FROM s_stock_movements WHERE salesperson_id = ? ORDER BY reason");
$reasonsStmt->execute([$repId]);
$reasons = $reasonsStmt->fetchAll(PDO::FETCH_COLUMN);

warehouse_portal_render_layout_start([
    'title' => 'Van Stock - ' . $salesRep['name'],
    'heading' => '📦 Van Stock Details',
    'subtitle' => htmlspecialchars($salesRep['name'], ENT_QUOTES, 'UTF-8') . ($salesRep['phone'] ? ' • ' . htmlspecialchars($salesRep['phone'], ENT_QUOTES, 'UTF-8') : ''),
    'user' => $user,
    'active' => 'sales_reps_stocks',
    'actions' => [
        ['label' => '← Back', 'href' => 'sales_reps_stocks.php', 'class' => 'btn btn-secondary'],
        ['label' => '🚚 Load More Stock', 'href' => 'load_van.php?rep_id=' . $repId, 'class' => 'btn btn-success'],
    ],
]);
?>

<style>
    .view-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
        background: var(--bg);
        padding: 6px;
        border-radius: 12px;
        width: fit-content;
    }
    .view-tab {
        padding: 12px 24px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        color: var(--muted);
        transition: all 0.2s;
    }
    .view-tab:hover {
        background: white;
        color: var(--text);
    }
    .view-tab.active {
        background: var(--primary);
        color: white;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border: 2px solid var(--border);
    }
    .stat-card .value {
        font-size: 1.8rem;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .stat-card .label {
        font-size: 0.85rem;
        color: var(--muted);
        font-weight: 500;
    }
    .stat-card.products .value { color: var(--primary); }
    .stat-card.units .value { color: #059669; }
    .stat-card.value-card .value { color: #f59e0b; }
    .stat-card.added .value { color: #059669; }
    .stat-card.removed .value { color: #dc2626; }
    .card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-bottom: 24px;
    }
    .card-title {
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .filter-bar {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        align-items: center;
    }
    .filter-bar input, .filter-bar select {
        padding: 10px 14px;
        border: 2px solid var(--border);
        border-radius: 8px;
        font-size: 0.95rem;
    }
    .filter-bar input:focus, .filter-bar select:focus {
        outline: none;
        border-color: var(--primary);
    }
    .btn {
        padding: 10px 18px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }
    .btn-primary { background: var(--primary); color: white; }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-sm { padding: 6px 12px; font-size: 0.85rem; }
    .stock-table {
        width: 100%;
        border-collapse: collapse;
    }
    .stock-table th {
        background: var(--bg);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        color: var(--text);
        border-bottom: 2px solid var(--border);
        font-size: 0.9rem;
    }
    .stock-table td {
        padding: 12px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    .stock-table tr:hover {
        background: #f8fafc;
    }
    .product-cell {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .product-image-placeholder {
        width: 45px;
        height: 45px;
        background: #f3f4f6;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
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
        font-size: 0.8rem;
        color: var(--muted);
        font-family: monospace;
        background: #f1f5f9;
        padding: 2px 6px;
        border-radius: 4px;
    }
    .qty-badge {
        display: inline-block;
        padding: 6px 14px;
        background: #d1fae5;
        color: #065f46;
        border-radius: 20px;
        font-weight: 600;
    }
    .movement-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.85rem;
    }
    .movement-badge.positive { background: #d1fae5; color: #065f46; }
    .movement-badge.negative { background: #fee2e2; color: #991b1b; }
    .reason-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 500;
        text-transform: capitalize;
    }
    .reason-badge.restock { background: #dbeafe; color: #1e40af; }
    .reason-badge.sale { background: #fef3c7; color: #92400e; }
    .reason-badge.return { background: #f3e8ff; color: #7c3aed; }
    .reason-badge.adjustment { background: #e0e7ff; color: #4338ca; }
    .reason-badge.initial { background: #d1fae5; color: #065f46; }
    .empty-state {
        text-align: center;
        padding: 50px 20px;
        color: var(--muted);
    }
    .empty-state-icon {
        font-size: 3.5rem;
        margin-bottom: 16px;
        opacity: 0.4;
    }
    .order-link {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
    }
    .order-link:hover {
        text-decoration: underline;
    }
    .date-cell {
        font-size: 0.9rem;
    }
    .date-cell .time {
        color: var(--muted);
        font-size: 0.8rem;
    }
</style>

<!-- View Tabs -->
<div class="view-tabs">
    <a href="?rep_id=<?= $repId ?>&view=stock" class="view-tab <?= $viewMode === 'stock' ? 'active' : '' ?>">
        📦 Current Stock
    </a>
    <a href="?rep_id=<?= $repId ?>&view=movements" class="view-tab <?= $viewMode === 'movements' ? 'active' : '' ?>">
        📊 Transaction History
    </a>
</div>

<?php if ($viewMode === 'stock'): ?>
    <!-- Current Stock View -->
    <div class="stats-grid">
        <div class="stat-card products">
            <div class="value"><?= $totalProducts ?></div>
            <div class="label">Products</div>
        </div>
        <div class="stat-card units">
            <div class="value"><?= number_format($totalQuantity, 0) ?></div>
            <div class="label">Total Units</div>
        </div>
        <div class="stat-card value-card">
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
                <a href="load_van.php?rep_id=<?= $repId ?>" class="btn btn-success" style="margin-top:16px;">
                    🚚 Load Van
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="filter-bar">
                <input type="text" id="searchInput" placeholder="🔍 Search products..." style="min-width: 280px;">
            </div>

            <table class="stock-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:center;">Quantity</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Total Value</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody id="stockTableBody">
                    <?php foreach ($stockItems as $item): ?>
                        <?php $itemValue = (float)$item['quantity'] * (float)($item['wholesale_price_usd'] ?? 0); ?>
                        <tr data-search="<?= strtolower(htmlspecialchars($item['item_name'] . ' ' . ($item['sku'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>">
                            <td>
                                <div class="product-cell">
                                    <div class="product-image-placeholder">📦</div>
                                    <div class="product-info">
                                        <div class="product-name"><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <span class="product-sku"><?= htmlspecialchars($item['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <span class="qty-badge">
                                    <?= number_format((float)$item['quantity'], 0) ?>
                                </span>
                            </td>
                            <td style="text-align:right;">
                                $<?= number_format((float)($item['wholesale_price_usd'] ?? 0), 2) ?>
                            </td>
                            <td style="text-align:right;font-weight:600;">
                                $<?= number_format($itemValue, 2) ?>
                            </td>
                            <td class="date-cell">
                                <?php if ($item['loaded_at']): ?>
                                    <?= date('M j, Y', strtotime($item['loaded_at'])) ?>
                                    <div class="time"><?= date('g:i A', strtotime($item['loaded_at'])) ?></div>
                                <?php else: ?>
                                    <span style="color: var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- Transaction History View -->
    <div class="stats-grid">
        <div class="stat-card products">
            <div class="value"><?= number_format((int)$movementSummary['total_movements']) ?></div>
            <div class="label">Total Transactions</div>
        </div>
        <div class="stat-card added">
            <div class="value">+<?= number_format((float)$movementSummary['total_added'], 0) ?></div>
            <div class="label">Units Added</div>
        </div>
        <div class="stat-card removed">
            <div class="value">-<?= number_format((float)$movementSummary['total_removed'], 0) ?></div>
            <div class="label">Units Removed</div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">🔍 Filter Transactions</div>
        <form method="GET" class="filter-bar">
            <input type="hidden" name="rep_id" value="<?= $repId ?>">
            <input type="hidden" name="view" value="movements">
            
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" placeholder="From Date">
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" placeholder="To Date">
            
            <select name="reason">
                <option value="">All Types</option>
                <?php foreach ($reasons as $reason): ?>
                    <option value="<?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?>" <?= $reasonFilter === $reason ? 'selected' : '' ?>>
                        <?= ucfirst(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="?rep_id=<?= $repId ?>&view=movements" class="btn btn-secondary">Clear</a>
        </form>
    </div>

    <?php if (empty($movements)): ?>
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <h3>No Transactions Found</h3>
                <p>No stock movements match your filters.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-title">📋 Transaction History (<?= count($movements) ?> records)</div>
            
            <table class="stock-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Product</th>
                        <th style="text-align:center;">Change</th>
                        <th>Type</th>
                        <th>Order #</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $movement): ?>
                        <tr>
                            <td class="date-cell">
                                <?= date('M j, Y', strtotime($movement['created_at'])) ?>
                                <div class="time"><?= date('g:i A', strtotime($movement['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="product-info">
                                    <div class="product-name"><?= htmlspecialchars($movement['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <span class="product-sku"><?= htmlspecialchars($movement['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <?php 
                                $delta = (float)$movement['delta_qty'];
                                $isPositive = $delta > 0;
                                ?>
                                <span class="movement-badge <?= $isPositive ? 'positive' : 'negative' ?>">
                                    <?= $isPositive ? '+' : '' ?><?= number_format($delta, 0) ?>
                                </span>
                            </td>
                            <td>
                                <span class="reason-badge <?= htmlspecialchars($movement['reason'] ?? 'other', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= ucfirst(htmlspecialchars($movement['reason'] ?? 'Unknown', ENT_QUOTES, 'UTF-8')) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($movement['order_number']): ?>
                                    <a href="../admin/orders.php?search=<?= urlencode($movement['order_number']) ?>" class="order-link">
                                        <?= htmlspecialchars($movement['order_number'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.9rem; color: var(--muted); max-width: 200px;">
                                <?= $movement['note'] ? htmlspecialchars($movement['note'], ENT_QUOTES, 'UTF-8') : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    document.getElementById('searchInput')?.addEventListener('input', function(e) {
        const search = e.target.value.toLowerCase();
        document.querySelectorAll('#stockTableBody tr').forEach(row => {
            const text = row.dataset.search || '';
            row.style.display = text.includes(search) ? '' : 'none';
        });
    });
</script>

<?php
warehouse_portal_render_layout_end();
