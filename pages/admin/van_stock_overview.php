<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';

// Admin authentication
require_login();
$user = auth_user();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'accountant'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Sales Rep Van Stocks';

// Filters
$repFilter = (int)($_GET['rep_id'] ?? 0);
$searchFilter = trim((string)($_GET['search'] ?? ''));
$stockAlert = (string)($_GET['alert'] ?? 'all');
$viewMode = (string)($_GET['view'] ?? 'stock'); // 'stock' or 'movements'
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

// Get all sales reps for filter dropdown
$repsStmt = $pdo->query("
    SELECT id, name, email
    FROM users
    WHERE role = 'sales_rep' AND is_active = 1
    ORDER BY name ASC
");
$salesReps = $repsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for van stocks
$whereClauses = ["u.role = 'sales_rep'"];
$params = [];

if ($repFilter > 0) {
    $whereClauses[] = "s.salesperson_id = :rep_id";
    $params[':rep_id'] = $repFilter;
}

if ($searchFilter !== '') {
    $whereClauses[] = "(p.item_name LIKE :search OR p.sku LIKE :search OR u.name LIKE :search)";
    $params[':search'] = '%' . $searchFilter . '%';
}

switch ($stockAlert) {
    case 'out':
        $whereClauses[] = "s.qty_on_hand = 0";
        break;
    case 'low':
        $whereClauses[] = "s.qty_on_hand > 0 AND s.qty_on_hand <= 5";
        break;
    case 'ok':
        $whereClauses[] = "s.qty_on_hand > 5";
        break;
}

$whereSQL = implode(' AND ', $whereClauses);

// Get van stock data with rep info
$stockQuery = "
    SELECT
        u.id AS rep_id,
        u.name AS rep_name,
        u.email AS rep_email,
        p.id AS product_id,
        p.sku,
        p.item_name,
        p.topcat AS category,
        p.wholesale_price_usd,
        s.qty_on_hand,
        (s.qty_on_hand * p.wholesale_price_usd) AS stock_value,
        s.updated_at
    FROM s_stock s
    INNER JOIN products p ON p.id = s.product_id
    INNER JOIN users u ON u.id = s.salesperson_id
    WHERE {$whereSQL}
    ORDER BY u.name ASC, p.item_name ASC
    LIMIT 500
";

$stockStmt = $pdo->prepare($stockQuery);
$stockStmt->execute($params);
$stocks = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats by rep
$repStats = [];
foreach ($stocks as $stock) {
    $repId = (int)$stock['rep_id'];
    if (!isset($repStats[$repId])) {
        $repStats[$repId] = [
            'name' => $stock['rep_name'],
            'email' => $stock['rep_email'],
            'total_skus' => 0,
            'total_units' => 0,
            'total_value' => 0,
            'out_of_stock' => 0,
            'low_stock' => 0,
        ];
    }

    $repStats[$repId]['total_skus']++;
    $repStats[$repId]['total_units'] += (float)$stock['qty_on_hand'];
    $repStats[$repId]['total_value'] += (float)$stock['stock_value'];

    if ((float)$stock['qty_on_hand'] == 0) {
        $repStats[$repId]['out_of_stock']++;
    } elseif ((float)$stock['qty_on_hand'] <= 5) {
        $repStats[$repId]['low_stock']++;
    }
}

// Overall summary
$totalReps = count($repStats);
$totalSkus = array_sum(array_column($repStats, 'total_skus'));
$totalUnits = array_sum(array_column($repStats, 'total_units'));
$totalValue = array_sum(array_column($repStats, 'total_value'));

// Get stock movements if in movements view
$movements = [];
$movementStats = [];
if ($viewMode === 'movements') {
    $movementWhere = ['1=1'];
    $movementParams = [];

    if ($repFilter > 0) {
        $movementWhere[] = "m.salesperson_id = :rep_id";
        $movementParams[':rep_id'] = $repFilter;
    }

    if ($searchFilter !== '') {
        $movementWhere[] = "(p.item_name LIKE :search OR p.sku LIKE :search OR u.name LIKE :search)";
        $movementParams[':search'] = '%' . $searchFilter . '%';
    }

    if ($dateFrom !== '') {
        $movementWhere[] = "DATE(m.created_at) >= :date_from";
        $movementParams[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $movementWhere[] = "DATE(m.created_at) <= :date_to";
        $movementParams[':date_to'] = $dateTo;
    }

    $movementWhereSQL = implode(' AND ', $movementWhere);

    $movementsQuery = "
        SELECT
            m.id,
            m.salesperson_id,
            m.product_id,
            m.delta_qty,
            m.reason,
            m.order_item_id,
            m.note,
            m.created_at,
            u.name AS rep_name,
            u.email AS rep_email,
            p.item_name,
            p.sku,
            p.topcat AS category,
            o.order_number
        FROM s_stock_movements m
        INNER JOIN users u ON u.id = m.salesperson_id
        INNER JOIN products p ON p.id = m.product_id
        LEFT JOIN order_items oi ON oi.id = m.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        WHERE {$movementWhereSQL}
        ORDER BY m.created_at DESC
        LIMIT 500
    ";

    $movementsStmt = $pdo->prepare($movementsQuery);
    $movementsStmt->execute($movementParams);
    $movements = $movementsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate movement stats
    $totalAdded = 0;
    $totalRemoved = 0;
    $totalMovements = count($movements);

    foreach ($movements as $move) {
        $delta = (float)$move['delta_qty'];
        if ($delta > 0) {
            $totalAdded += $delta;
        } else {
            $totalRemoved += abs($delta);
        }
    }

    $movementStats = [
        'total_movements' => $totalMovements,
        'total_added' => $totalAdded,
        'total_removed' => $totalRemoved,
    ];
}

$extraHead = <<<'CSS'
<style>
.stat-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
}
.stat-label {
    color: #6b7280;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 8px;
}
.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
}
.data-table th {
    background: #f9fafb;
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6b7280;
    border-bottom: 1px solid #e5e7eb;
}
.data-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 0.9rem;
}
.data-table tbody tr:hover {
    background: #f9fafb;
}
.data-table .text-right {
    text-align: right;
}
.data-table .text-center {
    text-align: center;
}
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    transition: border-color 0.2s;
}
.form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
</style>
CSS;

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Sales Rep Van Stock Overview',
    'subtitle' => 'Monitor field inventory across all sales representatives',
    'user' => $user,
    'active' => 'van_stock',
    'extra_head' => $extraHead,
]);

?>

<!-- View Mode Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0;">
    <a href="?view=stock<?= $repFilter ? '&rep_id=' . $repFilter : '' ?><?= $searchFilter ? '&search=' . urlencode($searchFilter) : '' ?>"
       class="btn <?= $viewMode === 'stock' ? 'btn-primary' : '' ?>"
       style="border-radius: 8px 8px 0 0; <?= $viewMode === 'stock' ? 'border-bottom: 2px solid #3b82f6; margin-bottom: -2px;' : '' ?>">
        📦 Current Stock
    </a>
    <a href="?view=movements<?= $repFilter ? '&rep_id=' . $repFilter : '' ?><?= $searchFilter ? '&search=' . urlencode($searchFilter) : '' ?>"
       class="btn <?= $viewMode === 'movements' ? 'btn-primary' : '' ?>"
       style="border-radius: 8px 8px 0 0; <?= $viewMode === 'movements' ? 'border-bottom: 2px solid #3b82f6; margin-bottom: -2px;' : '' ?>">
        📊 Stock Movements
    </a>
</div>

<?php if ($viewMode === 'stock'): ?>
<!-- Overall Summary Cards -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-label">Active Sales Reps</div>
        <div class="stat-value"><?= $totalReps ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total SKUs Stocked</div>
        <div class="stat-value"><?= number_format($totalSkus) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Units</div>
        <div class="stat-value"><?= number_format($totalUnits, 1) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Stock Value</div>
        <div class="stat-value">$<?= number_format($totalValue, 2) ?></div>
    </div>
</div>

<!-- Rep Summary Cards -->
<?php if (!empty($repStats)): ?>
<div style="margin-bottom: 32px;">
    <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 16px;">Sales Rep Summary</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
        <?php foreach ($repStats as $repId => $stats): ?>
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
            <div style="font-weight: 600; font-size: 1.05rem; margin-bottom: 4px;"><?= htmlspecialchars($stats['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div style="font-size: 0.85rem; color: #6b7280; margin-bottom: 12px;"><?= htmlspecialchars($stats['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.9rem;">
                <div>
                    <div style="color: #6b7280;">SKUs:</div>
                    <div style="font-weight: 600;"><?= $stats['total_skus'] ?></div>
                </div>
                <div>
                    <div style="color: #6b7280;">Units:</div>
                    <div style="font-weight: 600;"><?= number_format($stats['total_units'], 1) ?></div>
                </div>
                <div>
                    <div style="color: #6b7280;">Value:</div>
                    <div style="font-weight: 600;">$<?= number_format($stats['total_value'], 2) ?></div>
                </div>
                <div>
                    <div style="color: #6b7280;">Alerts:</div>
                    <div>
                        <?php if ($stats['out_of_stock'] > 0): ?>
                            <span style="color: #dc2626; font-size: 0.85rem;">🔴 <?= $stats['out_of_stock'] ?> out</span>
                        <?php endif; ?>
                        <?php if ($stats['low_stock'] > 0): ?>
                            <span style="color: #f59e0b; font-size: 0.85rem;">⚠️ <?= $stats['low_stock'] ?> low</span>
                        <?php endif; ?>
                        <?php if ($stats['out_of_stock'] == 0 && $stats['low_stock'] == 0): ?>
                            <span style="color: #10b981; font-size: 0.85rem;">✓ OK</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 12px;">
                <a href="?rep_id=<?= $repId ?>" class="btn" style="flex: 1; text-align: center;">View Stock</a>
                <a href="?view=movements&rep_id=<?= $repId ?>" class="btn btn-secondary" style="flex: 1; text-align: center;">Movements</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="filters" style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #e5e7eb;">
    <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Sales Rep</label>
            <select name="rep_id" class="form-control">
                <option value="0">All Sales Reps</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= $rep['id'] ?>" <?= $repFilter === (int)$rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Search Product/SKU</label>
            <input type="text" name="search" value="<?= htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Product name, SKU..." class="form-control">
        </div>
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Stock Alert</label>
            <select name="alert" class="form-control">
                <option value="all" <?= $stockAlert === 'all' ? 'selected' : '' ?>>All Stock Levels</option>
                <option value="out" <?= $stockAlert === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                <option value="low" <?= $stockAlert === 'low' ? 'selected' : '' ?>>Low Stock (≤5)</option>
                <option value="ok" <?= $stockAlert === 'ok' ? 'selected' : '' ?>>OK Stock (>5)</option>
            </select>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="?" class="btn">Clear</a>
        </div>
    </form>
</div>

<!-- Stock Details Table -->
<?php if (!empty($stocks)): ?>
<div class="table-container" style="background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Sales Rep</th>
                <th>Product</th>
                <th>SKU</th>
                <th>Category</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Qty on Hand</th>
                <th class="text-right">Stock Value</th>
                <th class="text-center">Status</th>
                <th class="text-center">Last Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stocks as $stock):
                $qty = (float)$stock['qty_on_hand'];
                $statusBadge = '';
                $statusColor = '';

                if ($qty == 0) {
                    $statusBadge = 'Out of Stock';
                    $statusColor = 'background: rgba(220, 38, 38, 0.1); color: #dc2626;';
                } elseif ($qty <= 5) {
                    $statusBadge = 'Low Stock';
                    $statusColor = 'background: rgba(245, 158, 11, 0.1); color: #f59e0b;';
                } else {
                    $statusBadge = 'In Stock';
                    $statusColor = 'background: rgba(16, 185, 129, 0.1); color: #10b981;';
                }
            ?>
            <tr>
                <td>
                    <div style="font-weight: 500;"><?= htmlspecialchars($stock['rep_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size: 0.85rem; color: #6b7280;"><?= htmlspecialchars($stock['rep_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><?= htmlspecialchars($stock['item_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><code><?= htmlspecialchars($stock['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><?= htmlspecialchars($stock['category'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-right">$<?= number_format((float)$stock['wholesale_price_usd'], 2) ?></td>
                <td class="text-right"><strong><?= number_format($qty, 1) ?></strong></td>
                <td class="text-right">$<?= number_format((float)$stock['stock_value'], 2) ?></td>
                <td class="text-center">
                    <span class="badge" style="<?= $statusColor ?>"><?= $statusBadge ?></span>
                </td>
                <td class="text-center" style="font-size: 0.85rem; color: #6b7280;">
                    <?= $stock['updated_at'] ? date('M d, Y', strtotime($stock['updated_at'])) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div style="margin-top: 16px; color: #6b7280; font-size: 0.9rem;">
    Showing <?= count($stocks) ?> stock items
</div>

<?php else: ?>
<div class="empty-state" style="background: white; border-radius: 8px; padding: 48px; text-align: center; border: 1px solid #e5e7eb;">
    <div style="font-size: 3rem; margin-bottom: 16px;">📦</div>
    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 8px;">No Stock Data Found</h3>
    <p style="color: #6b7280;">No sales reps have van stock matching your filters.</p>
</div>
<?php endif; ?>

<?php else: // movements view ?>

<!-- Movement Summary Cards -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-label">Total Movements</div>
        <div class="stat-value"><?= number_format($movementStats['total_movements'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Units Added</div>
        <div class="stat-value" style="color: #10b981;">+<?= number_format($movementStats['total_added'] ?? 0, 1) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Units Removed</div>
        <div class="stat-value" style="color: #ef4444;">-<?= number_format($movementStats['total_removed'] ?? 0, 1) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Net Change</div>
        <?php $netChange = ($movementStats['total_added'] ?? 0) - ($movementStats['total_removed'] ?? 0); ?>
        <div class="stat-value" style="color: <?= $netChange >= 0 ? '#10b981' : '#ef4444' ?>;">
            <?= $netChange >= 0 ? '+' : '' ?><?= number_format($netChange, 1) ?>
        </div>
    </div>
</div>

<!-- Movements Filters -->
<div class="filters" style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #e5e7eb;">
    <form method="GET" action="" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; align-items: end;">
        <input type="hidden" name="view" value="movements">
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Sales Rep</label>
            <select name="rep_id" class="form-control">
                <option value="0">All Sales Reps</option>
                <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= $rep['id'] ?>" <?= $repFilter === (int)$rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">Search Product/SKU</label>
            <input type="text" name="search" value="<?= htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Product name, SKU..." class="form-control">
        </div>
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">From Date</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
        </div>
        <div>
            <label style="display: block; font-weight: 500; margin-bottom: 6px;">To Date</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="?view=movements" class="btn">Clear</a>
        </div>
    </form>
</div>

<!-- Movements Table -->
<?php if (!empty($movements)): ?>
<div class="table-container" style="background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Sales Rep</th>
                <th>Product</th>
                <th>SKU</th>
                <th class="text-right">Qty Change</th>
                <th>Reason</th>
                <th>Order #</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($movements as $move):
                $delta = (float)$move['delta_qty'];
                $isAdd = $delta > 0;
                $deltaColor = $isAdd ? '#10b981' : '#ef4444';
                $deltaPrefix = $isAdd ? '+' : '';
                
                // Format reason nicely
                $reasonLabels = [
                    'sale' => 'Sale/Order',
                    'restock' => 'Restock',
                    'van_load' => 'Van Loading',
                    'return' => 'Return',
                    'customer_return' => 'Customer Return',
                    'adjustment' => 'Manual Adjustment',
                    'transfer' => 'Transfer',
                    'initial' => 'Initial Stock',
                ];
                $reasonLabel = $reasonLabels[$move['reason']] ?? ucfirst(str_replace('_', ' ', $move['reason'] ?? 'Unknown'));
                
                // Reason badge colors
                $reasonColors = [
                    'sale' => 'background: rgba(239, 68, 68, 0.1); color: #dc2626;',
                    'restock' => 'background: rgba(16, 185, 129, 0.1); color: #059669;',
                    'van_load' => 'background: rgba(59, 130, 246, 0.1); color: #2563eb;',
                    'return' => 'background: rgba(251, 191, 36, 0.1); color: #d97706;',
                    'customer_return' => 'background: rgba(16, 185, 129, 0.1); color: #059669;',
                    'adjustment' => 'background: rgba(156, 163, 175, 0.1); color: #6b7280;',
                    'transfer' => 'background: rgba(139, 92, 246, 0.1); color: #7c3aed;',
                    'initial' => 'background: rgba(59, 130, 246, 0.1); color: #2563eb;',
                ];
                $reasonStyle = $reasonColors[$move['reason']] ?? 'background: rgba(156, 163, 175, 0.1); color: #6b7280;';
            ?>
            <tr>
                <td style="white-space: nowrap;">
                    <div style="font-weight: 500;"><?= date('M d, Y', strtotime($move['created_at'])) ?></div>
                    <div style="font-size: 0.85rem; color: #6b7280;"><?= date('H:i', strtotime($move['created_at'])) ?></div>
                </td>
                <td>
                    <div style="font-weight: 500;"><?= htmlspecialchars($move['rep_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><?= htmlspecialchars($move['item_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><code style="background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 0.85rem;"><?= htmlspecialchars($move['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                <td class="text-right">
                    <span style="color: <?= $deltaColor ?>; font-weight: 600; font-size: 1.05rem;">
                        <?= $deltaPrefix ?><?= number_format($delta, 1) ?>
                    </span>
                </td>
                <td>
                    <span class="badge" style="<?= $reasonStyle ?>"><?= htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td>
                    <?php if ($move['order_number']): ?>
                        <a href="orders.php?search=<?= urlencode($move['order_number']) ?>" style="color: #3b82f6; text-decoration: none;">
                            <?= htmlspecialchars($move['order_number'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php else: ?>
                        <span style="color: #9ca3af;">—</span>
                    <?php endif; ?>
                </td>
                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($move['note'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?= $move['note'] ? htmlspecialchars($move['note'], ENT_QUOTES, 'UTF-8') : '<span style="color: #9ca3af;">—</span>' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div style="margin-top: 16px; color: #6b7280; font-size: 0.9rem;">
    Showing <?= count($movements) ?> stock movements (most recent first)
</div>

<?php else: ?>
<div class="empty-state" style="background: white; border-radius: 8px; padding: 48px; text-align: center; border: 1px solid #e5e7eb;">
    <div style="font-size: 3rem; margin-bottom: 16px;">📊</div>
    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 8px;">No Stock Movements Found</h3>
    <p style="color: #6b7280;">No stock movements match your filters. Try adjusting your search criteria.</p>
</div>
<?php endif; ?>

<?php endif; // end of view mode check ?>

<?php
admin_render_layout_end();
?>
