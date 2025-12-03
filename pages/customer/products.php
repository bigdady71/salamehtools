<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

$success = null;
$error = null;

// Handle add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 1);

    error_log("Add to cart request - Customer: {$customerId}, Product: {$productId}, Quantity: {$quantity}");

    if ($productId > 0 && $quantity > 0) {
        // Check if product exists and is active
        $productStmt = $pdo->prepare("SELECT id, item_name, min_quantity FROM products WHERE id = ? AND is_active = 1");
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $minQuantity = (float)$product['min_quantity'];
            error_log("Product found: " . $product['item_name'] . ", Min qty: {$minQuantity}");

            if ($quantity < $minQuantity) {
                $error = 'Minimum quantity for this product is ' . number_format($minQuantity, 2);
                error_log("Quantity too low: {$quantity} < {$minQuantity}");
            } else {
                // Check if already in cart
                $cartCheckStmt = $pdo->prepare("SELECT id, quantity FROM customer_cart WHERE customer_id = ? AND product_id = ?");
                $cartCheckStmt->execute([$customerId, $productId]);
                $existingCart = $cartCheckStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingCart) {
                    // Update existing cart item
                    $newQuantity = (float)$existingCart['quantity'] + $quantity;
                    $updateStmt = $pdo->prepare("UPDATE customer_cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$newQuantity, $existingCart['id']]);
                    $success = 'Updated quantity in cart!';
                    error_log("Cart updated - New quantity: {$newQuantity}");
                } else {
                    // Insert new cart item
                    $insertStmt = $pdo->prepare("INSERT INTO customer_cart (customer_id, product_id, quantity) VALUES (?, ?, ?)");
                    $result = $insertStmt->execute([$customerId, $productId, $quantity]);
                    $success = 'Added to cart successfully!';
                    error_log("Cart insert result: " . ($result ? 'SUCCESS' : 'FAILED') . " - ID: " . $pdo->lastInsertId());
                }
            }
        } else {
            $error = 'Product not found or unavailable.';
            error_log("Product not found or inactive: {$productId}");
        }
    } else {
        error_log("Invalid product ID or quantity");
    }
}

// Get filters
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$inStock = isset($_GET['in_stock']) && $_GET['in_stock'] === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

// Build query
$where = ['p.is_active = 1', 'p.sale_price_usd > 0', 'p.quantity_on_hand > 0'];
$params = [];

if ($search !== '') {
    $where[] = '(p.item_name LIKE ? OR p.second_name LIKE ? OR p.sku LIKE ?)';
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($category !== '') {
    $where[] = 'p.topcat = ?';
    $params[] = $category;
}

if ($inStock) {
    $where[] = 'p.quantity_on_hand > 0';
}

$whereClause = implode(' AND ', $where);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) FROM products p WHERE {$whereClause}";
$countStmt = $pdo->prepare($countQuery);
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
        p.topcat,
        p.topcat_name,
        p.unit,
        p.sale_price_usd,
        p.wholesale_price_usd,
        p.min_quantity,
        p.quantity_on_hand,
        p.description
    FROM products p
    WHERE {$whereClause}
    ORDER BY p.item_name ASC
    LIMIT {$perPage} OFFSET {$offset}
";

$productsStmt = $pdo->prepare($productsQuery);
$productsStmt->execute($params);
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categoriesStmt = $pdo->query("
    SELECT DISTINCT topcat, topcat_name
    FROM products
    WHERE is_active = 1 AND topcat IS NOT NULL AND topcat != ''
    ORDER BY topcat_name ASC
");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get cart count
$cartStmt = $pdo->prepare("SELECT COUNT(*) as count FROM customer_cart WHERE customer_id = ?");
$cartStmt->execute([$customerId]);
$cartCount = (int)$cartStmt->fetchColumn();

$title = 'Browse Products - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Browse Products',
    'subtitle' => 'Find and add products to your cart',
    'customer' => $customer,
    'active' => 'products',
    'actions' => [
        ['label' => '🛒 View Cart (' . $cartCount . ')', 'href' => 'cart.php', 'variant' => 'primary'],
    ]
]);

?>

<!-- Breadcrumb Structured Data -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
        {
            "@type": "ListItem",
            "position": 1,
            "name": "Home",
            "item": "https://salamehtools.com"
        },
        {
            "@type": "ListItem",
            "position": 2,
            "name": "B2B Portal",
            "item": "https://salamehtools.com/pages/customer/dashboard.php"
        },
        {
            "@type": "ListItem",
            "position": 3,
            "name": "Product Catalog",
            "item": "https://salamehtools.com/pages/customer/products.php"
        }
    ]
}
</script>

<!-- Product Catalog Structured Data -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ItemList",
    "name": "Salameh Tools - Wholesale Hardware Product Catalog",
    "description": "Browse our complete B2B wholesale catalog of hardware, tools, and industrial supplies. Exclusive trade prices for businesses.",
    "numberOfItems": <?= $totalProducts ?>,
    "itemListElement": [
        <?php
        $itemPosition = 1;
        foreach ($products as $idx => $prod) {
            $productName = addslashes($prod['item_name']);
            $productSku = addslashes($prod['sku'] ?? 'N/A');
            $productPrice = number_format((float)$prod['sale_price_usd'], 2, '.', '');
            $productUrl = 'https://salamehtools.com/pages/customer/products.php?search=' . urlencode($prod['sku']);

            echo '{';
            echo '"@type": "ListItem",';
            echo '"position": ' . $itemPosition . ',';
            echo '"item": {';
            echo '"@type": "Product",';
            echo '"name": "' . $productName . '",';
            echo '"sku": "' . $productSku . '",';
            echo '"offers": {';
            echo '"@type": "Offer",';
            echo '"url": "' . $productUrl . '",';
            echo '"priceCurrency": "USD",';
            echo '"price": "' . $productPrice . '"';
            echo '}';
            echo '}';
            echo '}';

            if ($idx < count($products) - 1) echo ',';

            $itemPosition++;
        }
        ?>
    ]
}
</script>

<style>
.filters-card {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    margin-bottom: 24px;
}
.filters-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 16px;
    align-items: end;
}
@media (max-width: 900px) {
    .filters-grid {
        grid-template-columns: 1fr;
    }
}
.form-group {
    margin: 0;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 0.92rem;
    color: var(--text);
}
.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.2s;
}
.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.checkbox-group {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
}
.checkbox-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}
.checkbox-group label {
    margin: 0;
    cursor: pointer;
}
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}
.product-card {
    background: var(--bg-panel);
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
    height: 220px;
    object-fit: cover;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    border-bottom: 1px solid var(--border);
}
.product-image-placeholder {
    width: 100%;
    height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    border-bottom: 1px solid var(--border);
    font-size: 3rem;
    color: #9ca3af;
}
.product-content {
    padding: 24px;
    display: flex;
    flex-direction: column;
    flex: 1;
}
.product-header {
    margin-bottom: 16px;
}
.product-header h3 {
    margin: 0 0 4px;
    font-size: 1.15rem;
    color: var(--text);
}
.product-header .sku {
    font-size: 0.8rem;
    color: var(--muted);
}
.product-category {
    display: inline-block;
    padding: 4px 10px;
    background: var(--bg-panel-alt);
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--accent);
    margin-bottom: 12px;
}
.product-description {
    font-size: 0.9rem;
    color: var(--muted);
    line-height: 1.5;
    margin-bottom: 16px;
    flex: 1;
}
.product-price {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--accent);
    margin-bottom: 12px;
}
.product-stock {
    font-size: 0.85rem;
    margin-bottom: 16px;
}
.stock-available {
    color: #059669;
    font-weight: 600;
}
.stock-low {
    color: #d97706;
    font-weight: 600;
}
.stock-out {
    color: #dc2626;
    font-weight: 600;
}
.add-to-cart-form {
    display: flex;
    gap: 8px;
}
.qty-input {
    width: 80px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
}
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 24px;
    font-size: 0.92rem;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}
.empty-state {
    text-align: center;
    padding: 64px 24px;
    color: var(--muted);
}
.empty-state h3 {
    font-size: 1.4rem;
    margin: 0 0 12px;
    color: var(--text);
}
</style>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        <a href="cart.php" style="float: right; color: var(--accent); font-weight: 600;">View Cart →</a>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="filters-card">
    <form method="get" action="products.php">
        <div class="filters-grid">
            <div class="form-group">
                <label for="search">Search Products</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    placeholder="Search by name or SKU..."
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label for="category">Category</label>
                <select id="category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['topcat'], ENT_QUOTES, 'UTF-8') ?>"
                            <?= $category === $cat['topcat'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['topcat_name'] ?? $cat['topcat'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input
                        type="checkbox"
                        id="in_stock"
                        name="in_stock"
                        value="1"
                        <?= $inStock ? 'checked' : '' ?>
                    >
                    <label for="in_stock">In Stock Only</label>
                </div>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
        </div>
    </form>
</div>

<!-- Products Grid -->
<?php if (count($products) > 0): ?>
    <div class="products-grid">
        <?php foreach ($products as $product): ?>
            <?php
            $productId = (int)$product['id'];
            $itemName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
            $sku = htmlspecialchars($product['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($product['topcat_name'] ?? $product['topcat'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8');
            $price = (float)$product['sale_price_usd'];
            $unit = htmlspecialchars($product['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8');
            $qtyOnHand = (float)$product['quantity_on_hand'];
            $minQty = (float)$product['min_quantity'];
            $description = htmlspecialchars($product['description'] ?? 'No description available.', ENT_QUOTES, 'UTF-8');

            // Product image path (based on SKU)
            $imagePath = '/images/products/' . urlencode($product['sku'] ?? 'default') . '.jpg';
            $imageExists = file_exists(__DIR__ . '/../..' . $imagePath);
            if (!$imageExists) {
                // Try PNG
                $imagePath = '/images/products/' . urlencode($product['sku'] ?? 'default') . '.png';
                $imageExists = file_exists(__DIR__ . '/../..' . $imagePath);
            }

            // Stock status
            if ($qtyOnHand <= 0) {
                $stockClass = 'stock-out';
                $stockText = 'Out of Stock';
                $canOrder = false;
            } elseif ($qtyOnHand < $minQty * 5) {
                $stockClass = 'stock-low';
                $stockText = 'Low Stock (' . number_format($qtyOnHand, 2) . ' ' . $unit . ')';
                $canOrder = true;
            } else {
                $stockClass = 'stock-available';
                $stockText = 'In Stock (' . number_format($qtyOnHand, 2) . ' ' . $unit . ')';
                $canOrder = true;
            }
            ?>
            <div class="product-card">
                <?php if ($imageExists): ?>
                    <img src="<?= $imagePath ?>" alt="<?= $itemName ?>" class="product-image" loading="lazy">
                <?php else: ?>
                    <div class="product-image-placeholder">
                        📦
                    </div>
                <?php endif; ?>
                <div class="product-content">
                <div class="product-header">
                    <h3><?= $itemName ?></h3>
                    <div class="sku">SKU: <?= $sku ?></div>
                </div>
                <div class="product-category"><?= $category ?></div>
                <div class="product-description"><?= $description ?></div>
                <div class="product-price">$<?= number_format($price, 2) ?></div>
                <div class="product-stock <?= $stockClass ?>">
                    <?= $stockText ?>
                </div>
                <?php if ($canOrder): ?>
                    <form method="post" action="products.php<?= $search || $category || $inStock ? '?' . http_build_query($_GET) : '' ?>" class="add-to-cart-form" data-product-id="<?= $productId ?>">
                        <input type="hidden" name="action" value="add_to_cart">
                        <input type="hidden" name="product_id" value="<?= $productId ?>">
                        <input
                            type="number"
                            name="quantity"
                            class="qty-input"
                            value="<?= max(1, (int)ceil($minQty)) ?>"
                            min="<?= max(1, (int)ceil($minQty)) ?>"
                            step="1"
                            required
                        >
                        <button type="submit" class="btn btn-primary add-to-cart-btn" style="flex: 1;">
                            <span class="btn-text">Add to Cart</span>
                            <span class="btn-loading" style="display: none;">Adding...</span>
                        </button>
                    </form>
                    <div style="margin-top: 8px; font-size: 0.8rem; color: var(--muted);">
                        Min. qty: <?= number_format($minQty, 2) ?> <?= $unit ?>
                    </div>
                <?php else: ?>
                    <button class="btn" disabled style="width: 100%; opacity: 0.5; cursor: not-allowed;">
                        Out of Stock
                    </button>
                <?php endif; ?>
                </div>

                <!-- Structured Data for Product -->
                <script type="application/ld+json">
                {
                    "@context": "https://schema.org/",
                    "@type": "Product",
                    "name": "<?= addslashes($itemName) ?>",
                    "sku": "<?= addslashes($sku) ?>",
                    "description": "<?= addslashes(strip_tags($description)) ?>",
                    "category": "<?= addslashes($category) ?>",
                    "offers": {
                        "@type": "Offer",
                        "url": "https://salamehtools.com/pages/customer/products.php?search=<?= urlencode($product['sku']) ?>",
                        "priceCurrency": "USD",
                        "price": "<?= number_format($price, 2, '.', '') ?>",
                        "availability": "<?= $canOrder ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' ?>",
                        "seller": {
                            "@type": "Organization",
                            "name": "Salameh Tools"
                        }
                    },
                    "brand": {
                        "@type": "Brand",
                        "name": "Salameh Tools"
                    }
                }
                </script>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 32px; flex-wrap: wrap;">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn" style="padding: 10px 16px;">‹ Previous</a>
            <?php else: ?>
                <span class="btn" style="padding: 10px 16px; opacity: 0.4; cursor: not-allowed;">‹ Previous</span>
            <?php endif; ?>

            <?php
            // Calculate page range to show
            $range = 2; // Show 2 pages on each side of current page
            $start = max(1, $page - $range);
            $end = min($totalPages, $page + $range);

            // Show first page if not in range
            if ($start > 1) {
                $queryParams = array_merge($_GET, ['page' => 1]);
                ?>
                <a href="?<?= http_build_query($queryParams) ?>" class="btn" style="padding: 10px 14px;">1</a>
                <?php if ($start > 2): ?>
                    <span style="padding: 10px 8px; color: var(--muted);">...</span>
                <?php endif;
            }

            // Show page numbers in range
            for ($i = $start; $i <= $end; $i++) {
                $queryParams = array_merge($_GET, ['page' => $i]);
                if ($i == $page) {
                    ?>
                    <span class="btn" style="padding: 10px 14px; background: var(--accent); color: white; font-weight: 700;"><?= $i ?></span>
                    <?php
                } else {
                    ?>
                    <a href="?<?= http_build_query($queryParams) ?>" class="btn" style="padding: 10px 14px;"><?= $i ?></a>
                    <?php
                }
            }

            // Show last page if not in range
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) {
                    ?>
                    <span style="padding: 10px 8px; color: var(--muted);">...</span>
                    <?php
                }
                $queryParams = array_merge($_GET, ['page' => $totalPages]);
                ?>
                <a href="?<?= http_build_query($queryParams) ?>" class="btn" style="padding: 10px 14px;"><?= $totalPages ?></a>
                <?php
            }
            ?>

            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn" style="padding: 10px 16px;">Next ›</a>
            <?php else: ?>
                <span class="btn" style="padding: 10px 16px; opacity: 0.4; cursor: not-allowed;">Next ›</span>
            <?php endif; ?>
        </div>
        <div style="text-align: center; margin-top: 12px; color: var(--muted); font-size: 0.9rem;">
            Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalProducts ?> total products)
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card">
        <div class="empty-state">
            <h3>No Products Found</h3>
            <p>Try adjusting your search or filters.</p>
            <a href="products.php" class="btn btn-primary">Clear Filters</a>
        </div>
    </div>
<?php endif; ?>

<style>
.flying-item {
    position: fixed;
    z-index: 9999;
    pointer-events: none;
    font-size: 2rem;
    animation: flyToCart 0.8s ease-in-out forwards;
}

@keyframes flyToCart {
    0% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.3);
        opacity: 0.8;
    }
    100% {
        transform: scale(0.3);
        opacity: 0;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Restore scroll position after page reload
    const savedScrollPosition = sessionStorage.getItem('scrollPosition');
    if (savedScrollPosition) {
        window.scrollTo(0, parseInt(savedScrollPosition));
        sessionStorage.removeItem('scrollPosition');
    }

    // Handle add to cart forms
    const forms = document.querySelectorAll('.add-to-cart-form');

    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const button = form.querySelector('.add-to-cart-btn');
            const btnText = button.querySelector('.btn-text');
            const btnLoading = button.querySelector('.btn-loading');
            const productCard = form.closest('.product-card');

            // Save scroll position BEFORE form submission
            sessionStorage.setItem('scrollPosition', window.scrollY);

            // Get cart icon position (in navbar)
            const cartLink = document.querySelector('a[href*="cart.php"]');
            let cartRect = { top: 20, left: window.innerWidth - 100 };
            if (cartLink) {
                cartRect = cartLink.getBoundingClientRect();
            }

            // Get product card position
            const cardRect = productCard.getBoundingClientRect();

            // Create flying item animation
            const flyingItem = document.createElement('div');
            flyingItem.className = 'flying-item';
            flyingItem.textContent = '🛒';
            flyingItem.style.left = (cardRect.left + cardRect.width / 2) + 'px';
            flyingItem.style.top = (cardRect.top + cardRect.height / 2) + 'px';
            document.body.appendChild(flyingItem);

            // Animate to cart
            setTimeout(() => {
                flyingItem.style.transition = 'all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94)';
                flyingItem.style.left = (cartRect.left + cartRect.width / 2) + 'px';
                flyingItem.style.top = (cartRect.top + cartRect.height / 2) + 'px';
                flyingItem.style.transform = 'scale(0.2)';
                flyingItem.style.opacity = '0';
            }, 50);

            // Show loading state
            btnText.style.display = 'none';
            btnLoading.style.display = 'inline';
            button.disabled = true;

            // Let form submit normally (animation will play during page load)
        });
    });
});
</script>

<?php

customer_portal_render_layout_end();
