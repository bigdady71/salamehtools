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

// Handle AJAX customer search (same as van_stock_sales.php)
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

// Handle order creation (same logic as van_stock_sales.php)
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
        $paymentAmountUSD = (float)($_POST['payment_usd'] ?? 0);
        $paymentAmountLBP = (float)($_POST['payment_lbp'] ?? 0);

        $errors = [];

        // Validate customer and get their credit balance
        $customerCreditLBP = 0;
        if ($customerId <= 0) {
            $errors[] = 'Please select a customer.';
        } else {
            // Verify customer is assigned to this sales rep and get credit balance
            $customerStmt = $pdo->prepare("SELECT id, COALESCE(account_balance_lbp, 0) as credit_lbp FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id AND is_active = 1");
            $customerStmt->execute([':id' => $customerId, ':rep_id' => $repId]);
            $customerData = $customerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$customerData) {
                $errors[] = 'Invalid customer selected or customer not assigned to you.';
            } else {
                $customerCreditLBP = (float)$customerData['credit_lbp'];
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

                if ($productId <= 0 || $quantity <= 0) {
                    continue; // Skip invalid items
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

        // Validate payment amounts
        if ($paymentAmountUSD < 0 || $paymentAmountLBP < 0) {
            $errors[] = 'Payment amount cannot be negative.';
        }

        // Convert LBP payment to USD equivalent for total payment calculation
        $paymentLBPinUSD = $exchangeRate > 0 ? $paymentAmountLBP / $exchangeRate : 0;
        $totalPaymentUSD = $paymentAmountUSD + $paymentLBPinUSD;

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Generate order number atomically (race-condition safe)
                $orderNumber = generate_order_number($pdo, $customerId, $repId);

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

                    // Calculate line totals
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

                // Calculate customer credit in USD equivalent
                $customerCreditUSD = $exchangeRate > 0 ? $customerCreditLBP / $exchangeRate : 0;

                // Calculate how much credit to use (up to the invoice total)
                $creditUsedUSD = min($customerCreditUSD, $totalUSD);
                $creditUsedLBP = $creditUsedUSD * $exchangeRate;

                // Calculate overpayment
                $remainingAfterCredit = max(0, $totalUSD - $creditUsedUSD);
                $overpaymentUSD = max(0, $totalPaymentUSD - $remainingAfterCredit);

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
                            total_usd, total_lbp, notes, invoice_ready
                        ) VALUES (
                            :order_number, 'van_stock_sale', 'delivered', :customer_id, :sales_rep_id, :exchange_rate_id,
                            :total_usd, :total_lbp, :notes, 1
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
                        INSERT INTO order_status_events (order_id, status, actor_user_id)
                        VALUES (:order_id, 'delivered', :actor_id)
                    ");
                    $statusStmt->execute([
                        ':order_id' => $orderId,
                        ':actor_id' => $repId,
                    ]);

                    // Update van stock and create stock movements
                    $stockUpdateStmt = $pdo->prepare("
                        UPDATE s_stock
                        SET qty_on_hand = qty_on_hand - :quantity
                        WHERE salesperson_id = :rep_id
                          AND product_id = :product_id
                          AND qty_on_hand >= :quantity
                    ");

                    $stockMovementStmt = $pdo->prepare("
                        INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                        VALUES (:rep_id, :product_id, :delta_qty, :reason, :note, NOW())
                    ");

                    foreach ($itemsWithPrices as $item) {
                        // Deduct from van stock
                        $stockUpdateStmt->execute([
                            ':quantity' => $item['quantity'],
                            ':rep_id' => $repId,
                            ':product_id' => $item['product_id'],
                        ]);

                        // Verify the update actually happened
                        if ($stockUpdateStmt->rowCount() === 0) {
                            throw new Exception("Unable to update stock for product ID {$item['product_id']}. Stock may have changed or is insufficient.");
                        }

                        // Log stock movement
                        $stockMovementStmt->execute([
                            ':rep_id' => $repId,
                            ':product_id' => $item['product_id'],
                            ':delta_qty' => -$item['quantity'],
                            ':reason' => 'sale',
                            ':note' => "Van sale order {$orderNumber}",
                        ]);
                    }

                    // Generate invoice with retry logic
                    $invoiceCreated = false;
                    $maxRetries = 3;
                    $invoiceId = null;

                    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                        try {
                            $invoiceNumber = generate_invoice_number($pdo, $customerId, $repId);
                            $totalPaidWithCredit = $totalPaymentUSD + $creditUsedUSD;
                            $invoiceStatus = $totalPaidWithCredit >= $totalUSD ? 'paid' : 'issued';

                            $invoiceStmt = $pdo->prepare("
                                INSERT INTO invoices (
                                    invoice_number, order_id, sales_rep_id, status, total_usd, total_lbp
                                ) VALUES (
                                    :invoice_number, :order_id, :sales_rep_id, :status, :total_usd, :total_lbp
                                )
                            ");
                            $invoiceStmt->execute([
                                ':invoice_number' => $invoiceNumber,
                                ':order_id' => $orderId,
                                ':sales_rep_id' => $repId,
                                ':status' => $invoiceStatus,
                                ':total_usd' => $totalUSD,
                                ':total_lbp' => $totalLBP,
                            ]);

                            $invoiceId = (int)$pdo->lastInsertId();
                            $invoiceCreated = true;
                            break;
                        } catch (PDOException $e) {
                            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'invoice_number') !== false) {
                                if ($attempt < $maxRetries) {
                                    usleep(100000);
                                    continue;
                                } else {
                                    throw new Exception('Failed to generate unique invoice number after ' . $maxRetries . ' attempts.');
                                }
                            }
                            throw $e;
                        }
                    }

                    if (!$invoiceCreated || !$invoiceId) {
                        throw new Exception('Failed to create invoice.');
                    }

                    // Calculate cash payment for invoice
                    $cashPaymentForInvoiceUSD = min($totalPaymentUSD, $remainingAfterCredit);
                    $cashPaymentForInvoiceLBP = $cashPaymentForInvoiceUSD * $exchangeRate;

                    // Create payment record for cash payment
                    if ($cashPaymentForInvoiceUSD > 0.01) {
                        $paymentStmt = $pdo->prepare("
                            INSERT INTO payments (
                                invoice_id, method, amount_usd, amount_lbp,
                                received_by_user_id, received_at
                            ) VALUES (
                                :invoice_id, :method, :amount_usd, :amount_lbp,
                                :received_by, NOW()
                            )
                        ");
                        $paymentStmt->execute([
                            ':invoice_id' => $invoiceId,
                            ':method' => 'cash',
                            ':amount_usd' => $cashPaymentForInvoiceUSD,
                            ':amount_lbp' => $cashPaymentForInvoiceLBP,
                            ':received_by' => $repId,
                        ]);
                    }

                    // Create payment record for credit used
                    if ($creditUsedUSD > 0.01) {
                        $creditPaymentStmt = $pdo->prepare("
                            INSERT INTO payments (
                                invoice_id, method, amount_usd, amount_lbp,
                                received_by_user_id, received_at
                            ) VALUES (
                                :invoice_id, 'account_credit', :amount_usd, :amount_lbp,
                                :received_by, NOW()
                            )
                        ");
                        $creditPaymentStmt->execute([
                            ':invoice_id' => $invoiceId,
                            ':amount_usd' => $creditUsedUSD,
                            ':amount_lbp' => $creditUsedLBP,
                            ':received_by' => $repId,
                        ]);
                    }

                    // Update customer account balance
                    $overpaymentLBP = $overpaymentUSD * $exchangeRate;
                    $netBalanceChangeLBP = $overpaymentLBP - $creditUsedLBP;
                    if (abs($netBalanceChangeLBP) > 0.01) {
                        $balanceStmt = $pdo->prepare("
                            UPDATE customers
                            SET account_balance_lbp = COALESCE(account_balance_lbp, 0) + :balance_change
                            WHERE id = :customer_id
                        ");
                        $balanceStmt->execute([
                            ':balance_change' => $netBalanceChangeLBP,
                            ':customer_id' => $customerId,
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

// Get all products with stock > 0 for this sales rep
$productsStmt = $pdo->prepare("
    SELECT
        p.id, p.sku, p.item_name, p.topcat_name as category,
        p.sale_price_usd, s.qty_on_hand
    FROM s_stock s
    JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id
      AND s.qty_on_hand > 0
      AND p.is_active = 1
    ORDER BY p.topcat_name, p.item_name
");
$productsStmt->execute([':rep_id' => $repId]);
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique categories for filter
$categories = array_unique(array_filter(array_column($products, 'category')));
sort($categories);

// Check if rep has any van stock
$hasStock = count($products) > 0;

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'Quick Sale - Visual Cart',
    'heading' => 'Quick Sale',
    'subtitle' => 'Tap products to add to cart',
    'active' => 'orders_cart',
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
            right: 20px;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white;
            padding: 18px 28px;
            border-radius: 50px;
            box-shadow: 0 6px 25px rgba(5, 150, 105, 0.4);
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
            box-shadow: 0 10px 35px rgba(5, 150, 105, 0.5);
        }
        .cart-button.empty {
            background: #9ca3af;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .cart-badge {
            background: white;
            color: #059669;
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
            color: #059669;
            font-weight: 700;
            margin-bottom: 4px;
            background: #dcfce7;
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
            border-color: var(--accent);
            background: var(--accent);
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
            color: var(--accent);
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
            border-color: var(--accent);
        }
        .customer-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid var(--accent);
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
            background: #f0f9ff;
        }
        .customer-option strong {
            display: block;
            margin-bottom: 2px;
        }
        .customer-option small {
            color: var(--muted);
        }
        .customer-selected {
            background: #dcfce7;
            border: 2px solid #22c55e;
            padding: 12px 16px;
            border-radius: 10px;
            margin-top: 10px;
            display: none;
        }
        .customer-selected.show {
            display: block;
        }
        .customer-selected strong {
            color: #166534;
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
            background: #f0f9ff;
            border-top: 2px solid #0ea5e9;
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
            border-top: 2px solid #0ea5e9;
            margin-top: 10px;
        }
        .cart-payment {
            padding: 20px 24px;
            background: white;
            border-top: 1px solid var(--border);
        }
        .cart-payment h4 {
            margin: 0 0 16px;
            font-size: 1.1rem;
        }
        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .payment-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .payment-group input {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .payment-group input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .payment-remaining {
            margin-top: 16px;
            padding: 14px;
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 10px;
        }
        .payment-remaining.paid {
            background: #dcfce7;
            border-color: #22c55e;
        }
        .payment-remaining.overpaid {
            background: #dbeafe;
            border-color: #3b82f6;
        }
        .payment-remaining-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-size: 0.95rem;
        }
        .payment-remaining-row:last-child {
            margin-bottom: 0;
        }
        .payment-remaining-row.highlight {
            font-weight: 700;
            font-size: 1.1rem;
        }
        .change-display {
            margin-top: 12px;
            padding: 14px;
            background: #dcfce7;
            border: 2px solid #22c55e;
            border-radius: 10px;
        }
        .change-display h5 {
            margin: 0 0 8px;
            color: #166534;
            font-size: 0.9rem;
        }
        .change-amount {
            font-size: 1.3rem;
            font-weight: 800;
            color: #166534;
        }
        .cart-submit {
            padding: 20px 24px;
            background: white;
            border-top: 1px solid var(--border);
        }
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
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
            box-shadow: 0 8px 20px rgba(5, 150, 105, 0.4);
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
        .flash {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        .flash.error {
            background: #fee2e2;
            border-color: #dc2626;
            color: #991b1b;
        }
        .flash h4 {
            margin: 0 0 8px;
        }
        @media (max-width: 600px) {
            .product-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .product-card img {
                height: 100px;
            }
            .payment-grid {
                grid-template-columns: 1fr;
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
    echo '</div>';
} elseif (!$hasStock) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">📦</div>';
    echo '<h3>No Products in Van Stock</h3>';
    echo '<p>You currently have no products in your van inventory.</p>';
    echo '<p>Please request stock from the warehouse to start making sales.</p>';
    echo '</div>';
} else {
?>
    <!-- Filter Bar -->
    <div class="filter-bar">
        <select id="categoryFilter" onchange="filterProducts()">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="searchFilter" placeholder="Search products..." oninput="filterProducts()">
    </div>

    <!-- Product Grid -->
    <div class="product-grid" id="productGrid">
        <?php foreach ($products as $product):
            $sku = $product['sku'] ?? '';
            $itemName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
            $category = $product['category'] ?? '';
            $priceUSD = (float)$product['sale_price_usd'];
            $stock = (float)$product['qty_on_hand'];

            // Find product image
            $imageExists = false;
            $imagePath = '../../images/products/default.jpg';
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
        ?>
            <div class="product-card"
                 data-id="<?= $product['id'] ?>"
                 data-name="<?= $itemName ?>"
                 data-sku="<?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?>"
                 data-price="<?= $priceUSD ?>"
                 data-stock="<?= $stock ?>"
                 data-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= $itemName ?>"
                     loading="lazy"
                     onerror="this.src='../../images/products/default.jpg'">
                <div class="product-sku"><?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="product-name"><?= $itemName ?></div>
                <div class="product-price">$<?= number_format($priceUSD, 2) ?></div>
                <div class="product-stock <?= $stock <= 5 ? 'low' : '' ?>">
                    Stock: <?= number_format($stock, 1) ?>
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
        <span>🛒</span>
        <span id="cartTotal">$0.00</span>
        <span class="cart-badge" id="cartBadge">0</span>
    </button>

    <!-- Cart Overlay -->
    <div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>

    <!-- Cart Panel -->
    <div class="cart-panel" id="cartPanel">
        <div class="cart-header">
            <h2>🛒 Your Cart</h2>
            <button class="cart-close" onclick="closeCart()">&times;</button>
        </div>

        <form method="POST" action="" id="cartForm">
            <input type="hidden" name="action" value="create_order">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="customer_id" id="customerId" value="">

            <div class="cart-items" id="cartItems">
                <div class="empty-state" id="emptyCart">
                    <div class="empty-state-icon">🛒</div>
                    <p>Your cart is empty. Tap products to add them.</p>
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
                    <div id="customerDropdown" class="customer-dropdown"></div>
                </div>
                <div id="customerSelected" class="customer-selected">
                    <strong id="selectedCustomerName"></strong>
                    <div id="selectedCustomerInfo" style="font-size: 0.85rem; color: #15803d;"></div>
                </div>
            </div>

            <div class="cart-notes">
                <label>Notes (Optional)</label>
                <textarea name="notes" id="orderNotes" placeholder="Add any notes..."></textarea>
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

            <div class="cart-payment">
                <h4>Payment</h4>
                <div class="payment-grid">
                    <div class="payment-group">
                        <label>USD $</label>
                        <input type="number" name="payment_usd" id="paymentUSD" step="0.01" min="0" placeholder="0.00" oninput="updatePaymentDisplay()">
                    </div>
                    <div class="payment-group">
                        <label>LBP L.L.</label>
                        <input type="number" name="payment_lbp" id="paymentLBP" step="1000" min="0" placeholder="0" oninput="updatePaymentDisplay()">
                    </div>
                </div>
                <div id="paymentRemaining" class="payment-remaining" style="display:none;">
                    <div class="payment-remaining-row">
                        <span>Total Due:</span>
                        <span id="displayTotalDue">$0.00</span>
                    </div>
                    <div class="payment-remaining-row">
                        <span>Total Paid:</span>
                        <span id="displayTotalPaid">$0.00</span>
                    </div>
                    <div class="payment-remaining-row highlight">
                        <span id="remainingLabel">Remaining:</span>
                        <span id="displayRemaining">$0.00</span>
                    </div>
                    <div class="payment-remaining-row" style="margin-top:8px; padding-top:8px; border-top:1px solid rgba(0,0,0,0.1);">
                        <span>In LBP:</span>
                        <span id="displayRemainingLBP">L.L. 0</span>
                    </div>
                </div>
                <div id="changeDisplay" class="change-display" style="display:none;">
                    <h5>💵 Change to Return:</h5>
                    <div class="change-amount">
                        <span id="changeUSD">$0.00</span> / <span id="changeLBP">L.L. 0</span>
                    </div>
                </div>
            </div>

            <div class="cart-submit">
                <button type="submit" class="btn-submit" id="submitBtn" disabled>
                    Complete Sale & Print Invoice
                </button>
            </div>

            <!-- Hidden cart items inputs -->
            <div id="cartInputs"></div>
        </form>
    </div>

    <script>
        const exchangeRate = <?= $exchangeRate ?>;
        let cart = {};
        let debounceTimer = null;

        // Filter products
        function filterProducts() {
            const category = document.getElementById('categoryFilter').value.toLowerCase();
            const search = document.getElementById('searchFilter').value.toLowerCase();

            document.querySelectorAll('.product-card').forEach(card => {
                const cardCategory = (card.dataset.category || '').toLowerCase();
                const cardName = (card.dataset.name || '').toLowerCase();

                const matchesCategory = !category || cardCategory === category;
                const matchesSearch = !search || cardName.includes(search);

                card.style.display = (matchesCategory && matchesSearch) ? 'block' : 'none';
            });
        }

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
            if (cart[id] && cart[id].quantity < cart[id].stock) {
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
                    if (plusBtn) plusBtn.disabled = cart[id].quantity >= cart[id].stock;
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
                        <div class="empty-state-icon">🛒</div>
                        <p>Your cart is empty. Tap products to add them.</p>
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

                itemsHtml += `
                    <div class="cart-item">
                        <div class="cart-item-info">
                            <div class="cart-item-sku">${escapeHtml(item.sku || '')}</div>
                            <div class="cart-item-name">${escapeHtml(item.name)}</div>
                            <div class="cart-item-qty">Qty: ${item.quantity} / Stock: ${item.stock}</div>
                            <div class="cart-item-price">$${item.price.toFixed(2)} × ${item.quantity} = $${subtotal.toFixed(2)}</div>
                        </div>
                        <div class="cart-item-controls">
                            <button type="button" class="qty-btn remove" onclick="removeFromCart(${id})">−</button>
                            <span class="qty-value">${item.quantity}</span>
                            <button type="button" class="qty-btn" onclick="increaseQty(${id})" ${item.quantity >= item.stock ? 'disabled' : ''}>+</button>
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
            if (cart[id] && cart[id].quantity < cart[id].stock) {
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

        // Customer search
        const customerSearch = document.getElementById('customerSearch');
        const customerDropdown = document.getElementById('customerDropdown');
        const customerSelected = document.getElementById('customerSelected');
        const customerIdInput = document.getElementById('customerId');

        customerSearch.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(debounceTimer);

            if (query.length < 1) {
                customerDropdown.classList.remove('show');
                return;
            }

            debounceTimer = setTimeout(() => {
                fetch(`?action=search_customers&q=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.results && data.results.length > 0) {
                            customerDropdown.innerHTML = data.results.map(c => `
                                <div class="customer-option" onclick="selectCustomer(${c.id}, '${escapeHtml(c.name)}', '${escapeHtml(c.phone || '')}', '${escapeHtml(c.city || '')}')">
                                    <strong>${escapeHtml(c.name)}</strong>
                                    <small>${escapeHtml(c.phone || 'No phone')} ${c.city ? '| ' + escapeHtml(c.city) : ''}</small>
                                </div>
                            `).join('');
                            customerDropdown.classList.add('show');
                        } else {
                            customerDropdown.innerHTML = '<div class="customer-option" style="cursor:default;">No customers found</div>';
                            customerDropdown.classList.add('show');
                        }
                    });
            }, 300);
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

        // Update payment display with remaining and change
        function updatePaymentDisplay() {
            const paymentUSD = parseFloat(document.getElementById('paymentUSD').value) || 0;
            const paymentLBP = parseFloat(document.getElementById('paymentLBP').value) || 0;
            const paymentRemainingDiv = document.getElementById('paymentRemaining');
            const changeDisplayDiv = document.getElementById('changeDisplay');

            // Calculate total due
            let totalDueUSD = 0;
            Object.keys(cart).forEach(id => {
                totalDueUSD += cart[id].price * cart[id].quantity;
            });

            if (totalDueUSD <= 0) {
                paymentRemainingDiv.style.display = 'none';
                changeDisplayDiv.style.display = 'none';
                return;
            }

            // Convert LBP payment to USD
            const paymentLBPinUSD = paymentLBP / exchangeRate;
            const totalPaidUSD = paymentUSD + paymentLBPinUSD;
            const remainingUSD = totalDueUSD - totalPaidUSD;
            const remainingLBP = remainingUSD * exchangeRate;

            // Update display
            document.getElementById('displayTotalDue').textContent = '$' + totalDueUSD.toFixed(2);
            document.getElementById('displayTotalPaid').textContent = '$' + totalPaidUSD.toFixed(2);

            if (remainingUSD > 0.01) {
                // Still owes money
                document.getElementById('remainingLabel').textContent = 'Remaining:';
                document.getElementById('displayRemaining').textContent = '$' + remainingUSD.toFixed(2);
                document.getElementById('displayRemainingLBP').textContent = 'L.L. ' + Math.round(remainingLBP).toLocaleString();
                paymentRemainingDiv.className = 'payment-remaining';
                changeDisplayDiv.style.display = 'none';
            } else if (remainingUSD < -0.01) {
                // Overpaid - show change
                const changeUSD = Math.abs(remainingUSD);
                const changeLBP = changeUSD * exchangeRate;
                document.getElementById('remainingLabel').textContent = 'Overpaid:';
                document.getElementById('displayRemaining').textContent = '$' + changeUSD.toFixed(2);
                document.getElementById('displayRemainingLBP').textContent = 'L.L. ' + Math.round(changeLBP).toLocaleString();
                paymentRemainingDiv.className = 'payment-remaining overpaid';

                // Show change to return
                document.getElementById('changeUSD').textContent = '$' + changeUSD.toFixed(2);
                document.getElementById('changeLBP').textContent = 'L.L. ' + Math.round(changeLBP).toLocaleString();
                changeDisplayDiv.style.display = 'block';
            } else {
                // Exact payment
                document.getElementById('remainingLabel').textContent = 'Remaining:';
                document.getElementById('displayRemaining').textContent = '$0.00';
                document.getElementById('displayRemainingLBP').textContent = 'L.L. 0';
                paymentRemainingDiv.className = 'payment-remaining paid';
                changeDisplayDiv.style.display = 'none';
            }

            paymentRemainingDiv.style.display = 'block';
        }

        // Also update payment display when cart changes
        const originalUpdateCartDisplay = updateCartDisplay;
        updateCartDisplay = function() {
            originalUpdateCartDisplay();
            updatePaymentDisplay();
        };

        // Form validation
        document.getElementById('cartForm').addEventListener('submit', function(e) {
            const customerId = document.getElementById('customerId').value;
            const itemCount = Object.keys(cart).length;

            if (!customerId) {
                e.preventDefault();
                alert('Please select a customer.');
                return false;
            }

            if (itemCount === 0) {
                e.preventDefault();
                alert('Please add at least one product.');
                return false;
            }

            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').textContent = 'Processing...';
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
