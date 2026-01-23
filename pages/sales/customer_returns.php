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
            SELECT id, name, phone, location as city, COALESCE(account_balance_lbp, 0) as credit_lbp
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

// Handle AJAX product search (all products, not just van stock)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'search_products') {
    header('Content-Type: application/json');

    $query = trim((string)($_GET['q'] ?? ''));

    if (strlen($query) < 1) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $searchPattern = '%' . $query . '%';
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.sku,
                p.item_name,
                p.topcat as category,
                p.sale_price_usd
            FROM products p
            WHERE p.is_active = 1
              AND (
                  p.item_name LIKE :pattern
                  OR p.sku LIKE :pattern
                  OR p.barcode LIKE :pattern
                  OR p.topcat LIKE :pattern
              )
            ORDER BY p.item_name
            LIMIT 20
        ");
        $stmt->execute([':pattern' => $searchPattern]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['results' => $results]);
    } catch (PDOException $e) {
        error_log("Product search failed: " . $e->getMessage());
        echo json_encode(['results' => [], 'error' => 'Search failed']);
    }
    exit;
}

// Handle AJAX invoice search for customer
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_customer_invoices') {
    header('Content-Type: application/json');

    $customerId = (int)($_GET['customer_id'] ?? 0);

    if ($customerId <= 0) {
        echo json_encode(['invoices' => []]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                i.id,
                i.invoice_number,
                i.total_usd,
                i.created_at,
                o.order_number
            FROM invoices i
            JOIN orders o ON o.id = i.order_id
            WHERE o.customer_id = :customer_id
              AND o.sales_rep_id = :rep_id
              AND i.status != 'voided'
            ORDER BY i.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([':customer_id' => $customerId, ':rep_id' => $repId]);

        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['invoices' => $invoices]);
    } catch (PDOException $e) {
        error_log("Invoice search failed: " . $e->getMessage());
        echo json_encode(['invoices' => [], 'error' => 'Search failed']);
    }
    exit;
}

// Handle AJAX get invoice items (with already returned quantities)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_invoice_items') {
    header('Content-Type: application/json');

    $invoiceId = (int)($_GET['invoice_id'] ?? 0);

    if ($invoiceId <= 0) {
        echo json_encode(['items' => []]);
        exit;
    }

    try {
        // Get original invoice items with already returned quantities
        $stmt = $pdo->prepare("
            SELECT
                oi.product_id,
                oi.quantity as original_qty,
                oi.unit_price_usd,
                p.sku,
                p.item_name,
                COALESCE(returned.total_returned, 0) as already_returned
            FROM order_items oi
            JOIN invoices i ON i.order_id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN (
                SELECT
                    cri.product_id,
                    cr.invoice_id,
                    SUM(cri.quantity) as total_returned
                FROM customer_return_items cri
                JOIN customer_returns cr ON cr.id = cri.return_id
                WHERE cr.invoice_id = :invoice_id_sub
                  AND cr.status NOT IN ('cash_rejected')
                GROUP BY cri.product_id, cr.invoice_id
            ) returned ON returned.product_id = oi.product_id AND returned.invoice_id = i.id
            WHERE i.id = :invoice_id
              AND i.sales_rep_id = :rep_id
        ");
        $stmt->execute([
            ':invoice_id' => $invoiceId,
            ':invoice_id_sub' => $invoiceId,
            ':rep_id' => $repId
        ]);

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate remaining returnable quantity
        foreach ($items as &$item) {
            $item['remaining_qty'] = max(0, (float)$item['original_qty'] - (float)$item['already_returned']);
            $item['quantity'] = $item['remaining_qty']; // Default to remaining
        }

        echo json_encode(['items' => $items]);
    } catch (PDOException $e) {
        error_log("Invoice items search failed: " . $e->getMessage());
        echo json_encode(['items' => [], 'error' => 'Search failed']);
    }
    exit;
}

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
    }
} catch (PDOException $e) {
    $exchangeRateError = true;
    error_log("Failed to fetch exchange rate: " . $e->getMessage());
}

if ($exchangeRateError || $exchangeRate === null) {
    $flashes[] = [
        'type' => 'error',
        'title' => 'Exchange Rate Unavailable',
        'message' => 'Cannot process returns at this time. Please contact your administrator.',
        'dismissible' => false,
    ];
    $canCreateReturn = false;
} else {
    $canCreateReturn = true;
}

// Handle return creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_return') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } elseif (!$canCreateReturn) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Cannot Create Return',
            'message' => 'Exchange rate is unavailable. Please refresh the page.',
            'dismissible' => true,
        ];
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $invoiceId = (int)($_POST['invoice_id'] ?? 0) ?: null;
        $refundMethod = ($_POST['refund_method'] ?? 'credit') === 'cash' ? 'cash' : 'credit';
        $reason = trim((string)($_POST['reason'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $items = $_POST['items'] ?? [];

        $errors = [];

        // Validate customer
        if ($customerId <= 0) {
            $errors[] = 'Please select a customer.';
        } else {
            $customerStmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id AND is_active = 1");
            $customerStmt->execute([':id' => $customerId, ':rep_id' => $repId]);
            $customerData = $customerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$customerData) {
                $errors[] = 'Invalid customer selected.';
            }
        }

        // Validate items
        if (!is_array($items) || count($items) === 0) {
            $errors[] = 'Please add at least one product to return.';
        } else {
            $validatedItems = [];
            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = (float)($item['quantity'] ?? 0);
                $unitPriceUsd = (float)($item['unit_price_usd'] ?? 0);
                $itemReason = trim((string)($item['reason'] ?? ''));

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                // Verify product exists
                $productStmt = $pdo->prepare("SELECT id, item_name, sale_price_usd FROM products WHERE id = :id AND is_active = 1");
                $productStmt->execute([':id' => $productId]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    $errors[] = "Product ID {$productId} not found.";
                    continue;
                }

                // If linked to invoice, validate quantity doesn't exceed remaining returnable
                if ($invoiceId) {
                    $remainingStmt = $pdo->prepare("
                        SELECT
                            oi.quantity as original_qty,
                            COALESCE(SUM(cri.quantity), 0) as already_returned
                        FROM order_items oi
                        JOIN invoices i ON i.order_id = oi.order_id
                        LEFT JOIN customer_return_items cri ON cri.product_id = oi.product_id
                        LEFT JOIN customer_returns cr ON cr.id = cri.return_id
                            AND cr.invoice_id = i.id
                            AND cr.status NOT IN ('cash_rejected')
                        WHERE i.id = :invoice_id AND oi.product_id = :product_id
                        GROUP BY oi.id
                    ");
                    $remainingStmt->execute([':invoice_id' => $invoiceId, ':product_id' => $productId]);
                    $remainingData = $remainingStmt->fetch(PDO::FETCH_ASSOC);

                    if ($remainingData) {
                        $maxReturnable = (float)$remainingData['original_qty'] - (float)$remainingData['already_returned'];
                        if ($quantity > $maxReturnable + 0.001) {
                            $errors[] = "Cannot return {$quantity} of {$product['item_name']}. Maximum returnable: {$maxReturnable}";
                            continue;
                        }
                    }
                }

                // Use provided price or default to current price
                if ($unitPriceUsd <= 0) {
                    $unitPriceUsd = (float)$product['sale_price_usd'];
                }

                $validatedItems[] = [
                    'product_id' => $productId,
                    'product_name' => $product['item_name'],
                    'quantity' => $quantity,
                    'unit_price_usd' => $unitPriceUsd,
                    'reason' => $itemReason,
                ];
            }

            if (count($validatedItems) === 0) {
                $errors[] = 'No valid items to return.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Calculate totals
                $totalUsd = 0;
                foreach ($validatedItems as $item) {
                    $totalUsd += $item['quantity'] * $item['unit_price_usd'];
                }
                $totalLbp = $totalUsd * $exchangeRate;

                // Generate return number
                $returnNumber = 'RET-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

                // Determine status based on refund method
                if ($refundMethod === 'credit') {
                    $status = 'completed';
                    $creditAppliedAt = date('Y-m-d H:i:s');
                    $cashRequestedAt = null;
                } else {
                    $status = 'pending_cash_approval';
                    $creditAppliedAt = null;
                    $cashRequestedAt = date('Y-m-d H:i:s');
                }

                // Get order_id from invoice if linked
                $orderId = null;
                if ($invoiceId) {
                    $invoiceStmt = $pdo->prepare("SELECT order_id FROM invoices WHERE id = :id");
                    $invoiceStmt->execute([':id' => $invoiceId]);
                    $orderId = $invoiceStmt->fetchColumn() ?: null;
                }

                // Insert return record
                $returnStmt = $pdo->prepare("
                    INSERT INTO customer_returns (
                        return_number, customer_id, sales_rep_id, invoice_id, order_id,
                        total_usd, total_lbp, exchange_rate_id,
                        refund_method, status, credit_applied_at, cash_requested_at,
                        reason, notes, created_at
                    ) VALUES (
                        :return_number, :customer_id, :sales_rep_id, :invoice_id, :order_id,
                        :total_usd, :total_lbp, :exchange_rate_id,
                        :refund_method, :status, :credit_applied_at, :cash_requested_at,
                        :reason, :notes, NOW()
                    )
                ");
                $returnStmt->execute([
                    ':return_number' => $returnNumber,
                    ':customer_id' => $customerId,
                    ':sales_rep_id' => $repId,
                    ':invoice_id' => $invoiceId,
                    ':order_id' => $orderId,
                    ':total_usd' => $totalUsd,
                    ':total_lbp' => $totalLbp,
                    ':exchange_rate_id' => $exchangeRateId,
                    ':refund_method' => $refundMethod,
                    ':status' => $status,
                    ':credit_applied_at' => $creditAppliedAt,
                    ':cash_requested_at' => $cashRequestedAt,
                    ':reason' => $reason,
                    ':notes' => $notes,
                ]);

                $returnId = (int)$pdo->lastInsertId();

                // Insert return items and update van stock
                $itemStmt = $pdo->prepare("
                    INSERT INTO customer_return_items (
                        return_id, product_id, quantity,
                        unit_price_usd, unit_price_lbp,
                        line_total_usd, line_total_lbp,
                        reason, stock_returned
                    ) VALUES (
                        :return_id, :product_id, :quantity,
                        :unit_price_usd, :unit_price_lbp,
                        :line_total_usd, :line_total_lbp,
                        :reason, 1
                    )
                ");

                $stockUpdateStmt = $pdo->prepare("
                    INSERT INTO s_stock (salesperson_id, product_id, qty_on_hand, created_at, updated_at)
                    VALUES (:rep_id, :product_id, :qty, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        qty_on_hand = qty_on_hand + :qty2,
                        updated_at = NOW()
                ");

                $movementStmt = $pdo->prepare("
                    INSERT INTO s_stock_movements (salesperson_id, product_id, delta_qty, reason, note, created_at)
                    VALUES (:rep_id, :product_id, :qty, 'customer_return', :note, NOW())
                ");

                foreach ($validatedItems as $item) {
                    $lineTotalUsd = $item['quantity'] * $item['unit_price_usd'];
                    $lineTotalLbp = $lineTotalUsd * $exchangeRate;

                    // Insert return item
                    $itemStmt->execute([
                        ':return_id' => $returnId,
                        ':product_id' => $item['product_id'],
                        ':quantity' => $item['quantity'],
                        ':unit_price_usd' => $item['unit_price_usd'],
                        ':unit_price_lbp' => $item['unit_price_usd'] * $exchangeRate,
                        ':line_total_usd' => $lineTotalUsd,
                        ':line_total_lbp' => $lineTotalLbp,
                        ':reason' => $item['reason'],
                    ]);

                    // Update van stock
                    $stockUpdateStmt->execute([
                        ':rep_id' => $repId,
                        ':product_id' => $item['product_id'],
                        ':qty' => $item['quantity'],
                        ':qty2' => $item['quantity'],
                    ]);

                    // Log stock movement
                    $movementStmt->execute([
                        ':rep_id' => $repId,
                        ':product_id' => $item['product_id'],
                        ':qty' => $item['quantity'],
                        ':note' => "Return #{$returnNumber} - {$item['product_name']}",
                    ]);
                }

                // If credit refund, update customer balance AND create credit payment if linked to invoice
                if ($refundMethod === 'credit') {
                    // Always add to customer credit balance
                    $creditStmt = $pdo->prepare("
                        UPDATE customers
                        SET account_balance_lbp = COALESCE(account_balance_lbp, 0) + :credit
                        WHERE id = :customer_id
                    ");
                    $creditStmt->execute([
                        ':credit' => $totalLbp,
                        ':customer_id' => $customerId,
                    ]);

                    // If linked to an invoice, create a credit payment to reduce outstanding
                    if ($invoiceId) {
                        $creditPaymentStmt = $pdo->prepare("
                            INSERT INTO payments (
                                invoice_id, method, amount_usd, amount_lbp,
                                received_by_user_id, received_at
                            ) VALUES (
                                :invoice_id, 'return_credit', :amount_usd, :amount_lbp,
                                :received_by, NOW()
                            )
                        ");
                        $creditPaymentStmt->execute([
                            ':invoice_id' => $invoiceId,
                            ':amount_usd' => $totalUsd,
                            ':amount_lbp' => $totalLbp,
                            ':received_by' => $repId,
                        ]);
                    }
                }

                $pdo->commit();

                $successMessage = $refundMethod === 'credit'
                    ? "Return #{$returnNumber} created. Credit of $" . number_format($totalUsd, 2) . " added to customer account."
                    : "Return #{$returnNumber} created. Cash refund pending admin approval.";

                $_SESSION['flash_success'] = $successMessage;
                header('Location: customer_returns.php?success=1');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create customer return: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to create return. Error: ' . $e->getMessage(),
                    'dismissible' => true,
                ];
            }
        } else {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Please fix the errors below:',
                'list' => $errors,
                'dismissible' => true,
            ];
        }
    }
}

// Check for success message
if (isset($_GET['success']) && isset($_SESSION['flash_success'])) {
    $flashes[] = [
        'type' => 'success',
        'title' => 'Return Created',
        'message' => $_SESSION['flash_success'],
        'dismissible' => true,
    ];
    unset($_SESSION['flash_success']);
}

// Filters
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Build query for returns list
$whereConditions = ['cr.sales_rep_id = :rep_id'];
$params = [':rep_id' => $repId];

if ($statusFilter !== 'all') {
    $whereConditions[] = 'cr.status = :status';
    $params[':status'] = $statusFilter;
}

if ($dateFrom) {
    $whereConditions[] = 'DATE(cr.created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = 'DATE(cr.created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// Get returns
$returnsStmt = $pdo->prepare("
    SELECT
        cr.*,
        c.name as customer_name,
        c.phone as customer_phone,
        i.invoice_number
    FROM customer_returns cr
    JOIN customers c ON c.id = cr.customer_id
    LEFT JOIN invoices i ON i.id = cr.invoice_id
    WHERE {$whereClause}
    ORDER BY cr.created_at DESC
    LIMIT 100
");
$returnsStmt->execute($params);
$returns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get totals
$totalsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_count,
        SUM(total_usd) as total_usd,
        SUM(CASE WHEN status = 'completed' THEN total_usd ELSE 0 END) as credit_total,
        SUM(CASE WHEN status IN ('pending_cash_approval', 'cash_approved', 'cash_paid') THEN total_usd ELSE 0 END) as cash_total,
        SUM(CASE WHEN status = 'pending_cash_approval' THEN 1 ELSE 0 END) as pending_count
    FROM customer_returns cr
    WHERE {$whereClause}
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'مرتجعات الزبائن',
    'heading' => 'مرتجعات الزبائن',
    'subtitle' => 'معالجة مرتجعات المنتجات والاسترداد',
    'active' => 'customer_returns',
    'user' => $user,
    'extra_head' => '<style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-panel);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border);
        }
        .stat-card .label {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .stat-card .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
        }
        .stat-card .value.credit { color: #22c55e; }
        .stat-card .value.pending { color: #f59e0b; }

        .filters-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-btn {
            padding: 10px 18px;
            border: 2px solid var(--border);
            background: var(--bg-panel);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            color: var(--muted);
            transition: all 0.2s;
        }
        .filter-btn:hover {
            border-color: var(--accent);
            color: var(--accent);
        }
        .filter-btn.active {
            background: var(--accent);
            border-color: var(--accent);
            color: white;
        }

        .return-card {
            background: var(--bg-panel);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid var(--border);
        }
        .return-card .header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .return-number {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--text);
        }
        .return-customer {
            color: var(--muted);
            font-size: 0.9rem;
        }
        .return-amount {
            text-align: right;
        }
        .return-amount .usd {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--accent);
        }
        .return-amount .lbp {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-pending_cash_approval { background: #fef3c7; color: #92400e; }
        .status-cash_approved { background: #dbeafe; color: #1e40af; }
        .status-cash_rejected { background: #fee2e2; color: #991b1b; }
        .status-cash_paid { background: #d1fae5; color: #065f46; }

        .method-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
        }
        .method-credit { background: #dcfce7; color: #166534; }
        .method-cash { background: #fef3c7; color: #92400e; }

        .return-meta {
            display: flex;
            gap: 20px;
            font-size: 0.85rem;
            color: var(--muted);
            flex-wrap: wrap;
        }

        .btn-new-return {
            background: var(--accent);
            color: white;
            padding: 14px 24px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        .btn-new-return:hover {
            opacity: 0.9;
        }

        /* Modal styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.show {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.3rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--muted);
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text);
        }
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--accent);
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
            max-height: 250px;
            overflow-y: auto;
            z-index: 1001;
        }
        .autocomplete-item {
            padding: 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
        }
        .autocomplete-item:hover {
            background: #f3f4f6;
        }
        .autocomplete-item strong {
            display: block;
            color: var(--text);
        }
        .autocomplete-item small {
            color: var(--muted);
        }

        .selected-customer {
            background: #dcfce7;
            border: 2px solid #22c55e;
            padding: 14px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .selected-customer strong {
            color: #166534;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .items-table th {
            background: #f3f4f6;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .items-table td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
        }
        .items-table input {
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            width: 80px;
        }

        .btn-add-item {
            background: #3b82f6;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }
        .btn-remove {
            background: #fee2e2;
            color: #dc2626;
            padding: 6px 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }

        .refund-options {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }
        .refund-option {
            flex: 1;
            padding: 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .refund-option:hover {
            border-color: var(--accent);
        }
        .refund-option.selected {
            border-color: var(--accent);
            background: rgba(220, 38, 38, 0.05);
        }
        .refund-option input {
            display: none;
        }
        .refund-option .icon {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        .refund-option .title {
            font-weight: 600;
            color: var(--text);
        }
        .refund-option .desc {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .total-row {
            background: #f3f4f6;
            padding: 16px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }
        .total-row .amount {
            color: var(--accent);
        }

        .btn-submit {
            background: var(--accent);
            color: white;
            padding: 14px 28px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
        }
        .btn-submit:hover {
            opacity: 0.9;
        }
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        .empty-state .icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }
    </style>'
]);
?>

<!-- Flash messages -->
<?php foreach ($flashes as $flash): ?>
    <div style="background: <?= $flash['type'] === 'error' ? '#fee2e2' : '#dcfce7' ?>;
                color: <?= $flash['type'] === 'error' ? '#991b1b' : '#166534' ?>;
                padding: 16px; border-radius: 10px; margin-bottom: 20px;">
        <strong><?= htmlspecialchars($flash['title']) ?></strong><br>
        <?= htmlspecialchars($flash['message']) ?>
        <?php if (!empty($flash['list'])): ?>
            <ul style="margin: 8px 0 0; padding-left: 20px;">
                <?php foreach ($flash['list'] as $item): ?>
                    <li><?= htmlspecialchars($item) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="label">Total Returns</div>
        <div class="value"><?= (int)($totals['total_count'] ?? 0) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Credit Refunds</div>
        <div class="value credit">$<?= number_format((float)($totals['credit_total'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Cash Refunds</div>
        <div class="value">$<?= number_format((float)($totals['cash_total'] ?? 0), 2) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Pending Approval</div>
        <div class="value pending"><?= (int)($totals['pending_count'] ?? 0) ?></div>
    </div>
</div>

<!-- Filters and New Button -->
<div class="filters-bar">
    <a href="?status=all" class="filter-btn <?= $statusFilter === 'all' ? 'active' : '' ?>">All</a>
    <a href="?status=completed" class="filter-btn <?= $statusFilter === 'completed' ? 'active' : '' ?>">Credit</a>
    <a href="?status=pending_cash_approval" class="filter-btn <?= $statusFilter === 'pending_cash_approval' ? 'active' : '' ?>">Pending Cash</a>
    <a href="?status=cash_paid" class="filter-btn <?= $statusFilter === 'cash_paid' ? 'active' : '' ?>">Cash Paid</a>
    <div style="flex: 1;"></div>
    <?php if ($canCreateReturn): ?>
        <button onclick="openReturnModal()" class="btn-new-return">+ New Return</button>
    <?php endif; ?>
</div>

<!-- Returns List -->
<?php if (count($returns) > 0): ?>
    <?php foreach ($returns as $return): ?>
        <div class="return-card">
            <div class="header">
                <div>
                    <div class="return-number">
                        <?= htmlspecialchars($return['return_number']) ?>
                        <span class="method-badge method-<?= $return['refund_method'] ?>">
                            <?= $return['refund_method'] === 'credit' ? 'Credit' : 'Cash' ?>
                        </span>
                    </div>
                    <div class="return-customer">
                        <?= htmlspecialchars($return['customer_name']) ?>
                        <?php if ($return['invoice_number']): ?>
                            &bull; Invoice: <?= htmlspecialchars($return['invoice_number']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="return-amount">
                    <div class="usd">$<?= number_format((float)$return['total_usd'], 2) ?></div>
                    <div class="lbp"><?= number_format((float)$return['total_lbp'], 0) ?> LBP</div>
                </div>
            </div>
            <div class="return-meta">
                <span class="status-badge status-<?= $return['status'] ?>">
                    <?= str_replace('_', ' ', $return['status']) ?>
                </span>
                <span><?= date('M j, Y g:i A', strtotime($return['created_at'])) ?></span>
                <?php if ($return['reason']): ?>
                    <span>Reason: <?= htmlspecialchars($return['reason']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-state">
        <div class="icon">📦</div>
        <h3>No Returns Found</h3>
        <p>Customer returns will appear here.</p>
    </div>
<?php endif; ?>

<!-- New Return Modal -->
<div id="returnModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Create Customer Return</h2>
            <button onclick="closeReturnModal()" class="modal-close">&times;</button>
        </div>

        <form method="POST" action="customer_returns.php" id="returnForm">
            <input type="hidden" name="action" value="create_return">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="customer_id" id="customer_id" value="">
            <input type="hidden" name="invoice_id" id="invoice_id" value="">

            <!-- Customer Selection -->
            <div class="form-group">
                <label>Customer *</label>
                <div style="position: relative;">
                    <input type="text" id="customer_search" class="form-control"
                           placeholder="Search customer by name or phone..." autocomplete="off">
                    <div id="customer_dropdown" class="autocomplete-dropdown" style="display: none;"></div>
                </div>
                <div id="selected_customer" class="selected-customer" style="display: none;"></div>
            </div>

            <!-- Invoice Selection (optional) -->
            <div class="form-group" id="invoice_section" style="display: none;">
                <label>Link to Invoice (optional)</label>
                <select id="invoice_select" class="form-control" onchange="loadInvoiceItems()">
                    <option value="">-- Select Invoice --</option>
                </select>
            </div>

            <!-- Return Items -->
            <div class="form-group">
                <label>Return Items *</label>
                <div style="position: relative; margin-bottom: 12px;">
                    <input type="text" id="product_search" class="form-control"
                           placeholder="Search product to add..." autocomplete="off">
                    <div id="product_dropdown" class="autocomplete-dropdown" style="display: none;"></div>
                </div>

                <table class="items-table" id="items_table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Price (USD)</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="items_body">
                        <tr id="no_items_row">
                            <td colspan="5" style="text-align: center; color: #9ca3af; padding: 20px;">
                                Add products to return
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="total-row">
                    <span>Total Refund:</span>
                    <span class="amount" id="total_display">$0.00</span>
                </div>
            </div>

            <!-- Refund Method -->
            <div class="form-group">
                <label>Refund Method *</label>
                <div class="refund-options">
                    <label class="refund-option selected" id="option_credit">
                        <input type="radio" name="refund_method" value="credit" checked>
                        <div class="icon">💳</div>
                        <div class="title">Store Credit</div>
                        <div class="desc">Instant credit to account</div>
                    </label>
                    <label class="refund-option" id="option_cash">
                        <input type="radio" name="refund_method" value="cash">
                        <div class="icon">💵</div>
                        <div class="title">Cash Refund</div>
                        <div class="desc">Requires admin approval</div>
                    </label>
                </div>
            </div>

            <!-- Reason -->
            <div class="form-group">
                <label>Reason</label>
                <select name="reason" class="form-control">
                    <option value="">-- Select Reason --</option>
                    <option value="damaged">Damaged Product</option>
                    <option value="wrong_item">Wrong Item</option>
                    <option value="quality_issue">Quality Issue</option>
                    <option value="expired">Expired</option>
                    <option value="customer_changed_mind">Customer Changed Mind</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <!-- Notes -->
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
            </div>

            <button type="submit" class="btn-submit" id="submit_btn" disabled>
                Create Return
            </button>
        </form>
    </div>
</div>

<script>
const exchangeRate = <?= $exchangeRate ?? 0 ?>;
let returnItems = [];
let itemIndex = 0;

function openReturnModal() {
    document.getElementById('returnModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeReturnModal() {
    document.getElementById('returnModal').classList.remove('show');
    document.body.style.overflow = '';
    resetForm();
}

function resetForm() {
    document.getElementById('returnForm').reset();
    document.getElementById('customer_id').value = '';
    document.getElementById('invoice_id').value = '';
    document.getElementById('selected_customer').style.display = 'none';
    document.getElementById('invoice_section').style.display = 'none';
    returnItems = [];
    itemIndex = 0;
    updateItemsTable();
}

// Customer search
let customerSearchTimeout;
document.getElementById('customer_search').addEventListener('input', function() {
    clearTimeout(customerSearchTimeout);
    const query = this.value.trim();

    if (query.length < 1) {
        document.getElementById('customer_dropdown').style.display = 'none';
        return;
    }

    customerSearchTimeout = setTimeout(() => {
        fetch(`customer_returns.php?action=search_customers&q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                const dropdown = document.getElementById('customer_dropdown');
                if (data.results && data.results.length > 0) {
                    dropdown.innerHTML = data.results.map(c => `
                        <div class="autocomplete-item" onclick="selectCustomer(${c.id}, '${escapeHtml(c.name)}', '${escapeHtml(c.phone || '')}')">
                            <strong>${escapeHtml(c.name)}</strong>
                            <small>${escapeHtml(c.phone || '')} ${c.city ? '- ' + escapeHtml(c.city) : ''}</small>
                        </div>
                    `).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.style.display = 'none';
                }
            });
    }, 300);
});

function selectCustomer(id, name, phone) {
    document.getElementById('customer_id').value = id;
    document.getElementById('customer_search').value = '';
    document.getElementById('customer_dropdown').style.display = 'none';
    document.getElementById('selected_customer').innerHTML = `<strong>${escapeHtml(name)}</strong><br><small>${escapeHtml(phone)}</small>`;
    document.getElementById('selected_customer').style.display = 'block';

    // Load customer invoices
    document.getElementById('invoice_section').style.display = 'block';
    fetch(`customer_returns.php?action=get_customer_invoices&customer_id=${id}`)
        .then(r => r.json())
        .then(data => {
            const select = document.getElementById('invoice_select');
            select.innerHTML = '<option value="">-- Select Invoice (optional) --</option>';
            if (data.invoices) {
                data.invoices.forEach(inv => {
                    select.innerHTML += `<option value="${inv.id}">${inv.invoice_number} - $${parseFloat(inv.total_usd).toFixed(2)} (${inv.created_at.split(' ')[0]})</option>`;
                });
            }
        });

    updateSubmitButton();
}

function loadInvoiceItems() {
    const invoiceId = document.getElementById('invoice_select').value;
    document.getElementById('invoice_id').value = invoiceId;

    if (!invoiceId) return;

    fetch(`customer_returns.php?action=get_invoice_items&invoice_id=${invoiceId}`)
        .then(r => r.json())
        .then(data => {
            if (data.items) {
                returnItems = [];
                itemIndex = 0;
                data.items.forEach(item => {
                    // Only add items that have remaining returnable quantity
                    const remaining = parseFloat(item.remaining_qty) || 0;
                    if (remaining > 0) {
                        addItemFromInvoice(
                            item.product_id,
                            item.item_name,
                            item.sku,
                            remaining,  // Default qty to return = remaining
                            item.unit_price_usd,
                            parseFloat(item.original_qty) || 0,
                            parseFloat(item.already_returned) || 0
                        );
                    }
                });

                // Show message if all items already fully returned
                if (returnItems.length === 0 && data.items.length > 0) {
                    alert('All items from this invoice have already been fully returned.');
                }
            }
        });
}

// Product search
let productSearchTimeout;
document.getElementById('product_search').addEventListener('input', function() {
    clearTimeout(productSearchTimeout);
    const query = this.value.trim();

    if (query.length < 1) {
        document.getElementById('product_dropdown').style.display = 'none';
        return;
    }

    productSearchTimeout = setTimeout(() => {
        fetch(`customer_returns.php?action=search_products&q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                const dropdown = document.getElementById('product_dropdown');
                if (data.results && data.results.length > 0) {
                    dropdown.innerHTML = data.results.map(p => `
                        <div class="autocomplete-item" onclick="addItem(${p.id}, '${escapeHtml(p.item_name)}', '${escapeHtml(p.sku)}', 1, ${p.sale_price_usd})">
                            <strong>${escapeHtml(p.item_name)}</strong>
                            <small>${escapeHtml(p.sku)} - $${parseFloat(p.sale_price_usd).toFixed(2)}</small>
                        </div>
                    `).join('');
                    dropdown.style.display = 'block';
                } else {
                    dropdown.style.display = 'none';
                }
            });
    }, 300);
});

function addItem(productId, name, sku, qty, price) {
    // Check if product already exists
    const existing = returnItems.find(i => i.product_id === productId);
    if (existing) {
        existing.quantity += parseFloat(qty);
        updateItemsTable();
        return;
    }

    returnItems.push({
        index: itemIndex++,
        product_id: productId,
        name: name,
        sku: sku,
        quantity: parseFloat(qty),
        unit_price_usd: parseFloat(price),
        // No invoice tracking for manual adds
        original_qty: null,
        already_returned: null,
        max_returnable: null
    });

    document.getElementById('product_search').value = '';
    document.getElementById('product_dropdown').style.display = 'none';
    updateItemsTable();
}

// Add item from invoice with tracking info
function addItemFromInvoice(productId, name, sku, qty, price, originalQty, alreadyReturned) {
    // Check if product already exists
    const existing = returnItems.find(i => i.product_id === productId);
    if (existing) {
        return; // Don't add duplicates from invoice
    }

    const maxReturnable = originalQty - alreadyReturned;

    returnItems.push({
        index: itemIndex++,
        product_id: productId,
        name: name,
        sku: sku,
        quantity: parseFloat(qty),
        unit_price_usd: parseFloat(price),
        // Invoice tracking
        original_qty: originalQty,
        already_returned: alreadyReturned,
        max_returnable: maxReturnable
    });

    updateItemsTable();
}

function removeItem(index) {
    returnItems = returnItems.filter(i => i.index !== index);
    updateItemsTable();
}

function updateItemQuantity(index, qty, maxReturnable) {
    const item = returnItems.find(i => i.index === index);
    if (item) {
        let newQty = parseFloat(qty) || 0;

        // Enforce max returnable limit if set
        if (maxReturnable !== null && newQty > maxReturnable) {
            newQty = maxReturnable;
            alert(`Maximum returnable quantity for this item is ${maxReturnable}`);
        }

        item.quantity = newQty;
    }
    updateItemsTable(); // Refresh to show corrected value
}

function updateItemPrice(index, price) {
    const item = returnItems.find(i => i.index === index);
    if (item) {
        item.unit_price_usd = parseFloat(price) || 0;
    }
    updateTotal();
}

function updateItemsTable() {
    const tbody = document.getElementById('items_body');

    if (returnItems.length === 0) {
        tbody.innerHTML = `
            <tr id="no_items_row">
                <td colspan="5" style="text-align: center; color: #9ca3af; padding: 20px;">
                    Add products to return
                </td>
            </tr>
        `;
    } else {
        tbody.innerHTML = returnItems.map(item => {
            // Build invoice tracking info if available
            let trackingInfo = '';
            let maxAttr = '';
            if (item.original_qty !== null) {
                trackingInfo = `
                    <div style="font-size:0.75rem; margin-top:4px; padding:4px 6px; background:#f0f9ff; border-radius:4px; color:#0369a1;">
                        <span title="Original invoice quantity">Orig: ${item.original_qty}</span>
                        ${item.already_returned > 0 ? `<span style="color:#dc2626;" title="Already returned"> | Ret: ${item.already_returned}</span>` : ''}
                        <span style="color:#059669;" title="Maximum you can return"> | Max: ${item.max_returnable}</span>
                    </div>
                `;
                maxAttr = `max="${item.max_returnable}"`;
            }

            return `
            <tr>
                <td>
                    <strong>${escapeHtml(item.name)}</strong><br>
                    <small style="color:#9ca3af;">${escapeHtml(item.sku)}</small>
                    ${trackingInfo}
                    <input type="hidden" name="items[${item.index}][product_id]" value="${item.product_id}">
                    <input type="hidden" name="items[${item.index}][reason]" value="">
                </td>
                <td>
                    <input type="number" name="items[${item.index}][quantity]" value="${item.quantity}"
                           min="0.001" step="0.001" ${maxAttr} style="width:70px;"
                           onchange="updateItemQuantity(${item.index}, this.value, ${item.max_returnable || 'null'})">
                </td>
                <td>
                    <input type="number" name="items[${item.index}][unit_price_usd]" value="${item.unit_price_usd.toFixed(2)}"
                           min="0" step="0.01" style="width:80px;"
                           onchange="updateItemPrice(${item.index}, this.value)">
                </td>
                <td>$${(item.quantity * item.unit_price_usd).toFixed(2)}</td>
                <td>
                    <button type="button" class="btn-remove" onclick="removeItem(${item.index})">X</button>
                </td>
            </tr>
        `}).join('');
    }

    updateTotal();
    updateSubmitButton();
}

function updateTotal() {
    const total = returnItems.reduce((sum, item) => sum + (item.quantity * item.unit_price_usd), 0);
    document.getElementById('total_display').textContent = '$' + total.toFixed(2);
}

function updateSubmitButton() {
    const hasCustomer = document.getElementById('customer_id').value !== '';
    const hasItems = returnItems.length > 0 && returnItems.some(i => i.quantity > 0);
    document.getElementById('submit_btn').disabled = !(hasCustomer && hasItems);
}

// Refund method selection
document.querySelectorAll('.refund-option').forEach(opt => {
    opt.addEventListener('click', function() {
        document.querySelectorAll('.refund-option').forEach(o => o.classList.remove('selected'));
        this.classList.add('selected');
    });
});

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('#customer_search') && !e.target.closest('#customer_dropdown')) {
        document.getElementById('customer_dropdown').style.display = 'none';
    }
    if (!e.target.closest('#product_search') && !e.target.closest('#product_dropdown')) {
        document.getElementById('product_dropdown').style.display = 'none';
    }
});

// Close modal on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReturnModal();
    }
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php
sales_portal_render_layout_end();
