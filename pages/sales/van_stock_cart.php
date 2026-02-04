<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/counter.php';
require_once __DIR__ . '/../../includes/InvoicePDF.php';

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
        $paymentAmountUSD = (float)($_POST['payment_usd'] ?? 0);
        $paymentAmountLBP = (float)($_POST['payment_lbp'] ?? 0);
        $centsDiscountFromClient = (float)($_POST['cents_discount'] ?? 0);

        $errors = [];

        // Validate customer and get their credit balance
        $customerCreditLBP = 0;
        $customerPhone = '';
        $customerName = '';
        if ($customerId <= 0) {
            $errors[] = 'Please select a customer.';
        } else {
            // Verify customer is assigned to this sales rep and get credit balance + phone
            $customerStmt = $pdo->prepare("SELECT id, name, phone, COALESCE(account_balance_lbp, 0) as credit_lbp FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id AND is_active = 1");
            $customerStmt->execute([':id' => $customerId, ':rep_id' => $repId]);
            $customerData = $customerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$customerData) {
                $errors[] = 'Invalid customer selected or customer not assigned to you.';
            } else {
                $customerCreditLBP = (float)$customerData['credit_lbp'];
                $customerPhone = trim((string)($customerData['phone'] ?? ''));
                $customerName = trim((string)($customerData['name'] ?? ''));
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
                            p.wholesale_price_usd,
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

                    $unitPriceUSD = (float)$product['wholesale_price_usd'];
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

                // Apply cents discount: if total >= $20 and has decimals, discount the cents
                $centsDiscount = 0;
                $cents = $totalUSD - floor($totalUSD);
                if ($totalUSD >= 20 && $cents > 0.001) {
                    $centsDiscount = $cents;
                    $totalUSD = floor($totalUSD);
                    $totalLBP = $totalUSD * $exchangeRate;

                    // Add discount note
                    $discountNote = "خصم القروش: $" . number_format($centsDiscount, 2);
                    if ($notes !== '') {
                        $notes = $discountNote . "\n" . $notes;
                    } else {
                        $notes = $discountNote;
                    }
                }

                // Customer balance logic:
                // Positive balance = customer owes us money (debt)
                // Negative balance = we owe customer money (credit)
                // When making a sale: unpaid amount gets ADDED to balance

                // Calculate how much is unpaid after cash payment
                $unpaidUSD = max(0, $totalUSD - $totalPaymentUSD);
                $unpaidLBP = $unpaidUSD * $exchangeRate;

                // Calculate overpayment (if cash exceeds invoice total - adds credit to customer)
                $overpaymentUSD = max(0, $totalPaymentUSD - $totalUSD);
                $overpaymentLBP = $overpaymentUSD * $exchangeRate;

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
                            // Invoice is paid only if cash payment covers the total
                            $invoiceStatus = $totalPaymentUSD >= $totalUSD ? 'paid' : 'issued';

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

                    // Record payments in their original currencies (no conversion)
                    // This allows tracking actual amounts collected in each currency for commission calculations

                    $paymentStmt = $pdo->prepare("
                        INSERT INTO payments (
                            invoice_id, method, amount_usd, amount_lbp,
                            received_by_user_id, received_at
                        ) VALUES (
                            :invoice_id, :method, :amount_usd, :amount_lbp,
                            :received_by, NOW()
                        )
                    ");

                    // Record USD payment if any
                    if ($paymentAmountUSD > 0.01) {
                        $paymentStmt->execute([
                            ':invoice_id' => $invoiceId,
                            ':method' => 'cash_usd',
                            ':amount_usd' => $paymentAmountUSD,
                            ':amount_lbp' => 0,
                            ':received_by' => $repId,
                        ]);
                    }

                    // Record LBP payment if any (store as USD equivalent for totals, but keep original LBP)
                    if ($paymentAmountLBP > 1000) {
                        $paymentStmt->execute([
                            ':invoice_id' => $invoiceId,
                            ':method' => 'cash_lbp',
                            ':amount_usd' => $paymentLBPinUSD, // USD equivalent for invoice tracking
                            ':amount_lbp' => $paymentAmountLBP, // Actual LBP received
                            ':received_by' => $repId,
                        ]);
                    }

                    // Update customer account balance
                    // Add unpaid amount (increases their debt)
                    // Subtract overpayment (decreases their debt / gives them credit)
                    $balanceChangeLBP = $unpaidLBP - $overpaymentLBP;
                    if (abs($balanceChangeLBP) > 0.01) {
                        $balanceStmt = $pdo->prepare("
                            UPDATE customers
                            SET account_balance_lbp = COALESCE(account_balance_lbp, 0) + :balance_change
                            WHERE id = :customer_id
                        ");
                        $balanceStmt->execute([
                            ':balance_change' => $balanceChangeLBP,
                            ':customer_id' => $customerId,
                        ]);
                    }

                    $pdo->commit();

                    // Auto-generate and save PDF for the invoice
                    try {
                        $pdfGenerator = new InvoicePDF($pdo);
                        $pdfGenerator->savePDF($invoiceId);
                    } catch (Exception $pdfError) {
                        // Log error but don't fail the sale
                        error_log("Failed to auto-generate PDF for invoice {$invoiceId}: " . $pdfError->getMessage());
                    }

                    if ($wantsJson) {
                        header('Content-Type: application/json');

                        // Format phone for WhatsApp
                        $whatsappPhone = '';
                        if ($customerPhone) {
                            $phone = preg_replace('/[^0-9+]/', '', $customerPhone);
                            if (strpos($phone, '+961') === 0) {
                                $whatsappPhone = '961' . substr($phone, 4);
                            } elseif (strpos($phone, '00961') === 0) {
                                $whatsappPhone = '961' . substr($phone, 5);
                            } elseif (strpos($phone, '961') === 0) {
                                $whatsappPhone = $phone;
                            } elseif (strpos($phone, '0') === 0) {
                                $whatsappPhone = '961' . substr($phone, 1);
                            } else {
                                $whatsappPhone = '961' . $phone;
                            }
                        }

                        // Build WhatsApp URL
                        $whatsappUrl = '';
                        if ($whatsappPhone) {
                            $invoiceMessage = "مرحبا {$customerName}،\n\n";
                            $invoiceMessage .= "فاتورتك من سلامة تولز جاهزة:\n";
                            $invoiceMessage .= "رقم الفاتورة: {$invoiceNumber}\n";
                            $invoiceMessage .= "الإجمالي: \${$totalUSD}\n\n";
                            $invoiceMessage .= "يمكنك عرض الفاتورة من الرابط أدناه:\n";
                            $invoiceMessage .= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/invoice_pdf.php?invoice_id={$invoiceId}&action=view";
                            $invoiceMessage .= "\n\nشكراً لتعاملك معنا!";
                            $whatsappUrl = 'https://wa.me/' . $whatsappPhone . '?text=' . rawurlencode($invoiceMessage);
                        }

                        echo json_encode([
                            'ok' => true,
                            'message' => 'Sale completed successfully.',
                            'invoice_id' => $invoiceId,
                            'invoice_number' => $invoiceNumber,
                            'customer_name' => $customerName,
                            'customer_phone' => $customerPhone,
                            'total_usd' => $totalUSD,
                            'redirect_url' => "print_invoice.php?invoice_id={$invoiceId}",
                            'whatsapp_url' => $whatsappUrl,
                            'pdf_url' => "invoice_pdf.php?invoice_id={$invoiceId}&action=download",
                        ]);
                        exit;
                    }

                    // Redirect to print invoice
                    header("Location: print_invoice.php?invoice_id={$invoiceId}");
                    exit;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create van stock sale: " . $e->getMessage());
                if ($wantsJson) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok' => false,
                        'message' => 'Unable to create sale order. Please try again.',
                    ]);
                    exit;
                }
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to create sale order. Please try again. Error: ' . $e->getMessage(),
                    'dismissible' => true,
                ];
            }
        } else {
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'message' => 'Unable to create sale. Please fix the errors below.',
                    'errors' => $errors,
                ]);
                exit;
            }
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

// Get all products for this sales rep (including qty=0 and price=0 for toggle filter)
$productsStmt = $pdo->prepare("
    SELECT
        p.id, p.sku, p.item_name, p.topcat_name as category,
        p.wholesale_price_usd, COALESCE(s.qty_on_hand, 0) as qty_on_hand
    FROM s_stock s
    JOIN products p ON p.id = s.product_id
    WHERE s.salesperson_id = :rep_id
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
    'title' => 'بيع سريع',
    'heading' => 'بيع سريع',
    'subtitle' => 'اضغط على المنتجات لإضافتها إلى السلة',
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
        .filter-bar input[type="text"] {
            flex: 1;
            min-width: 180px;
        }
        .toggle-filter {
            background: var(--bg-panel);
            padding: 8px 14px;
            border-radius: 8px;
            border: 2px solid var(--border);
            white-space: nowrap;
        }
        .toggle-filter:hover {
            border-color: var(--accent);
        }
        .toggle-filter input[type="checkbox"] {
            accent-color: var(--accent);
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
            left: 20px;
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
        .payment-group input::placeholder {
            color: #059669;
            font-weight: 600;
            font-size: 0.95rem;
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
        .flash.success {
            background: #d1fae5;
            border-color: #059669;
            color: #065f46;
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

echo '<div id="ajaxFlash"></div>';

if (!$canCreateOrder) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">⚠️</div>';
    echo '<h3>النظام غير متاح</h3>';
    echo '<p>نظام المبيعات غير متاح حالياً بسبب عدم توفر سعر الصرف.</p>';
    echo '</div>';
} elseif (!$hasStock) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">📦</div>';
    echo '<h3>لا توجد منتجات في مخزون السيارة</h3>';
    echo '<p>ليس لديك حالياً أي منتجات في مخزون سيارتك.</p>';
    echo '<p>يرجى طلب البضاعة من المستودع للبدء بالبيع.</p>';
    echo '</div>';
} else {
?>
    <!-- Filter Bar -->
    <div class="filter-bar">
        <select id="categoryFilter" onchange="filterProducts()">
            <option value="">جميع الفئات</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="searchFilter" placeholder="بحث بالاسم أو الكود..." oninput="filterProducts()">
        <label class="toggle-filter" style="display:flex; align-items:center; gap:8px; cursor:pointer; user-select:none;">
            <input type="checkbox" id="hideZeroToggle" checked onchange="filterProducts()"
                style="width:18px; height:18px; cursor:pointer;">
            <span style="font-size:0.9rem; color:var(--text);">إخفاء الكمية 0 والسعر 0</span>
        </label>
    </div>

    <!-- Product Grid -->
    <div class="product-grid" id="productGrid">
        <?php foreach ($products as $product):
            $sku = $product['sku'] ?? '';
            $itemName = htmlspecialchars($product['item_name'], ENT_QUOTES, 'UTF-8');
            $category = $product['category'] ?? '';
            $priceUSD = (float)$product['wholesale_price_usd'];
            $stock = (float)$product['qty_on_hand'];

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
            <div class="product-card" data-id="<?= $product['id'] ?>" data-name="<?= $itemName ?>"
                data-sku="<?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?>" data-price="<?= $priceUSD ?>"
                data-stock="<?= $stock ?>" data-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $itemName ?>" loading="lazy"
                    class="lazy-image" onload="this.classList.add('is-loaded')"
                    onerror="this.src='<?= htmlspecialchars($fallbackSrc, ENT_QUOTES, 'UTF-8') ?>'">
                <div class="product-sku"><?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="product-name"><?= $itemName ?></div>
                <div class="product-price">$<?= number_format($priceUSD, 2) ?></div>
                <div class="product-stock <?= $stock <= 5 ? 'low' : '' ?>">
                    المخزون: <?= number_format($stock, 1) ?>
                </div>
                <div class="product-controls" id="controls-<?= $product['id'] ?>" onclick="event.stopPropagation();">
                    <button type="button" class="product-qty-btn minus"
                        onclick="decreaseQtyFromCard(<?= $product['id'] ?>)">−</button>
                    <span class="product-qty-display" id="qty-display-<?= $product['id'] ?>">0</span>
                    <button type="button" class="product-qty-btn plus" onclick="increaseQtyFromCard(<?= $product['id'] ?>)"
                        id="plus-btn-<?= $product['id'] ?>">+</button>
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
            <h2>🛒 سلتك</h2>
            <button class="cart-close" onclick="closeCart()">&times;</button>
        </div>

        <form method="POST" action="" id="cartForm">
            <input type="hidden" name="action" value="create_order">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="customer_id" id="customerId" value="">
            <input type="hidden" name="cents_discount" id="centsDiscount" value="0">

            <div class="cart-items" id="cartItems">
                <div class="empty-state" id="emptyCart">
                    <div class="empty-state-icon">🛒</div>
                    <p>سلتك فارغة. اضغط على المنتجات لإضافتها.</p>
                </div>
            </div>

            <div class="cart-customer">
                <label>اختر الزبون</label>
                <div class="customer-search-wrapper">
                    <input type="text" id="customerSearch" class="customer-input" placeholder="بحث بالاسم أو رقم الهاتف..."
                        autocomplete="off">
                    <div id="customerDropdown" class="customer-dropdown"></div>
                </div>
                <div id="customerSelected" class="customer-selected">
                    <strong id="selectedCustomerName"></strong>
                    <div id="selectedCustomerInfo" style="font-size: 0.85rem; color: #15803d;"></div>
                </div>
            </div>

            <div class="cart-notes">
                <label>ملاحظات (اختياري)</label>
                <textarea name="notes" id="orderNotes" placeholder="أضف ملاحظات..."></textarea>
            </div>

            <div class="cart-totals">
                <div class="total-row">
                    <span>المنتجات:</span>
                    <span id="totalItems">0</span>
                </div>
                <div class="total-row" id="subtotalRow">
                    <span>المجموع الفرعي:</span>
                    <span id="subtotalUSD">$0.00</span>
                </div>
                <div class="total-row" id="discountRow" style="display:none; color:#059669;">
                    <span>خصم القروش:</span>
                    <span id="discountAmount">-$0.00</span>
                </div>
                <div class="total-row grand">
                    <span>المجموع بالدولار:</span>
                    <span id="totalUSD">$0.00</span>
                </div>
                <div class="total-row">
                    <span>المجموع بالليرة:</span>
                    <span id="totalLBP">ل.ل. 0</span>
                </div>
            </div>

            <div class="cart-payment">
                <h4>الدفع</h4>
                <div class="payment-grid">
                    <div class="payment-group">
                        <label>دولار $</label>
                        <input type="number" name="payment_usd" id="paymentUSD" step="0.01" min="0" placeholder="0.00"
                            oninput="updatePaymentDisplay()">
                    </div>
                    <div class="payment-group">
                        <label>ليرة ل.ل.</label>
                        <input type="number" name="payment_lbp" id="paymentLBP" step="1000" min="0" placeholder="0"
                            oninput="updatePaymentDisplay()">
                    </div>
                </div>
                <div id="paymentRemaining" class="payment-remaining" style="display:none;">
                    <div class="payment-remaining-row">
                        <span>المجموع المستحق:</span>
                        <span id="displayTotalDue">$0.00</span>
                    </div>
                    <div class="payment-remaining-row">
                        <span>المدفوع:</span>
                        <span id="displayTotalPaid">$0.00</span>
                    </div>
                    <div class="payment-remaining-row highlight">
                        <span id="remainingLabel">المتبقي:</span>
                        <span id="displayRemaining">$0.00</span>
                    </div>
                    <div class="payment-remaining-row"
                        style="margin-top:8px; padding-top:8px; border-top:1px solid rgba(0,0,0,0.1);">
                        <span>بالليرة:</span>
                        <span id="displayRemainingLBP">ل.ل. 0</span>
                    </div>
                </div>
                <div id="changeDisplay" class="change-display" style="display:none;">
                    <h5>💵 الباقي:</h5>
                    <div class="change-amount">
                        <span id="changeUSD">$0.00</span> / <span id="changeLBP">ل.ل. 0</span>
                    </div>
                </div>
            </div>

            <div class="cart-submit">
                <button type="submit" class="btn-submit" id="submitBtn" disabled onclick="console.log('Button clicked');">
                    إتمام البيع
                </button>
            </div>

            <!-- Hidden cart items inputs -->
            <div id="cartInputs"></div>
        </form>
    </div>

    <script>
        const exchangeRate = <?= json_encode($exchangeRate ?? 0, JSON_UNESCAPED_UNICODE) ?>;
        let cart = {};
        let debounceTimer = null;

        // Filter products
        function filterProducts() {
            const category = document.getElementById('categoryFilter').value.toLowerCase();
            const search = document.getElementById('searchFilter').value.toLowerCase();
            const hideZero = document.getElementById('hideZeroToggle').checked;

            document.querySelectorAll('.product-card').forEach(card => {
                const cardCategory = (card.dataset.category || '').toLowerCase();
                const cardName = (card.dataset.name || '').toLowerCase();
                const cardSku = (card.dataset.sku || '').toLowerCase();
                const cardStock = parseFloat(card.dataset.stock) || 0;
                const cardPrice = parseFloat(card.dataset.price) || 0;

                const matchesCategory = !category || cardCategory === category;
                const matchesSearch = !search || cardName.includes(search) || cardSku.includes(search);
                const matchesZeroFilter = !hideZero || (cardStock > 0 && cardPrice > 0);

                card.style.display = (matchesCategory && matchesSearch && matchesZeroFilter) ? 'block' : 'none';
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
                cart[id] = {
                    name,
                    sku,
                    price,
                    stock,
                    quantity: 1
                };
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
                        <p>سلتك فارغة. اضغط على المنتجات لإضافتها.</p>
                    </div>
                `;
                cartInputs.innerHTML = '';
                cartButton.classList.add('empty');
                cartBadge.textContent = '0';
                cartTotal.textContent = '$0.00';
                submitBtn.disabled = true;
                document.getElementById('totalItems').textContent = '0';
                document.getElementById('totalUSD').textContent = '$0.00';
                document.getElementById('totalLBP').textContent = 'ل.ل. 0';
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
                            <div class="cart-item-qty">الكمية: ${item.quantity} / المخزون: ${item.stock}</div>
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

            // Calculate cents discount: if total >= $20 and has decimals, discount the cents
            let centsDiscount = 0;
            let finalTotalUSD = totalUSD;
            const cents = totalUSD - Math.floor(totalUSD);

            if (totalUSD >= 20 && cents > 0.001) {
                centsDiscount = cents;
                finalTotalUSD = Math.floor(totalUSD);
            }

            // Update hidden field for backend
            document.getElementById('centsDiscount').value = centsDiscount.toFixed(2);

            // Show/hide discount row
            const discountRow = document.getElementById('discountRow');
            const subtotalRow = document.getElementById('subtotalRow');
            if (centsDiscount > 0) {
                subtotalRow.style.display = 'flex';
                discountRow.style.display = 'flex';
                document.getElementById('subtotalUSD').textContent = '$' + totalUSD.toFixed(2);
                document.getElementById('discountAmount').textContent = '-$' + centsDiscount.toFixed(2);
            } else {
                subtotalRow.style.display = 'none';
                discountRow.style.display = 'none';
            }

            cartTotal.textContent = '$' + finalTotalUSD.toFixed(2);

            document.getElementById('totalItems').textContent = totalQty;
            document.getElementById('totalUSD').textContent = '$' + finalTotalUSD.toFixed(2);
            document.getElementById('totalLBP').textContent = 'ل.ل. ' + Math.round(finalTotalUSD * exchangeRate)
                .toLocaleString();

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
                                    <small>${escapeHtml(c.phone || 'لا يوجد هاتف')} ${c.city ? '| ' + escapeHtml(c.city) : ''}</small>
                                </div>
                            `).join('');
                            customerDropdown.classList.add('show');
                        } else {
                            customerDropdown.innerHTML =
                                '<div class="customer-option" style="cursor:default;">لم يتم العثور على زبائن</div>';
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
            document.getElementById('selectedCustomerInfo').textContent =
                `${phone || 'لا يوجد هاتف'}${city ? ' | ' + city : ''}`;
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
            const paymentUSDInput = document.getElementById('paymentUSD');
            const paymentLBPInput = document.getElementById('paymentLBP');
            const paymentUSD = parseFloat(paymentUSDInput.value) || 0;
            const paymentLBP = parseFloat(paymentLBPInput.value) || 0;
            const paymentRemainingDiv = document.getElementById('paymentRemaining');
            const changeDisplayDiv = document.getElementById('changeDisplay');

            // Calculate total due (before discount)
            let subtotalUSD = 0;
            Object.keys(cart).forEach(id => {
                subtotalUSD += cart[id].price * cart[id].quantity;
            });

            // Apply cents discount if applicable
            let totalDueUSD = subtotalUSD;
            const cents = subtotalUSD - Math.floor(subtotalUSD);
            if (subtotalUSD >= 20 && cents > 0.001) {
                totalDueUSD = Math.floor(subtotalUSD);
            }

            if (totalDueUSD <= 0) {
                paymentRemainingDiv.style.display = 'none';
                changeDisplayDiv.style.display = 'none';
                // Reset placeholders
                paymentUSDInput.placeholder = '0.00';
                paymentLBPInput.placeholder = '0';
                return;
            }

            // Convert LBP payment to USD
            const paymentLBPinUSD = paymentLBP / exchangeRate;
            const totalPaidUSD = paymentUSD + paymentLBPinUSD;
            const remainingUSD = totalDueUSD - totalPaidUSD;
            const remainingLBP = remainingUSD * exchangeRate;

            // Update placeholders with remaining amounts
            if (remainingUSD > 0.01) {
                // Show remaining in placeholders
                paymentUSDInput.placeholder = 'المتبقي: $' + remainingUSD.toFixed(2);
                paymentLBPInput.placeholder = 'المتبقي: ' + Math.round(remainingLBP).toLocaleString();
            } else {
                // Paid in full or overpaid
                paymentUSDInput.placeholder = '0.00';
                paymentLBPInput.placeholder = '0';
            }

            // Update display
            document.getElementById('displayTotalDue').textContent = '$' + totalDueUSD.toFixed(2);
            document.getElementById('displayTotalPaid').textContent = '$' + totalPaidUSD.toFixed(2);

            if (remainingUSD > 0.01) {
                // Still owes money
                document.getElementById('remainingLabel').textContent = 'المتبقي:';
                document.getElementById('displayRemaining').textContent = '$' + remainingUSD.toFixed(2);
                document.getElementById('displayRemainingLBP').textContent = 'ل.ل. ' + Math.round(remainingLBP)
                    .toLocaleString();
                paymentRemainingDiv.className = 'payment-remaining';
                changeDisplayDiv.style.display = 'none';
            } else if (remainingUSD < -0.01) {
                // Overpaid - show change
                const changeUSD = Math.abs(remainingUSD);
                const changeLBP = changeUSD * exchangeRate;
                document.getElementById('remainingLabel').textContent = 'زيادة:';
                document.getElementById('displayRemaining').textContent = '$' + changeUSD.toFixed(2);
                document.getElementById('displayRemainingLBP').textContent = 'ل.ل. ' + Math.round(changeLBP).toLocaleString();
                paymentRemainingDiv.className = 'payment-remaining overpaid';

                // Show change to return
                document.getElementById('changeUSD').textContent = '$' + changeUSD.toFixed(2);
                document.getElementById('changeLBP').textContent = 'ل.ل. ' + Math.round(changeLBP).toLocaleString();
                changeDisplayDiv.style.display = 'block';
            } else {
                // Exact payment
                document.getElementById('remainingLabel').textContent = 'المتبقي:';
                document.getElementById('displayRemaining').textContent = '$0.00';
                document.getElementById('displayRemainingLBP').textContent = 'ل.ل. 0';
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

        function showAjaxFlash(type, title, message, list) {
            const host = document.getElementById('ajaxFlash');
            const safeTitle = title || (type === 'success' ? 'تم' : 'خطأ');
            let html = `<div class="flash ${type}">`;
            html += `<h4>${safeTitle}</h4>`;
            if (message) html += `<p>${message}</p>`;
            if (Array.isArray(list) && list.length > 0) {
                html += '<ul>';
                list.forEach(item => {
                    html += `<li>${item}</li>`;
                });
                html += '</ul>';
            }
            html += '</div>';
            if (host) {
                host.innerHTML = html;
                host.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
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

            console.log('Form submit triggered', {
                customerId,
                itemCount,
                cart
            });

            if (!customerId) {
                showAjaxFlash('error', 'يرجى اختيار زبون', 'يرجى اختيار زبون.');
                return false;
            }

            if (itemCount === 0) {
                showAjaxFlash('error', 'السلة فارغة', 'يرجى إضافة منتج واحد على الأقل.');
                return false;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'جاري المعالجة...';

            try {
                const formData = new FormData(this);
                console.log('Sending form data...');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                console.log('Response status:', response.status);
                const responseText = await response.text();
                console.log('Response text:', responseText.substring(0, 500));

                let payload;
                try {
                    payload = JSON.parse(responseText);
                } catch (parseErr) {
                    console.error('JSON parse error:', parseErr);
                    showAjaxFlash('error', 'خطأ في الاستجابة',
                        'الخادم أرجع استجابة غير صالحة. يرجى المحاولة مرة أخرى.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'إتمام البيع';
                    return;
                }

                if (!payload.ok) {
                    showAjaxFlash('error', 'فشل الطلب', payload.message || 'تعذر إنشاء الطلب.', payload.errors || []);
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'إتمام البيع';
                    return;
                }

                // Clear cart and reset form
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
                document.getElementById('paymentUSD').value = '';
                document.getElementById('paymentLBP').value = '';
                updatePaymentDisplay();
                submitBtn.disabled = true;
                submitBtn.textContent = 'إتمام البيع';

                // Show success modal with WhatsApp option
                showSaleSuccessModal(payload);
            } catch (err) {
                console.error('Fetch error:', err);
                showAjaxFlash('error', 'خطأ بالشبكة', 'تعذر إرسال الطلب، حاول مرة أخرى. ' + err.message);
                submitBtn.disabled = false;
                submitBtn.textContent = 'إتمام البيع';
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

        // Apply filter on page load to hide 0 qty and 0 price items by default
        filterProducts();

        // Sale success modal with WhatsApp integration
        function showSaleSuccessModal(payload) {
            // Remove existing modal if any
            const existingModal = document.getElementById('saleSuccessModal');
            if (existingModal) existingModal.remove();

            const hasWhatsApp = payload.whatsapp_url && payload.whatsapp_url.length > 0;

            const modalHtml = `
        <div id="saleSuccessModal" class="sale-success-modal" onclick="if(event.target===this) closeSaleSuccessModal()">
            <div class="sale-success-content">
                <div class="sale-success-icon">✓</div>
                <h2>تمت عملية البيع بنجاح!</h2>
                <div class="sale-success-details">
                    <p><strong>رقم الفاتورة:</strong> ${payload.invoice_number || ''}</p>
                    <p><strong>العميل:</strong> ${payload.customer_name || ''}</p>
                    <p><strong>الإجمالي:</strong> $${(payload.total_usd || 0).toFixed(2)}</p>
                </div>
                <div class="sale-success-actions">
                    ${hasWhatsApp ? `
                    <a href="${payload.whatsapp_url}" target="_blank" class="btn-whatsapp-send" onclick="setTimeout(closeSaleSuccessModal, 500)">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                        </svg>
                        إرسال عبر واتساب
                    </a>
                    ` : ''}
                    <a href="${payload.redirect_url || '#'}" target="_blank" class="btn-view-invoice" onclick="setTimeout(closeSaleSuccessModal, 500)">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20M12,19L8,15H10.5V12H13.5V15H16L12,19Z"/>
                        </svg>
                        عرض الفاتورة
                    </a>
                    <a href="${payload.pdf_url || '#'}" target="_blank" class="btn-download-pdf">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z"/>
                        </svg>
                        تحميل PDF
                    </a>
                    <button type="button" class="btn-new-sale" onclick="closeSaleSuccessModal()">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"/>
                        </svg>
                        عملية بيع جديدة
                    </button>
                </div>
            </div>
        </div>
    `;

            document.body.insertAdjacentHTML('beforeend', modalHtml);

            // Add styles if not already present
            if (!document.getElementById('saleSuccessStyles')) {
                const styles = `
            <style id="saleSuccessStyles">
                .sale-success-modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0,0,0,0.7);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 10000;
                    animation: fadeIn 0.3s ease;
                }
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes fadeOut {
                    from { opacity: 1; }
                    to { opacity: 0; }
                }
                .sale-success-content {
                    background: white;
                    border-radius: 16px;
                    padding: 32px;
                    max-width: 420px;
                    width: 90%;
                    text-align: center;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    animation: slideUp 0.3s ease;
                }
                @keyframes slideUp {
                    from { transform: translateY(30px); opacity: 0; }
                    to { transform: translateY(0); opacity: 1; }
                }
                .sale-success-icon {
                    width: 80px;
                    height: 80px;
                    background: linear-gradient(135deg, #22c55e, #16a34a);
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto 20px;
                    font-size: 40px;
                    color: white;
                    box-shadow: 0 8px 20px rgba(34, 197, 94, 0.4);
                }
                .sale-success-content h2 {
                    margin: 0 0 16px;
                    color: #1e293b;
                    font-size: 1.5rem;
                }
                .sale-success-details {
                    background: #f8fafc;
                    border-radius: 10px;
                    padding: 16px;
                    margin-bottom: 24px;
                    text-align: right;
                }
                .sale-success-details p {
                    margin: 8px 0;
                    color: #475569;
                    font-size: 0.95rem;
                }
                .sale-success-details strong {
                    color: #1e293b;
                }
                .sale-success-actions {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }
                .sale-success-actions a,
                .sale-success-actions button {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    padding: 14px 20px;
                    border-radius: 10px;
                    font-size: 1rem;
                    font-weight: 600;
                    text-decoration: none;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    border: none;
                }
                .btn-whatsapp-send {
                    background: linear-gradient(135deg, #25D366, #128C7E);
                    color: white !important;
                    box-shadow: 0 4px 15px rgba(37, 211, 102, 0.4);
                }
                .btn-whatsapp-send:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(37, 211, 102, 0.5);
                }
                .btn-view-invoice {
                    background: linear-gradient(135deg, #3b82f6, #2563eb);
                    color: white !important;
                }
                .btn-view-invoice:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
                }
                .btn-download-pdf {
                    background: #f1f5f9;
                    color: #475569 !important;
                    border: 1px solid #e2e8f0;
                }
                .btn-download-pdf:hover {
                    background: #e2e8f0;
                }
                .btn-new-sale {
                    background: linear-gradient(135deg, #22c55e, #16a34a);
                    color: white;
                }
                .btn-new-sale:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 15px rgba(34, 197, 94, 0.4);
                }
                .sale-success-actions svg {
                    flex-shrink: 0;
                }
            </style>
        `;
                document.head.insertAdjacentHTML('beforeend', styles);
            }
        }

        function closeSaleSuccessModal() {
            const modal = document.getElementById('saleSuccessModal');
            if (modal) {
                modal.style.animation = 'fadeOut 0.2s ease forwards';
                setTimeout(() => modal.remove(), 200);
            }
        }
    </script>
<?php
}

sales_portal_render_layout_end();
