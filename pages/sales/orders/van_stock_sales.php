<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/guard.php';
require_once __DIR__ . '/../../../includes/sales_portal.php';
require_once __DIR__ . '/../../../includes/counter.php';
require_once __DIR__ . '/../../../includes/audit.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Get active exchange rate
$exchangeRate = 89500.0; // Default fallback
$exchangeRateId = null;
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
    }
} catch (PDOException $e) {
    error_log("Failed to fetch exchange rate: " . $e->getMessage());
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
                    'discount' => $discount,
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
                $orderNumber = generate_order_number($pdo);

                // Calculate totals and verify stock availability
                $totalUSD = 0;
                $totalLBP = 0;
                $itemsWithPrices = [];

                foreach ($validatedItems as $item) {
                    // Get product price and verify van stock
                    $productStmt = $pdo->prepare("
                        SELECT
                            p.sale_price_usd,
                            p.item_name,
                            s.qty_on_hand
                        FROM products p
                        LEFT JOIN s_stock s ON s.product_id = p.id AND s.salesperson_id = :rep_id
                        WHERE p.id = :product_id AND p.is_active = 1
                    ");
                    $productStmt->execute([
                        ':product_id' => $item['product_id'],
                        ':rep_id' => $repId,
                    ]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        $errors[] = "Product ID {$item['product_id']} not found or inactive.";
                        break;
                    }

                    $vanStock = (float)($product['qty_on_hand'] ?? 0);
                    if ($vanStock < $item['quantity']) {
                        $errors[] = "Insufficient van stock for {$product['item_name']}. Available: {$vanStock}, Requested: {$item['quantity']}.";
                        break;
                    }

                    $unitPriceUSD = (float)$product['sale_price_usd'];
                    $unitPriceLBP = $unitPriceUSD * $exchangeRate; // Calculate LBP from USD

                    // Apply discount
                    $discountMultiplier = 1 - ($item['discount'] / 100);
                    $lineUSD = $unitPriceUSD * $item['quantity'] * $discountMultiplier;
                    $lineLBP = $unitPriceLBP * $item['quantity'] * $discountMultiplier;

                    $totalUSD += $lineUSD;
                    $totalLBP += $lineLBP;

                    $itemsWithPrices[] = [
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price_usd' => $unitPriceUSD,
                        'unit_price_lbp' => $unitPriceLBP,
                        'discount_percent' => $item['discount'],
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
                            total_usd, total_lbp, notes, created_at, updated_at
                        ) VALUES (
                            :order_number, 'van_stock_sale', 'delivered', :customer_id, :sales_rep_id, :exchange_rate_id,
                            :total_usd, :total_lbp, :notes, NOW(), NOW()
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

                    // Audit log: order created
                    audit_log($pdo, $repId, 'order_created_van_stock', 'orders', $orderId, [
                        'order_number' => $orderNumber,
                        'customer_id' => $customerId,
                        'total_usd' => $totalUSD,
                        'total_lbp' => $totalLBP,
                        'item_count' => count($itemsWithPrices)
                    ]);

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

                    // Create initial order status
                    $statusStmt = $pdo->prepare("
                        INSERT INTO order_status_events (order_id, status, actor_user_id, note, created_at)
                        VALUES (:order_id, 'delivered', :actor_id, 'Van stock sale - delivered on-site', NOW())
                    ");
                    $statusStmt->execute([
                        ':order_id' => $orderId,
                        ':actor_id' => $repId,
                    ]);

                    // Deduct from van stock and log movements
                    $stockUpdateStmt = $pdo->prepare("
                        UPDATE s_stock
                        SET qty_on_hand = qty_on_hand - :quantity, updated_at = NOW()
                        WHERE salesperson_id = :rep_id AND product_id = :product_id
                    ");

                    $movementStmt = $pdo->prepare("
                        INSERT INTO s_stock_movements (
                            salesperson_id, product_id, delta_qty, reason, order_item_id, note, created_at
                        ) VALUES (
                            :rep_id, :product_id, :delta_qty, 'sale', :order_item_id, :note, NOW()
                        )
                    ");

                    $orderItemsStmt = $pdo->prepare("SELECT id, product_id, quantity FROM order_items WHERE order_id = :order_id");
                    $orderItemsStmt->execute([':order_id' => $orderId]);
                    $orderItems = $orderItemsStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($orderItems as $orderItem) {
                        $stockUpdateStmt->execute([
                            ':quantity' => $orderItem['quantity'],
                            ':rep_id' => $repId,
                            ':product_id' => $orderItem['product_id'],
                        ]);

                        $movementStmt->execute([
                            ':rep_id' => $repId,
                            ':product_id' => $orderItem['product_id'],
                            ':delta_qty' => -1 * (float)$orderItem['quantity'],
                            ':order_item_id' => $orderItem['id'],
                            ':note' => "Van sale - Order {$orderNumber}",
                        ]);
                    }

                    // Create invoice for the order
                    // Generate invoice number atomically (race-condition safe)
                    $invoiceNumber = generate_invoice_number($pdo);

                    // Create invoice with 'issued' status since it's already delivered
                    $invoiceStmt = $pdo->prepare("
                        INSERT INTO invoices (
                            invoice_number, order_id, status, total_usd, total_lbp,
                            issued_at, created_at, updated_at
                        ) VALUES (
                            :invoice_number, :order_id, 'issued', :total_usd, :total_lbp,
                            NOW(), NOW(), NOW()
                        )
                    ");
                    $invoiceStmt->execute([
                        ':invoice_number' => $invoiceNumber,
                        ':order_id' => $orderId,
                        ':total_usd' => $totalUSD,
                        ':total_lbp' => $totalLBP,
                    ]);
                    $invoiceId = (int)$pdo->lastInsertId();

                    // Process payment if provided
                    $paidAmountUsd = (float)($_POST['paid_amount_usd'] ?? 0);
                    $paidAmountLbp = (float)($_POST['paid_amount_lbp'] ?? 0);
                    $paymentMethod = $_POST['payment_method'] ?? 'cash';

                    if ($paidAmountUsd > 0 || $paidAmountLbp > 0) {
                        // Validate payment doesn't exceed invoice total
                        if ($paidAmountUsd > $totalUSD + 0.01 || $paidAmountLbp > $totalLBP + 0.01) {
                            $pdo->rollBack();
                            $flashes[] = [
                                'type' => 'error',
                                'title' => 'Payment Error',
                                'message' => 'Payment amount cannot exceed invoice total.',
                                'dismissible' => true,
                            ];
                        } else {
                            // Record payment
                            $paymentStmt = $pdo->prepare("
                                INSERT INTO payments (
                                    invoice_id, method, amount_usd, amount_lbp,
                                    received_by_user_id, received_at
                                ) VALUES (
                                    :invoice_id, :method, :amount_usd, :amount_lbp,
                                    :user_id, NOW()
                                )
                            ");
                            $paymentStmt->execute([
                                ':invoice_id' => $invoiceId,
                                ':method' => $paymentMethod,
                                ':amount_usd' => $paidAmountUsd,
                                ':amount_lbp' => $paidAmountLbp,
                                ':user_id' => $repId,
                            ]);

                            // Update invoice status to 'paid' if fully paid
                            // Invoice is paid if EITHER USD is fully paid OR LBP is fully paid (they represent the same debt)
                            $isFullyPaidUsd = $paidAmountUsd >= $totalUSD - 0.01;
                            $isFullyPaidLbp = $paidAmountLbp >= $totalLBP - 0.01;

                            if ($isFullyPaidUsd || $isFullyPaidLbp) {
                                $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = :id")
                                    ->execute([':id' => $invoiceId]);
                            }
                        }
                    }

                    $pdo->commit();

                    // Build success message
                    $successMessage = "Order {$orderNumber} and Invoice {$invoiceNumber} have been created. Van stock has been updated.";
                    if ($paidAmountUsd > 0 || $paidAmountLbp > 0) {
                        $paymentParts = [];
                        if ($paidAmountUsd > 0) $paymentParts[] = '$' . number_format($paidAmountUsd, 2);
                        if ($paidAmountLbp > 0) $paymentParts[] = number_format($paidAmountLbp, 0) . ' LBP';
                        $successMessage .= " Payment of " . implode(' + ', $paymentParts) . " has been recorded.";
                    }

                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Order Created Successfully',
                        'message' => $successMessage,
                        'dismissible' => true,
                    ];

                    // Clear the form by redirecting
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create van stock order: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to create order. Please try again.',
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
        'title' => 'Order Created Successfully',
        'message' => 'Your van stock sale has been recorded and inventory has been updated.',
        'dismissible' => true,
    ];
}

// Get sales rep's customers
$customersStmt = $pdo->prepare("
    SELECT id, name, phone, location AS city
    FROM customers
    WHERE assigned_sales_rep_id = :rep_id AND is_active = 1
    ORDER BY name
");
$customersStmt->execute([':rep_id' => $repId]);
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get van stock products with availability
$vanStockStmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.topcat as category,
        p.sale_price_usd,
        s.qty_on_hand
    FROM products p
    INNER JOIN s_stock s ON s.product_id = p.id AND s.salesperson_id = :rep_id
    WHERE p.is_active = 1 AND s.qty_on_hand > 0
    ORDER BY p.item_name
");
$vanStockStmt->execute([':rep_id' => $repId]);
$vanStockProducts = $vanStockStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'Van Stock Sales Order',
    'heading' => 'Create Van Stock Sale',
    'subtitle' => 'Sell directly from your van inventory',
    'active' => 'orders_van',
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
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--bg-panel);
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
            transition: background 0.2s;
        }
        .product-item:hover {
            background: var(--bg-panel-alt);
        }
        .product-item:last-child {
            border-bottom: none;
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
        .btn-submit {
            width: 100%;
            padding: 14px 20px;
            background: #10b981;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-submit:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-submit:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
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

if (empty($customers)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">👥</div>';
    echo '<h3>No Customers Assigned</h3>';
    echo '<p>You need to have customers assigned to you before creating van stock sales.</p>';
    echo '<p><a href="../users.php">Go to Customers page</a> to add customers.</p>';
    echo '</div>';
} elseif (empty($vanStockProducts)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">📦</div>';
    echo '<h3>No Van Stock Available</h3>';
    echo '<p>You need to have products in your van stock before creating sales.</p>';
    echo '<p><a href="../van_stock.php">Go to Van Stock page</a> to add inventory.</p>';
    echo '</div>';
} else {
    echo '<form method="POST" id="orderForm">';
    echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
    echo '<input type="hidden" name="action" value="create_order">';

    echo '<div class="order-form">';

    // Customer Selection
    echo '<div class="form-section">';
    echo '<h3>1. Select Customer</h3>';
    echo '<div class="form-group">';
    echo '<label>Customer <span style="color:red;">*</span></label>';
    echo '<select name="customer_id" required>';
    echo '<option value="">Choose a customer...</option>';
    foreach ($customers as $customer) {
        $custId = (int)$customer['id'];
        $custName = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
        $custPhone = $customer['phone'] ? ' - ' . htmlspecialchars($customer['phone'], ENT_QUOTES, 'UTF-8') : '';
        $custCity = $customer['city'] ? ' (' . htmlspecialchars($customer['city'], ENT_QUOTES, 'UTF-8') . ')' : '';
        echo '<option value="', $custId, '">', $custName, $custPhone, $custCity, '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';

    // Product Selection
    echo '<div class="form-section">';
    echo '<h3>2. Select Products</h3>';
    echo '<div class="alert-info">';
    echo 'Click on products below to add them to your order. Only products available in your van stock are shown.';
    echo '</div>';
    echo '<div class="products-selector">';
    echo '<div class="product-search">';
    echo '<input type="text" id="productSearch" placeholder="Search products...">';
    echo '</div>';
    echo '<div class="product-list" id="productList">';

    foreach ($vanStockProducts as $product) {
        $prodId = (int)$product['id'];
        $prodSku = htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8');
        $prodName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
        $prodCategory = $product['category'] ? htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') : '';
        $prodPriceUSD = (float)$product['sale_price_usd'];
        $prodStock = (float)$product['qty_on_hand'];
        $stockClass = $prodStock <= 5 ? 'low' : '';

        $prodPriceLBP = $prodPriceUSD * $exchangeRate;
        echo '<div class="product-item" data-product-id="', $prodId, '" data-product-name="', $prodName, '" ';
        echo 'data-product-sku="', $prodSku, '" data-price-usd="', $prodPriceUSD, '" ';
        echo 'data-price-lbp="', $prodPriceLBP, '" data-max-stock="', $prodStock, '">';
        echo '<div class="product-item-header">';
        echo '<span class="product-item-name">', $prodName, '</span>';
        echo '<span class="product-item-price">$', number_format($prodPriceUSD, 2), '</span>';
        echo '</div>';
        echo '<div class="product-item-meta">';
        echo 'SKU: ', $prodSku;
        if ($prodCategory) {
            echo ' | Category: ', $prodCategory;
        }
        echo ' | <span class="product-item-stock ', $stockClass, '">Stock: ', number_format($prodStock, 1), '</span>';
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
    echo '<h3>3. Order Notes (Optional)</h3>';
    echo '<div class="form-group">';
    echo '<label>Notes</label>';
    echo '<textarea name="notes" placeholder="Add any additional notes about this order..."></textarea>';
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
    echo '<div class="summary-row">';
    echo '<span>Subtotal (USD):</span>';
    echo '<span id="summarySubtotalUSD">$0.00</span>';
    echo '</div>';
    echo '<div class="summary-row">';
    echo '<span>Total Discount:</span>';
    echo '<span id="summaryDiscountUSD">$0.00</span>';
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

    // Payment section
    echo '<div class="payment-section" style="margin-top: 24px; padding: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">';
    echo '<h3 style="margin-bottom: 16px; font-size: 1rem; color: #374151;">Payment (Optional)</h3>';
    echo '<p style="font-size: 0.875rem; color: #6b7280; margin-bottom: 16px;">Record payment received at the time of sale. You can also add payments later from the Invoices page.</p>';

    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">';
    echo '<div class="form-field">';
    echo '<label style="display: block; font-weight: 500; margin-bottom: 6px;">Amount Paid (USD)</label>';
    echo '<input type="number" name="paid_amount_usd" id="paid_amount_usd" step="0.01" min="0" placeholder="0.00" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" onchange="convertPaidUsdToLbp()">';
    echo '</div>';
    echo '<div class="form-field">';
    echo '<label style="display: block; font-weight: 500; margin-bottom: 6px;">Amount Paid (LBP)</label>';
    echo '<input type="number" name="paid_amount_lbp" id="paid_amount_lbp" step="1" min="0" placeholder="0" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;" onchange="convertPaidLbpToUsd()">';
    echo '</div>';
    echo '</div>';

    echo '<div class="form-field" style="margin-bottom: 16px;">';
    echo '<label style="display: block; font-weight: 500; margin-bottom: 6px;">Payment Method</label>';
    echo '<select name="payment_method" id="payment_method" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">';
    echo '<option value="cash">Cash</option>';
    echo '<option value="qr_cash">QR Cash</option>';
    echo '<option value="card">Card</option>';
    echo '<option value="bank">Bank Transfer</option>';
    echo '<option value="other">Other</option>';
    echo '</select>';
    echo '</div>';

    echo '<div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px; font-size: 0.875rem; color: #1e40af;">';
    echo '<strong>💡 Tip:</strong> Enter amount in either USD or LBP - it will auto-convert at the current exchange rate (9,000 LBP = $1 USD)';
    echo '</div>';
    echo '</div>';

    echo '<button type="submit" class="btn-submit" id="submitButton" disabled>Create Van Stock Sale</button>';
    echo '</form>';
}

echo '<script>';
echo 'const selectedProducts = [];';
echo 'const exchangeRate = ', $exchangeRate, ';';
echo '';
echo 'function addProduct(productId, productName, sku, priceUSD, priceLBP, maxStock) {';
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
echo '    maxStock: maxStock,';
echo '    quantity: 1,';
echo '    discount: 0';
echo '  });';
echo '  renderSelectedProducts();';
echo '  updateSummary();';
echo '}';
echo '';
echo 'function removeProduct(productId) {';
echo '  const index = selectedProducts.findIndex(p => p.id === productId);';
echo '  if (index > -1) {';
echo '    selectedProducts.splice(index, 1);';
echo '    renderSelectedProducts();';
echo '    updateSummary();';
echo '  }';
echo '}';
echo '';
echo 'function updateProduct(productId, field, value) {';
echo '  const product = selectedProducts.find(p => p.id === productId);';
echo '  if (product) {';
echo '    if (field === "quantity") {';
echo '      const qty = parseFloat(value);';
echo '      if (qty > 0 && qty <= product.maxStock) {';
echo '        product.quantity = qty;';
echo '      } else if (qty > product.maxStock) {';
echo '        alert("Quantity exceeds available stock (" + product.maxStock + ")");';
echo '        return;';
echo '      }';
echo '    } else if (field === "discount") {';
echo '      const disc = parseFloat(value);';
echo '      if (disc >= 0 && disc <= 100) {';
echo '        product.discount = disc;';
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
echo '    const subtotal = product.priceUSD * product.quantity * (1 - product.discount / 100);';
echo '    const safeName = escapeHtml(product.name);';
echo '    const safeSku = escapeHtml(product.sku);';
echo '    html += `<div class="selected-product">`;';
echo '    html += `<div class="selected-product-header">`;';
echo '    html += `<div><div class="selected-product-name">${safeName}</div>`;';
echo '    html += `<div style="font-size:0.85rem;color:var(--muted);">SKU: ${safeSku} | Unit: $${product.priceUSD.toFixed(2)} | Stock: ${product.maxStock}</div></div>`;';
echo '    html += `<button type="button" class="btn-remove" onclick="removeProduct(${product.id})">Remove</button>`;';
echo '    html += `</div>`;';
echo '    html += `<div class="selected-product-controls">`;';
echo '    html += `<div class="control-group">`;';
echo '    html += `<label>Quantity</label>`;';
echo '    html += `<input type="number" step="0.1" min="0.1" max="${product.maxStock}" value="${product.quantity}" `;';
echo '    html += `onchange="updateProduct(${product.id}, \'quantity\', this.value)">`;';
echo '    html += `</div>`;';
echo '    html += `<div class="control-group">`;';
echo '    html += `<label>Discount (%)</label>`;';
echo '    html += `<input type="number" step="0.01" min="0" max="100" value="${product.discount}" `;';
echo '    html += `onchange="updateProduct(${product.id}, \'discount\', this.value)">`;';
echo '    html += `</div>`;';
echo '    html += `</div>`;';
echo '    html += `<div class="selected-product-subtotal">Subtotal: $${subtotal.toFixed(2)}</div>`;';
echo '    html += `<input type="hidden" name="items[${product.id}][product_id]" value="${product.id}">`;';
echo '    html += `<input type="hidden" name="items[${product.id}][quantity]" value="${product.quantity}">`;';
echo '    html += `<input type="hidden" name="items[${product.id}][discount]" value="${product.discount}">`;';
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
echo '  let subtotalUSD = 0;';
echo '  let discountUSD = 0;';
echo '  let totalUSD = 0;';
echo '  let totalLBP = 0;';
echo '  ';
echo '  selectedProducts.forEach(product => {';
echo '    const lineSubtotal = product.priceUSD * product.quantity;';
echo '    const lineDiscount = lineSubtotal * (product.discount / 100);';
echo '    const lineTotal = lineSubtotal - lineDiscount;';
echo '    const lineTotalLBP = product.priceLBP * product.quantity * (1 - product.discount / 100);';
echo '    ';
echo '    subtotalUSD += lineSubtotal;';
echo '    discountUSD += lineDiscount;';
echo '    totalUSD += lineTotal;';
echo '    totalLBP += lineTotalLBP;';
echo '  });';
echo '  ';
echo '  document.getElementById("summaryItemCount").textContent = itemCount;';
echo '  document.getElementById("summarySubtotalUSD").textContent = "$" + subtotalUSD.toFixed(2);';
echo '  document.getElementById("summaryDiscountUSD").textContent = "$" + discountUSD.toFixed(2);';
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
echo '    const maxStock = parseFloat(this.dataset.maxStock);';
echo '    addProduct(productId, productName, sku, priceUSD, priceLBP, maxStock);';
echo '  });';
echo '});';
echo '';
echo 'document.getElementById("productSearch").addEventListener("input", function() {';
echo '  const search = this.value.toLowerCase();';
echo '  document.querySelectorAll(".product-item").forEach(item => {';
echo '    const name = item.dataset.productName.toLowerCase();';
echo '    const sku = item.dataset.productSku.toLowerCase();';
echo '    if (name.includes(search) || sku.includes(search)) {';
echo '      item.style.display = "block";';
echo '    } else {';
echo '      item.style.display = "none";';
echo '    }';
echo '  });';
echo '});';
echo '';
echo '// Payment currency conversion functions';
echo 'function convertPaidUsdToLbp() {';
echo '  const usdInput = document.getElementById("paid_amount_usd");';
echo '  const lbpInput = document.getElementById("paid_amount_lbp");';
echo '  const usdValue = parseFloat(usdInput.value) || 0;';
echo '  if (usdValue > 0) {';
echo '    lbpInput.value = Math.round(usdValue * 9000);';
echo '  } else {';
echo '    lbpInput.value = "";';
echo '  }';
echo '}';
echo '';
echo 'function convertPaidLbpToUsd() {';
echo '  const usdInput = document.getElementById("paid_amount_usd");';
echo '  const lbpInput = document.getElementById("paid_amount_lbp");';
echo '  const lbpValue = parseFloat(lbpInput.value) || 0;';
echo '  if (lbpValue > 0) {';
echo '    usdInput.value = (lbpValue / 9000).toFixed(2);';
echo '  } else {';
echo '    usdInput.value = "";';
echo '  }';
echo '}';
echo '</script>';

sales_portal_render_layout_end();
