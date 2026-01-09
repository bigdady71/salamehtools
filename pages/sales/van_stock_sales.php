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

// Handle AJAX customer search
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'search_customers') {
    header('Content-Type: application/json');

    $query = trim((string)($_GET['q'] ?? ''));

    if (strlen($query) < 1) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $searchPattern = '%' . $query . '%';
        $stmt = $pdo->prepare("
            SELECT id, name, phone, location as city
            FROM customers
            WHERE assigned_sales_rep_id = :rep_id
              AND is_active = 1
              AND (name LIKE :pattern OR phone LIKE :pattern)
            ORDER BY name
            LIMIT 12
        ");
        $stmt->execute([
            ':rep_id' => $repId,
            ':pattern' => $searchPattern
        ]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['results' => $results]);
    } catch (PDOException $e) {
        error_log("Customer search failed: " . $e->getMessage());
        echo json_encode(['results' => [], 'error' => 'Search failed']);
    }
    exit;
}

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
        'message' => 'Cannot create sales orders at this time. The system exchange rate is not configured. Please contact your administrator.',
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
    } elseif (!$canCreateOrder) {
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
        $paymentAmountUSD = (float)($_POST['payment_amount_usd'] ?? 0);

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

        // Validate payment amount
        if ($paymentAmountUSD < 0) {
            $errors[] = 'Payment amount cannot be negative.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Generate order number atomically (race-condition safe)
                $orderNumber = generate_order_number($pdo);

                // Calculate totals and verify van stock availability
                $totalUSD = 0;
                $totalLBP = 0;
                $itemsWithPrices = [];

                foreach ($validatedItems as $item) {
                    // Get product price and verify van stock
                    $productStmt = $pdo->prepare("
                        SELECT
                            p.sale_price_usd,
                            p.item_name,
                            s.qty_on_hand as van_stock
                        FROM products p
                        LEFT JOIN s_stock s ON s.product_id = p.id AND s.salesperson_id = :rep_id
                        WHERE p.id = :product_id AND p.is_active = 1
                    ");
                    $productStmt->execute([
                        ':product_id' => $item['product_id'],
                        ':rep_id' => $repId
                    ]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        $errors[] = "Product ID {$item['product_id']} not found or inactive.";
                        break;
                    }

                    $vanStock = (float)($product['van_stock'] ?? 0);
                    if ($vanStock < $item['quantity']) {
                        $errors[] = "Insufficient van stock for {$product['item_name']}. Available: {$vanStock}, Requested: {$item['quantity']}.";
                        break;
                    }

                    $unitPriceUSD = (float)$product['sale_price_usd'];
                    $unitPriceLBP = $unitPriceUSD * $exchangeRate;

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

                // Validate payment doesn't exceed total
                if ($paymentAmountUSD > $totalUSD) {
                    $errors[] = 'Payment amount cannot exceed order total.';
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
                            :order_number, 'van_sale', 'delivered', :customer_id, :sales_rep_id, :exchange_rate_id,
                            :total_usd, :total_lbp, :notes, 1, NOW(), NOW()
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

                    // Create initial order status (delivered - on-site sale)
                    $statusStmt = $pdo->prepare("
                        INSERT INTO order_status_events (order_id, status, actor_user_id, note, created_at)
                        VALUES (:order_id, 'delivered', :actor_id, 'Van stock sale completed on-site', NOW())
                    ");
                    $statusStmt->execute([
                        ':order_id' => $orderId,
                        ':actor_id' => $repId,
                    ]);

                    // Update van stock and create stock movements
                    $stockUpdateStmt = $pdo->prepare("
                        UPDATE s_stock
                        SET qty_on_hand = qty_on_hand - :quantity,
                            updated_at = NOW()
                        WHERE salesperson_id = :rep_id AND product_id = :product_id
                    ");

                    $stockMovementStmt = $pdo->prepare("
                        INSERT INTO s_stock_movements (
                            salesperson_id, product_id, movement_type, quantity, reference_type, reference_id, notes, created_at
                        ) VALUES (
                            :rep_id, :product_id, 'sale', :quantity, 'order', :order_id, :notes, NOW()
                        )
                    ");

                    foreach ($itemsWithPrices as $item) {
                        // Deduct from van stock
                        $stockUpdateStmt->execute([
                            ':quantity' => $item['quantity'],
                            ':rep_id' => $repId,
                            ':product_id' => $item['product_id'],
                        ]);

                        // Log stock movement
                        $stockMovementStmt->execute([
                            ':rep_id' => $repId,
                            ':product_id' => $item['product_id'],
                            ':quantity' => -$item['quantity'], // Negative for outgoing
                            ':order_id' => $orderId,
                            ':notes' => "Van sale order {$orderNumber}",
                        ]);
                    }

                    // Generate invoice
                    $invoiceNumber = generate_invoice_number($pdo);
                    $invoiceStatus = $paymentAmountUSD >= $totalUSD ? 'paid' : 'issued';

                    $invoiceStmt = $pdo->prepare("
                        INSERT INTO invoices (
                            invoice_number, order_id, customer_id, total_usd, total_lbp,
                            amount_paid_usd, amount_paid_lbp, status, issued_at, created_at
                        ) VALUES (
                            :invoice_number, :order_id, :customer_id, :total_usd, :total_lbp,
                            :amount_paid_usd, :amount_paid_lbp, :status, NOW(), NOW()
                        )
                    ");
                    $invoiceStmt->execute([
                        ':invoice_number' => $invoiceNumber,
                        ':order_id' => $orderId,
                        ':customer_id' => $customerId,
                        ':total_usd' => $totalUSD,
                        ':total_lbp' => $totalLBP,
                        ':amount_paid_usd' => $paymentAmountUSD,
                        ':amount_paid_lbp' => $paymentAmountUSD * $exchangeRate,
                        ':status' => $invoiceStatus,
                    ]);

                    $invoiceId = (int)$pdo->lastInsertId();

                    // Create payment record if payment was provided
                    if ($paymentAmountUSD > 0) {
                        $paymentStmt = $pdo->prepare("
                            INSERT INTO payments (
                                invoice_id, customer_id, amount_usd, amount_lbp,
                                payment_method, payment_date, created_at
                            ) VALUES (
                                :invoice_id, :customer_id, :amount_usd, :amount_lbp,
                                'cash', NOW(), NOW()
                            )
                        ");
                        $paymentStmt->execute([
                            ':invoice_id' => $invoiceId,
                            ':customer_id' => $customerId,
                            ':amount_usd' => $paymentAmountUSD,
                            ':amount_lbp' => $paymentAmountUSD * $exchangeRate,
                        ]);
                    }

                    $pdo->commit();

                    // Redirect to print invoice
                    header("Location: print_invoice.php?invoice_id={$invoiceId}");
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create van stock sale: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to create sale order. Please try again. Error: ' . $e->getMessage(),
                    'dismissible' => true,
                ];
            }
        } else {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Unable to create sale. Please fix the errors below:',
                'list' => $errors,
                'dismissible' => true,
            ];
        }
    }
}

// Get van stock products (only products with qty > 0)
$vanStockStmt = $pdo->prepare("
    SELECT
        p.id,
        p.sku,
        p.item_name,
        p.topcat as category,
        p.description,
        p.barcode,
        p.code_clean,
        p.sale_price_usd,
        s.qty_on_hand
    FROM s_stock s
    JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id
      AND s.qty_on_hand > 0
      AND p.is_active = 1
    ORDER BY p.item_name
");
$vanStockStmt->execute([':rep_id' => $repId]);
$products = $vanStockStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'Van Stock Sales',
    'heading' => 'Create New Sale',
    'subtitle' => 'Sell products directly from your van inventory',
    'active' => 'orders_van',
    'user' => $user,
    'extra_head' => '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"><style>
        /* Mobile-first responsive design */
        .order-form {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 20px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }

        @media (min-width: 768px) {
            .order-form {
                padding: 28px;
            }
        }

        .form-section {
            margin-bottom: 28px;
            padding-bottom: 28px;
            border-bottom: 2px solid var(--border);
        }

        .form-section:last-child {
            border-bottom: none;
        }

        .form-section h3 {
            margin: 0 0 16px;
            font-size: 1.2rem;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: var(--accent);
            color: white;
            border-radius: 50%;
            font-size: 0.85rem;
            font-weight: 700;
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

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 16px; /* Prevent zoom on mobile */
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent);
        }

        .customer-search-wrapper {
            position: relative;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid var(--accent);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .autocomplete-item {
            padding: 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item:hover {
            background: var(--bg-panel-alt);
        }

        .autocomplete-item strong {
            display: block;
            color: var(--text);
            margin-bottom: 4px;
        }

        .autocomplete-item small {
            color: var(--muted);
            font-size: 0.85rem;
        }

        .customer-selected {
            background: #dcfce7;
            border: 2px solid #22c55e;
            padding: 16px;
            border-radius: 8px;
            margin-top: 12px;
            display: none;
        }

        .customer-selected.show {
            display: block;
        }

        .customer-selected strong {
            color: #166534;
            display: block;
            font-size: 1.1rem;
            margin-bottom: 4px;
        }

        .customer-selected small {
            color: #15803d;
        }

        .product-search {
            margin-bottom: 16px;
        }

        .product-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        .product-item {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.15s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .product-item:last-child {
            border-bottom: none;
        }

        .product-item:hover {
            background: var(--bg-panel-alt);
        }

        .product-item.selected {
            background: #dbeafe;
        }

        .product-item.low-stock {
            background: #fef9c3;
        }

        .product-info {
            flex: 1;
            min-width: 0;
        }

        .product-name {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 4px;
            word-wrap: break-word;
        }

        .product-meta {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .product-price {
            text-align: right;
            white-space: nowrap;
        }

        .price-usd {
            font-weight: 700;
            color: var(--accent);
            font-size: 1.1rem;
        }

        .stock-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #e5e7eb;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 4px;
        }

        .stock-badge.low {
            background: #fef3c7;
            color: #92400e;
        }

        .selected-products {
            margin-top: 24px;
        }

        .selected-product {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .selected-product-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
            gap: 12px;
        }

        .selected-product-name {
            font-weight: 600;
            flex: 1;
        }

        .remove-btn {
            background: #fee2e2;
            color: #991b1b;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .remove-btn:hover {
            background: #fecaca;
        }

        .selected-product-controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 480px) {
            .selected-product-controls {
                grid-template-columns: 1fr;
            }
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .control-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--muted);
        }

        .control-group input {
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 16px;
        }

        .subtotal {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--border);
            text-align: right;
            font-weight: 600;
            color: var(--accent);
        }

        .order-summary {
            background: linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%);
            color: white;
            padding: 24px;
            border-radius: 12px;
            margin-top: 24px;
            display: none;
        }

        .order-summary.show {
            display: block;
        }

        .order-summary h4 {
            margin: 0 0 16px;
            font-size: 1.3rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .summary-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            padding-top: 12px;
            border-top: 2px solid rgba(255,255,255,0.3);
            margin-top: 12px;
        }

        .payment-section {
            background: var(--bg-panel-alt);
            padding: 20px;
            border-radius: 8px;
            margin-top: 16px;
        }

        .payment-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 480px) {
            .payment-inputs {
                grid-template-columns: 1fr;
            }
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: white;
            color: var(--accent);
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        }

        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .flash {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .flash.error {
            background: #fee2e2;
            border-color: #dc2626;
            color: #991b1b;
        }

        .flash.success {
            background: #dcfce7;
            border-color: #22c55e;
            color: #166534;
        }

        .flash h4 {
            margin: 0 0 8px;
            font-size: 1.1rem;
        }

        .flash p, .flash ul {
            margin: 4px 0;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 12px;
        }
    </style>',
]);

// Display flash messages
foreach ($flashes as $flash) {
    $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');

    echo '<div class="flash ' . $type . '">';
    echo '<h4>' . $title . '</h4>';
    echo '<p>' . $message . '</p>';

    if (!empty($flash['list'])) {
        echo '<ul>';
        foreach ($flash['list'] as $item) {
            echo '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

if (!$canCreateOrder) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">⚠️</div>';
    echo '<h3>System Unavailable</h3>';
    echo '<p>The sales system is temporarily unavailable due to missing exchange rate configuration.</p>';
    echo '<p>Please contact your administrator to resolve this issue.</p>';
    echo '</div>';
} elseif (empty($products)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">📦</div>';
    echo '<h3>No Products in Van Stock</h3>';
    echo '<p>You currently have no products in your van inventory.</p>';
    echo '<p>Please request stock from the warehouse to start making sales.</p>';
    echo '</div>';
} else {
    ?>
    <form method="POST" action="" id="salesForm" class="order-form">
        <input type="hidden" name="action" value="create_order">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="customer_id" id="customerId" value="">

        <!-- Step 1: Customer Selection -->
        <div class="form-section">
            <h3><span class="step-badge">1</span> Select Customer</h3>
            <div class="form-group">
                <label for="customerSearch">Search by name or phone</label>
                <div class="customer-search-wrapper">
                    <input
                        type="text"
                        id="customerSearch"
                        class="form-control"
                        placeholder="Type at least 1 character to search..."
                        autocomplete="off"
                    >
                    <div id="autocompleteDropdown" class="autocomplete-dropdown" style="display: none;"></div>
                </div>
                <div id="customerSelected" class="customer-selected">
                    <strong id="selectedCustomerName"></strong>
                    <small id="selectedCustomerInfo"></small>
                </div>
            </div>
        </div>

        <!-- Step 2: Product Selection -->
        <div class="form-section">
            <h3><span class="step-badge">2</span> Select Products</h3>
            <div class="product-search">
                <input
                    type="text"
                    id="productSearch"
                    class="form-control"
                    placeholder="Search products by name, SKU, barcode, or category..."
                >
            </div>
            <div class="product-list" id="productList">
                <?php foreach ($products as $product): ?>
                    <div class="product-item<?= (float)$product['qty_on_hand'] <= 5 ? ' low-stock' : '' ?>"
                         data-product-id="<?= (int)$product['id'] ?>"
                         data-product-name="<?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?>"
                         data-product-price="<?= (float)$product['sale_price_usd'] ?>"
                         data-product-stock="<?= (float)$product['qty_on_hand'] ?>"
                         data-search-text="<?= htmlspecialchars(strtolower($product['item_name'] . ' ' . $product['sku'] . ' ' . $product['category'] . ' ' . ($product['barcode'] ?? '') . ' ' . ($product['code_clean'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="product-meta">
                                SKU: <?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($product['category'])): ?>
                                    | <?= htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                            <div class="stock-badge<?= (float)$product['qty_on_hand'] <= 5 ? ' low' : '' ?>">
                                Stock: <?= number_format((float)$product['qty_on_hand'], 2) ?>
                            </div>
                        </div>
                        <div class="product-price">
                            <div class="price-usd">$<?= number_format((float)$product['sale_price_usd'], 2) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="selectedProducts" class="selected-products" style="display: none;">
                <h4 style="margin: 0 0 12px; font-size: 1.1rem;">Products in this sale:</h4>
                <div id="selectedProductsList"></div>
            </div>
        </div>

        <!-- Step 3: Notes -->
        <div class="form-section">
            <h3><span class="step-badge">3</span> Additional Notes (Optional)</h3>
            <div class="form-group">
                <textarea
                    name="notes"
                    id="notes"
                    class="form-control"
                    rows="3"
                    placeholder="Add any special instructions or comments about this sale..."
                ></textarea>
            </div>
        </div>

        <!-- Order Summary & Payment -->
        <div id="orderSummary" class="order-summary">
            <h4>Order Summary</h4>
            <div class="summary-row">
                <span>Number of items:</span>
                <span id="summaryItemCount">0</span>
            </div>
            <div class="summary-row">
                <span>Subtotal:</span>
                <span id="summarySubtotal">$0.00</span>
            </div>
            <div class="summary-row">
                <span>Total Discount:</span>
                <span id="summaryDiscount">$0.00</span>
            </div>
            <div class="summary-row total">
                <span>TOTAL (USD):</span>
                <span id="summaryTotalUSD">$0.00</span>
            </div>
            <div class="summary-row">
                <span>TOTAL (LBP):</span>
                <span id="summaryTotalLBP">L.L. 0</span>
            </div>

            <div class="payment-section">
                <h4 style="margin: 0 0 12px; font-size: 1rem;">Payment (Optional)</h4>
                <p style="font-size: 0.85rem; margin: 0 0 12px; opacity: 0.9;">Enter payment if customer pays on-site</p>
                <div class="payment-inputs">
                    <div class="form-group" style="margin: 0;">
                        <label for="paymentUSD" style="color: white; opacity: 0.9;">Amount USD</label>
                        <input
                            type="number"
                            id="paymentUSD"
                            name="payment_amount_usd"
                            class="form-control"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                        >
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label for="paymentLBP" style="color: white; opacity: 0.9;">Amount LBP</label>
                        <input
                            type="number"
                            id="paymentLBP"
                            class="form-control"
                            step="1"
                            min="0"
                            placeholder="0"
                        >
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                Complete Sale & Print Invoice
            </button>
        </div>
    </form>

    <script>
        const exchangeRate = <?= $exchangeRate ?>;
        let selectedProducts = {};
        let debounceTimer = null;

        // Customer search autocomplete
        const customerSearch = document.getElementById('customerSearch');
        const autocompleteDropdown = document.getElementById('autocompleteDropdown');
        const customerSelected = document.getElementById('customerSelected');
        const customerId = document.getElementById('customerId');

        customerSearch.addEventListener('input', function() {
            const query = this.value.trim();

            clearTimeout(debounceTimer);

            if (query.length < 1) {
                autocompleteDropdown.style.display = 'none';
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch(`?action=search_customers&q=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.results && data.results.length > 0) {
                            autocompleteDropdown.innerHTML = data.results.map(customer => `
                                <div class="autocomplete-item" data-id="${customer.id}" data-name="${escapeHtml(customer.name)}" data-phone="${escapeHtml(customer.phone || '')}" data-city="${escapeHtml(customer.city || '')}">
                                    <strong>${escapeHtml(customer.name)}</strong>
                                    <small>${escapeHtml(customer.phone || 'No phone')} ${customer.city ? '| ' + escapeHtml(customer.city) : ''}</small>
                                </div>
                            `).join('');
                            autocompleteDropdown.style.display = 'block';

                            // Add click handlers
                            document.querySelectorAll('.autocomplete-item').forEach(item => {
                                item.addEventListener('click', function() {
                                    selectCustomer(
                                        this.dataset.id,
                                        this.dataset.name,
                                        this.dataset.phone,
                                        this.dataset.city
                                    );
                                });
                            });
                        } else {
                            autocompleteDropdown.innerHTML = '<div class="autocomplete-item" style="cursor: default;">No customers found</div>';
                            autocompleteDropdown.style.display = 'block';
                        }
                    })
                    .catch(err => {
                        console.error('Customer search failed:', err);
                        autocompleteDropdown.style.display = 'none';
                    });
            }, 300);
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!customerSearch.contains(e.target) && !autocompleteDropdown.contains(e.target)) {
                autocompleteDropdown.style.display = 'none';
            }
        });

        function selectCustomer(id, name, phone, city) {
            customerId.value = id;
            customerSearch.value = name;
            autocompleteDropdown.style.display = 'none';

            document.getElementById('selectedCustomerName').textContent = name;
            document.getElementById('selectedCustomerInfo').textContent = `${phone || 'No phone'}${city ? ' | ' + city : ''}`;
            customerSelected.classList.add('show');

            // Scroll to products section
            document.getElementById('productSearch').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Product search
        const productSearch = document.getElementById('productSearch');
        const productItems = document.querySelectorAll('.product-item');

        productSearch.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();

            productItems.forEach(item => {
                const searchText = item.dataset.searchText;
                if (query === '' || searchText.includes(query)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        });

        // Product selection
        productItems.forEach(item => {
            item.addEventListener('click', function() {
                const productId = this.dataset.productId;
                const productName = this.dataset.productName;
                const productPrice = parseFloat(this.dataset.productPrice);
                const productStock = parseFloat(this.dataset.productStock);

                if (!selectedProducts[productId]) {
                    selectedProducts[productId] = {
                        name: productName,
                        price: productPrice,
                        stock: productStock,
                        quantity: 1,
                        discount: 0
                    };

                    this.classList.add('selected');
                    renderSelectedProducts();
                    updateOrderSummary();
                }
            });
        });

        function renderSelectedProducts() {
            const container = document.getElementById('selectedProductsList');
            const selectedSection = document.getElementById('selectedProducts');

            if (Object.keys(selectedProducts).length === 0) {
                selectedSection.style.display = 'none';
                return;
            }

            selectedSection.style.display = 'block';

            container.innerHTML = Object.keys(selectedProducts).map(productId => {
                const product = selectedProducts[productId];
                const subtotal = product.price * product.quantity * (1 - product.discount / 100);

                return `
                    <div class="selected-product" data-product-id="${productId}">
                        <div class="selected-product-header">
                            <div class="selected-product-name">${escapeHtml(product.name)}</div>
                            <button type="button" class="remove-btn" onclick="removeProduct(${productId})">Remove</button>
                        </div>
                        <div class="selected-product-controls">
                            <div class="control-group">
                                <label>Quantity (Max: ${product.stock})</label>
                                <input
                                    type="number"
                                    class="quantity-input"
                                    data-product-id="${productId}"
                                    value="${product.quantity}"
                                    min="0.01"
                                    max="${product.stock}"
                                    step="1"
                                >
                                <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                                <input type="hidden" name="items[${productId}][quantity]" value="${product.quantity}" class="quantity-hidden-${productId}">
                            </div>
                            <div class="control-group">
                                <label>Discount %</label>
                                <input
                                    type="number"
                                    class="discount-input"
                                    data-product-id="${productId}"
                                    value="${product.discount}"
                                    min="0"
                                    max="100"
                                    step="0.1"
                                >
                                <input type="hidden" name="items[${productId}][discount]" value="${product.discount}" class="discount-hidden-${productId}">
                            </div>
                        </div>
                        <div class="subtotal">Subtotal: $${subtotal.toFixed(2)}</div>
                    </div>
                `;
            }).join('');

            // Add event listeners for quantity and discount changes
            document.querySelectorAll('.quantity-input').forEach(input => {
                input.addEventListener('input', function() {
                    const productId = this.dataset.productId;
                    const value = parseFloat(this.value) || 0;
                    const maxStock = selectedProducts[productId].stock;

                    if (value > maxStock) {
                        alert(`Maximum available stock is ${maxStock}`);
                        this.value = maxStock;
                        selectedProducts[productId].quantity = maxStock;
                    } else {
                        selectedProducts[productId].quantity = value;
                    }

                    document.querySelector(`.quantity-hidden-${productId}`).value = selectedProducts[productId].quantity;
                    renderSelectedProducts();
                    updateOrderSummary();
                });
            });

            document.querySelectorAll('.discount-input').forEach(input => {
                input.addEventListener('input', function() {
                    const productId = this.dataset.productId;
                    const value = parseFloat(this.value) || 0;
                    selectedProducts[productId].discount = Math.max(0, Math.min(100, value));
                    document.querySelector(`.discount-hidden-${productId}`).value = selectedProducts[productId].discount;
                    renderSelectedProducts();
                    updateOrderSummary();
                });
            });
        }

        function removeProduct(productId) {
            delete selectedProducts[productId];
            document.querySelector(`.product-item[data-product-id="${productId}"]`)?.classList.remove('selected');
            renderSelectedProducts();
            updateOrderSummary();
        }

        function updateOrderSummary() {
            const summary = document.getElementById('orderSummary');

            if (Object.keys(selectedProducts).length === 0) {
                summary.classList.remove('show');
                return;
            }

            summary.classList.add('show');

            let itemCount = 0;
            let subtotal = 0;
            let totalDiscount = 0;

            Object.values(selectedProducts).forEach(product => {
                itemCount += 1;
                const lineSubtotal = product.price * product.quantity;
                const lineDiscount = lineSubtotal * (product.discount / 100);
                subtotal += lineSubtotal;
                totalDiscount += lineDiscount;
            });

            const totalUSD = subtotal - totalDiscount;
            const totalLBP = totalUSD * exchangeRate;

            document.getElementById('summaryItemCount').textContent = itemCount;
            document.getElementById('summarySubtotal').textContent = '$' + subtotal.toFixed(2);
            document.getElementById('summaryDiscount').textContent = '$' + totalDiscount.toFixed(2);
            document.getElementById('summaryTotalUSD').textContent = '$' + totalUSD.toFixed(2);
            document.getElementById('summaryTotalLBP').textContent = 'L.L. ' + totalLBP.toLocaleString('en-US', {maximumFractionDigits: 0});
        }

        // Payment currency conversion
        const paymentUSD = document.getElementById('paymentUSD');
        const paymentLBP = document.getElementById('paymentLBP');

        paymentUSD.addEventListener('input', function() {
            const usd = parseFloat(this.value) || 0;
            paymentLBP.value = Math.round(usd * exchangeRate);
        });

        paymentLBP.addEventListener('input', function() {
            const lbp = parseFloat(this.value) || 0;
            paymentUSD.value = (lbp / exchangeRate).toFixed(2);
        });

        // Form validation
        document.getElementById('salesForm').addEventListener('submit', function(e) {
            const customerIdValue = document.getElementById('customerId').value;
            const productCount = Object.keys(selectedProducts).length;

            if (!customerIdValue) {
                e.preventDefault();
                alert('Please select a customer before submitting.');
                customerSearch.focus();
                return false;
            }

            if (productCount === 0) {
                e.preventDefault();
                alert('Please add at least one product to the sale.');
                return false;
            }

            // Disable submit button to prevent double submission
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').textContent = 'Processing...';
        });
    </script>
    <?php
}

sales_portal_render_layout_end();
