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
$wantsJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (!empty($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

function render_restock_cart_html(array $cartItems, string $csrfToken): string
{
    ob_start();
    if (empty($cartItems)) {
        echo '<div class="cart-empty">';
        echo '<div class="cart-empty-icon">🛒</div>';
        echo '<p>السلة فارغة</p>';
        echo '<p style="font-size: 0.9rem;">أضف منتجات من القائمة لبدء طلب التعبئة</p>';
        echo '</div>';
    } else {
        echo '<div class="cart-items">';
        $totalItems = 0;
        $totalQty = 0;
        foreach ($cartItems as $item) {
            $totalItems++;
            $totalQty += $item['quantity'];
            echo '<div class="cart-item">';
            echo '<div class="cart-item-info">';
            echo '<div class="cart-item-name">', htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'), '</div>';
            echo '<div class="cart-item-sku">', htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8'), '</div>';
            echo '</div>';
            echo '<div class="cart-item-controls">';
            echo '<form method="POST" class="ajax-cart-form" style="display: flex; gap: 4px; align-items: center;">';
            echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
            echo '<input type="hidden" name="action" value="update_quantity">';
            echo '<input type="hidden" name="item_id" value="', (int)$item['id'], '">';
            echo '<div class="cart-qty-controls">';
            echo '<button type="button" class="cart-qty-btn cart-qty-minus" onclick="adjustCartQty(this, -1)">−</button>';
            echo '<input type="number" name="quantity" class="cart-qty-input" value="', number_format((float)$item['quantity'], 0), '" min="0" step="1" onchange="this.form.requestSubmit()">';
            echo '<button type="button" class="cart-qty-btn cart-qty-plus" onclick="adjustCartQty(this, 1)">+</button>';
            echo '</div>';
            echo '</form>';
            echo '<form method="POST" class="ajax-cart-form">';
            echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
            echo '<input type="hidden" name="action" value="remove_item">';
            echo '<input type="hidden" name="item_id" value="', (int)$item['id'], '">';
            echo '<button type="submit" class="btn btn-danger" style="padding: 6px 10px;">✕</button>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="cart-totals">';
        echo '<div class="cart-total-row"><span>عدد المنتجات:</span><span>', $totalItems, '</span></div>';
        echo '<div class="cart-total-row grand"><span>إجمالي الوحدات:</span><span>', number_format((float)$totalQty, 1), '</span></div>';
        echo '</div>';
        echo '<div class="submit-section">';
        echo '<form method="POST">';
        echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
        echo '<input type="hidden" name="action" value="submit_request">';
        echo '<textarea name="notes" placeholder="ملاحظات للمستودع (اختياري)..."></textarea>';
        echo '<button type="submit" class="btn btn-success btn-submit">📤 إرسال طلب التعبئة للمستودع</button>';
        echo '</form>';
        echo '</div>';
    }
    return ob_get_clean();
}

function fetch_restock_cart_items(PDO $pdo, int $repId): array
{
    $activeCartStmt = $pdo->prepare("
        SELECT r.id
        FROM van_restock_requests r
        WHERE r.sales_rep_id = :rep_id AND r.status = 'pending'
        ORDER BY r.created_at DESC LIMIT 1
    ");
    $activeCartStmt->execute([':rep_id' => $repId]);
    $activeCartId = $activeCartStmt->fetchColumn();

    if (!$activeCartId) {
        return [];
    }

    $cartItemsStmt = $pdo->prepare("
        SELECT ri.id, ri.product_id, ri.quantity,
               p.sku, p.item_name, p.wholesale_price_usd, p.quantity_on_hand as warehouse_stock
        FROM van_restock_items ri
        JOIN products p ON p.id = ri.product_id
        WHERE ri.request_id = :request_id
        ORDER BY ri.id DESC
    ");
    $cartItemsStmt->execute([':request_id' => (int)$activeCartId]);
    return $cartItemsStmt->fetchAll(PDO::FETCH_ASSOC);
}

function normalize_number_string($value): string
{
    $value = (string)$value;
    $map = [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        ',' => '.',
        ' ' => '',
    ];
    return strtr($value, $map);
}

// Handle adding item to restock cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_to_cart') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => 'رمز الأمان غير صالح.',
            ]);
            exit;
        }
        $flashes[] = ['type' => 'error', 'title' => 'خطأ أمني', 'message' => 'رمز الأمان غير صالح.'];
    } else {
        $productIdRaw = normalize_number_string($_POST['product_id'] ?? '');
        $productId = (int)filter_var($productIdRaw, FILTER_SANITIZE_NUMBER_INT);
        $skuRaw = normalize_number_string($_POST['sku'] ?? '');
        $sku = trim((string)$skuRaw);
        $quantityRaw = normalize_number_string($_POST['quantity'] ?? '');
        $quantity = (float)preg_replace('/[^0-9.]+/', '', $quantityRaw);
        if ($quantity <= 0) {
            $quantity = 1;
        }
        if ($productId <= 0 && $sku !== '') {
            $lookup = $pdo->prepare("SELECT id FROM products WHERE sku = :sku LIMIT 1");
            $lookup->execute([':sku' => $sku]);
            $productId = (int)$lookup->fetchColumn();
        }

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
            if ($wantsJson) {
                $cartItems = fetch_restock_cart_items($pdo, $repId);
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => true,
                    'message' => 'تمت إضافة المنتج إلى سلة التعبئة.',
                    'cart_html' => render_restock_cart_html($cartItems, csrf_token()),
                ]);
                exit;
            }
        } elseif ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => 'البيانات غير صالحة لإضافة المنتج (معرّف المنتج أو الكمية مفقودة).',
            ]);
            exit;
        }
    }
}

// Handle updating item quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_quantity') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => 'رمز الأمان غير صالح.',
            ]);
            exit;
        }
        $flashes[] = ['type' => 'error', 'title' => 'خطأ أمني', 'message' => 'رمز الأمان غير صالح.'];
    } else {
        $itemIdRaw = normalize_number_string($_POST['item_id'] ?? '0');
        $itemId = (int)preg_replace('/\D+/', '', $itemIdRaw);
        $quantity = (float)normalize_number_string($_POST['quantity'] ?? '0');

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
    if ($wantsJson) {
        $cartItems = fetch_restock_cart_items($pdo, $repId);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'cart_html' => render_restock_cart_html($cartItems, csrf_token()),
        ]);
        exit;
    }
    // Redirect to avoid form resubmission
    header('Location: van_restock.php');
    exit;
}

// Handle removing item from cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'remove_item') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => 'رمز الأمان غير صالح.',
            ]);
            exit;
        }
        $flashes[] = ['type' => 'error', 'title' => 'خطأ أمني', 'message' => 'رمز الأمان غير صالح.'];
    } else {
        $itemIdRaw = normalize_number_string($_POST['item_id'] ?? '0');
        $itemId = (int)preg_replace('/\D+/', '', $itemIdRaw);
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
    if ($wantsJson) {
        $cartItems = fetch_restock_cart_items($pdo, $repId);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'cart_html' => render_restock_cart_html($cartItems, csrf_token()),
        ]);
        exit;
    }
    header('Location: van_restock.php');
    exit;
}

// Handle submitting the restock request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit_request') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => 'رمز الأمان غير صالح.',
            ]);
            exit;
        }
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
                if ($wantsJson) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => true,
                        'message' => 'تم إرسال طلب التعبئة للمستودع. سيتم إشعارك عند الموافقة.',
                        'cart_html' => render_restock_cart_html([], csrf_token()),
                    ]);
                    exit;
                }
            } else {
                if ($wantsJson) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => false,
                        'message' => 'يرجى إضافة منتجات إلى السلة أولاً.',
                    ]);
                    exit;
                }
                $flashes[] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'يرجى إضافة منتجات إلى السلة أولاً.'];
            }
        }
    }
}

// Get items in active cart
$cartItems = fetch_restock_cart_items($pdo, $repId);

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
           p.wholesale_price_usd, p.quantity_on_hand as warehouse_stock
    FROM products p
    WHERE p.is_active = 1
      AND p.wholesale_price_usd > 0
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
    $defaultImagePath = __DIR__ . '/../../images/products/default.jpg';
    $imageVersion = file_exists($defaultImagePath) ? (string)filemtime($defaultImagePath) : null;
    $possibleExtensions = ['webp', 'jpg', 'jpeg', 'png', 'gif'];
    foreach ($possibleExtensions as $ext) {
        $serverPath = __DIR__ . '/../../images/products/' . $product['sku'] . '.' . $ext;
        if (file_exists($serverPath)) {
            $imagePath = '../../images/products/' . $product['sku'] . '.' . $ext;
            $imageVersion = (string)filemtime($serverPath);
            break;
        }
    }
    $product['image_path'] = $imagePath . ($imageVersion ? '?v=' . rawurlencode($imageVersion) : '');
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
        .qty-controls {
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .qty-btn {
            width: 32px;
            height: 32px;
            border: 2px solid var(--border);
            background: #f1f5f9;
            border-radius: 6px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            user-select: none;
        }
        .qty-btn:hover { background: #e2e8f0; }
        .qty-btn:active { transform: scale(0.95); }
        .qty-minus { color: #dc2626; }
        .qty-plus { color: #059669; }
        .qty-input {
            width: 60px;
            padding: 6px;
            border: 2px solid var(--border);
            border-radius: 8px;
            text-align: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: #1e293b;
            background: #fff;
        }
        .qty-input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        .qty-input::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }
        .hide-images-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: #f1f5f9;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            white-space: nowrap;
            user-select: none;
        }
        .hide-images-toggle:hover { background: #e2e8f0; }
        .hide-images-toggle input { cursor: pointer; }
        .product-list.hide-images .product-image { display: none !important; }
        .product-list.hide-images.card-view .product-item { padding-top: 8px; }
        @media (max-width: 480px) {
            .qty-btn { width: 28px; height: 28px; font-size: 1rem; }
            .qty-input { width: 50px; padding: 4px; }
            .hide-images-toggle { padding: 6px 8px; font-size: 0.8rem; }
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
        .cart-qty-controls {
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .cart-qty-btn {
            width: 28px;
            height: 28px;
            border: 1px solid var(--border);
            background: #f1f5f9;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cart-qty-btn:hover { background: #e2e8f0; }
        .cart-qty-minus { color: #dc2626; }
        .cart-qty-plus { color: #059669; }
        .cart-qty-input {
            width: 50px;
            padding: 4px;
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
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="view-toggle">
                    <button type="button" class="view-toggle-btn" id="listViewBtn" onclick="setView('list')"
                        title="عرض قائمة">☰</button>
                    <button type="button" class="view-toggle-btn active" id="cardViewBtn" onclick="setView('card')"
                        title="عرض بطاقات">▦</button>
                </div>
                <label class="hide-images-toggle" title="إخفاء الصور لتسريع التحميل">
                    <input type="checkbox" id="hideImagesToggle" onchange="toggleImages()">
                    <span>🖼️ إخفاء الصور</span>
                </label>
            </div>

            <?php
            $defaultImageFs = __DIR__ . '/../../images/products/default.jpg';
            $defaultImageVersion = file_exists($defaultImageFs) ? (string)filemtime($defaultImageFs) : null;
            $defaultImageSrc = '../../images/products/default.jpg' . ($defaultImageVersion ? '?v=' . rawurlencode($defaultImageVersion) : '');
            ?>
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
                            class="product-image lazy-image" loading="lazy" onload="this.classList.add('is-loaded')"
                            onerror="this.src='<?= htmlspecialchars($defaultImageSrc, ENT_QUOTES, 'UTF-8') ?>'">
                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="product-meta">
                                <span
                                    class="product-sku"><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="product-price" style="color: var(--primary); font-weight: 700;">
                                    $<?= number_format((float)$product['wholesale_price_usd'], 2) ?>
                                </span>
                                <span class="product-stock <?= $stockClass ?>">
                                    المستودع: <?= number_format((float)$product['warehouse_stock'], 1) ?>
                                </span>
                            </div>
                        </div>
                        <form method="POST" class="add-form ajax-cart-form" data-product-id="<?= (int)$product['id'] ?>"
                            data-product-sku="<?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token"
                                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <input type="hidden" name="sku"
                                value="<?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="qty-controls">
                                <button type="button" class="qty-btn qty-minus" onclick="adjustQty(this, -1)">−</button>
                                <input type="number" name="quantity" class="qty-input" value="1" min="1"
                                    max="<?= (int)$product['warehouse_stock'] ?>" step="1"
                                    data-max="<?= (int)$product['warehouse_stock'] ?>" placeholder="1"
                                    onfocus="this.select()">
                                <button type="button" class="qty-btn qty-plus" onclick="adjustQty(this, 1)">+</button>
                            </div>
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
                            <span style="color: var(--muted);">- <?= number_format((float)$history['total_quantity'], 1) ?>
                                وحدة</span>
                            <br>
                            <small
                                style="color: var(--muted);"><?= date('Y/m/d H:i', strtotime($history['created_at'])) ?></small>
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
            <div id="ajaxFlash"></div>

            <div id="cartContent">
                <?= render_restock_cart_html($cartItems, $csrfToken) ?>
            </div>
        </div>
    </div>
</div>

<script>
    // View toggle functionality
    let currentView = localStorage.getItem('vanRestockView') || 'card';
    let hideImages = localStorage.getItem('vanRestockHideImages') === 'true';

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

    function toggleImages() {
        const checkbox = document.getElementById('hideImagesToggle');
        const productList = document.getElementById('productList');
        hideImages = checkbox.checked;
        localStorage.setItem('vanRestockHideImages', hideImages);

        if (hideImages) {
            productList.classList.add('hide-images');
        } else {
            productList.classList.remove('hide-images');
        }
    }

    function adjustQty(btn, delta) {
        const container = btn.closest('.qty-controls');
        const input = container.querySelector('.qty-input');
        const max = parseInt(input.dataset.max) || 9999;
        let val = parseInt(input.value) || 1;
        val += delta;
        if (val < 1) val = 1;
        if (val > max) {
            val = max;
            // Visual feedback for max reached
            input.style.borderColor = '#f59e0b';
            setTimeout(() => {
                input.style.borderColor = '';
            }, 300);
        }
        input.value = val;
    }

    function initHideImages() {
        const checkbox = document.getElementById('hideImagesToggle');
        const productList = document.getElementById('productList');
        if (checkbox && hideImages) {
            checkbox.checked = true;
            productList.classList.add('hide-images');
        }
    }

    function adjustCartQty(btn, delta) {
        const container = btn.closest('.cart-qty-controls');
        const input = container.querySelector('.cart-qty-input');
        let val = parseInt(input.value) || 0;
        val += delta;
        if (val < 0) val = 0;
        input.value = val;
        // Trigger form submit
        input.dispatchEvent(new Event('change', {
            bubbles: true
        }));
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

    function showAjaxFlash(type, message) {
        const host = document.getElementById('ajaxFlash');
        if (!host) {
            return;
        }
        const title = type === 'success' ? 'تم' : 'خطأ';
        host.innerHTML = `<div class="flash ${type}"><h4>${title}</h4><p>${message}</p></div>`;
        host.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    async function submitCartForm(form) {
        const normalizeDigits = (value) => {
            const map = {
                '٠': '0',
                '١': '1',
                '٢': '2',
                '٣': '3',
                '٤': '4',
                '٥': '5',
                '٦': '6',
                '٧': '7',
                '٨': '8',
                '٩': '9',
                '۰': '0',
                '۱': '1',
                '۲': '2',
                '۳': '3',
                '۴': '4',
                '۵': '5',
                '۶': '6',
                '۷': '7',
                '۸': '8',
                '۹': '9'
            };
            return (value || '').toString().split('').map(ch => map[ch] ?? ch).join('').replace(/,/g, '.').trim();
        };
        const formData = new FormData(form);
        const productIdInput = form.querySelector('[name="product_id"]');
        const skuInput = form.querySelector('[name="sku"]');
        const quantityInput = form.querySelector('[name="quantity"]');
        const productId = normalizeDigits(productIdInput ? productIdInput.value : form.dataset.productId);
        let quantity = normalizeDigits(quantityInput ? quantityInput.value : '');
        const sku = normalizeDigits(skuInput ? skuInput.value : form.dataset.productSku);
        if (!productId && form.dataset.productId) {
            formData.set('product_id', form.dataset.productId);
        } else if (productId) {
            formData.set('product_id', productId);
        }
        if (sku) {
            formData.set('sku', sku);
        }
        if (!quantity || Number(quantity) <= 0) {
            quantity = '1';
        }
        formData.set('quantity', quantity);
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            const payload = await response.json();
            if (!payload.ok) {
                showAjaxFlash('error', payload.message || 'تعذر تحديث السلة.');
                return;
            }
            if (payload.message) {
                showAjaxFlash('success', payload.message);
            }
            if (payload.cart_html) {
                const cartContent = document.getElementById('cartContent');
                if (cartContent) {
                    cartContent.innerHTML = payload.cart_html;
                }
            }
            bindAjaxCartForms();
        } catch (err) {
            showAjaxFlash('error', 'تعذر الاتصال بالخادم.');
        }
    }

    function bindAjaxCartForms() {
        document.querySelectorAll('form.ajax-cart-form, #cartContent form').forEach(form => {
            if (form.dataset.ajaxBound === '1') {
                return;
            }
            form.dataset.ajaxBound = '1';
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                submitCartForm(form);
            });
        });
    }

    // Initialize view on page load
    document.addEventListener('DOMContentLoaded', function() {
        setView(currentView);
        initHideImages();
        bindAjaxCartForms();

        // Prevent over-adjustment: validate quantity on input change
        document.querySelectorAll('.qty-input').forEach(input => {
            input.addEventListener('change', function() {
                const max = parseInt(this.dataset.max) || 9999;
                let val = parseInt(this.value) || 1;
                if (val < 1) val = 1;
                if (val > max) {
                    val = max;
                    this.style.borderColor = '#f59e0b';
                    setTimeout(() => {
                        this.style.borderColor = '';
                    }, 300);
                }
                this.value = val;
            });
        });
    });
</script>

<?php
sales_portal_render_layout_end();
