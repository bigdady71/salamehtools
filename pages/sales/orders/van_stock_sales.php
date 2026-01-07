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
        p.description,
        p.barcode,
        p.code_clean,
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
    'title' => 'Create New Sale',
    'heading' => '🚚 Create New Sale',
    'subtitle' => 'Quick and easy way to record a sale from your van',
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
            padding: 10px 40px 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--bg-panel);
            transition: all 0.2s;
        }
        .product-search input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        .product-search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }
        .product-search-clear {
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
        .product-search-clear:hover {
            background: #dc2626;
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
        .product-item.selected {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
        }
        .product-item.highlighted {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
        }
        .product-item.hidden {
            display: none !important;
        }
        .no-results {
            display: none;
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-size: 0.95rem;
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

if (!$canCreateOrder) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">⚠️</div>';
    echo '<h3>System Configuration Required</h3>';
    echo '<p>Orders cannot be created until the exchange rate is properly configured in the system.</p>';
    echo '<p><a href="dashboard.php" class="btn btn-info">Return to Dashboard</a></p>';
    echo '</div>';
    sales_portal_render_layout_end();
    exit;
} elseif (empty($customers)) {
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
    echo '<h3>Step 1: Who is the customer? <span style="color:red;">*</span></h3>';
    echo '<p style="color: #6b7280; font-size: 0.9rem; margin: 0 0 16px 0;">Search for your customer by typing their name or phone number</p>';
    echo '<div class="form-group">';

    // Filter by Governorate
    echo '<div style="display: flex; gap: 12px; margin-bottom: 16px;">';
    echo '<div style="flex: 1;">';
    echo '<label style="font-size: 0.9rem; color: #047857; margin-bottom: 4px; display: block;">Filter by Governorate</label>';
    echo '<select id="governorateFilter" style="width: 100%; padding: 10px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 1rem;">';
    echo '<option value="">All Governorates - كل المحافظات</option>';
    $lebanonGovernorates = [
        'Beirut' => 'Beirut - بيروت',
        'Mount Lebanon' => 'Mount Lebanon - جبل لبنان',
        'North' => 'North - الشمال',
        'South' => 'South - الجنوب',
        'Beqaa' => 'Beqaa - البقاع',
        'Nabatieh' => 'Nabatieh - النبطية',
        'Akkar' => 'Akkar - عكار',
        'Baalbek-Hermel' => 'Baalbek-Hermel - بعلبك الهرمل'
    ];
    foreach ($lebanonGovernorates as $value => $label) {
        echo '<option value="', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), '">', htmlspecialchars($label, ENT_QUOTES, 'UTF-8'), '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';

    echo '<label style="font-size: 1rem; color: #047857;">Customer Name or Phone</label>';
    echo '<div class="customer-search-wrapper" style="display: flex; gap: 8px;">';
    echo '<div style="position: relative; flex: 1;">';
    echo '<input type="text" id="customerSearch" placeholder="🔍 Type customer name or phone number..." autocomplete="off" style="font-size: 1rem; width: 100%; padding-right: 40px;">';
    echo '<button type="button" class="customer-search-clear" id="clearCustomerSearch" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #9ca3af; cursor: pointer; padding: 4px 8px; display: none;">✕</button>';
    echo '</div>';
    echo '<button type="button" id="searchCustomerBtn" style="padding: 10px 20px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; white-space: nowrap;">🔍 Search</button>';
    echo '</div>';
    echo '<input type="hidden" name="customer_id" id="selectedCustomerId" required>';
    echo '<div class="customer-list" id="customerList" style="display:none;">';
    foreach ($customers as $customer) {
        $custId = (int)$customer['id'];
        $custName = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
        $custPhone = htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8');
        $custCity = htmlspecialchars($customer['city'] ?? '', ENT_QUOTES, 'UTF-8');
        $custGov = htmlspecialchars($customer['governorate'] ?? '', ENT_QUOTES, 'UTF-8');
        echo '<div class="customer-item" data-customer-id="', $custId, '" data-customer-name="', $custName, '" ';
        echo 'data-customer-phone="', $custPhone, '" data-customer-city="', $custCity, '" data-customer-governorate="', $custGov, '">';
        echo '<div class="customer-item-name">', $custName, '</div>';
        echo '<div class="customer-item-meta">';
        if ($custPhone) echo 'Phone: ', $custPhone;
        if ($custPhone && ($custCity || $custGov)) echo ' | ';
        if ($custGov) echo $custGov;
        if ($custGov && $custCity) echo ', ';
        if ($custCity) echo $custCity;
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '<div class="selected-customer" id="selectedCustomerDisplay" style="display:none;"></div>';
    echo '</div>';
    echo '</div>';

    // Product Selection
    echo '<div class="form-section">';
    echo '<h3>Step 2: What are you selling? <span style="color:red;">*</span></h3>';
    echo '<div class="alert-info" style="background: #dbeafe; border: 2px solid #3b82f6; padding: 16px; border-radius: 10px; margin-bottom: 16px;">';
    echo '<strong style="display: block; margin-bottom: 8px; color: #1e40af; font-size: 1rem;">📦 How to add products:</strong>';
    echo '<ul style="margin: 0; padding-left: 20px; color: #1e40af; line-height: 1.8;">';
    echo '<li>Type the product name or scan barcode to search</li>';
    echo '<li>Click on any product to add it to your sale</li>';
    echo '<li>Adjust quantity and discount if needed</li>';
    echo '</ul>';
    echo '</div>';
    echo '<div class="products-selector">';
    echo '<div class="product-search">';
    echo '<input type="text" id="productSearch" placeholder="🔍 Type product name or scan barcode..." autocomplete="off" style="font-size: 1rem;">';
    echo '<button type="button" class="product-search-clear" id="clearSearch">✕ Clear</button>';
    echo '<span class="product-search-icon">🔍</span>';
    echo '</div>';
    echo '<div class="product-list" id="productList">';
    echo '<div class="no-results" id="noResults" style="background: #fef3c7; border: 2px solid #f59e0b; border-radius: 10px; padding: 30px;">🔍 <strong>No products found.</strong><br><br>Try searching with a different name or barcode.</div>';

    foreach ($vanStockProducts as $product) {
        $prodId = (int)$product['id'];
        $prodSku = htmlspecialchars($product['sku'] ?? '', ENT_QUOTES, 'UTF-8');
        $prodName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
        $prodCategory = $product['category'] ? htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8') : '';
        $prodDescription = $product['description'] ? htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8') : '';
        $prodBarcode = $product['barcode'] ? htmlspecialchars($product['barcode'], ENT_QUOTES, 'UTF-8') : '';
        $prodCodeClean = $product['code_clean'] ? htmlspecialchars($product['code_clean'], ENT_QUOTES, 'UTF-8') : '';
        $prodPriceUSD = (float)$product['sale_price_usd'];
        $prodStock = (float)$product['qty_on_hand'];
        $stockClass = $prodStock <= 5 ? 'low' : '';

        $prodPriceLBP = $prodPriceUSD * $exchangeRate;
        echo '<div class="product-item" data-product-id="', $prodId, '" data-product-name="', $prodName, '" ';
        echo 'data-product-sku="', $prodSku, '" data-product-category="', $prodCategory, '" ';
        echo 'data-product-description="', $prodDescription, '" data-product-barcode="', $prodBarcode, '" ';
        echo 'data-product-code="', $prodCodeClean, '" data-price-usd="', $prodPriceUSD, '" ';
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
    echo '<h4 style="color: #059669; font-size: 1.1rem; margin-bottom: 12px;">✅ Products in this sale:</h4>';
    echo '<div id="selectedProductsList">';
    echo '<p style="color:var(--muted);text-align:center; padding: 20px; background: #f9fafb; border-radius: 8px; border: 2px dashed #e5e7eb;">No products added yet. Search and click products above to add them.</p>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Order Notes
    echo '<div class="form-section">';
    echo '<h3>Step 3: Add notes (Optional)</h3>';
    echo '<p style="color: #6b7280; font-size: 0.9rem; margin: 0 0 12px 0;">Add any special instructions or notes about this sale</p>';
    echo '<div class="form-group">';
    echo '<label>Notes</label>';
    echo '<textarea name="notes" placeholder="Example: Customer requested delivery next week, Special discount approved, etc..."></textarea>';
    echo '</div>';
    echo '</div>';

    echo '</div>'; // End order-form

    // Order Summary
    echo '<div class="order-summary" id="orderSummary" style="display:none; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 3px solid #f59e0b; padding: 24px; border-radius: 16px; margin-bottom: 24px;">';
    echo '<h3 style="color: #92400e; font-size: 1.4rem; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">📊 Sale Summary</h3>';
    echo '<div class="summary-row" style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #fbbf24; font-size: 1rem;">';
    echo '<span style="color: #92400e; font-weight: 500;">Number of Items:</span>';
    echo '<span id="summaryItemCount" style="font-weight: 700; color: #92400e;">0</span>';
    echo '</div>';
    echo '<div class="summary-row" style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #fbbf24; font-size: 1rem;">';
    echo '<span style="color: #92400e; font-weight: 500;">Subtotal:</span>';
    echo '<span id="summarySubtotalUSD" style="font-weight: 700; color: #92400e;">$0.00</span>';
    echo '</div>';
    echo '<div class="summary-row" style="display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #fbbf24; font-size: 1rem;">';
    echo '<span style="color: #92400e; font-weight: 500;">Discount:</span>';
    echo '<span id="summaryDiscountUSD" style="font-weight: 700; color: #92400e;">$0.00</span>';
    echo '</div>';
    echo '<div class="summary-row total" style="display: flex; justify-content: space-between; padding: 16px 0; border-top: 3px solid #f59e0b; margin-top: 8px; font-size: 1.4rem;">';
    echo '<span style="color: #78350f; font-weight: 700;">TOTAL (USD):</span>';
    echo '<span id="summaryTotalUSD" style="font-weight: 800; color: #78350f;">$0.00</span>';
    echo '</div>';
    echo '<div class="summary-row" style="display: flex; justify-content: space-between; padding: 12px 0; font-size: 1.1rem;">';
    echo '<span style="color: #78350f; font-weight: 600;">TOTAL (LBP):</span>';
    echo '<span id="summaryTotalLBP" style="font-weight: 700; color: #78350f;">L.L. 0</span>';
    echo '</div>';
    echo '</div>';

    // Payment section
    echo '<div class="payment-section" style="margin-top: 24px; padding: 24px; background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 2px solid #059669; border-radius: 12px;">';
    echo '<h3 style="margin-bottom: 12px; font-size: 1.2rem; color: #065f46;">💵 Did the customer pay now?</h3>';
    echo '<p style="font-size: 0.95rem; color: #047857; margin-bottom: 16px; line-height: 1.6;"><strong>Optional:</strong> If the customer paid you cash or by card, enter the amount below. Otherwise, leave blank and record payment later.</p>';

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

    echo '<input type="hidden" name="payment_method" value="cash">';

    echo '<div style="background: #eff6ff; border: 2px solid #3b82f6; border-radius: 8px; padding: 14px; font-size: 0.9rem; color: #1e40af; line-height: 1.6;">';
    echo '<strong style="font-size: 1rem;">💡 Helpful Tip:</strong><br>';
    echo 'Enter amount in either USD or LBP - it will automatically convert using today\'s exchange rate';
    echo '</div>';
    echo '</div>';

    echo '<div id="submitHint" style="display: none; background: #fef3c7; border: 2px solid #f59e0b; border-radius: 10px; padding: 16px; margin-bottom: 16px; text-align: center; font-size: 1rem; color: #92400e;">';
    echo '⬆️ <strong>Add at least one product above to complete your sale</strong>';
    echo '</div>';

    echo '<button type="submit" class="btn btn-success btn-block btn-lg" id="submitButton" disabled style="width: 100%; padding: 18px; font-size: 1.2rem; font-weight: 700; border-radius: 12px; background: linear-gradient(135deg, #059669 0%, #047857 100%); border: none; color: white; cursor: pointer; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.4); transition: all 0.3s;">✅ Complete Sale & Print Invoice</button>';
    echo '</form>';
}

echo '<script>';
echo 'const selectedProducts = [];';
echo 'const exchangeRate = ', $exchangeRate, ';';
echo '';
echo 'function addProduct(productId, productName, sku, priceUSD, priceLBP, maxStock) {';
echo '  if (selectedProducts.find(p => p.id === productId)) {';
echo '    alert("⚠️ This product is already in your sale!\\n\\nYou can change the quantity below if needed.");';
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
echo '  updateProductItemStates();';
echo '  document.getElementById("productSearch").value = "";';
echo '  performSearch();';
echo '  document.getElementById("productSearch").focus();';
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
echo '      if (qty > 0 && qty <= product.maxStock) {';
echo '        product.quantity = qty;';
echo '      } else if (qty > product.maxStock) {';
echo '        alert("⚠️ Not enough stock!\\n\\nYou only have " + product.maxStock + " units available in your van.\\n\\nPlease enter a smaller quantity.");';
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
echo '    container.innerHTML = \'<p style="color:var(--muted);text-align:center; padding: 20px; background: #f9fafb; border-radius: 8px; border: 2px dashed #e5e7eb;">📦 No products added yet.<br><strong style="color: #059669;">Search and click products above to add them to your sale.</strong></p>\';';
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
echo '    html += `<label style="font-size: 0.95rem; color: #047857;">📦 How many?</label>`;';
echo '    html += `<input type="number" step="0.1" min="0.1" max="${product.maxStock}" value="${product.quantity}" `;';
echo '    html += `onchange="updateProduct(${product.id}, \'quantity\', this.value)" style="font-size: 1.1rem; font-weight: 600;">`;';
echo '    html += `</div>`;';
echo '    html += `<div class="control-group">`;';
echo '    html += `<label style="font-size: 0.95rem; color: #047857;">💰 Discount %</label>`;';
echo '    html += `<input type="number" step="0.01" min="0" max="100" value="${product.discount}" `;';
echo '    html += `onchange="updateProduct(${product.id}, \'discount\', this.value)" placeholder="0" style="font-size: 1.1rem; font-weight: 600;">`;';
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
echo '  const submitHint = document.getElementById("submitHint");';
echo '  ';
echo '  if (selectedProducts.length === 0) {';
echo '    summary.style.display = "none";';
echo '    submitBtn.disabled = true;';
echo '    if (submitHint) submitHint.style.display = "block";';
echo '    return;';
echo '  }';
echo '  ';
echo '  summary.style.display = "block";';
echo '  submitBtn.disabled = false;';
echo '  if (submitHint) submitHint.style.display = "none";';
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
echo '  clearBtn.style.display = search.length > 0 ? "block" : "none";';
echo '';
echo '  let hasResults = false;';
echo '  document.querySelectorAll(".product-item").forEach(item => {';
echo '    const name = item.dataset.productName.toLowerCase();';
echo '    const sku = (item.dataset.productSku || "").toLowerCase();';
echo '    const category = (item.dataset.productCategory || "").toLowerCase();';
echo '    const description = (item.dataset.productDescription || "").toLowerCase();';
echo '    const barcode = (item.dataset.productBarcode || "").toLowerCase();';
echo '    const code = (item.dataset.productCode || "").toLowerCase();';
echo '    const matches = search === "" || name.includes(search) || sku.includes(search) || category.includes(search) || description.includes(search) || barcode.includes(search) || code.includes(search);';
echo '';
echo '    item.classList.toggle("hidden", !matches);';
echo '    item.classList.remove("highlighted");';
echo '';
echo '    if (matches) {';
echo '      hasResults = true;';
echo '      visibleProducts.push(item);';
echo '    }';
echo '  });';
echo '';
echo '  noResults.style.display = hasResults ? "none" : "block";';
echo '';
echo '  if (visibleProducts.length > 0) {';
echo '    highlightProduct(0);';
echo '  }';
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
echo '    const maxStock = parseFloat(item.dataset.maxStock);';
echo '    addProduct(productId, productName, sku, priceUSD, priceLBP, maxStock);';
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
echo 'document.getElementById("productSearch").focus();';
echo '';
echo '// Customer search functionality';
echo 'let currentCustomerHighlightIndex = -1;';
echo 'let visibleCustomers = [];';
echo '';
echo 'function performCustomerSearch() {';
echo '  const searchInput = document.getElementById("customerSearch");';
echo '  const clearBtn = document.getElementById("clearCustomerSearch");';
echo '  const customerList = document.getElementById("customerList");';
echo '  const governorateFilter = document.getElementById("governorateFilter");';
echo '  const search = searchInput.value.toLowerCase().trim();';
echo '  const selectedGovernorate = governorateFilter.value.toLowerCase();';
echo '';
echo '  visibleCustomers = [];';
echo '  currentCustomerHighlightIndex = -1;';
echo '';
echo '  clearBtn.style.display = search.length > 0 ? "inline-block" : "none";';
echo '';
echo '  customerList.style.display = "block";';
echo '  let hasResults = false;';
echo '';
echo '  document.querySelectorAll(".customer-item").forEach(item => {';
echo '    const name = item.dataset.customerName.toLowerCase();';
echo '    const phone = (item.dataset.customerPhone || "").toLowerCase();';
echo '    const governorate = (item.dataset.customerGovernorate || "").toLowerCase();';
echo '    ';
echo '    const searchMatches = search === "" || name.includes(search) || phone.includes(search);';
echo '    const governorateMatches = selectedGovernorate === "" || governorate === selectedGovernorate;';
echo '    const matches = searchMatches && governorateMatches;';
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
echo '// Search button click handler';
echo 'document.getElementById("searchCustomerBtn").addEventListener("click", function() {';
echo '  performCustomerSearch();';
echo '  document.getElementById("customerSearch").focus();';
echo '});';
echo '';
echo '// Governorate filter change handler';
echo 'document.getElementById("governorateFilter").addEventListener("change", function() {';
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
