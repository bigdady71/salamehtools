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
            WHERE assigned_sales_rep_id = :rep_id*
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

// Handle AJAX product search
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'search_products') {
    header('Content-Type: application/json');

    $query = trim((string)($_GET['q'] ?? ''));
    $showZero = isset($_GET['show_zero']) && $_GET['show_zero'] === '1';

    if (strlen($query) < 1) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $searchPattern = '%' . $query . '%';

        // Build stock condition - hide zero by default unless show_zero is checked
        $stockCondition = $showZero ? 's.qty_on_hand >= 0' : 's.qty_on_hand > 0';

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.sku,
                p.item_name,
                p.topcat as category,
                p.barcode,
                p.code_clean,
                p.sale_price_usd,
                s.qty_on_hand
            FROM s_stock s
            JOIN products p ON p.id = s.product_id
            WHERE s.salesperson_id = :rep_id
              AND {$stockCondition}
              AND p.is_active = 1
              AND (
                  p.item_name LIKE :pattern
                  OR p.sku LIKE :pattern
                  OR p.barcode LIKE :pattern
                  OR p.code_clean LIKE :pattern
                  OR p.topcat LIKE :pattern
              )
            ORDER BY p.item_name
            LIMIT 20
        ");
        $stmt->execute([
            ':rep_id' => $repId,
            ':pattern' => $searchPattern
        ]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['results' => $results]);
    } catch (PDOException $e) {
        error_log("Product search failed: " . $e->getMessage());
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
                // Format: {customerId}-{salespersonId}-{sequence}
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

                // Calculate customer credit in USD equivalent
                $customerCreditUSD = $exchangeRate > 0 ? $customerCreditLBP / $exchangeRate : 0;

                // Total available payment = cash payment + customer credit
                $totalAvailableUSD = $totalPaymentUSD + $customerCreditUSD;

                // Calculate how much credit to use (up to the invoice total)
                $creditUsedUSD = min($customerCreditUSD, $totalUSD);
                $creditUsedLBP = $creditUsedUSD * $exchangeRate;

                // Calculate overpayment (if cash payment exceeds remaining after credit)
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
                        // Deduct from van stock with additional safety check
                        $stockUpdateStmt->execute([
                            ':quantity' => $item['quantity'],
                            ':rep_id' => $repId,
                            ':product_id' => $item['product_id'],
                        ]);

                        // Verify the update actually happened
                        if ($stockUpdateStmt->rowCount() === 0) {
                            throw new Exception("Unable to update stock for product ID {$item['product_id']}. Stock may have changed or is insufficient.");
                        }

                        // Log stock movement (negative delta for outgoing sale)
                        $stockMovementStmt->execute([
                            ':rep_id' => $repId,
                            ':product_id' => $item['product_id'],
                            ':delta_qty' => -$item['quantity'],
                            ':reason' => 'sale',
                            ':note' => "Van sale order {$orderNumber}",
                        ]);
                    }

                    // Generate invoice with retry logic for duplicate handling
                    $invoiceCreated = false;
                    $maxRetries = 3;
                    $invoiceId = null;

                    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                        try {
                            $invoiceNumber = generate_invoice_number($pdo, $customerId, $repId);
                            // Invoice is paid if cash payment + credit used covers the total
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
                            // If duplicate key error, retry with new invoice number
                            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'invoice_number') !== false) {
                                if ($attempt < $maxRetries) {
                                    // Small delay before retry
                                    usleep(100000); // 100ms
                                    continue;
                                } else {
                                    throw new Exception('Failed to generate unique invoice number after ' . $maxRetries . ' attempts. Please contact support.');
                                }
                            }
                            throw $e;
                        }
                    }

                    if (!$invoiceCreated || !$invoiceId) {
                        throw new Exception('Failed to create invoice.');
                    }

                    // Calculate how much cash payment applies to THIS invoice (capped at remaining after credit)
                    // Any excess is overpayment that goes to customer credit
                    $cashPaymentForInvoiceUSD = min($totalPaymentUSD, $remainingAfterCredit);
                    $cashPaymentForInvoiceLBP = $cashPaymentForInvoiceUSD * $exchangeRate;

                    // Create payment record for cash payment if provided (USD or LBP)
                    // Only record up to the invoice amount - overpayment goes to customer credit
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

                    // Create payment record for credit used (if any)
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

                    // Update customer account balance:
                    // - Deduct credit used (negative)
                    // - Add overpayment as new credit (positive)
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

// Check if rep has any van stock (for display purposes)
$hasStockStmt = $pdo->prepare("
    SELECT COUNT(*) as stock_count
    FROM s_stock s
    JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id
      AND s.qty_on_hand > 0
      AND p.is_active = 1
");
$hasStockStmt->execute([':rep_id' => $repId]);
$hasStock = ((int)$hasStockStmt->fetchColumn()) > 0;

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
            margin: 0 0 20px;
            font-size: 1.4rem;
            color: #000;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: #059669;
            color: white;
            border-radius: 50%;
            font-size: 1.1rem;
            font-weight: 700;
        }

        /* Small toggle checkbox */
        .checkbox-toggle-small {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 22px;
            cursor: pointer;
        }
        .checkbox-toggle-small input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider-small {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            border-radius: 22px;
            transition: 0.3s;
        }
        .toggle-slider-small:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            border-radius: 50%;
            transition: 0.3s;
        }
        .checkbox-toggle-small input:checked + .toggle-slider-small {
            background-color: #059669;
        }
        .checkbox-toggle-small input:checked + .toggle-slider-small:before {
            transform: translateX(18px);
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 1.1rem;
            color: #000;
        }

        .form-control {
            width: 100%;
            padding: 16px;
            border: 2px solid #333;
            border-radius: 10px;
            font-size: 18px; /* Larger for easier reading */
            transition: border-color 0.2s;
            color: #000;
        }

        .form-control:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.2);
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
            background: #f0f9ff;
            color: #000;
            padding: 28px;
            border-radius: 16px;
            margin-top: 24px;
            display: none;
            border: 3px solid #0ea5e9;
        }

        .order-summary.show {
            display: block;
        }

        .order-summary h4 {
            margin: 0 0 20px;
            font-size: 1.5rem;
            color: #000;
            font-weight: 700;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 1.1rem;
            color: #000;
        }

        .summary-row.total {
            font-size: 1.5rem;
            font-weight: 700;
            padding-top: 16px;
            border-top: 3px solid #0ea5e9;
            margin-top: 16px;
            color: #000;
        }

        .payment-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-top: 20px;
        }

        .payment-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 480px) {
            .payment-inputs {
                grid-template-columns: 1fr;
            }
        }

        .btn-submit {
            width: 100%;
            padding: 20px;
            background: #059669;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.4rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 24px;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            background: #047857;
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        }

        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #9ca3af;
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
} elseif (!$hasStock) {
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
            <h3><span class="step-badge">1</span> اختر الزبون / Select Customer</h3>
            <div class="form-group">
                <label for="customerSearch">ابحث بالاسم او رقم الهاتف</label>
                <div class="customer-search-wrapper">
                    <input
                        type="text"
                        id="customerSearch"
                        class="form-control"
                        placeholder="Type at least 1 character to search..."
                        autocomplete="off">
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
            <h3><span class="step-badge">2</span> اختر المنتجات / Select Products</h3>
            <div class="form-group">
                <label for="productSearch">ابحث بالاسم، الكود، الباركود، او الفئة</label>
                <div class="customer-search-wrapper">
                    <input
                        type="text"
                        id="productSearch"
                        class="form-control"
                        placeholder="Type at least 1 character to search..."
                        autocomplete="off">
                    <div id="productDropdown" class="autocomplete-dropdown" style="display: none;"></div>
                </div>
                <div style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">
                    <label class="checkbox-toggle-small">
                        <input type="checkbox" id="showZeroStock">
                        <span class="toggle-slider-small"></span>
                    </label>
                    <span style="font-size: 0.9rem; color: var(--muted);">Show zero stock items</span>
                </div>
            </div>

            <div id="selectedProducts" class="selected-products" style="display: none;">
                <h4 style="margin: 0 0 12px; font-size: 1.1rem;">Products in this sale:</h4>
                <div id="selectedProductsList"></div>
            </div>
        </div>

        <!-- Step 3: Notes -->
        <div class="form-section">
            <h3><span class="step-badge">3</span> ملاحظات / Notes (اختياري)</h3>
            <div class="form-group">
                <textarea
                    name="notes"
                    id="notes"
                    class="form-control"
                    rows="3"
                    placeholder="اضف اي ملاحظات عن هذه البيعة..."></textarea>
            </div>
        </div>

        <!-- Order Summary & Payment -->
        <div id="orderSummary" class="order-summary">
            <h4>ملخص الطلب / Order Summary</h4>
            <div class="summary-row">
                <span>عدد المنتجات:</span>
                <span id="summaryItemCount">0</span>
            </div>
            <div class="summary-row total">
                <span>المجموع بالدولار:</span>
                <span id="summaryTotalUSD">$0.00</span>
            </div>
            <div class="summary-row">
                <span>المجموع بالليرة:</span>
                <span id="summaryTotalLBP">L.L. 0</span>
            </div>

            <div class="payment-section" style="background: white; border: 3px solid #000; border-radius: 12px;">
                <h4 style="margin: 0 0 16px; font-size: 1.3rem; color: #000; font-weight: 700;">الدفع / Payment</h4>
                <p style="font-size: 1rem; margin: 0 0 16px; color: #333;">ادخل المبلغ المدفوع (دولار او ليرة او كلاهما)</p>
                <div class="payment-inputs">
                    <div class="form-group" style="margin: 0;">
                        <label for="paymentUSD" style="color: #000; font-size: 1.1rem; font-weight: 700;">دولار USD $</label>
                        <input
                            type="number"
                            id="paymentUSD"
                            name="payment_usd"
                            class="form-control"
                            style="font-size: 1.3rem; padding: 16px; border: 2px solid #000; font-weight: 600;"
                            step="0.01"
                            min="0"
                            placeholder="0.00">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <label for="paymentLBP" style="color: #000; font-size: 1.1rem; font-weight: 700;">ليرة LBP ل.ل.</label>
                        <input
                            type="number"
                            id="paymentLBP"
                            name="payment_lbp"
                            class="form-control"
                            style="font-size: 1.3rem; padding: 16px; border: 2px solid #000; font-weight: 600;"
                            step="1000"
                            min="0"
                            placeholder="0">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                اتمام البيع وطباعة الفاتورة
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
            document.getElementById('productSearch').scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Product search autocomplete
        const productSearch = document.getElementById('productSearch');
        const productDropdown = document.getElementById('productDropdown');
        const showZeroStockCheckbox = document.getElementById('showZeroStock');
        let productDebounceTimer = null;

        productSearch.addEventListener('input', function() {
            const query = this.value.trim();

            clearTimeout(productDebounceTimer);

            if (query.length < 1) {
                productDropdown.style.display = 'none';
                return;
            }

            productDebounceTimer = setTimeout(() => {
                const showZero = showZeroStockCheckbox.checked ? '1' : '0';
                fetch(`?action=search_products&q=${encodeURIComponent(query)}&show_zero=${showZero}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.results && data.results.length > 0) {
                            productDropdown.innerHTML = data.results.map(product => {
                                const stockQty = parseFloat(product.qty_on_hand);
                                const isZeroStock = stockQty <= 0;
                                const isLowStock = stockQty > 0 && stockQty <= 5;

                                let stockStyle = '';
                                let stockLabel = '';
                                if (isZeroStock) {
                                    stockStyle = ' style="background: #fee2e2; opacity: 0.7;"';
                                    stockLabel = ' (OUT OF STOCK)';
                                } else if (isLowStock) {
                                    stockStyle = ' style="background: #fef9c3;"';
                                }

                                return `
                                    <div class="autocomplete-item product-result" data-product-id="${product.id}" data-product-name="${escapeHtml(product.item_name)}" data-product-price="${product.sale_price_usd}" data-product-stock="${product.qty_on_hand}"${stockStyle}>
                                        <strong>${escapeHtml(product.item_name)}${stockLabel}</strong>
                                        <small>SKU: ${escapeHtml(product.sku)} | Stock: ${stockQty.toFixed(2)} | $${parseFloat(product.sale_price_usd).toFixed(2)}</small>
                                    </div>
                                `;
                            }).join('');
                            productDropdown.style.display = 'block';

                            // Add click handlers
                            document.querySelectorAll('.product-result').forEach(item => {
                                item.addEventListener('click', function() {
                                    const productId = this.dataset.productId;
                                    const productName = this.dataset.productName;
                                    const productPrice = parseFloat(this.dataset.productPrice);
                                    const productStock = parseFloat(this.dataset.productStock);

                                    // Don't allow adding zero stock items
                                    if (productStock <= 0) {
                                        alert('Cannot add out of stock items to sale.');
                                        return;
                                    }

                                    if (!selectedProducts[productId]) {
                                        selectedProducts[productId] = {
                                            name: productName,
                                            price: productPrice,
                                            stock: productStock,
                                            quantity: 1,
                                            discount: 0
                                        };

                                        renderSelectedProducts();
                                        updateOrderSummary();
                                        productSearch.value = '';
                                        productDropdown.style.display = 'none';
                                    } else {
                                        alert('This product is already added to the sale.');
                                    }
                                });
                            });
                        } else {
                            productDropdown.innerHTML = '<div class="autocomplete-item" style="cursor: default;">No products found in van stock</div>';
                            productDropdown.style.display = 'block';
                        }
                    })
                    .catch(err => {
                        console.error('Product search failed:', err);
                        productDropdown.style.display = 'none';
                    });
            }, 300);
        });

        // Close product dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!productSearch.contains(e.target) && !productDropdown.contains(e.target)) {
                productDropdown.style.display = 'none';
            }
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
                const subtotal = product.price * product.quantity;

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
                                    min="1"
                                    max="${product.stock}"
                                    step="1"
                                >
                                <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                                <input type="hidden" name="items[${productId}][quantity]" value="${product.quantity}" class="quantity-hidden-${productId}">
                                <input type="hidden" name="items[${productId}][discount]" value="0">
                            </div>
                        </div>
                        <div class="subtotal" id="subtotal-${productId}">Subtotal: $${subtotal.toFixed(2)}</div>
                    </div>
                `;
            }).join('');

            // Add event listeners for quantity changes
            document.querySelectorAll('.quantity-input').forEach(input => {
                // Select all text on focus for easy editing
                input.addEventListener('focus', function() {
                    this.select();
                });

                // Update values as user types (without re-rendering)
                input.addEventListener('input', function() {
                    const productId = this.dataset.productId;
                    let value = parseFloat(this.value) || 0;
                    const maxStock = selectedProducts[productId].stock;
                    const product = selectedProducts[productId];

                    // Allow typing without immediate validation (will validate on blur)
                    if (value > 0 && value <= maxStock) {
                        selectedProducts[productId].quantity = value;
                        document.querySelector(`.quantity-hidden-${productId}`).value = value;

                        // Update subtotal display without re-render
                        const subtotal = product.price * value;
                        const subtotalEl = document.getElementById(`subtotal-${productId}`);
                        if (subtotalEl) {
                            subtotalEl.textContent = `Subtotal: $${subtotal.toFixed(2)}`;
                        }
                        updateOrderSummary();
                    }
                });

                // Validate on blur (when leaving the field)
                input.addEventListener('blur', function() {
                    const productId = this.dataset.productId;
                    let value = parseFloat(this.value) || 1;
                    const maxStock = selectedProducts[productId].stock;

                    if (value < 1) {
                        value = 1;
                        this.value = 1;
                    } else if (value > maxStock) {
                        alert(`Maximum available stock is ${maxStock}`);
                        value = maxStock;
                        this.value = maxStock;
                    }

                    selectedProducts[productId].quantity = value;
                    document.querySelector(`.quantity-hidden-${productId}`).value = value;

                    // Update subtotal display
                    const product = selectedProducts[productId];
                    const subtotal = product.price * value;
                    const subtotalEl = document.getElementById(`subtotal-${productId}`);
                    if (subtotalEl) {
                        subtotalEl.textContent = `Subtotal: $${subtotal.toFixed(2)}`;
                    }
                    updateOrderSummary();
                });
            });
        }

        function removeProduct(productId) {
            delete selectedProducts[productId];
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
            let totalUSD = 0;

            Object.values(selectedProducts).forEach(product => {
                itemCount += 1;
                totalUSD += product.price * product.quantity;
            });

            const totalLBP = totalUSD * exchangeRate;

            document.getElementById('summaryItemCount').textContent = itemCount;
            document.getElementById('summaryTotalUSD').textContent = '$' + totalUSD.toFixed(2);
            document.getElementById('summaryTotalLBP').textContent = 'L.L. ' + totalLBP.toLocaleString('en-US', {
                maximumFractionDigits: 0
            });
        }

        // Payment fields - independent inputs for split payment
        const paymentUSD = document.getElementById('paymentUSD');
        const paymentLBP = document.getElementById('paymentLBP');

        // Calculate remaining amounts in both directions
        function updateRemainingPayments() {
            let totalUSD = 0;
            Object.values(selectedProducts).forEach(product => {
                totalUSD += product.price * product.quantity;
            });

            const totalLBP = totalUSD * exchangeRate;
            const paidUSD = parseFloat(paymentUSD.value) || 0;
            const paidLBP = parseFloat(paymentLBP.value) || 0;

            // Convert paid amounts to USD equivalent
            const paidLBPinUSD = paidLBP / exchangeRate;
            const totalPaidUSD = paidUSD + paidLBPinUSD;
            const remainingUSD = totalUSD - totalPaidUSD;

            // Update LBP placeholder (show remaining after USD payment)
            if (paidUSD > 0 && remainingUSD > 0) {
                const remainingLBP = Math.ceil(remainingUSD * exchangeRate);
                paymentLBP.placeholder = remainingLBP.toLocaleString('en-US') + ' (المتبقي)';
            } else if (totalPaidUSD >= totalUSD && totalUSD > 0) {
                paymentLBP.placeholder = '0 (مدفوع بالكامل)';
            } else {
                paymentLBP.placeholder = '0';
            }

            // Update USD placeholder (show remaining after LBP payment)
            if (paidLBP > 0 && remainingUSD > 0) {
                paymentUSD.placeholder = remainingUSD.toFixed(2) + ' (المتبقي)';
            } else if (totalPaidUSD >= totalUSD && totalUSD > 0) {
                paymentUSD.placeholder = '0.00 (مدفوع بالكامل)';
            } else {
                paymentUSD.placeholder = '0.00';
            }
        }

        paymentUSD.addEventListener('input', updateRemainingPayments);
        paymentLBP.addEventListener('input', updateRemainingPayments);

        // Also update when products change - wrap the original updateOrderSummary
        const originalUpdateOrderSummary = updateOrderSummary;
        updateOrderSummary = function() {
            originalUpdateOrderSummary();
            updateRemainingPayments();
        };

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
            document.getElementById('submitBtn').textContent = 'جاري المعالجة...';
        });
    </script>
<?php
}
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
sales_portal_render_layout_end();
