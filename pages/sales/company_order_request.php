<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/counter.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];


// Get active exchange rate
$exchangeRate = null;
$exchangeRateId = null;
$exchangeRateError = false;

try {
    $rateStmt = $pdo->prepare("
        SELECT id, rate
        FROM exchange_rates
        WHERE UPPER(base_currency) = 'USD'
          AND UPPER(quote_currency) IN ('LBP', 'LEBP')
        ORDER BY valid_from DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    $rateStmt->execute();
    $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if ($rateRow && (float)$rateRow['rate'] > 0) {
        $exchangeRate = (float)$rateRow['rate'];
        $exchangeRateId = (int)$rateRow['id'];
    } else {
        $exchangeRateError = true;
        error_log("Exchange rate not found in database");
    }
} catch (PDOException $e) {
    $exchangeRateError = true;
    error_log("Failed to fetch exchange rate: " . $e->getMessage());
}

// If exchange rate is unavailable, show error and block form
if ($exchangeRateError || $exchangeRate === null) {
    $flashes[] = [
        'type' => 'error',
        'title' => 'Exchange Rate Unavailable',
        'message' => 'Cannot create orders at this time. The system exchange rate is not configured. Please contact your administrator.',
        'dismissible' => false,
    ];
    $canCreateOrder = false;
} else {
    $canCreateOrder = true;
}

// Handle order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_order') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $items = $_POST['items'] ?? [];

        $errors = [];

        // Validate customer
        if ($customerId <= 0) {
            $errors[] = 'Please select a customer.';
        } else {
            // Verify customer is assigned to this sales rep
            $customerStmt = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id AND is_active = 1");
            $customerStmt->execute([':id' => $customerId, ':rep_id' => $repId]);
            if (!$customerStmt->fetch()) {
                $errors[] = 'Invalid customer selected or customer not assigned to you.';
            }
        }

        // Validate order items
        if (!is_array($items) || count($items) === 0) {
            $errors[] = 'Please add at least one product to the order.';
        } else {
            $validatedItems = [];
            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = (float)($item['quantity'] ?? 0);
                $discount = (float)($item['discount'] ?? 0);

                if ($productId <= 0 || $quantity <= 0) {
                    continue; // Skip invalid items
                }

                if ($discount < 0 || $discount > 100) {
                    $errors[] = "Invalid discount for product ID {$productId}. Must be between 0 and 100.";
                    continue;
                }

                $validatedItems[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'discount' => 0,
                ];
            }

            if (empty($validatedItems)) {
                $errors[] = 'No valid products in the order.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Generate order number atomically (race-condition safe)
                // Format: {customerId}-{salespersonId}-{sequence}
                $orderNumber = generate_order_number($pdo, $customerId, $repId);

                // Calculate totals and verify products exist
                $totalUSD = 0;
                $totalLBP = 0;
                $itemsWithPrices = [];

                foreach ($validatedItems as $item) {
                    // Get product price
                    $productStmt = $pdo->prepare("
                        SELECT
                            p.sale_price_usd,
                            p.item_name,
                            p.quantity_on_hand
                        FROM products p
                        WHERE p.id = :product_id AND p.is_active = 1
                    ");
                    $productStmt->execute([':product_id' => $item['product_id']]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        $errors[] = "Product ID {$item['product_id']} not found or inactive.";
                        break;
                    }

                    // Check warehouse stock availability (informational only, order can still be placed)
                    $warehouseStock = (float)($product['quantity_on_hand'] ?? 0);
                    if ($warehouseStock < $item['quantity']) {
                        // Note: We don't block the order, just log it for admin review
                        error_log("Order request for product {$product['item_name']} exceeds warehouse stock. Available: {$warehouseStock}, Requested: {$item['quantity']}");
                    }

                    $unitPriceUSD = (float)$product['sale_price_usd'];
                    $unitPriceLBP = $unitPriceUSD * $exchangeRate; // Calculate LBP from USD

                    // Calculate line totals (no discount)
                    $lineUSD = $unitPriceUSD * $item['quantity'];
                    $lineLBP = $unitPriceLBP * $item['quantity'];

                    $totalUSD += $lineUSD;
                    $totalLBP += $lineLBP;

                    $itemsWithPrices[] = [
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price_usd' => $unitPriceUSD,
                        'unit_price_lbp' => $unitPriceLBP,
                        'discount_percent' => 0,
                    ];
                }

                if ($errors) {
                    $pdo->rollBack();
                    $flashes[] = [
                        'type' => 'error',
                        'title' => 'Validation Failed',
                        'message' => 'Unable to create order. Please fix the errors below:',
                        'list' => $errors,
                        'dismissible' => true,
                    ];
                } else {
                    // Create order
                    $orderStmt = $pdo->prepare("
                        INSERT INTO orders (
                            order_number, order_type, status, customer_id, sales_rep_id, exchange_rate_id,
                            total_usd, total_lbp, notes, invoice_ready, created_at, updated_at
                        ) VALUES (
                            :order_number, 'company_order', 'on_hold', :customer_id, :sales_rep_id, :exchange_rate_id,
                            :total_usd, :total_lbp, :notes, 0, NOW(), NOW()
                        )
                    ");
                    $orderStmt->execute([
                        ':order_number' => $orderNumber,
                        ':customer_id' => $customerId,
                        ':sales_rep_id' => $repId,
                        ':exchange_rate_id' => $exchangeRateId,
                        ':total_usd' => $totalUSD,
                        ':total_lbp' => $totalLBP,
                        ':notes' => $notes !== '' ? $notes : null,
                    ]);

                    $orderId = (int)$pdo->lastInsertId();

                    // Insert order items
                    $itemStmt = $pdo->prepare("
                        INSERT INTO order_items (
                            order_id, product_id, quantity, unit_price_usd, unit_price_lbp, discount_percent
                        ) VALUES (
                            :order_id, :product_id, :quantity, :unit_price_usd, :unit_price_lbp, :discount_percent
                        )
                    ");

                    foreach ($itemsWithPrices as $item) {
                        $itemStmt->execute([
                            ':order_id' => $orderId,
                            ':product_id' => $item['product_id'],
                            ':quantity' => $item['quantity'],
                            ':unit_price_usd' => $item['unit_price_usd'],
                            ':unit_price_lbp' => $item['unit_price_lbp'],
                            ':discount_percent' => $item['discount_percent'],
                        ]);
                    }

                    // Create initial order status (on_hold - awaiting approval)
                    $statusStmt = $pdo->prepare("
                        INSERT INTO order_status_events (order_id, status, actor_user_id)
                        VALUES (:order_id, 'on_hold', :actor_id)
                    ");
                    $statusStmt->execute([
                        ':order_id' => $orderId,
                        ':actor_id' => $repId,
                    ]);

                    $pdo->commit();

                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Order Request Submitted',
                        'message' => "Order request {$orderNumber} has been successfully submitted and is pending approval.",
                        'dismissible' => true,
                    ];

                    // Clear the form by redirecting
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create company order request: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to create order request. Please try again.',
                    'dismissible' => true,
                ];
            }
        } else {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Unable to create order. Please fix the errors below:',
                'list' => $errors,
                'dismissible' => true,
            ];
        }
    }
}

// Check for success redirect
if (isset($_GET['success'])) {
    $flashes[] = [
        'type' => 'success',
        'title' => 'Order Request Submitted Successfully',
        'message' => 'Your order request has been submitted and is pending approval by the warehouse team.',
        'dismissible' => true,
    ];
}

// Get sales rep's customers
$customersStmt = $pdo->prepare("
    SELECT id, name, phone, location
    FROM customers
    WHERE assigned_sales_rep_id = :rep_id AND is_active = 1
    ORDER BY name
");
$customersStmt->execute([':rep_id' => $repId]);
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all active products (warehouse inventory)
$productsStmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.topcat as category,
        p.description,
        p.barcode,
        p.code_clean,
        p.sale_price_usd,
        p.quantity_on_hand
    FROM products p
    WHERE p.is_active = 1
    ORDER BY p.item_name
");
$productsStmt->execute();
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'Company Order Request',
    'heading' => 'Create Company Order Request',
    'subtitle' => 'Request products to be fulfilled from warehouse stock',
    'active' => 'orders_request',
    'user' => $user,
    'extra_head' => '<style>
        .order-form {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 28px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .form-section {
            margin-bottom: 32px;
        }
        .form-section h3 {
            margin: 0 0 16px;
            font-size: 1.2rem;
            color: var(--text);
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--bg);
        }
        .customer-search-wrapper {
            position: relative;
            margin-bottom: 10px;
        }
        .customer-search-wrapper input {
            width: 100%;
            padding: 10px 40px 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--bg-panel);
            transition: all 0.2s;
        }
        .customer-search-wrapper input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        .customer-search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }
        .customer-search-clear {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: #ef4444;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            display: none;
            font-weight: 600;
        }
        .customer-search-clear:hover {
            background: #dc2626;
        }
        .customer-list {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-panel);
            margin-top: 8px;
        }
        .customer-item {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.2s;
        }
        .customer-item:hover {
            background: var(--bg-panel-alt);
        }
        .customer-item:last-child {
            border-bottom: none;
        }
        .customer-item.highlighted {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
        }
        .customer-item.hidden {
            display: none !important;
        }
        .customer-item-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .customer-item-meta {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .selected-customer {
            padding: 12px 16px;
            background: #d1fae5;
            border: 2px solid #059669;
            border-radius: 8px;
            margin-top: 10px;
        }
        .selected-customer-name {
            font-weight: 600;
            color: #065f46;
            margin-bottom: 4px;
        }
        .selected-customer-info {
            font-size: 0.9rem;
            color: #047857;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .products-selector {
            background: var(--bg-panel-alt);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .product-search {
            position: relative;
            margin-bottom: 16px;
        }
        .product-search input {
            width: 100%;
            padding: 12px 44px 12px 16px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            background: var(--bg-panel);
            transition: all 0.2s;
        }
        .product-search input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .product-search-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            font-size: 1.2rem;
            pointer-events: none;
        }
        .product-search-clear {
            position: absolute;
            right: 44px;
            top: 50%;
            transform: translateY(-50%);
            background: #dc2626;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            display: none;
        }
        .product-search-clear:hover {
            background: #991b1b;
        }
        .product-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg-panel);
        }
        .product-item {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
        }
        .product-item:hover {
            background: var(--bg-panel-alt);
            border-left: 4px solid #6366f1;
            padding-left: 8px;
        }
        .product-item.selected {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding-left: 8px;
        }
        .product-item.highlighted {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding-left: 8px;
        }
        .product-item:last-child {
            border-bottom: none;
        }
        .product-item.hidden {
            display: none;
        }
        .no-results {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
            display: none;
        }
        .no-results.visible {
            display: block;
        }
        .product-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .product-item-name {
            font-weight: 600;
        }
        .product-item-price {
            font-weight: 600;
            color: var(--accent);
        }
        .product-item-meta {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .product-item-stock {
            color: #059669;
            font-weight: 600;
        }
        .product-item-stock.low {
            color: #f59e0b;
        }
        .product-item-stock.out {
            color: #dc2626;
        }
        .selected-products {
            margin-top: 20px;
        }
        .selected-products h4 {
            margin: 0 0 12px;
            font-size: 1.1rem;
        }
        .selected-product {
            background: var(--bg-panel);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .selected-product-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .selected-product-name {
            font-weight: 600;
            font-size: 1rem;
        }
        .btn-remove {
            background: #ef4444;
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .btn-remove:hover {
            background: #dc2626;
        }
        .selected-product-controls {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
        }
        .control-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .control-group label {
            font-size: 0.85rem;
            font-weight: 600;
        }
        .control-group input {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .selected-product-subtotal {
            margin-top: 12px;
            text-align: right;
            font-weight: 600;
            font-size: 1rem;
        }
        .order-summary {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 24px;
            border: 2px solid var(--accent);
            margin-bottom: 24px;
        }
        .order-summary h3 {
            margin: 0 0 16px;
            font-size: 1.3rem;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .summary-row:last-child {
            border-bottom: none;
            padding-top: 16px;
            margin-top: 8px;
            border-top: 2px solid var(--border);
        }
        .summary-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--accent);
        }
        .flash-stack {
            margin-bottom: 24px;
        }
        .flash {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 12px;
            border: 1px solid;
        }
        .flash-success {
            background: #d1fae5;
            border-color: #065f46;
            color: #065f46;
        }
        .flash-error {
            background: #fee2e2;
            border-color: #991b1b;
            color: #991b1b;
        }
        .flash-title {
            font-weight: 700;
            margin-bottom: 6px;
        }
        .flash-list {
            margin: 8px 0 0 20px;
            padding: 0;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .alert-info {
            background: #dbeafe;
            border: 1px solid #3b82f6;
            color: #1e40af;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
        .alert-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
    </style>',
]);

// Render flash messages
if ($flashes) {
    echo '<div class="flash-stack">';
    foreach ($flashes as $flash) {
        $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
        $title = isset($flash['title']) ? htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8') : '';
        $message = isset($flash['message']) ? htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') : '';
        $list = $flash['list'] ?? [];

        echo '<div class="flash flash-', $type, '">';
        if ($title) {
            echo '<div class="flash-title">', $title, '</div>';
        }
        if ($message) {
            echo '<div>', $message, '</div>';
        }
        if ($list) {
            echo '<ul class="flash-list">';
            foreach ($list as $item) {
                echo '<li>', htmlspecialchars($item, ENT_QUOTES, 'UTF-8'), '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    echo '</div>';
}

if (!$canCreateOrder) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">⚠️</div>';
    echo '<h3>System Configuration Required</h3>';
    echo '<p>Orders cannot be created until the exchange rate is properly configured in the system.</p>';
    echo '<p><a href="../dashboard.php" class="btn btn-info">Return to Dashboard</a></p>';
    echo '</div>';
    sales_portal_render_layout_end();
    exit;
}

if (empty($customers)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">👥</div>';
    echo '<h3>No Customers Assigned</h3>';
    echo '<p>You need to have customers assigned to you before creating order requests.</p>';
    echo '<p><a href="../users.php">Go to Customers page</a> to add customers.</p>';
    echo '</div>';
} elseif (empty($products)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">📦</div>';
    echo '<h3>No Products Available</h3>';
    echo '<p>There are no active products available for ordering.</p>';
    echo '</div>';
} else {
    echo '<div class="alert-warning">';
    echo '<strong>Note:</strong> This creates an order request that will be fulfilled from warehouse stock. ';
    echo 'The order will be placed on hold pending approval. Orders are fulfilled by the warehouse team, not from your van stock.';
    echo '</div>';

    echo '<form method="POST" id="orderForm">';
    echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
    echo '<input type="hidden" name="action" value="create_order">';

    echo '<div class="order-form">';

    // Customer Selection
    echo '<div class="form-section">';
    echo '<h3>1. Select Customer</h3>';
    echo '<div class="form-group">';
    echo '<label>Customer <span style="color:red;">*</span></label>';
    echo '<div class="customer-search-wrapper">';
    echo '<input type="text" id="customerSearch" placeholder="Search by name or phone..." autocomplete="off">';
    echo '<button type="button" class="customer-search-clear" id="clearCustomerSearch">✕ Clear</button>';
    echo '<span class="customer-search-icon">🔍</span>';
    echo '</div>';
    echo '<input type="hidden" name="customer_id" id="selectedCustomerId" required>';
    echo '<div class="customer-list" id="customerList" style="display:none;">';
    foreach ($customers as $customer) {
        $custId = (int)$customer['id'];
        $custName = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
        $custPhone = htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8');
        $custCity = htmlspecialchars($customer['city'] ?? '', ENT_QUOTES, 'UTF-8');
        echo '<div class="customer-item" data-customer-id="', $custId, '" data-customer-name="', $custName, '" ';
        echo 'data-customer-phone="', $custPhone, '" data-customer-city="', $custCity, '">';
        echo '<div class="customer-item-name">', $custName, '</div>';
        echo '<div class="customer-item-meta">';
        if ($custPhone) echo 'Phone: ', $custPhone;
        if ($custPhone && $custCity) echo ' | ';
        if ($custCity) echo 'City: ', $custCity;
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '<div class="selected-customer" id="selectedCustomerDisplay" style="display:none;"></div>';
    echo '</div>';
    echo '</div>';

    // Product Selection
    echo '<div class="form-section">';
    echo '<h3>2. Select Products</h3>';
    echo '<div class="alert-info">';
    echo 'Click on products below to add them to your order. All warehouse products are shown with their availability status.';
    echo '</div>';
    echo '<div class="products-selector">';
    echo '<div class="product-search">';
    echo '<input type="text" id="productSearch" placeholder="Search by name, SKU, category, barcode, code, or description..." autocomplete="off">';
    echo '<button type="button" class="product-search-clear" id="clearSearch">✕ Clear</button>';
    echo '<span class="product-search-icon">🔍</span>';
    echo '</div>';
    echo '<div class="product-list" id="productList">';
    echo '<div class="no-results" id="noResults">No products found. Try a different search term.</div>';

    foreach ($products as $product) {
        $prodId = (int)$product['id'];
        $prodSku = htmlspecialchars($product['sku'] ?? '', ENT_QUOTES, 'UTF-8');
        $prodName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
        $prodCategory = $product['category'] ? htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') : '';
        $prodDescription = $product['description'] ? htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') : '';
        $prodBarcode = $product['barcode'] ? htmlspecialchars($product['barcode'], ENT_QUOTES, 'UTF-8') : '';
        $prodCodeClean = $product['code_clean'] ? htmlspecialchars($product['code_clean'], ENT_QUOTES, 'UTF-8') : '';
        $prodPriceUSD = (float)$product['sale_price_usd'];
        $prodStock = (float)$product['quantity_on_hand'];

        $stockClass = '';
        $stockLabel = 'In Stock';
        if ($prodStock <= 0) {
            $stockClass = 'out';
            $stockLabel = 'Out of Stock';
        } elseif ($prodStock <= 10) {
            $stockClass = 'low';
            $stockLabel = 'Low Stock';
        }

        $prodPriceLBP = $prodPriceUSD * $exchangeRate;
        echo '<div class="product-item" data-product-id="', $prodId, '" data-product-name="', $prodName, '" ';
        echo 'data-product-sku="', $prodSku, '" data-product-category="', $prodCategory, '" ';
        echo 'data-product-description="', $prodDescription, '" data-product-barcode="', $prodBarcode, '" ';
        echo 'data-product-code="', $prodCodeClean, '" data-price-usd="', $prodPriceUSD, '" ';
        echo 'data-price-lbp="', $prodPriceLBP, '" data-warehouse-stock="', $prodStock, '">';
        echo '<div class="product-item-header">';
        echo '<span class="product-item-name">', $prodName, '</span>';
        echo '<span class="product-item-price">$', number_format($prodPriceUSD, 2), '</span>';
        echo '</div>';
        echo '<div class="product-item-meta">';
        echo 'SKU: ', $prodSku;
        if ($prodCategory) {
            echo ' | Category: ', $prodCategory;
        }
        echo ' | <span class="product-item-stock ', $stockClass, '">', $stockLabel, ': ', number_format($prodStock, 1), '</span>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
    echo '</div>';

    echo '<div class="selected-products" id="selectedProducts">';
    echo '<h4>Selected Products</h4>';
    echo '<div id="selectedProductsList">';
    echo '<p style="color:var(--muted);text-align:center;">No products selected yet. Click on products above to add them.</p>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Order Notes
    echo '<div class="form-section">';
    echo '<h3>3. Order Notes & Delivery Instructions</h3>';
    echo '<div class="form-group">';
    echo '<label>Notes</label>';
    echo '<textarea name="notes" placeholder="Add delivery instructions, special requests, or any notes for the warehouse team..."></textarea>';
    echo '</div>';
    echo '</div>';

    echo '</div>'; // End order-form

    // Order Summary
    echo '<div class="order-summary" id="orderSummary" style="display:none;">';
    echo '<h3>Order Summary</h3>';
    echo '<div class="summary-row">';
    echo '<span>Items Count:</span>';
    echo '<span id="summaryItemCount">0</span>';
    echo '</div>';
    echo '<div class="summary-row total">';
    echo '<span>Total (USD):</span>';
    echo '<span id="summaryTotalUSD">$0.00</span>';
    echo '</div>';
    echo '<div class="summary-row">';
    echo '<span>Total (LBP):</span>';
    echo '<span id="summaryTotalLBP">L.L. 0</span>';
    echo '</div>';
    echo '</div>';

    echo '<button type="submit" class="btn btn-success btn-block btn-lg" id="submitButton" disabled>Submit Order Request</button>';
    echo '</form>';
}

echo '<script>';
echo 'const selectedProducts = [];';
echo 'const exchangeRate = ', $exchangeRate, ';';
echo '';
echo 'function addProduct(productId, productName, sku, priceUSD, priceLBP, warehouseStock) {';
echo '  if (selectedProducts.find(p => p.id === productId)) {';
echo '    alert("Product already added!");';
echo '    return;';
echo '  }';
echo '  selectedProducts.push({';
echo '    id: productId,';
echo '    name: productName,';
echo '    sku: sku,';
echo '    priceUSD: priceUSD,';
echo '    priceLBP: priceLBP,';
echo '    warehouseStock: warehouseStock,';
echo '    quantity: 1,';
echo '    discount: 0';
echo '  });';
echo '  renderSelectedProducts();';
echo '  updateSummary();';
echo '  updateProductItemStates();';
echo '}';
echo '';
echo 'function removeProduct(productId) {';
echo '  const index = selectedProducts.findIndex(p => p.id === productId);';
echo '  if (index > -1) {';
echo '    selectedProducts.splice(index, 1);';
echo '    renderSelectedProducts();';
echo '    updateSummary();';
echo '    updateProductItemStates();';
echo '  }';
echo '}';
echo '';
echo 'function updateProduct(productId, field, value) {';
echo '  const product = selectedProducts.find(p => p.id === productId);';
echo '  if (product) {';
echo '    if (field === "quantity") {';
echo '      const qty = parseFloat(value);';
echo '      if (qty > 0) {';
echo '        product.quantity = qty;';
echo '        if (qty > product.warehouseStock) {';
echo '          console.warn("Requested quantity exceeds warehouse stock - order will require approval");';
echo '        }';
echo '      }';
echo '    }';
echo '    renderSelectedProducts();';
echo '    updateSummary();';
echo '  }';
echo '}';
echo '';
echo '// HTML escape function to prevent XSS';
echo 'function escapeHtml(text) {';
echo '  const div = document.createElement("div");';
echo '  div.textContent = text;';
echo '  return div.innerHTML;';
echo '}';
echo '';
echo 'function renderSelectedProducts() {';
echo '  const container = document.getElementById("selectedProductsList");';
echo '  if (selectedProducts.length === 0) {';
echo '    container.innerHTML = \'<p style="color:var(--muted);text-align:center;">No products selected yet. Click on products above to add them.</p>\';';
echo '    return;';
echo '  }';
echo '  let html = "";';
echo '  selectedProducts.forEach(product => {';
echo '    const subtotal = product.priceUSD * product.quantity;';
echo '    const stockWarning = product.quantity > product.warehouseStock ? " (⚠️ Exceeds warehouse stock)" : "";';
echo '    const safeName = escapeHtml(product.name);';
echo '    const safeSku = escapeHtml(product.sku);';
echo '    html += `<div class="selected-product">`;';
echo '    html += `<div class="selected-product-header">`;';
echo '    html += `<div><div class="selected-product-name">${safeName}</div>`;';
echo '    html += `<div style="font-size:0.85rem;color:var(--muted);">SKU: ${safeSku} | Unit: $${product.priceUSD.toFixed(2)} | Warehouse: ${product.warehouseStock}${stockWarning}</div></div>`;';
echo '    html += `<button type="button" class="btn-remove" onclick="removeProduct(${product.id})">Remove</button>`;';
echo '    html += `</div>`;';
echo '    html += `<div class="selected-product-controls">`;';
echo '    html += `<div class="control-group">`;';
echo '    html += `<label>Quantity</label>`;';
echo '    html += `<input type="number" step="0.1" min="0.1" value="${product.quantity}" `;';
echo '    html += `onchange="updateProduct(${product.id}, \'quantity\', this.value)">`;';
echo '    html += `</div>`;';
echo '    html += `</div>`;';
echo '    html += `<div class="selected-product-subtotal">Subtotal: $${subtotal.toFixed(2)}</div>`;';
echo '    html += `<input type="hidden" name="items[${product.id}][product_id]" value="${product.id}">`;';
echo '    html += `<input type="hidden" name="items[${product.id}][quantity]" value="${product.quantity}">`;';
echo '    html += `<input type="hidden" name="items[${product.id}][discount]" value="0">`;';
echo '    html += `</div>`;';
echo '  });';
echo '  container.innerHTML = html;';
echo '}';
echo '';
echo 'function updateSummary() {';
echo '  const summary = document.getElementById("orderSummary");';
echo '  const submitBtn = document.getElementById("submitButton");';
echo '  ';
echo '  if (selectedProducts.length === 0) {';
echo '    summary.style.display = "none";';
echo '    submitBtn.disabled = true;';
echo '    return;';
echo '  }';
echo '  ';
echo '  summary.style.display = "block";';
echo '  submitBtn.disabled = false;';
echo '  ';
echo '  let itemCount = selectedProducts.length;';
echo '  let totalUSD = 0;';
echo '  let totalLBP = 0;';
echo '  ';
echo '  selectedProducts.forEach(product => {';
echo '    totalUSD += product.priceUSD * product.quantity;';
echo '    totalLBP += product.priceLBP * product.quantity;';
echo '  });';
echo '  ';
echo '  document.getElementById("summaryItemCount").textContent = itemCount;';
echo '  document.getElementById("summaryTotalUSD").textContent = "$" + totalUSD.toFixed(2);';
echo '  document.getElementById("summaryTotalLBP").textContent = "L.L. " + totalLBP.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",");';
echo '}';
echo '';
echo 'document.querySelectorAll(".product-item").forEach(item => {';
echo '  item.addEventListener("click", function() {';
echo '    const productId = parseInt(this.dataset.productId);';
echo '    const productName = this.dataset.productName;';
echo '    const sku = this.dataset.productSku;';
echo '    const priceUSD = parseFloat(this.dataset.priceUsd);';
echo '    const priceLBP = parseFloat(this.dataset.priceLbp);';
echo '    const warehouseStock = parseFloat(this.dataset.warehouseStock);';
echo '    addProduct(productId, productName, sku, priceUSD, priceLBP, warehouseStock);';
echo '  });';
echo '});';
echo '';
echo '// Enhanced search functionality with keyboard navigation';
echo 'let currentHighlightIndex = -1;';
echo 'let visibleProducts = [];';
echo '';
echo 'function performSearch() {';
echo '  const searchInput = document.getElementById("productSearch");';
echo '  const clearBtn = document.getElementById("clearSearch");';
echo '  const noResults = document.getElementById("noResults");';
echo '  const search = searchInput.value.toLowerCase().trim();';
echo '';
echo '  visibleProducts = [];';
echo '  currentHighlightIndex = -1;';
echo '';
echo '  // Show/hide clear button';
echo '  clearBtn.style.display = search.length > 0 ? "block" : "none";';
echo '';
echo '  let hasResults = false;';
echo '  document.querySelectorAll(".product-item").forEach(item => {';
echo '    item.classList.remove("highlighted");';
echo '    if (search === "") {';
echo '      item.classList.remove("hidden");';
echo '      hasResults = true;';
echo '      visibleProducts.push(item);';
echo '      return;';
echo '    }';
echo '    const name = item.dataset.productName.toLowerCase();';
echo '    const sku = (item.dataset.productSku || "").toLowerCase();';
echo '    const category = (item.dataset.productCategory || "").toLowerCase();';
echo '    const description = (item.dataset.productDescription || "").toLowerCase();';
echo '    const barcode = (item.dataset.productBarcode || "").toLowerCase();';
echo '    const code = (item.dataset.productCode || "").toLowerCase();';
echo '    if (name.includes(search) || sku.includes(search) || category.includes(search) || description.includes(search) || barcode.includes(search) || code.includes(search)) {';
echo '      item.classList.remove("hidden");';
echo '      visibleProducts.push(item);';
echo '      hasResults = true;';
echo '    } else {';
echo '      item.classList.add("hidden");';
echo '    }';
echo '  });';
echo '  noResults.classList.toggle("visible", !hasResults);';
echo '  if (visibleProducts.length > 0) highlightProduct(0);';
echo '}';
echo '';
echo 'function highlightProduct(index) {';
echo '  visibleProducts.forEach(item => item.classList.remove("highlighted"));';
echo '  if (index >= 0 && index < visibleProducts.length) {';
echo '    currentHighlightIndex = index;';
echo '    visibleProducts[index].classList.add("highlighted");';
echo '    visibleProducts[index].scrollIntoView({ behavior: "smooth", block: "nearest" });';
echo '  }';
echo '}';
echo '';
echo 'function selectHighlightedProduct() {';
echo '  if (currentHighlightIndex >= 0 && currentHighlightIndex < visibleProducts.length) {';
echo '    const item = visibleProducts[currentHighlightIndex];';
echo '    const productId = parseInt(item.dataset.productId);';
echo '    const productName = item.dataset.productName;';
echo '    const sku = item.dataset.productSku;';
echo '    const priceUSD = parseFloat(item.dataset.priceUsd);';
echo '    const priceLBP = parseFloat(item.dataset.priceLbp);';
echo '    const warehouseStock = parseFloat(item.dataset.warehouseStock);';
echo '    addProduct(productId, productName, sku, priceUSD, priceLBP, warehouseStock);';
echo '    document.getElementById("productSearch").value = "";';
echo '    performSearch();';
echo '    document.getElementById("productSearch").focus();';
echo '  }';
echo '}';
echo '';
echo 'function updateProductItemStates() {';
echo '  document.querySelectorAll(".product-item").forEach(item => {';
echo '    const productId = parseInt(item.dataset.productId);';
echo '    item.classList.toggle("selected", !!selectedProducts.find(p => p.id === productId));';
echo '  });';
echo '}';
echo '';
echo 'document.getElementById("productSearch").addEventListener("input", performSearch);';
echo '';
echo 'document.getElementById("productSearch").addEventListener("keydown", function(e) {';
echo '  if (e.key === "ArrowDown") {';
echo '    e.preventDefault();';
echo '    if (currentHighlightIndex < visibleProducts.length - 1) highlightProduct(currentHighlightIndex + 1);';
echo '  } else if (e.key === "ArrowUp") {';
echo '    e.preventDefault();';
echo '    if (currentHighlightIndex > 0) highlightProduct(currentHighlightIndex - 1);';
echo '  } else if (e.key === "Enter") {';
echo '    e.preventDefault();';
echo '    selectHighlightedProduct();';
echo '  } else if (e.key === "Escape") {';
echo '    e.preventDefault();';
echo '    this.value = "";';
echo '    performSearch();';
echo '  }';
echo '});';
echo '';
echo 'document.getElementById("clearSearch").addEventListener("click", function() {';
echo '  document.getElementById("productSearch").value = "";';
echo '  performSearch();';
echo '  document.getElementById("productSearch").focus();';
echo '});';
echo '';
echo '// Customer search functionality';
echo 'let currentCustomerHighlightIndex = -1;';
echo 'let visibleCustomers = [];';
echo '';
echo 'function performCustomerSearch() {';
echo '  const searchInput = document.getElementById("customerSearch");';
echo '  const clearBtn = document.getElementById("clearCustomerSearch");';
echo '  const customerList = document.getElementById("customerList");';
echo '  const search = searchInput.value.toLowerCase().trim();';
echo '';
echo '  visibleCustomers = [];';
echo '  currentCustomerHighlightIndex = -1;';
echo '';
echo '  clearBtn.style.display = search.length > 0 ? "block" : "none";';
echo '';
echo '  customerList.style.display = "block";';
echo '  let hasResults = false;';
echo '';
echo '  document.querySelectorAll(".customer-item").forEach(item => {';
echo '    const name = item.dataset.customerName.toLowerCase();';
echo '    const phone = (item.dataset.customerPhone || "").toLowerCase();';
echo '    const matches = search === "" || name.includes(search) || phone.includes(search);';
echo '';
echo '    item.classList.toggle("hidden", !matches);';
echo '    item.classList.remove("highlighted");';
echo '';
echo '    if (matches) {';
echo '      hasResults = true;';
echo '      visibleCustomers.push(item);';
echo '    }';
echo '  });';
echo '';
echo '  if (visibleCustomers.length > 0) {';
echo '    highlightCustomer(0);';
echo '  }';
echo '}';
echo '';
echo 'function highlightCustomer(index) {';
echo '  visibleCustomers.forEach(item => item.classList.remove("highlighted"));';
echo '  if (index >= 0 && index < visibleCustomers.length) {';
echo '    currentCustomerHighlightIndex = index;';
echo '    visibleCustomers[index].classList.add("highlighted");';
echo '    visibleCustomers[index].scrollIntoView({ behavior: "smooth", block: "nearest" });';
echo '  }';
echo '}';
echo '';
echo 'function selectHighlightedCustomer() {';
echo '  if (currentCustomerHighlightIndex >= 0 && currentCustomerHighlightIndex < visibleCustomers.length) {';
echo '    const item = visibleCustomers[currentCustomerHighlightIndex];';
echo '    selectCustomer(item);';
echo '  }';
echo '}';
echo '';
echo 'function selectCustomer(item) {';
echo '  const customerId = item.dataset.customerId;';
echo '  const customerName = item.dataset.customerName;';
echo '  const customerPhone = item.dataset.customerPhone || "";';
echo '  const customerCity = item.dataset.customerCity || "";';
echo '';
echo '  document.getElementById("selectedCustomerId").value = customerId;';
echo '  document.getElementById("customerSearch").value = customerName;';
echo '  document.getElementById("customerList").style.display = "none";';
echo '';
echo '  let displayHtml = "<div class=\"selected-customer-name\">" + customerName + "</div>";';
echo '  displayHtml += "<div class=\"selected-customer-info\">";';
echo '  if (customerPhone) displayHtml += "Phone: " + customerPhone;';
echo '  if (customerPhone && customerCity) displayHtml += " | ";';
echo '  if (customerCity) displayHtml += "City: " + customerCity;';
echo '  displayHtml += "</div>";';
echo '';
echo '  const displayDiv = document.getElementById("selectedCustomerDisplay");';
echo '  displayDiv.innerHTML = displayHtml;';
echo '  displayDiv.style.display = "block";';
echo '}';
echo '';
echo 'document.getElementById("customerSearch").addEventListener("input", performCustomerSearch);';
echo '';
echo 'document.getElementById("customerSearch").addEventListener("focus", function() {';
echo '  performCustomerSearch();';
echo '});';
echo '';
echo 'document.getElementById("customerSearch").addEventListener("keydown", function(e) {';
echo '  if (e.key === "ArrowDown") {';
echo '    e.preventDefault();';
echo '    if (currentCustomerHighlightIndex < visibleCustomers.length - 1) highlightCustomer(currentCustomerHighlightIndex + 1);';
echo '  } else if (e.key === "ArrowUp") {';
echo '    e.preventDefault();';
echo '    if (currentCustomerHighlightIndex > 0) highlightCustomer(currentCustomerHighlightIndex - 1);';
echo '  } else if (e.key === "Enter") {';
echo '    e.preventDefault();';
echo '    selectHighlightedCustomer();';
echo '  } else if (e.key === "Escape") {';
echo '    e.preventDefault();';
echo '    this.value = "";';
echo '    document.getElementById("customerList").style.display = "none";';
echo '    document.getElementById("clearCustomerSearch").style.display = "none";';
echo '  }';
echo '});';
echo '';
echo 'document.getElementById("clearCustomerSearch").addEventListener("click", function() {';
echo '  document.getElementById("customerSearch").value = "";';
echo '  document.getElementById("selectedCustomerId").value = "";';
echo '  document.getElementById("customerList").style.display = "none";';
echo '  document.getElementById("selectedCustomerDisplay").style.display = "none";';
echo '  this.style.display = "none";';
echo '  document.getElementById("customerSearch").focus();';
echo '});';
echo '';
echo 'document.querySelectorAll(".customer-item").forEach(item => {';
echo '  item.addEventListener("click", function() {';
echo '    selectCustomer(this);';
echo '  });';
echo '});';
echo '';
echo '// Hide customer list when clicking outside';
echo 'document.addEventListener("click", function(e) {';
echo '  const customerSearch = document.getElementById("customerSearch");';
echo '  const customerList = document.getElementById("customerList");';
echo '  const clearBtn = document.getElementById("clearCustomerSearch");';
echo '  if (e.target !== customerSearch && e.target !== clearBtn && !customerList.contains(e.target)) {';
echo '    customerList.style.display = "none";';
echo '  }';
echo '});';
echo '';
echo '// Focus customer search on page load';
echo 'document.getElementById("customerSearch").focus();';
echo '</script>';

sales_portal_render_layout_end();
