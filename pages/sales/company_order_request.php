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
$wantsJson = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (!empty($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false);


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
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid or expired CSRF token. Please try again.',
            ]);
            exit;
        }
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } elseif (!$canCreateOrder) {
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'message' => 'Exchange rate is unavailable. Please refresh the page and try again.',
            ]);
            exit;
        }
        $flashes[] = [
            'type' => 'error',
            'title' => 'Cannot Create Order',
            'message' => 'Exchange rate is unavailable. Please refresh the page and try again.',
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
                            p.wholesale_price_usd,
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

                    $unitPriceUSD = (float)$product['wholesale_price_usd'];
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
                    if ($wantsJson) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'ok' => false,
                            'message' => 'Unable to create order. Please fix the errors below.',
                            'errors' => $errors,
                        ]);
                        exit;
                    }
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

                    if ($wantsJson) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'ok' => true,
                            'message' => "Order request {$orderNumber} has been successfully submitted and is pending approval.",
                            'order_number' => $orderNumber,
                        ]);
                        exit;
                    }

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
                if ($wantsJson) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => false,
                        'message' => 'Unable to create order request. Please try again.',
                    ]);
                    exit;
                }
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to create order request. Please try again.',
                    'dismissible' => true,
                ];
            }
        } else {
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'message' => 'Unable to create order. Please fix the errors below.',
                    'errors' => $errors,
                ]);
                exit;
            }
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
        p.topcat_name as category,
        p.description,
        p.barcode,
        p.code_clean,
        p.wholesale_price_usd,
        p.quantity_on_hand
    FROM products p
    WHERE p.is_active = 1
    ORDER BY p.topcat_name, p.item_name
");
$productsStmt->execute();
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique categories for filter
$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'طلب من الشركة',
    'heading' => 'إنشاء طلب من الشركة',
    'subtitle' => 'اضغط على المنتجات لإضافتها إلى طلبك',
    'active' => 'orders_request',
    'user' => $user,
    'extra_head' => '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"><style>
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-bar select,
        .filter-bar input {
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            min-width: 150px;
        }
        .filter-bar select:focus,
        .filter-bar input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 16px;
            padding-bottom: 100px;
        }
        .product-card {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.2s;
            position: relative;
        }
        .product-card:hover {
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        .product-card.in-cart {
            border-color: #22c55e;
            background: #dcfce7;
        }
        .product-card.in-cart::after {
            content: "\2713";
            position: absolute;
            top: -8px;
            right: -8px;
            background: #22c55e;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
        }
        .product-card img {
            width: 100%;
            height: 130px;
            object-fit: cover;
            border-radius: 10px;
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        }
        .product-sku {
            font-size: 0.75rem;
            color: var(--muted);
            margin-top: 8px;
            font-family: monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
        }
        .product-name {
            font-weight: 600;
            font-size: 0.9rem;
            margin: 6px 0 6px;
            color: var(--text);
            min-height: 40px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .product-price {
            font-weight: 800;
            font-size: 1.2rem;
            color: var(--accent);
        }
        .product-stock {
            font-size: 0.8rem;
            color: var(--muted);
            margin-top: 4px;
        }
        .product-stock.low {
            color: #f59e0b;
            font-weight: 600;
        }
        .product-stock.out {
            color: #dc2626;
            font-weight: 600;
        }
        .product-controls {
            display: none;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        .product-card.in-cart .product-controls {
            display: flex;
        }
        .product-qty-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 2px solid var(--border);
            background: white;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .product-qty-btn:hover:not(:disabled) {
            border-color: var(--accent);
            background: var(--accent);
            color: white;
        }
        .product-qty-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .product-qty-btn.minus {
            border-color: #dc2626;
            color: #dc2626;
        }
        .product-qty-btn.minus:hover:not(:disabled) {
            background: #dc2626;
            color: white;
        }
        .product-qty-display {
            font-weight: 700;
            font-size: 1.1rem;
            min-width: 30px;
            text-align: center;
        }
        .cart-button {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            padding: 18px 28px;
            border-radius: 50px;
            box-shadow: 0 6px 25px rgba(99, 102, 241, 0.4);
            z-index: 1000;
            cursor: pointer;
            border: none;
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .cart-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 35px rgba(99, 102, 241, 0.5);
        }
        .cart-button.empty {
            background: #9ca3af;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .cart-badge {
            background: white;
            color: #6366f1;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }
        .cart-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-radius: 24px 24px 0 0;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(100%);
            transition: transform 0.3s ease-out;
            z-index: 1001;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.2);
        }
        .cart-panel.open {
            transform: translateY(0);
        }
        .cart-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        .cart-overlay.open {
            opacity: 1;
            visibility: visible;
        }
        .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        .cart-header h2 {
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cart-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: var(--muted);
            padding: 0;
        }
        .cart-items {
            padding: 16px 24px;
            max-height: 35vh;
            overflow-y: auto;
        }
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid var(--border);
            gap: 12px;
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
            margin-bottom: 4px;
        }
        .cart-item-sku {
            font-size: 0.75rem;
            color: var(--muted);
            font-family: monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 4px;
        }
        .cart-item-price {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .cart-item-qty {
            font-size: 0.9rem;
            color: #6366f1;
            font-weight: 700;
            margin-bottom: 4px;
            background: #e0e7ff;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .qty-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 2px solid var(--border);
            background: white;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qty-btn:hover {
            border-color: #6366f1;
            background: #6366f1;
            color: white;
        }
        .qty-btn.remove {
            border-color: #dc2626;
            color: #dc2626;
        }
        .qty-btn.remove:hover {
            background: #dc2626;
            color: white;
        }
        .qty-value {
            font-weight: 700;
            font-size: 1.1rem;
            min-width: 30px;
            text-align: center;
        }
        .cart-item-subtotal {
            font-weight: 700;
            color: #6366f1;
            min-width: 70px;
            text-align: right;
        }
        .cart-customer {
            padding: 20px 24px;
            background: #f8fafc;
            border-top: 1px solid var(--border);
        }
        .cart-customer label {
            display: block;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        .customer-search-wrapper {
            position: relative;
        }
        .customer-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 1rem;
        }
        .customer-input:focus {
            outline: none;
            border-color: #6366f1;
        }
        .customer-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #6366f1;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }
        .customer-dropdown.show {
            display: block;
        }
        .customer-option {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
        }
        .customer-option:hover {
            background: #eef2ff;
        }
        .customer-option strong {
            display: block;
            margin-bottom: 2px;
        }
        .customer-option small {
            color: var(--muted);
        }
        .customer-selected {
            background: #e0e7ff;
            border: 2px solid #6366f1;
            padding: 12px 16px;
            border-radius: 10px;
            margin-top: 10px;
            display: none;
        }
        .customer-selected.show {
            display: block;
        }
        .customer-selected strong {
            color: #3730a3;
        }
        .cart-notes {
            padding: 0 24px 20px;
        }
        .cart-notes label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: var(--muted);
        }
        .cart-notes textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 1rem;
            resize: none;
            height: 70px;
        }
        .cart-totals {
            padding: 20px 24px;
            background: #eef2ff;
            border-top: 2px solid #6366f1;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
        .total-row.grand {
            font-size: 1.4rem;
            font-weight: 800;
            color: #000;
            padding-top: 10px;
            border-top: 2px solid #6366f1;
            margin-top: 10px;
        }
        .cart-submit {
            padding: 20px 24px;
            background: white;
            border-top: 1px solid var(--border);
        }
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1.3rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
        }
        .btn-submit:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 16px;
        }
        .flash-stack {
            margin-bottom: 24px;
        }
        .flash {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 12px;
            border-left: 4px solid;
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
        .alert-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.9rem;
        }
        @media (max-width: 600px) {
            .product-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .product-card img {
                height: 100px;
            }
            .cart-button {
                bottom: 15px;
                right: 15px;
                padding: 14px 20px;
                font-size: 1rem;
            }
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
?>
    <div class="alert-warning">
        <strong>Note:</strong> This creates an order request that will be fulfilled from warehouse stock.
        The order will be placed on hold pending approval. Orders are fulfilled by the warehouse team, not from your van stock.
    </div>
    <div id="ajaxFlash"></div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <select id="categoryFilter">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="searchFilter" placeholder="Search products...">
    </div>

    <!-- Stock Filter Checkboxes -->
    <div class="filter-checkboxes" style="display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; background: var(--bg-panel); padding: 14px 18px; border-radius: 10px; border: 1px solid var(--border);">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.95rem; font-weight: 500;">
            <input type="checkbox" id="hideZeroStock" style="width: 18px; height: 18px; cursor: pointer;">
            <span>إخفاء المخزون = 0</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.95rem; font-weight: 500;">
            <input type="checkbox" id="hideZeroPrice" style="width: 18px; height: 18px; cursor: pointer;">
            <span>إخفاء السعر = 0</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.95rem; font-weight: 500;">
            <input type="checkbox" id="hideLowStock" style="width: 18px; height: 18px; cursor: pointer;">
            <span>إخفاء المخزون < 8</span>
        </label>
    </div>

    <!-- Product Grid -->
    <div class="product-grid" id="productGrid">
        <?php foreach ($products as $product):
            $sku = $product['sku'] ?? '';
            $itemName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
            $category = $product['category'] ?? '';
            $priceUSD = (float)$product['wholesale_price_usd'];
            $stock = (float)$product['quantity_on_hand'];

            // Stock status
            $stockClass = '';
            if ($stock <= 0) {
                $stockClass = 'out';
            } elseif ($stock <= 10) {
                $stockClass = 'low';
            }

            // Find product image
            $imageExists = false;
            $imagePath = '../../images/products/default.jpg';
            $defaultImagePath = __DIR__ . '/../../images/products/default.jpg';
            $imageVersion = file_exists($defaultImagePath) ? (string)filemtime($defaultImagePath) : null;
            $possibleExtensions = ['webp', 'jpg', 'jpeg', 'png', 'gif'];

            if ($sku) {
                foreach ($possibleExtensions as $ext) {
                    $serverPath = __DIR__ . '/../../images/products/' . $sku . '.' . $ext;
                    if (file_exists($serverPath)) {
                        $imagePath = '../../images/products/' . $sku . '.' . $ext;
                        $imageVersion = (string)filemtime($serverPath);
                        $imageExists = true;
                        break;
                    }
                }
            }
            $imageSrc = $imagePath . ($imageVersion ? '?v=' . rawurlencode($imageVersion) : '');
            $fallbackSrc = '../../images/products/default.jpg' . ($imageVersion ? '?v=' . rawurlencode($imageVersion) : '');
        ?>
            <div class="product-card"
                 data-id="<?= $product['id'] ?>"
                 data-name="<?= $itemName ?>"
                 data-sku="<?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?>"
                 data-price="<?= $priceUSD ?>"
                 data-stock="<?= $stock ?>"
                 data-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= $itemName ?>"
                     loading="lazy"
                     class="lazy-image"
                     onload="this.classList.add('is-loaded')"
                     onerror="this.src='<?= htmlspecialchars($fallbackSrc, ENT_QUOTES, 'UTF-8') ?>'">
                <div class="product-sku"><?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="product-name"><?= $itemName ?></div>
                <div class="product-price">$<?= number_format($priceUSD, 2) ?></div>
                <div class="product-stock <?= $stockClass ?>">
                    Warehouse: <?= number_format($stock, 1) ?>
                </div>
                <div class="product-controls" id="controls-<?= $product['id'] ?>" onclick="event.stopPropagation();">
                    <button type="button" class="product-qty-btn minus" onclick="decreaseQtyFromCard(<?= $product['id'] ?>)">−</button>
                    <span class="product-qty-display" id="qty-display-<?= $product['id'] ?>">0</span>
                    <button type="button" class="product-qty-btn plus" onclick="increaseQtyFromCard(<?= $product['id'] ?>)" id="plus-btn-<?= $product['id'] ?>">+</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Floating Cart Button -->
    <button class="cart-button empty" id="cartButton" onclick="openCart()">
        <span>📦</span>
        <span id="cartTotal">$0.00</span>
        <span class="cart-badge" id="cartBadge">0</span>
    </button>

    <!-- Cart Overlay -->
    <div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>

    <!-- Cart Panel -->
    <div class="cart-panel" id="cartPanel">
        <div class="cart-header">
            <h2>📦 Order Request</h2>
            <button class="cart-close" onclick="closeCart()">&times;</button>
        </div>

        <form method="POST" action="" id="cartForm">
            <input type="hidden" name="action" value="create_order">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="customer_id" id="customerId" value="">

            <div class="cart-items" id="cartItems">
                <div class="empty-state" id="emptyCart">
                    <div class="empty-state-icon">📦</div>
                    <p>No products selected. Tap products to add them.</p>
                </div>
            </div>

            <div class="cart-customer">
                <label>Select Customer</label>
                <div class="customer-search-wrapper">
                    <input type="text"
                           id="customerSearch"
                           class="customer-input"
                           placeholder="Search by name or phone..."
                           autocomplete="off">
                    <div id="customerDropdown" class="customer-dropdown">
                        <?php foreach ($customers as $customer): ?>
                            <div class="customer-option"
                                 onclick="selectCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars(addslashes($customer['name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($customer['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($customer['location'] ?? ''), ENT_QUOTES, 'UTF-8') ?>')">
                                <strong><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <small><?= htmlspecialchars($customer['phone'] ?? 'No phone', ENT_QUOTES, 'UTF-8') ?><?= $customer['location'] ? ' | ' . htmlspecialchars($customer['location'], ENT_QUOTES, 'UTF-8') : '' ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="customerSelected" class="customer-selected">
                    <strong id="selectedCustomerName"></strong>
                    <div id="selectedCustomerInfo" style="font-size: 0.85rem; color: #4338ca;"></div>
                </div>
            </div>

            <div class="cart-notes">
                <label>Notes & Delivery Instructions (Optional)</label>
                <textarea name="notes" id="orderNotes" placeholder="Add any notes for the warehouse team..."></textarea>
            </div>

            <div class="cart-totals">
                <div class="total-row">
                    <span>Items:</span>
                    <span id="totalItems">0</span>
                </div>
                <div class="total-row grand">
                    <span>Total USD:</span>
                    <span id="totalUSD">$0.00</span>
                </div>
                <div class="total-row">
                    <span>Total LBP:</span>
                    <span id="totalLBP">L.L. 0</span>
                </div>
            </div>

            <div class="cart-submit">
                <button type="submit" class="btn-submit" id="submitBtn" disabled>
                    Submit Order Request
                </button>
            </div>

            <!-- Hidden cart items inputs -->
            <div id="cartInputs"></div>
        </form>
    </div>

    <script>
        const exchangeRate = <?= json_encode($exchangeRate ?? 0, JSON_UNESCAPED_UNICODE) ?>;
        let cart = {};

        // Filter products
        function filterProducts() {
            const category = document.getElementById('categoryFilter').value.toLowerCase();
            const search = document.getElementById('searchFilter').value.toLowerCase();
            const hideZeroStock = document.getElementById('hideZeroStock').checked;
            const hideZeroPrice = document.getElementById('hideZeroPrice').checked;
            const hideLowStock = document.getElementById('hideLowStock').checked;

            document.querySelectorAll('.product-card').forEach(card => {
                const cardCategory = (card.dataset.category || '').toLowerCase();
                const cardName = (card.dataset.name || '').toLowerCase();
                const cardSku = (card.dataset.sku || '').toLowerCase();
                const cardStock = parseFloat(card.dataset.stock) || 0;
                const cardPrice = parseFloat(card.dataset.price) || 0;

                const matchesCategory = !category || cardCategory === category;
                const matchesSearch = !search || cardName.includes(search) || cardSku.includes(search);

                // Stock/Price filters
                const passZeroStock = !hideZeroStock || cardStock > 0;
                const passZeroPrice = !hideZeroPrice || cardPrice > 0;
                const passLowStock = !hideLowStock || cardStock >= 8;

                const visible = matchesCategory && matchesSearch && passZeroStock && passZeroPrice && passLowStock;
                card.style.display = visible ? 'block' : 'none';
            });
        }

        // Save filter preferences to localStorage
        function saveFilterPrefs() {
            localStorage.setItem('companyOrderFilters', JSON.stringify({
                hideZeroStock: document.getElementById('hideZeroStock').checked,
                hideZeroPrice: document.getElementById('hideZeroPrice').checked,
                hideLowStock: document.getElementById('hideLowStock').checked
            }));
        }

        // Load filter preferences from localStorage
        function loadFilterPrefs() {
            const saved = localStorage.getItem('companyOrderFilters');
            if (saved) {
                try {
                    const prefs = JSON.parse(saved);
                    if (prefs.hideZeroStock !== undefined) {
                        document.getElementById('hideZeroStock').checked = prefs.hideZeroStock;
                    }
                    if (prefs.hideZeroPrice !== undefined) {
                        document.getElementById('hideZeroPrice').checked = prefs.hideZeroPrice;
                    }
                    if (prefs.hideLowStock !== undefined) {
                        document.getElementById('hideLowStock').checked = prefs.hideLowStock;
                    }
                    filterProducts();
                } catch (e) {
                    console.error('Error loading filter preferences:', e);
                }
            }
        }

        // Load preferences on page load and attach event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Attach filter event listeners (avoids inline handlers that may fire before script loads)
            document.getElementById('categoryFilter').addEventListener('change', filterProducts);
            document.getElementById('searchFilter').addEventListener('input', filterProducts);
            document.getElementById('hideZeroStock').addEventListener('change', function() {
                filterProducts();
                saveFilterPrefs();
            });
            document.getElementById('hideZeroPrice').addEventListener('change', function() {
                filterProducts();
                saveFilterPrefs();
            });
            document.getElementById('hideLowStock').addEventListener('change', function() {
                filterProducts();
                saveFilterPrefs();
            });

            // Load saved preferences
            loadFilterPrefs();
        });

        // Toggle item selection (select/deselect)
        function toggleSelectItem(cardElement) {
            const id = cardElement.dataset.id;
            const name = cardElement.dataset.name;
            const sku = cardElement.dataset.sku;
            const price = parseFloat(cardElement.dataset.price);
            const stock = parseFloat(cardElement.dataset.stock);

            if (cart[id]) {
                // Already in cart - remove it (deselect)
                delete cart[id];
            } else {
                // Not in cart - add with quantity 1 (select)
                cart[id] = { name, sku, price, stock, quantity: 1 };
            }

            updateCartDisplay();
            updateProductCards();
        }

        // Increase quantity from product card controls
        function increaseQtyFromCard(id) {
            if (cart[id]) {
                cart[id].quantity++;
                updateCartDisplay();
                updateProductCards();
            }
        }

        // Decrease quantity from product card controls
        function decreaseQtyFromCard(id) {
            if (cart[id]) {
                cart[id].quantity--;
                if (cart[id].quantity <= 0) {
                    delete cart[id];
                }
                updateCartDisplay();
                updateProductCards();
            }
        }

        // Update product cards to show in-cart state
        function updateProductCards() {
            document.querySelectorAll('.product-card').forEach(card => {
                const id = card.dataset.id;
                const qtyDisplay = document.getElementById('qty-display-' + id);
                const plusBtn = document.getElementById('plus-btn-' + id);

                if (cart[id]) {
                    card.classList.add('in-cart');
                    card.dataset.cartQty = cart[id].quantity;
                    if (qtyDisplay) qtyDisplay.textContent = cart[id].quantity;
                    // No stock limit for company orders (warehouse fulfillment)
                    if (plusBtn) plusBtn.disabled = false;
                } else {
                    card.classList.remove('in-cart');
                    delete card.dataset.cartQty;
                    if (qtyDisplay) qtyDisplay.textContent = '0';
                }
            });
        }

        // Update cart display
        function updateCartDisplay() {
            const cartItems = document.getElementById('cartItems');
            const cartInputs = document.getElementById('cartInputs');
            const cartButton = document.getElementById('cartButton');
            const cartBadge = document.getElementById('cartBadge');
            const cartTotal = document.getElementById('cartTotal');
            const submitBtn = document.getElementById('submitBtn');

            const keys = Object.keys(cart);

            if (keys.length === 0) {
                cartItems.innerHTML = `
                    <div class="empty-state" id="emptyCart">
                        <div class="empty-state-icon">📦</div>
                        <p>No products selected. Tap products to add them.</p>
                    </div>
                `;
                cartInputs.innerHTML = '';
                cartButton.classList.add('empty');
                cartBadge.textContent = '0';
                cartTotal.textContent = '$0.00';
                submitBtn.disabled = true;
                document.getElementById('totalItems').textContent = '0';
                document.getElementById('totalUSD').textContent = '$0.00';
                document.getElementById('totalLBP').textContent = 'L.L. 0';
                return;
            }

            cartButton.classList.remove('empty');

            let totalQty = 0;
            let totalUSD = 0;
            let itemsHtml = '';
            let inputsHtml = '';

            keys.forEach(id => {
                const item = cart[id];
                const subtotal = item.price * item.quantity;
                totalQty += item.quantity;
                totalUSD += subtotal;

                const stockWarning = item.quantity > item.stock ? ' (Exceeds warehouse stock)' : '';

                itemsHtml += `
                    <div class="cart-item">
                        <div class="cart-item-info">
                            <div class="cart-item-sku">${escapeHtml(item.sku || '')}</div>
                            <div class="cart-item-name">${escapeHtml(item.name)}</div>
                            <div class="cart-item-qty">Qty: ${item.quantity} / Warehouse: ${item.stock}${stockWarning}</div>
                            <div class="cart-item-price">$${item.price.toFixed(2)} x ${item.quantity} = $${subtotal.toFixed(2)}</div>
                        </div>
                        <div class="cart-item-controls">
                            <button type="button" class="qty-btn remove" onclick="removeFromCart(${id})">−</button>
                            <span class="qty-value">${item.quantity}</span>
                            <button type="button" class="qty-btn" onclick="increaseQty(${id})">+</button>
                        </div>
                        <div class="cart-item-subtotal">$${subtotal.toFixed(2)}</div>
                    </div>
                `;

                inputsHtml += `
                    <input type="hidden" name="items[${id}][product_id]" value="${id}">
                    <input type="hidden" name="items[${id}][quantity]" value="${item.quantity}">
                `;
            });

            cartItems.innerHTML = itemsHtml;
            cartInputs.innerHTML = inputsHtml;

            cartBadge.textContent = keys.length;
            cartTotal.textContent = '$' + totalUSD.toFixed(2);

            document.getElementById('totalItems').textContent = totalQty;
            document.getElementById('totalUSD').textContent = '$' + totalUSD.toFixed(2);
            document.getElementById('totalLBP').textContent = 'L.L. ' + Math.round(totalUSD * exchangeRate).toLocaleString();

            // Enable submit only if customer is selected
            const customerId = document.getElementById('customerId').value;
            submitBtn.disabled = !customerId;
        }

        // Remove from cart
        function removeFromCart(id) {
            if (cart[id]) {
                cart[id].quantity--;
                if (cart[id].quantity <= 0) {
                    delete cart[id];
                }
            }
            updateCartDisplay();
            updateProductCards();
        }

        // Increase quantity
        function increaseQty(id) {
            if (cart[id]) {
                cart[id].quantity++;
                updateCartDisplay();
                updateProductCards();
            }
        }

        // Open/close cart
        function openCart() {
            document.getElementById('cartPanel').classList.add('open');
            document.getElementById('cartOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeCart() {
            document.getElementById('cartPanel').classList.remove('open');
            document.getElementById('cartOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }

        // Customer search and selection
        const customerSearch = document.getElementById('customerSearch');
        const customerDropdown = document.getElementById('customerDropdown');
        const customerSelected = document.getElementById('customerSelected');
        const customerIdInput = document.getElementById('customerId');

        customerSearch.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const options = customerDropdown.querySelectorAll('.customer-option');
            let hasVisible = false;

            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                if (query === '' || text.includes(query)) {
                    option.style.display = 'block';
                    hasVisible = true;
                } else {
                    option.style.display = 'none';
                }
            });

            customerDropdown.classList.toggle('show', hasVisible && query.length > 0);
        });

        customerSearch.addEventListener('focus', function() {
            if (this.value.length > 0) {
                this.dispatchEvent(new Event('input'));
            }
        });

        function selectCustomer(id, name, phone, city) {
            customerIdInput.value = id;
            customerSearch.value = name;
            customerDropdown.classList.remove('show');

            document.getElementById('selectedCustomerName').textContent = name;
            document.getElementById('selectedCustomerInfo').textContent = `${phone || 'No phone'}${city ? ' | ' + city : ''}`;
            customerSelected.classList.add('show');

            // Enable submit if cart has items
            if (Object.keys(cart).length > 0) {
                document.getElementById('submitBtn').disabled = false;
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!customerSearch.contains(e.target) && !customerDropdown.contains(e.target)) {
                customerDropdown.classList.remove('show');
            }
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showAjaxFlash(type, title, message, list) {
            const host = document.getElementById('ajaxFlash');
            const safeTitle = title || (type === 'success' ? 'Success' : 'Error');
            let html = `<div class="flash flash-${type}">`;
            html += `<div class="flash-title">${safeTitle}</div>`;
            if (message) html += `<div>${message}</div>`;
            if (Array.isArray(list) && list.length > 0) {
                html += '<ul class="flash-list">';
                list.forEach(item => {
                    html += `<li>${item}</li>`;
                });
                html += '</ul>';
            }
            html += '</div>';
            if (host) {
                host.innerHTML = html;
                host.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                alert(message || safeTitle);
            }
        }

        // Form validation + AJAX submit
        document.getElementById('cartForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const customerId = document.getElementById('customerId').value;
            const itemCount = Object.keys(cart).length;
            const submitBtn = document.getElementById('submitBtn');

            if (!customerId) {
                showAjaxFlash('error', 'Missing Customer', 'Please select a customer.');
                return false;
            }

            if (itemCount === 0) {
                showAjaxFlash('error', 'Empty Order', 'Please add at least one product.');
                return false;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';

            try {
                const formData = new FormData(this);
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
                    showAjaxFlash('error', 'Order Failed', payload.message || 'Unable to create order.', payload.errors || []);
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Order Request';
                    return;
                }

                showAjaxFlash('success', 'Order Submitted', payload.message || 'Order request submitted.');
                cart = {};
                updateCartDisplay();
                updateProductCards();
                closeCart();
                document.getElementById('customerId').value = '';
                document.getElementById('customerSearch').value = '';
                document.getElementById('customerSelected').classList.remove('show');
                document.getElementById('selectedCustomerName').textContent = '';
                document.getElementById('selectedCustomerInfo').textContent = '';
                const notes = document.getElementById('orderNotes');
                if (notes) notes.value = '';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submit Order Request';
            } catch (err) {
                showAjaxFlash('error', 'Network Error', 'Unable to submit the order. Please try again.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Order Request';
            }
        });

        // Add click listeners to all product cards
        document.querySelectorAll('.product-card').forEach(card => {
            card.addEventListener('click', function(e) {
                // Don't trigger if clicking on controls
                if (e.target.closest('.product-controls')) {
                    return;
                }
                toggleSelectItem(this);
            });
        });
    </script>
<?php
}

sales_portal_render_layout_end();
