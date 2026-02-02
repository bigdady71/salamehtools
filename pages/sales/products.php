<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/admin_page.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'Sales · Product Catalog';

// Pagination & filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';

// Include product filters helper
require_once __DIR__ . '/../../includes/product_filters.php';

// Get product filter settings from database
$filterSettings = [];
try {
    $filterStmt = $pdo->query("SELECT k, v FROM settings WHERE k LIKE 'product_filter.%'");
    while ($row = $filterStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = str_replace('product_filter.', '', $row['k']);
        $filterSettings[$key] = $row['v'];
    }
} catch (PDOException $e) {
    // Settings table might not exist yet - use defaults
}

// Check if filters should apply to sales reps
$applyFilters = should_apply_product_filters($pdo, 'sales_rep');

// Build WHERE conditions
$where = ['p.is_active = 1']; // Only show active products to sales reps
$params = [];

// Apply admin-configured product filters only if they apply to sales reps
if ($applyFilters) {
    if (!empty($filterSettings['hide_zero_stock']) && $filterSettings['hide_zero_stock'] === '1') {
        $where[] = 'p.quantity_on_hand > 0';
    }
    if (!empty($filterSettings['hide_zero_retail_price']) && $filterSettings['hide_zero_retail_price'] === '1') {
        $where[] = 'p.sale_price_usd > 0';
    }
    if (!empty($filterSettings['hide_zero_wholesale_price']) && $filterSettings['hide_zero_wholesale_price'] === '1') {
        $where[] = 'p.wholesale_price_usd > 0';
    }
    if (!empty($filterSettings['hide_same_prices']) && $filterSettings['hide_same_prices'] === '1') {
        $where[] = 'ABS(p.sale_price_usd - p.wholesale_price_usd) > 0.001';
    }
    if (!empty($filterSettings['hide_zero_stock_and_price']) && $filterSettings['hide_zero_stock_and_price'] === '1') {
        $where[] = 'NOT (p.quantity_on_hand <= 0 AND p.wholesale_price_usd <= 0)';
    }
    $minQtyThreshold = (int)($filterSettings['min_quantity_threshold'] ?? 0);
    if ($minQtyThreshold > 0) {
        $where[] = 'p.quantity_on_hand >= ' . $minQtyThreshold;
    }
}

if ($search !== '') {
    $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR p.second_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if ($category !== '') {
    $where[] = "(p.topcat_name = :category OR p.midcat_name = :category)";
    $params[':category'] = $category;
}

if ($stockFilter === 'in_stock') {
    $where[] = "p.quantity_on_hand > 0";
} elseif ($stockFilter === 'low') {
    $where[] = "p.quantity_on_hand > 0 AND p.quantity_on_hand <= GREATEST(p.min_quantity, 5)";
} elseif ($stockFilter === 'out') {
    $where[] = "p.quantity_on_hand = 0";
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereClause}");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Get products with margin calculation
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.second_name,
        p.unit,
        p.topcat_name,
        p.midcat_name,
        p.sale_price_usd,
        p.wholesale_price_usd,
        p.cost_price_usd,
        p.min_quantity,
        p.quantity_on_hand,
        p.is_active,
        CASE
            WHEN p.cost_price_usd > 0 AND p.sale_price_usd > 0
            THEN ((p.sale_price_usd - p.cost_price_usd) / p.sale_price_usd) * 100
            ELSE NULL
        END as margin_percent,
        CASE
            WHEN p.cost_price_usd > 0 AND p.wholesale_price_usd > 0
            THEN ((p.wholesale_price_usd - p.cost_price_usd) / p.wholesale_price_usd) * 100
            ELSE NULL
        END as wholesale_margin_percent
    FROM products p
    WHERE {$whereClause}
    ORDER BY p.sku ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$catStmt = $pdo->query("
    SELECT DISTINCT topcat_name AS category
    FROM products
    WHERE topcat_name IS NOT NULL AND topcat_name != '' AND is_active = 1
    UNION
    SELECT DISTINCT midcat_name
    FROM products
    WHERE midcat_name IS NOT NULL AND midcat_name != '' AND is_active = 1
    ORDER BY category
");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Summary stats
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN quantity_on_hand > 0 THEN 1 ELSE 0 END) as in_stock,
        SUM(CASE WHEN quantity_on_hand = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN quantity_on_hand > 0 AND quantity_on_hand <= GREATEST(min_quantity, 5) THEN 1 ELSE 0 END) as low_stock
    FROM products
    WHERE is_active = 1
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$extraHead = <<<'HTML'
<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
}
.stat-label {
    color: var(--muted);
    font-size: 0.85rem;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--text);
}
.stat-value.green { color: #15803d; }
.stat-value.orange { color: #ea580c; }
.stat-value.red { color: #dc2626; }
.filters-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 24px;
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}
.filter-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text);
}
.filter-field input,
.filter-field select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
    transition: border-color 0.2s;
}
.filter-field input:focus,
.filter-field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}
.filter-actions {
    display: flex;
    gap: 10px;
}
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}
.product-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    transition: all 0.2s;
    position: relative;
}
.product-card:hover {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}
.product-sku {
    font-size: 0.85rem;
    color: var(--muted);
    font-weight: 600;
    margin-bottom: 8px;
}
.product-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
}
.product-second-name {
    font-size: 0.9rem;
    color: var(--muted);
    margin-bottom: 12px;
}
.product-category {
    display: inline-block;
    padding: 4px 10px;
    background: rgba(14, 165, 233, 0.1);
    color: var(--accent);
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 12px;
}
.product-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
.product-detail {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.product-detail-label {
    font-size: 0.75rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.product-detail-value {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
}
.product-detail-value.price {
    color: var(--accent);
    font-size: 1.2rem;
}
.stock-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    gap: 6px;
}
.stock-badge.in-stock {
    background: rgba(34, 197, 94, 0.15);
    color: #15803d;
}
.stock-badge.low-stock {
    background: rgba(251, 191, 36, 0.15);
    color: #b45309;
}
.stock-badge.out-of-stock {
    background: rgba(239, 68, 68, 0.15);
    color: #991b1b;
}
.stock-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.stock-indicator.green {
    background: #15803d;
}
.stock-indicator.orange {
    background: #b45309;
}
.stock-indicator.red {
    background: #991b1b;
}
.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--muted);
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 14px;
}
.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.3;
}
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 32px;
}
.pagination a,
.pagination span {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    text-decoration: none;
    color: var(--text);
    background: var(--bg-panel);
}
.pagination a:hover {
    background: var(--bg-panel-alt);
    border-color: var(--accent);
}
.pagination span.current {
    background: var(--accent);
    color: #fff;
    font-weight: 600;
}
</style>
HTML;

sales_portal_render_layout_start([
    'title' => 'كتالوج المنتجات',
    'heading' => 'كتالوج المنتجات',
    'subtitle' => 'تصفح المنتجات النشطة مع الأسعار والتوفر',
    'user' => $user,
    'active' => 'products',
    'extra_head' => $extraHead,
]);
?>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Products</div>
        <div class="stat-value"><?= number_format((int)($stats['total'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">In Stock</div>
        <div class="stat-value green"><?= number_format((int)($stats['in_stock'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Low Stock</div>
        <div class="stat-value orange"><?= number_format((int)($stats['low_stock'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Out of Stock</div>
        <div class="stat-value red"><?= number_format((int)($stats['out_of_stock'] ?? 0)) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filters-card">
    <form method="GET" action="products.php">
        <div class="filter-grid">
            <div class="filter-field">
                <label>Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="SKU or product name...">
            </div>

            <div class="filter-field">
                <label>Category</label>
                <select name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $category === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label>Stock Status</label>
                <select name="stock">
                    <option value="">All Stock Levels</option>
                    <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-info">Apply Filters</button>
            <a href="products.php" class="btn btn-secondary">Clear</a>
        </div>
    </form>
</div>

<!-- Products Grid -->
<?php if (empty($products)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📦</div>
        <h3 style="margin: 0 0 8px 0;">No products found</h3>
        <p style="margin: 0; color: var(--muted);">
            <?= $search || $category || $stockFilter
                ? 'Try adjusting your filters to see more products'
                : 'No active products available at this time' ?>
        </p>
    </div>
<?php else: ?>
    <div style="margin-bottom: 16px; color: var(--muted);">
        Showing <?= number_format(count($products)) ?> of <?= number_format($totalProducts) ?> products
    </div>

    <div class="products-grid">
        <?php foreach ($products as $product):
            $qtyOnHand = (float)$product['quantity_on_hand'];
            $minQty = max((float)$product['min_quantity'], 5);
            $isLowStock = $qtyOnHand > 0 && $qtyOnHand <= $minQty;
            $isOutOfStock = $qtyOnHand <= 0;

            // Product image path (based on SKU) - check multiple formats
            $imageExists = false;
            $imagePath = '../../images/products/default.jpg';
            $defaultImagePath = __DIR__ . '/../../images/products/default.jpg';
            $imageVersion = file_exists($defaultImagePath) ? (string)filemtime($defaultImagePath) : null;
            $possibleExtensions = ['webp', 'jpg', 'jpeg', 'png', 'gif'];
            $productSku = $product['sku'] ?? 'default';

            foreach ($possibleExtensions as $ext) {
                $serverPath = __DIR__ . '/../../images/products/' . $productSku . '.' . $ext;
                if (file_exists($serverPath)) {
                    $imagePath = '../../images/products/' . $productSku . '.' . $ext;
                    $imageVersion = (string)filemtime($serverPath);
                    $imageExists = true;
                    break;
                }
            }

            if (!$imageExists) {
                $imagePath = '../../images/products/default.jpg';
                $imageExists = true;
            }
            $imageSrc = $imagePath . ($imageVersion ? '?v=' . rawurlencode($imageVersion) : '');
            $fallbackSrc = '../../images/products/default.jpg' . ($imageVersion ? '?v=' . rawurlencode($imageVersion) : '');
        ?>
            <div class="product-card">
                <!-- Product Image -->
                <div
                    style="width: 100%; height: 220px; display: flex; align-items: center; justify-content: center; background: #ffffff; border-radius: 8px; margin-bottom: 16px; overflow: hidden;">
                    <?php if ($imageExists): ?>
                        <img src="<?= htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8') ?>"
                            alt="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                            style="max-width: 100%; max-height: 100%; object-fit: contain;" class="lazy-image" loading="lazy"
                            onload="this.classList.add('is-loaded')"
                            onerror="this.src='<?= htmlspecialchars($fallbackSrc, ENT_QUOTES, 'UTF-8') ?>'">
                    <?php else: ?>
                        <div style="font-size: 3rem; opacity: 0.3;">📦</div>
                    <?php endif; ?>
                </div>

                <div class="product-sku"><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></div>

                <div class="product-name"><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?></div>

                <?php if ($product['second_name']): ?>
                    <div class="product-second-name"><?= htmlspecialchars($product['second_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($product['topcat_name']): ?>
                    <div class="product-category"><?= htmlspecialchars($product['topcat_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <div class="product-details">
                    <!-- Pricing Tiers -->
                    <div style="background: rgba(59, 130, 246, 0.05); border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                        <div
                            style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #1d4ed8; margin-bottom: 8px;">
                            💰 Pricing</div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div>
                                <div style="font-size: 0.75rem; color: var(--muted);">Retail</div>
                                <div style="font-size: 1.1rem; font-weight: 600; color: #15803d;">
                                    $<?= number_format((float)$product['sale_price_usd'], 2) ?>
                                </div>
                                <?php if ($product['margin_percent'] !== null): ?>
                                    <div
                                        style="font-size: 0.75rem; color: <?= $product['margin_percent'] < 20 ? '#dc2626' : '#15803d' ?>;">
                                        <?= number_format((float)$product['margin_percent'], 1) ?>% margin
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; color: var(--muted);">Wholesale</div>
                                <div style="font-size: 1.1rem; font-weight: 600; color: #1d4ed8;">
                                    $<?= number_format((float)$product['wholesale_price_usd'], 2) ?>
                                </div>
                                <?php if ($product['wholesale_margin_percent'] !== null): ?>
                                    <div
                                        style="font-size: 0.75rem; color: <?= $product['wholesale_margin_percent'] < 15 ? '#dc2626' : '#15803d' ?>;">
                                        <?= number_format((float)$product['wholesale_margin_percent'], 1) ?>% margin
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($product['cost_price_usd'] !== null && $product['cost_price_usd'] > 0): ?>
                            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1);">
                                <div style="font-size: 0.75rem; color: var(--muted);">Cost Basis</div>
                                <div style="font-size: 0.9rem; font-weight: 500;">
                                    $<?= number_format((float)$product['cost_price_usd'], 2) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="product-detail">
                        <div class="product-detail-label">Unit</div>
                        <div class="product-detail-value">
                            <?= htmlspecialchars($product['unit'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>

                    <div class="product-detail">
                        <div class="product-detail-label">Stock</div>
                        <div class="product-detail-value">
                            <?php if ($isOutOfStock): ?>
                                <span class="stock-badge out-of-stock">
                                    <span class="stock-indicator red"></span>
                                    Out of Stock
                                </span>
                            <?php elseif ($isLowStock): ?>
                                <span class="stock-badge low-stock">
                                    <span class="stock-indicator orange"></span>
                                    <?= number_format($qtyOnHand, 1) ?> (Low)
                                </span>
                            <?php else: ?>
                                <span class="stock-badge in-stock">
                                    <span class="stock-indicator green"></span>
                                    <?= number_format($qtyOnHand, 1) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Margin Alert -->
                    <?php if ($product['margin_percent'] !== null && $product['margin_percent'] < 15): ?>
                        <div
                            style="margin-top: 8px; padding: 8px; background: rgba(239, 68, 68, 0.1); border-radius: 6px; border-left: 3px solid #dc2626;">
                            <div style="font-size: 0.75rem; color: #991b1b; font-weight: 600;">⚠️ Low Margin Alert</div>
                            <div style="font-size: 0.7rem; color: #991b1b; margin-top: 2px;">Retail margin below 15% - negotiate
                                carefully</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a
                    href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?>">
                    ← Previous
                </a>
            <?php endif; ?>

            <span class="current">Page <?= $page ?> of <?= $totalPages ?></span>

            <?php if ($page < $totalPages): ?>
                <a
                    href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?>">
                    Next →
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php sales_portal_render_layout_end(); ?>