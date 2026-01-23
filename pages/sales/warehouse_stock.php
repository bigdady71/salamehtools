<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'Sales · Warehouse Stock';
$repId = (int)$user['id'];

// Filters
$searchFilter = trim((string)($_GET['search'] ?? ''));
$categoryFilter = (string)($_GET['category'] ?? '');
$stockAlert = (string)($_GET['alert'] ?? 'all');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$whereClauses = ["p.is_active = 1"];
$params = [];

if ($searchFilter !== '') {
    $whereClauses[] = "(p.item_name LIKE :search OR p.sku LIKE :search OR p.second_name LIKE :search)";
    $params[':search'] = '%' . $searchFilter . '%';
}

if ($categoryFilter !== '') {
    $whereClauses[] = "p.topcat_name = :category";
    $params[':category'] = $categoryFilter;
}

switch ($stockAlert) {
    case 'critical':
        $whereClauses[] = "p.quantity_on_hand <= p.safety_stock";
        break;
    case 'low':
        $whereClauses[] = "p.quantity_on_hand > p.safety_stock AND p.quantity_on_hand <= p.reorder_point";
        break;
    case 'ok':
        $whereClauses[] = "p.quantity_on_hand > p.reorder_point";
        break;
    case 'out':
        $whereClauses[] = "p.quantity_on_hand = 0";
        break;
}

$whereSQL = implode(' AND ', $whereClauses);

// Get categories for filter
$categoriesStmt = $pdo->query("
    SELECT DISTINCT topcat_name
    FROM products
    WHERE topcat_name IS NOT NULL AND topcat_name != '' AND is_active = 1
    ORDER BY topcat_name ASC
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSQL}");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);

// Get products
$productsQuery = "
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.second_name,
        p.topcat_name,
        p.topcat,
        p.midcat,
        p.midcat_name,
        p.unit,
        p.sale_price_usd,
        p.wholesale_price_usd,
        p.quantity_on_hand,
        p.safety_stock,
        p.reorder_point,
        p.min_quantity
    FROM products p
    WHERE {$whereSQL}
    ORDER BY p.item_name ASC
    LIMIT :limit OFFSET :offset
";

$productsStmt = $pdo->prepare($productsQuery);
// Bind all the filter params
foreach ($params as $key => $value) {
    $productsStmt->bindValue($key, $value);
}
// Bind LIMIT and OFFSET as integers
$productsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$productsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$productsStmt->execute();
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary stats
$statsQuery = "
    SELECT
        COUNT(*) as total_products,
        SUM(CASE WHEN quantity_on_hand = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity_on_hand > 0 AND quantity_on_hand <= safety_stock THEN 1 ELSE 0 END) as critical,
        SUM(CASE WHEN quantity_on_hand > safety_stock AND quantity_on_hand <= reorder_point THEN 1 ELSE 0 END) as low_stock,
        SUM(quantity_on_hand * sale_price_usd) as total_value
    FROM products p
    WHERE p.is_active = 1
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

sales_portal_render_layout_start([
    'title' => 'مخزون المستودع',
    'heading' => 'توفر مخزون المستودع',
    'subtitle' => 'عرض مخزون المستودع لتخطيط طلبات الشركة (للقراءة فقط)',
    'user' => $user,
    'active' => 'warehouse_stock',
    'extra_head' => '<link rel="stylesheet" href="../../css/sales-common.css">
    <style>
        .warehouse-table {
            width: 100%;
            border-collapse: collapse;
        }
        .warehouse-table thead {
            background: #f9fafb;
        }
        .warehouse-table th {
            padding: 14px 16px;
            text-align: center;
            font-weight: 600;
            font-size: 0.875rem;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #e5e7eb;
            background: #f3f4f6;
        }
        .warehouse-table td {
            padding: 12px 16px;
            text-align: center;
            font-size: 0.9rem;
            border: 1px solid #e5e7eb;
            color: #111827;
        }
        .warehouse-table tbody tr:nth-child(odd) {
            background: #ffffff;
        }
        .warehouse-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        .warehouse-table tbody tr:hover {
            background: #eff6ff;
            transition: background-color 0.2s ease;
        }
        .product-name-cell {
            text-align: left !important;
        }
    </style>'
]);
?>

<div class="page-header">
    <div class="page-title">
        <h1>📦 Warehouse Stock Availability</h1>
        <p class="subtitle">View warehouse inventory for company order planning (Read-only)</p>
    </div>
</div>

<!-- Info Alert -->
<div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
    <div style="display: flex; gap: 12px; align-items: start;">
        <span style="font-size: 1.5rem;">ℹ️</span>
        <div>
            <div style="font-weight: 600; color: #1e40af; margin-bottom: 4px;">Warehouse Stock Reference</div>
            <div style="font-size: 0.9rem; color: #3b82f6;">
                This page shows <strong>warehouse inventory</strong> for planning company orders.
                For van stock sales, use your <a href="van_stock.php" style="color: #1d4ed8; text-decoration: underline;">Van Stock</a> inventory.
            </div>
        </div>
    </div>
</div>

<!-- Summary Stats -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-label">Total Products</div>
        <div class="stat-value"><?= number_format((int)$stats['total_products']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Critical Stock</div>
        <div class="stat-value" style="color: #dc2626;"><?= number_format((int)$stats['critical']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Low Stock</div>
        <div class="stat-value" style="color: #f59e0b;"><?= number_format((int)$stats['low_stock']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Out of Stock</div>
        <div class="stat-value" style="color: #6b7280;"><?= number_format((int)$stats['out_of_stock']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Inventory Value</div>
        <div class="stat-value">$<?= number_format((float)$stats['total_value'], 2) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <form method="GET" action="">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
            <div>
                <label>Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Product name, SKU..." class="form-control">
            </div>
            <div>
                <label>Category</label>
                <select name="category" class="form-control">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $categoryFilter === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Stock Level</label>
                <select name="alert" class="form-control">
                    <option value="all" <?= $stockAlert === 'all' ? 'selected' : '' ?>>All Levels</option>
                    <option value="out" <?= $stockAlert === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                    <option value="critical" <?= $stockAlert === 'critical' ? 'selected' : '' ?>>Critical (≤ Safety Stock)</option>
                    <option value="low" <?= $stockAlert === 'low' ? 'selected' : '' ?>>Low (≤ Reorder Point)</option>
                    <option value="ok" <?= $stockAlert === 'ok' ? 'selected' : '' ?>>OK Stock</option>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-info">🔍 Apply Filters</button>
            <a href="?" class="btn btn-secondary">✕ Clear</a>
        </div>
    </form>
</div>

<!-- Products Table -->
<?php if (!empty($products)): ?>
<div class="table-container" style="background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow-x: auto;">
    <table class="warehouse-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Unit</th>
                <th>Sale Price</th>
                <th>Wholesale</th>
                <th>Warehouse Qty</th>
                <th>Safety Stock</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $product):
                $qty = (float)$product['quantity_on_hand'];
                $safetyStock = (float)($product['safety_stock'] ?? 0);
                $reorderPoint = (float)($product['reorder_point'] ?? 0);

                if ($qty == 0) {
                    $status = 'Out of Stock';
                    $statusColor = 'background: rgba(107, 114, 128, 0.1); color: #6b7280;';
                } elseif ($qty <= $safetyStock) {
                    $status = 'Critical';
                    $statusColor = 'background: rgba(220, 38, 38, 0.1); color: #dc2626;';
                } elseif ($qty <= $reorderPoint) {
                    $status = 'Low Stock';
                    $statusColor = 'background: rgba(245, 158, 11, 0.1); color: #f59e0b;';
                } else {
                    $status = 'In Stock';
                    $statusColor = 'background: rgba(16, 185, 129, 0.1); color: #10b981;';
                }
            ?>
            <tr>
                <td class="product-name-cell">
                    <div style="font-weight: 500;"><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($product['second_name']): ?>
                        <div style="font-size: 0.85rem; color: #6b7280;"><?= htmlspecialchars($product['second_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td><code style="font-size: 0.9rem; background: #f3f4f6; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td>
                    <?php if ($product['topcat_name']): ?>
                        <span style="font-size: 0.85rem; background: #f3f4f6; padding: 3px 8px; border-radius: 4px;">
                            <?= htmlspecialchars($product['topcat_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($product['unit'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td>$<?= number_format((float)$product['sale_price_usd'], 2) ?></td>
                <td>$<?= number_format((float)$product['wholesale_price_usd'], 2) ?></td>
                <td>
                    <strong style="font-size: 1.05rem;"><?= number_format($qty, 1) ?></strong>
                </td>
                <td style="color: #6b7280; font-size: 0.9rem;"><?= number_format($safetyStock, 1) ?></td>
                <td>
                    <span class="badge" style="<?= $statusColor ?>"><?= $status ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 24px;">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?><?= $searchFilter ? '&search=' . urlencode($searchFilter) : '' ?><?= $categoryFilter ? '&category=' . urlencode($categoryFilter) : '' ?><?= $stockAlert !== 'all' ? '&alert=' . urlencode($stockAlert) : '' ?>" class="btn">← Previous</a>
    <?php endif; ?>

    <span>Page <?= $page ?> of <?= $totalPages ?></span>

    <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?><?= $searchFilter ? '&search=' . urlencode($searchFilter) : '' ?><?= $categoryFilter ? '&category=' . urlencode($categoryFilter) : '' ?><?= $stockAlert !== 'all' ? '&alert=' . urlencode($stockAlert) : '' ?>" class="btn">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="margin-top: 16px; color: #6b7280; font-size: 0.9rem; text-align: center;">
    Showing <?= count($products) ?> of <?= number_format($totalProducts) ?> products
</div>

<?php else: ?>
<div class="empty-state" style="background: white; border-radius: 8px; padding: 48px; text-align: center; border: 1px solid #e5e7eb;">
    <div style="font-size: 3rem; margin-bottom: 16px;">📦</div>
    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 8px;">No Products Found</h3>
    <p style="color: #6b7280;">Try adjusting your filters to see more products.</p>
</div>
<?php endif; ?>

<?php
sales_portal_render_layout_end();
?>
