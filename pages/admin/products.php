<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Products';

// Pagination & filtering
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 75;
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$activeFilter = $_GET['active'] ?? '';
$priceMin = $_GET['price_min'] ?? '';
$priceMax = $_GET['price_max'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'sku';
$sortOrder = $_GET['sort_order'] ?? 'asc';

// Build WHERE conditions
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(p.sku LIKE :search OR p.item_name LIKE :search OR p.second_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if ($category !== '') {
    $where[] = "(p.topcat_name = :category OR p.midcat_name = :category)";
    $params[':category'] = $category;
}

if ($stockFilter === 'in_stock') {
    $where[] = "p.quantity_on_hand > 5";
} elseif ($stockFilter === 'low') {
    $where[] = "p.quantity_on_hand >= 1 AND p.quantity_on_hand <= 5";
} elseif ($stockFilter === 'out') {
    $where[] = "(p.quantity_on_hand = 0 OR p.quantity_on_hand IS NULL)";
}

if ($activeFilter !== '') {
    $where[] = "p.is_active = :active";
    $params[':active'] = (int)$activeFilter;
}

if ($priceMin !== '') {
    $where[] = "p.sale_price_usd >= :price_min";
    $params[':price_min'] = (float)$priceMin;
}

if ($priceMax !== '') {
    $where[] = "p.sale_price_usd <= :price_max";
    $params[':price_max'] = (float)$priceMax;
}

$whereClause = implode(' AND ', $where);

// Build ORDER BY clause
$allowedSortFields = ['sku', 'item_name', 'sale_price_usd', 'wholesale_price_usd', 'quantity_on_hand'];
$sortField = in_array($sortBy, $allowedSortFields) ? $sortBy : 'sku';
$sortDirection = strtoupper($sortOrder) === 'DESC' ? 'DESC' : 'ASC';
$orderClause = "p.{$sortField} {$sortDirection}";

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereClause}");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);
$page = min($page, max(1, $totalPages));
$offset = ($page - 1) * $perPage;

// Get products
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
        p.min_quantity,
        p.quantity_on_hand,
        p.is_active
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
    SELECT DISTINCT topcat_name AS category FROM products WHERE topcat_name IS NOT NULL AND topcat_name != ''
    UNION
    SELECT DISTINCT midcat_name FROM products WHERE midcat_name IS NOT NULL AND midcat_name != ''
    ORDER BY category
");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_active') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        
        try {
            $updateStmt = $pdo->prepare("UPDATE products SET is_active = :status, updated_at = NOW() WHERE id = :id");
            $updateStmt->execute([':status' => $newStatus, ':id' => $productId]);
            flash('success', 'Product status updated successfully.');
        } catch (PDOException $e) {
            flash('error', 'Failed to update product status.');
        }
        
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'update_price') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $salePrice = (float)($_POST['sale_price'] ?? 0);
        $wholesalePrice = (float)($_POST['wholesale_price'] ?? 0);
        
        try {
            $updateStmt = $pdo->prepare("
                UPDATE products 
                SET sale_price_usd = :sale, wholesale_price_usd = :wholesale, updated_at = NOW() 
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':sale' => $salePrice,
                ':wholesale' => $wholesalePrice,
                ':id' => $productId
            ]);
            flash('success', 'Prices updated successfully.');
        } catch (PDOException $e) {
            flash('error', 'Failed to update prices.');
        }
        
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Summary stats - clearer categorization
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        SUM(CASE WHEN quantity_on_hand > 5 THEN 1 ELSE 0 END) as in_stock,
        SUM(CASE WHEN quantity_on_hand >= 1 AND quantity_on_hand <= 5 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN quantity_on_hand = 0 OR quantity_on_hand IS NULL THEN 1 ELSE 0 END) as out_of_stock,
        SUM(quantity_on_hand) as total_units
    FROM products
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$flashes = consume_flashes();

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Products',
    'subtitle' => 'Manage your product catalogue and inventory',
    'active' => 'products',
    'user' => $user,
    'actions' => [
        ['label' => 'Import Excel', 'href' => 'products_import.php', 'variant' => 'primary'],
        ['label' => 'Export CSV', 'href' => 'products_export.php'],
    ],
]);

admin_render_flashes($flashes);
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: var(--bg-panel-alt);
        padding: 20px;
        border-radius: 12px;
        border: 1px solid var(--border);
    }
    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        margin: 8px 0;
    }
    .stat-label {
        color: var(--muted);
        font-size: 0.9rem;
    }
    .stat-sub {
        font-size: 0.8rem;
        color: var(--muted);
        margin-top: 4px;
    }
    .filters {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .filter-input {
        padding: 8px 12px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 8px;
        color: var(--text);
        font-size: 0.9rem;
    }
    .product-table {
        background: var(--bg-panel-alt);
        border-radius: 12px;
        overflow: hidden;
    }
    .product-table table {
        width: 100%;
        border-collapse: collapse;
    }
    .product-table th {
        background: rgba(0,0,0,0.3);
        padding: 12px;
        text-align: left;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
    }
    .product-table td {
        padding: 12px;
        border-top: 1px solid var(--border);
    }
    .product-table tr:hover {
        background: rgba(255,255,255,0.02);
    }
    .stock-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .stock-ok { background: rgba(110, 231, 183, 0.2); color: #6ee7b7; }
    .stock-low { background: rgba(255, 209, 102, 0.2); color: #ffd166; }
    .stock-out { background: rgba(255, 92, 122, 0.2); color: #ff5c7a; }
    .price-input {
        width: 100px;
        padding: 4px 8px;
        background: var(--bg-panel);
        border: 1px solid var(--border);
        border-radius: 4px;
        color: var(--text);
        font-size: 0.9rem;
    }
    .inline-form {
        display: inline-flex;
        gap: 4px;
        align-items: center;
    }
    .pagination {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin-top: 24px;
    }
    .pagination a, .pagination span {
        padding: 8px 12px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text);
        text-decoration: none;
        font-size: 0.9rem;
    }
    .pagination a:hover {
        background: var(--accent);
        color: #000;
    }
    .pagination .active {
        background: var(--accent-2);
        color: #fff;
    }
    /* Card View Styles */
    .products-grid {
        display: none;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }
    .products-grid.active {
        display: grid;
    }
    .product-card {
        background: var(--bg-panel-alt);
        border-radius: 16px;
        padding: 0;
        border: 1px solid var(--border);
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        transition: all 0.3s;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .product-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12);
    }
    .product-image {
        width: 100%;
        height: 200px;
        object-fit: cover;
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        border-bottom: 1px solid var(--border);
    }
    .product-image-placeholder {
        width: 100%;
        height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        border-bottom: 1px solid var(--border);
        font-size: 3rem;
        color: #9ca3af;
    }
    .product-content {
        padding: 20px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .product-header h3 {
        margin: 0 0 4px;
        font-size: 1.1rem;
        color: var(--text);
    }
    .product-header .sku-text {
        font-size: 0.8rem;
        color: var(--muted);
        font-family: monospace;
    }
    .product-category {
        display: inline-block;
        padding: 4px 10px;
        background: rgba(0,0,0,0.2);
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--accent);
        margin: 8px 0;
        max-width: fit-content;
    }
    .product-prices {
        margin: 12px 0;
    }
    .product-price-main {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--accent);
    }
    .product-price-wholesale {
        font-size: 0.9rem;
        color: var(--muted);
    }
    .product-stock-info {
        font-size: 0.9rem;
        margin-bottom: 12px;
    }
    .product-card-actions {
        margin-top: auto;
        padding-top: 12px;
        border-top: 1px solid var(--border);
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .product-table.hidden {
        display: none;
    }
    .view-toggle-btn {
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        color: var(--text);
        padding: 8px 12px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .view-toggle-btn:hover {
        background: var(--accent);
        color: #000;
    }
    @media (max-width: 900px) {
        .products-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .product-image,
        .product-image-placeholder {
            height: 160px;
        }
        .product-content {
            padding: 16px;
        }
    }
    @media (max-width: 600px) {
        .products-grid {
            grid-template-columns: 1fr;
        }
        .product-image,
        .product-image-placeholder {
            height: 180px;
        }
    }
</style>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Products</div>
        <div class="stat-value"><?= number_format($stats['total']) ?></div>
        <div class="stat-sub"><?= number_format((float)$stats['total_units']) ?> total units</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Products</div>
        <div class="stat-value" style="color: #22c55e"><?= number_format($stats['active']) ?></div>
        <div class="stat-sub"><?= number_format($stats['inactive']) ?> inactive</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">In Stock (>5)</div>
        <div class="stat-value" style="color: #3b82f6"><?= number_format($stats['in_stock']) ?></div>
        <div class="stat-sub">Healthy stock level</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Low Stock (1-5)</div>
        <div class="stat-value" style="color: #f59e0b"><?= number_format($stats['low_stock']) ?></div>
        <div class="stat-sub">Needs restocking soon</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Out of Stock (0)</div>
        <div class="stat-value" style="color: #ef4444"><?= number_format($stats['out_of_stock']) ?></div>
        <div class="stat-sub">Requires immediate attention</div>
    </div>
</div>

<section class="card">
    <form method="get" class="filters">
        <input type="hidden" name="path" value="admin/products">
        <input type="text" name="search" placeholder="Search SKU, name..." 
               value="<?= htmlspecialchars($search) ?>" class="filter-input" style="flex: 1; min-width: 200px;">
        
        <select name="category" class="filter-input">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select name="stock" class="filter-input">
            <option value="">All Stock Levels</option>
            <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>In Stock (&gt;5)</option>
            <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock (1-5)</option>
            <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock (0)</option>
        </select>
        
        <select name="active" class="filter-input">
            <option value="">All Status</option>
            <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Inactive</option>
        </select>
        
        <button type="submit" class="btn">Filter</button>
        <a href="?path=admin/products" class="btn">Clear</a>
        <button type="button" class="view-toggle-btn" id="toggleViewBtn" onclick="toggleView()">
            <span id="viewIcon">🔲</span> <span id="viewLabel">Card View</span>
        </button>
    </form>

    <div class="product-table" id="tableView">
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th>Retail Price</th>
                    <th>Wholesale Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--muted);">
                            No products found. Try adjusting your filters or <a href="products_import.php" style="color: var(--accent)">import products</a>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td style="font-family: monospace; font-size: 0.85rem;">
                                <?= htmlspecialchars($product['sku'] ?: '—') ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($product['item_name']) ?></strong>
                                <?php if ($product['second_name']): ?>
                                    <br><small style="color: var(--muted)"><?= htmlspecialchars($product['second_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.85rem;">
                                <?= htmlspecialchars($product['topcat_name'] ?: '—') ?>
                                <?php if ($product['midcat_name']): ?>
                                    <br><small style="color: var(--muted)"><?= htmlspecialchars($product['midcat_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($product['unit'] ?: '—') ?></td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="update_price">
                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                    $<input type="number" name="sale_price" value="<?= $product['sale_price_usd'] ?>" 
                                           step="0.01" min="0" class="price-input">
                                </form>
                            </td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="update_price">
                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                    $<input type="number" name="wholesale_price" value="<?= $product['wholesale_price_usd'] ?>" 
                                           step="0.01" min="0" class="price-input">
                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.8rem;">Save</button>
                                </form>
                            </td>
                            <td>
                                <?php
                                $qty = (float)$product['quantity_on_hand'];
                                $stockClass = 'stock-ok';
                                $stockLabel = 'In Stock';
                                if ($qty == 0) {
                                    $stockClass = 'stock-out';
                                    $stockLabel = 'Out';
                                } elseif ($qty >= 1 && $qty <= 5) {
                                    $stockClass = 'stock-low';
                                    $stockLabel = 'Low';
                                }
                                ?>
                                <span class="stock-badge <?= $stockClass ?>">
                                    <?= number_format($qty, 2) ?>
                                </span>
                                <br><small style="color: var(--muted)"><?= $stockLabel ?></small>
                            </td>
                            <td>
                                <?php if ($product['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="inline-form">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $product['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.8rem;">
                                        <?= $product['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Card View (hidden by default) -->
    <div class="products-grid" id="cardView">
        <?php if (empty($products)): ?>
            <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: var(--muted);">
                No products found. Try adjusting your filters or <a href="products_import.php" style="color: var(--accent)">import products</a>.
            </div>
        <?php else: ?>
            <?php foreach ($products as $product): ?>
                <?php
                $sku = htmlspecialchars($product['sku'] ?? '', ENT_QUOTES, 'UTF-8');
                $itemName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
                $secondName = $product['second_name'] ? htmlspecialchars($product['second_name'], ENT_QUOTES, 'UTF-8') : '';
                $category = $product['topcat_name'] ? htmlspecialchars($product['topcat_name'], ENT_QUOTES, 'UTF-8') : 'Uncategorized';
                $midCategory = $product['midcat_name'] ? htmlspecialchars($product['midcat_name'], ENT_QUOTES, 'UTF-8') : '';
                $qty = (float)$product['quantity_on_hand'];
                $salePriceUSD = (float)$product['sale_price_usd'];
                $wholesalePriceUSD = (float)$product['wholesale_price_usd'];

                // Stock status
                $stockClass = 'stock-ok';
                $stockLabel = 'In Stock';
                if ($qty == 0) {
                    $stockClass = 'stock-out';
                    $stockLabel = 'Out of Stock';
                } elseif ($qty >= 1 && $qty <= 5) {
                    $stockClass = 'stock-low';
                    $stockLabel = 'Low Stock';
                }

                // Product image path (based on SKU) - check multiple formats
                $imageExists = false;
                $imagePath = '';
                $possibleExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                if ($sku) {
                    foreach ($possibleExtensions as $ext) {
                        $serverPath = __DIR__ . '/../../images/products/' . $sku . '.' . $ext;
                        if (file_exists($serverPath)) {
                            $imagePath = '../../images/products/' . $sku . '.' . $ext;
                            $imageExists = true;
                            break;
                        }
                    }
                }

                if (!$imageExists) {
                    $defaultPath = __DIR__ . '/../../images/products/default.jpg';
                    if (file_exists($defaultPath)) {
                        $imagePath = '../../images/products/default.jpg';
                        $imageExists = true;
                    }
                }
                ?>
                <div class="product-card">
                    <?php if ($imageExists): ?>
                        <img src="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $itemName ?>" class="product-image" loading="lazy">
                    <?php else: ?>
                        <div class="product-image-placeholder">📦</div>
                    <?php endif; ?>

                    <div class="product-content">
                        <div class="product-header">
                            <h3><?= $itemName ?></h3>
                            <?php if ($secondName): ?>
                                <div style="font-size: 0.85rem; color: var(--muted); margin-bottom: 4px;"><?= $secondName ?></div>
                            <?php endif; ?>
                            <div class="sku-text">SKU: <?= $sku ?: '—' ?></div>
                        </div>

                        <div class="product-category"><?= $category ?><?= $midCategory ? ' / ' . $midCategory : '' ?></div>

                        <div class="product-prices">
                            <div class="product-price-main">$<?= number_format($salePriceUSD, 2) ?></div>
                            <div class="product-price-wholesale">Wholesale: $<?= number_format($wholesalePriceUSD, 2) ?></div>
                        </div>

                        <div class="product-stock-info">
                            <strong>Stock:</strong>
                            <span class="stock-badge <?= $stockClass ?>"><?= number_format($qty, 2) ?></span>
                            <span style="color: var(--muted); font-size: 0.85rem;">(<?= $stockLabel ?>)</span>
                            <br>
                            <strong>Unit:</strong> <?= htmlspecialchars($product['unit'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="product-card-actions">
                            <?php if ($product['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                            <form method="post" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $product['is_active'] ? 0 : 1 ?>">
                                <button type="submit" class="btn" style="padding: 4px 10px; font-size: 0.8rem;">
                                    <?= $product['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?path=admin/products&page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">First</a>
                <a href="?path=admin/products&page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">Previous</a>
            <?php endif; ?>
            
            <?php 
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++): 
            ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?path=admin/products&page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?path=admin/products&page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">Next</a>
                <a href="?path=admin/products&page=<?= $totalPages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $category ? '&category=' . urlencode($category) : '' ?><?= $stockFilter ? '&stock=' . urlencode($stockFilter) : '' ?><?= $activeFilter !== '' ? '&active=' . urlencode($activeFilter) : '' ?>">Last</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<script>
// Toggle between table and card view
let isCardView = false;
function toggleView() {
    const tableView = document.getElementById('tableView');
    const cardView = document.getElementById('cardView');
    const viewIcon = document.getElementById('viewIcon');
    const viewLabel = document.getElementById('viewLabel');

    if (!tableView || !cardView) return;

    isCardView = !isCardView;

    if (isCardView) {
        tableView.classList.add('hidden');
        cardView.classList.add('active');
        viewIcon.textContent = '📋';
        viewLabel.textContent = 'Table View';
    } else {
        tableView.classList.remove('hidden');
        cardView.classList.remove('active');
        viewIcon.textContent = '🔲';
        viewLabel.textContent = 'Card View';
    }
}
</script>

<?php admin_render_layout_end(); ?>
