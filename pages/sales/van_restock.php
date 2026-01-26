<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Handle adding item to restock cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_to_cart') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'خطأ أمني', 'message' => 'رمز الأمان غير صالح.'];
    } else {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);

        if ($productId > 0 && $quantity > 0) {
            // Check if there's an active (pending) restock request for this rep
            $activeRequest = $pdo->prepare("
                SELECT id FROM van_restock_requests
                WHERE sales_rep_id = :rep_id AND status = 'pending'
                ORDER BY created_at DESC LIMIT 1
            ");
            $activeRequest->execute([':rep_id' => $repId]);
            $requestId = $activeRequest->fetchColumn();

            if (!$requestId) {
                // Create new restock request
                $createRequest = $pdo->prepare("
                    INSERT INTO van_restock_requests (sales_rep_id, status, created_at)
                    VALUES (:rep_id, 'pending', NOW())
                ");
                $createRequest->execute([':rep_id' => $repId]);
                $requestId = (int)$pdo->lastInsertId();
            }

            // Check if product already in cart
            $existingItem = $pdo->prepare("
                SELECT id, quantity FROM van_restock_items
                WHERE request_id = :request_id AND product_id = :product_id
            ");
            $existingItem->execute([':request_id' => $requestId, ':product_id' => $productId]);
            $existing = $existingItem->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update quantity
                $newQty = (float)$existing['quantity'] + $quantity;
                $updateItem = $pdo->prepare("
                    UPDATE van_restock_items SET quantity = :quantity WHERE id = :id
                ");
                $updateItem->execute([':quantity' => $newQty, ':id' => $existing['id']]);
            } else {
                // Add new item
                $addItem = $pdo->prepare("
                    INSERT INTO van_restock_items (request_id, product_id, quantity)
                    VALUES (:request_id, :product_id, :quantity)
                ");
                $addItem->execute([
                    ':request_id' => $requestId,
                    ':product_id' => $productId,
                    ':quantity' => $quantity
                ]);
            }

            $flashes[] = ['type' => 'success', 'title' => 'تمت الإضافة', 'message' => 'تمت إضافة المنتج إلى سلة التعبئة.'];
        }
    }
}

// Handle updating item quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_quantity') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'خطأ أمني', 'message' => 'رمز الأمان غير صالح.'];
    } else {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);

        if ($itemId > 0) {
            if ($quantity <= 0) {
                // Remove item
                $deleteItem = $pdo->prepare("
                    DELETE FROM van_restock_items
                    WHERE id = :id AND request_id IN (
                        SELECT id FROM van_restock_requests WHERE sales_rep_id = :rep_id AND status = 'pending'
                    )
                ");
                $deleteItem->execute([':id' => $itemId, ':rep_id' => $repId]);
            } else {
                // Update quantity
                $updateItem = $pdo->prepare("
                    UPDATE van_restock_items SET quantity = :quantity
                    WHERE id = :id AND request_id IN (
                        SELECT id FROM van_restock_requests WHERE sales_rep_id = :rep_id AND status = 'pending'
                    )
                ");
                $updateItem->execute([':quantity' => $quantity, ':id' => $itemId, ':rep_id' => $repId]);
            }
        }
    }
    // Redirect to avoid form resubmission
    header('Location: van_restock.php');
    exit;
}

// Handle removing item from cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'remove_item') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'خطأ أمني', 'message' => 'رمز الأمان غير صالح.'];
    } else {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $deleteItem = $pdo->prepare("
                DELETE FROM van_restock_items
                WHERE id = :id AND request_id IN (
                    SELECT id FROM van_restock_requests WHERE sales_rep_id = :rep_id AND status = 'pending'
                )
            ");
            $deleteItem->execute([':id' => $itemId, ':rep_id' => $repId]);
        }
    }
    header('Location: van_restock.php');
    exit;
}

// Handle submitting the restock request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit_request') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = ['type' => 'error', 'title' => 'خطأ أمني', 'message' => 'رمز الأمان غير صالح.'];
    } else {
        $notes = trim((string)($_POST['notes'] ?? ''));

        // Get active request
        $activeRequest = $pdo->prepare("
            SELECT id FROM van_restock_requests
            WHERE sales_rep_id = :rep_id AND status = 'pending'
            ORDER BY created_at DESC LIMIT 1
        ");
        $activeRequest->execute([':rep_id' => $repId]);
        $requestId = $activeRequest->fetchColumn();

        if ($requestId) {
            // Check if there are items
            $itemCount = $pdo->prepare("SELECT COUNT(*) FROM van_restock_items WHERE request_id = ?");
            $itemCount->execute([$requestId]);

            if ($itemCount->fetchColumn() > 0) {
                // Update request to submitted
                $submitRequest = $pdo->prepare("
                    UPDATE van_restock_requests
                    SET status = 'submitted', notes = :notes, submitted_at = NOW()
                    WHERE id = :id
                ");
                $submitRequest->execute([':notes' => $notes ?: null, ':id' => $requestId]);

                $flashes[] = ['type' => 'success', 'title' => 'تم الإرسال', 'message' => 'تم إرسال طلب التعبئة للمستودع. سيتم إشعارك عند الموافقة.'];
            } else {
                $flashes[] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'يرجى إضافة منتجات إلى السلة أولاً.'];
            }
        }
    }
}

// Get active (pending) restock cart
$activeCartStmt = $pdo->prepare("
    SELECT r.id, r.created_at, r.notes
    FROM van_restock_requests r
    WHERE r.sales_rep_id = :rep_id AND r.status = 'pending'
    ORDER BY r.created_at DESC LIMIT 1
");
$activeCartStmt->execute([':rep_id' => $repId]);
$activeCart = $activeCartStmt->fetch(PDO::FETCH_ASSOC);

// Get items in active cart
$cartItems = [];
if ($activeCart) {
    $cartItemsStmt = $pdo->prepare("
        SELECT ri.id, ri.product_id, ri.quantity,
               p.sku, p.item_name, p.sale_price_usd, p.quantity_on_hand as warehouse_stock
        FROM van_restock_items ri
        JOIN products p ON p.id = ri.product_id
        WHERE ri.request_id = :request_id
        ORDER BY ri.id DESC
    ");
    $cartItemsStmt->execute([':request_id' => $activeCart['id']]);
    $cartItems = $cartItemsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get submitted/processing requests history
$historyStmt = $pdo->prepare("
    SELECT r.id, r.status, r.created_at, r.submitted_at, r.fulfilled_at, r.notes,
           (SELECT COUNT(*) FROM van_restock_items WHERE request_id = r.id) as item_count,
           (SELECT SUM(quantity) FROM van_restock_items WHERE request_id = r.id) as total_quantity
    FROM van_restock_requests r
    WHERE r.sales_rep_id = :rep_id AND r.status != 'pending'
    ORDER BY r.created_at DESC
    LIMIT 20
");
$historyStmt->execute([':rep_id' => $repId]);
$requestHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all active products for adding to cart (exclude price = 0 or stock < 8)
$productsStmt = $pdo->prepare("
    SELECT p.id, p.sku, p.item_name, p.topcat_name as category,
           p.sale_price_usd, p.quantity_on_hand as warehouse_stock
    FROM products p
    WHERE p.is_active = 1
      AND p.sale_price_usd > 0
      AND p.quantity_on_hand >= 8
    ORDER BY p.topcat_name, p.item_name
");
$productsStmt->execute();
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique categories
$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories);

$csrfToken = csrf_token();

// Build product image paths
foreach ($products as &$product) {
    $imagePath = '../../images/products/default.jpg';
    $possibleExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    foreach ($possibleExtensions as $ext) {
        $serverPath = __DIR__ . '/../../images/products/' . $product['sku'] . '.' . $ext;
        if (file_exists($serverPath)) {
            $imagePath = '../../images/products/' . $product['sku'] . '.' . $ext;
            break;
        }
    }
    $product['image_path'] = $imagePath;
}
unset($product);

sales_portal_render_layout_start([
    'title' => 'تعبئة السيارة',
    'heading' => '🚚 طلب تعبئة السيارة',
    'subtitle' => 'اطلب منتجات من المستودع لتعبئة مخزون سيارتك',
    'active' => 'van_restock',
    'user' => $user,
    'extra_head' => '<style>
        .restock-container {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
        }
        @media (max-width: 1024px) {
            .restock-container {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .restock-container {
                gap: 16px;
            }
        }
        .section-card {
            background: var(--bg-panel);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--border);
        }
        @media (max-width: 480px) {
            .section-card {
                padding: 12px;
                border-radius: 12px;
            }
        }
        .section-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-bar input, .filter-bar select {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            flex: 1;
            min-width: 150px;
        }
        @media (max-width: 480px) {
            .filter-bar input, .filter-bar select {
                padding: 8px 10px;
                font-size: 0.9rem;
                min-width: 120px;
            }
        }
        .view-toggle {
            display: flex;
            gap: 4px;
            background: var(--border);
            padding: 4px;
            border-radius: 8px;
        }
        .view-toggle-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: transparent;
            font-size: 1.1rem;
            transition: all 0.2s;
        }
        .view-toggle-btn.active {
            background: var(--primary);
            color: white;
        }
        .product-list {
            max-height: 600px;
            overflow-y: auto;
        }
        /* List View (default) */
        .product-list.list-view .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 10px;
            margin-bottom: 8px;
            background: #f8fafc;
            transition: all 0.2s;
        }
        .product-list.list-view .product-item:hover {
            border-color: var(--primary);
            background: #f0f9ff;
        }
        .product-list.list-view .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            margin-left: 12px;
            flex-shrink: 0;
        }
        .product-list.list-view .product-info {
            flex: 1;
        }
        .product-list.list-view .add-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        /* Card/Grid View */
        .product-list.card-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }
        @media (max-width: 480px) {
            .product-list.card-view {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
        }
        .product-list.card-view .product-item {
            display: flex;
            flex-direction: column;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 12px;
            background: #f8fafc;
            transition: all 0.2s;
            text-align: center;
        }
        .product-list.card-view .product-item:hover {
            border-color: var(--primary);
            background: #f0f9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .product-list.card-view .product-image {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        @media (max-width: 480px) {
            .product-list.card-view .product-image {
                height: 100px;
            }
        }
        .product-list.card-view .product-info {
            flex: 1;
            margin-bottom: 10px;
        }
        .product-list.card-view .product-name {
            font-size: 0.9rem;
            line-height: 1.3;
            margin-bottom: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .product-list.card-view .product-meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .product-list.card-view .add-form {
            display: flex;
            gap: 6px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }
        .product-list.card-view .qty-input {
            width: 60px;
            padding: 6px;
        }
        .product-list.card-view .btn-primary {
            padding: 6px 12px;
            font-size: 0.9rem;
        }
        .product-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .product-meta {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .product-sku {
            font-family: monospace;
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        .product-stock {
            color: #059669;
            font-weight: 600;
        }
        .product-stock.low { color: #f59e0b; }
        .product-stock.out { color: #dc2626; }
        .add-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .qty-input {
            width: 80px;
            padding: 8px;
            border: 2px solid var(--border);
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-success {
            background: #059669;
            color: white;
        }
        .cart-section {
            position: sticky;
            top: 20px;
        }
        @media (max-width: 1024px) {
            .cart-section {
                position: relative;
                top: 0;
            }
        }
        .cart-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }
        .cart-empty-icon {
            font-size: 3rem;
            margin-bottom: 12px;
        }
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--border);
            gap: 8px;
        }
        .cart-item:last-child {
            border-bottom: none;
        }
        .cart-item-info {
            flex: 1;
            min-width: 0;
        }
        .cart-item-name {
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cart-item-sku {
            font-size: 0.8rem;
            color: var(--muted);
        }
        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        @media (max-width: 480px) {
            .cart-item {
                flex-wrap: wrap;
            }
            .cart-item-controls {
                width: 100%;
                justify-content: flex-end;
                margin-top: 8px;
            }
        }
        .cart-qty-input {
            width: 60px;
            padding: 6px;
            border: 2px solid var(--border);
            border-radius: 6px;
            text-align: center;
            font-weight: 600;
        }
        .cart-totals {
            padding: 16px;
            background: #f0f9ff;
            border-radius: 10px;
            margin-top: 16px;
        }
        .cart-total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .cart-total-row.grand {
            font-size: 1.2rem;
            font-weight: 700;
            padding-top: 8px;
            border-top: 2px solid var(--primary);
        }
        .submit-section {
            margin-top: 16px;
        }
        .submit-section textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            margin-bottom: 12px;
            resize: none;
            height: 80px;
        }
        .btn-submit {
            width: 100%;
            padding: 16px;
            font-size: 1.1rem;
        }
        .history-section {
            margin-top: 24px;
        }
        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 8px;
            background: #f8fafc;
        }
        .history-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-submitted { background: #fef3c7; color: #92400e; }
        .status-approved { background: #dbeafe; color: #1e40af; }
        .status-fulfilled { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .flash {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .flash-success { background: #d1fae5; color: #065f46; border: 1px solid #065f46; }
        .flash-error { background: #fee2e2; color: #991b1b; border: 1px solid #991b1b; }
    </style>',
]);

// Display flash messages
foreach ($flashes as $flash) {
    echo '<div class="flash flash-' . $flash['type'] . '">';
    echo '<strong>' . htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') . '</strong> - ';
    echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');
    echo '</div>';
}
?>

<div class="restock-container">
    <!-- Products Section -->
    <div>
        <div class="section-card">
            <h2 class="section-title">📦 المنتجات المتوفرة</h2>

            <div class="filter-bar">
                <input type="text" id="searchFilter" placeholder="بحث بالاسم أو الكود..." oninput="filterProducts()">
                <select id="categoryFilter" onchange="filterProducts()">
                    <option value="">جميع الفئات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="view-toggle">
                    <button type="button" class="view-toggle-btn" id="listViewBtn" onclick="setView('list')" title="عرض قائمة">☰</button>
                    <button type="button" class="view-toggle-btn active" id="cardViewBtn" onclick="setView('card')" title="عرض بطاقات">▦</button>
                </div>
            </div>

            <div class="product-list card-view" id="productList">
                <?php foreach ($products as $product):
                    $stockClass = '';
                    if ($product['warehouse_stock'] <= 0) $stockClass = 'out';
                    elseif ($product['warehouse_stock'] <= 10) $stockClass = 'low';
                ?>
                    <div class="product-item"
                         data-name="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                         data-sku="<?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>"
                         data-category="<?= htmlspecialchars($product['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($product['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                             class="product-image"
                             onerror="this.src='../../images/products/default.jpg'">
                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="product-meta">
                                <span class="product-sku"><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="product-stock <?= $stockClass ?>">
                                    المستودع: <?= number_format((float)$product['warehouse_stock'], 1) ?>
                                </span>
                            </div>
                        </div>
                        <form method="POST" class="add-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <input type="number" name="quantity" class="qty-input" value="1" min="0.1" step="0.1">
                            <button type="submit" class="btn btn-primary">+ إضافة</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- History Section -->
        <?php if (!empty($requestHistory)): ?>
        <div class="section-card history-section">
            <h2 class="section-title">📜 سجل الطلبات</h2>
            <?php foreach ($requestHistory as $history): ?>
                <div class="history-item">
                    <div>
                        <strong><?= (int)$history['item_count'] ?> منتج</strong>
                        <span style="color: var(--muted);">- <?= number_format((float)$history['total_quantity'], 1) ?> وحدة</span>
                        <br>
                        <small style="color: var(--muted);"><?= date('Y/m/d H:i', strtotime($history['created_at'])) ?></small>
                    </div>
                    <span class="history-status status-<?= $history['status'] ?>">
                        <?php
                        $statusLabels = [
                            'submitted' => 'مرسل',
                            'approved' => 'معتمد',
                            'fulfilled' => 'مكتمل',
                            'rejected' => 'مرفوض'
                        ];
                        echo $statusLabels[$history['status']] ?? $history['status'];
                        ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cart Section -->
    <div class="cart-section">
        <div class="section-card">
            <h2 class="section-title">🛒 سلة التعبئة</h2>

            <?php if (empty($cartItems)): ?>
                <div class="cart-empty">
                    <div class="cart-empty-icon">🛒</div>
                    <p>السلة فارغة</p>
                    <p style="font-size: 0.9rem;">أضف منتجات من القائمة لبدء طلب التعبئة</p>
                </div>
            <?php else: ?>
                <div class="cart-items">
                    <?php
                    $totalItems = 0;
                    $totalQty = 0;
                    foreach ($cartItems as $item):
                        $totalItems++;
                        $totalQty += $item['quantity'];
                    ?>
                        <div class="cart-item">
                            <div class="cart-item-info">
                                <div class="cart-item-name"><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="cart-item-sku"><?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="cart-item-controls">
                                <form method="POST" style="display: flex; gap: 4px; align-items: center;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="update_quantity">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="number" name="quantity" class="cart-qty-input"
                                           value="<?= number_format((float)$item['quantity'], 1) ?>"
                                           min="0" step="0.1" onchange="this.form.submit()">
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="remove_item">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 6px 10px;">✕</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cart-totals">
                    <div class="cart-total-row">
                        <span>عدد المنتجات:</span>
                        <span><?= $totalItems ?></span>
                    </div>
                    <div class="cart-total-row grand">
                        <span>إجمالي الوحدات:</span>
                        <span><?= number_format($totalQty, 1) ?></span>
                    </div>
                </div>

                <div class="submit-section">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="submit_request">
                        <textarea name="notes" placeholder="ملاحظات للمستودع (اختياري)..."></textarea>
                        <button type="submit" class="btn btn-success btn-submit">
                            📤 إرسال طلب التعبئة للمستودع
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// View toggle functionality
let currentView = localStorage.getItem('vanRestockView') || 'card';

function setView(view) {
    currentView = view;
    localStorage.setItem('vanRestockView', view);

    const productList = document.getElementById('productList');
    const listBtn = document.getElementById('listViewBtn');
    const cardBtn = document.getElementById('cardViewBtn');

    if (view === 'list') {
        productList.classList.remove('card-view');
        productList.classList.add('list-view');
        listBtn.classList.add('active');
        cardBtn.classList.remove('active');
    } else {
        productList.classList.remove('list-view');
        productList.classList.add('card-view');
        cardBtn.classList.add('active');
        listBtn.classList.remove('active');
    }

    // Reapply filter to fix display property
    filterProducts();
}

function filterProducts() {
    const search = document.getElementById('searchFilter').value.toLowerCase().trim();
    const category = document.getElementById('categoryFilter').value;
    const isCardView = document.getElementById('productList').classList.contains('card-view');

    document.querySelectorAll('.product-item').forEach(item => {
        const name = (item.dataset.name || '').toLowerCase();
        const sku = (item.dataset.sku || '').toLowerCase();
        const itemCategory = item.dataset.category || '';

        const matchesSearch = !search || name.includes(search) || sku.includes(search);
        const matchesCategory = !category || itemCategory === category;

        if (matchesSearch && matchesCategory) {
            item.style.display = isCardView ? 'flex' : 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// Initialize view on page load
document.addEventListener('DOMContentLoaded', function() {
    setView(currentView);
});
</script>

<?php
sales_portal_render_layout_end();
