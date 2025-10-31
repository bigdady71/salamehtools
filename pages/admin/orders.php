<?php
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';

require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$pdo = db();
$title = 'Admin · Orders';

$statusLabels = [
    'on_hold' => 'On Hold',
    'approved' => 'Approved',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pickup',
    'in_transit' => 'In Transit',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
    'returned' => 'Returned',
];

$statusBadgeClasses = [
    'on_hold' => 'status-warning',
    'approved' => 'status-info',
    'preparing' => 'status-info',
    'ready' => 'status-info',
    'in_transit' => 'status-accent',
    'delivered' => 'status-success',
    'cancelled' => 'status-danger',
    'returned' => 'status-danger',
];

$invoiceStatusLabels = [
    'draft' => 'Draft',
    'issued' => 'Issued',
    'paid' => 'Paid',
    'voided' => 'Voided',
];

$exchangeRateBaseCurrency = 'USD';
$exchangeRateQuoteCurrency = 'LBP';
$activeExchangeRate = [
    'id' => null,
    'rate' => null,
];

try {
    $rateStmt = $pdo->prepare("
        SELECT id, rate
        FROM exchange_rates
        WHERE UPPER(base_currency) = :base
          AND UPPER(quote_currency) IN ('LBP', 'LEBP')
        ORDER BY valid_from DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    $rateStmt->execute([':base' => strtoupper($exchangeRateBaseCurrency)]);
    $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if ($rateRow) {
        $activeExchangeRate['id'] = (int)$rateRow['id'];
        $activeExchangeRate['rate'] = (float)$rateRow['rate'];
    }
} catch (PDOException $e) {
    // Ignore exchange rate lookup failures; fallback below.
}

if (!is_finite($activeExchangeRate['rate'] ?? null) || $activeExchangeRate['rate'] <= 0) {
    $activeExchangeRate['rate'] = 89500.0;
    $activeExchangeRate['id'] = null;
}

$productLookupEndpoint = 'ajax_product_lookup.php';
$dirPath = realpath(__DIR__);
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
if ($dirPath && $docRoot) {
    $dirNormalized = str_replace('\\', '/', $dirPath);
    $docRootNormalized = rtrim(str_replace('\\', '/', $docRoot), '/');
    if ($docRootNormalized !== '' && str_starts_with($dirNormalized, $docRootNormalized)) {
        $relative = substr($dirNormalized, strlen($docRootNormalized));
        $relative = '/' . ltrim($relative, '/');
        $productLookupEndpoint = rtrim($relative, '/') . '/ajax_product_lookup.php';
    }
}
$customerSearchEndpointValue = $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? '');

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$repFilter = $_GET['rep'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$validStatusFilters = array_merge(array_keys($statusLabels), ['none']);
if (!in_array($statusFilter, $validStatusFilters, true)) {
    $statusFilter = 'none';
}

$repIdFilter = null;
if ($repFilter !== '') {
    $repIdFilter = (int)$repFilter;
    if ($repIdFilter <= 0) {
        $repFilter = '';
        $repIdFilter = null;
    }
}

$latestStatusSql = <<<SQL
    SELECT ose.order_id, ose.status, ose.created_at
    FROM order_status_events ose
    INNER JOIN (
        SELECT order_id, MAX(id) AS max_id
        FROM order_status_events
        GROUP BY order_id
    ) latest ON latest.order_id = ose.order_id AND latest.max_id = ose.id
SQL;

if (($_GET['ajax'] ?? '') === 'customer_search') {
    $term = trim((string)($_GET['term'] ?? ''));
    header('Content-Type: application/json');

    if ($term === '') {
        echo json_encode(['results' => []]);
        exit;
    }

    $likeTerm = '%' . $term . '%';
    $searchStmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            c.phone,
            c.user_id,
            COALESCE(u.name, u.email) AS user_name
        FROM customers c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.name LIKE :term OR (c.phone IS NOT NULL AND c.phone LIKE :term)
        ORDER BY c.name ASC
        LIMIT 12
    ");
    $searchStmt->execute([':term' => $likeTerm]);
    $results = [];
    foreach ($searchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'phone' => $row['phone'],
            'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'user_name' => $row['user_name'] ?? null,
        ];
    }

    echo json_encode(['results' => $results]);
    exit;
}

function evaluate_invoice_ready(PDO $pdo, int $orderId): array
{
    global $latestStatusSql;

    $reasons = [];

    $orderStmt = $pdo->prepare(
        "SELECT o.customer_id, o.sales_rep_id, o.exchange_rate_id, o.total_usd, o.total_lbp,
                fx.rate AS fx_rate, latest.status AS latest_status
         FROM orders o
         LEFT JOIN exchange_rates fx ON fx.id = o.exchange_rate_id
         LEFT JOIN ({$latestStatusSql}) latest ON latest.order_id = o.id
         WHERE o.id = :order_id
         LIMIT 1"
    );
    $orderStmt->execute([':order_id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['ready' => false, 'reasons' => ['Order not found']];
    }

    $status = $order['latest_status'] ?? null;
    $allowedStatuses = ['approved', 'preparing', 'ready'];
    if (!in_array($status, $allowedStatuses, true)) {
        $reasons[] = 'Order status must be Approved, Preparing, or Ready.';
    }

    if (empty($order['customer_id'])) {
        $reasons[] = 'Customer is required.';
    }

    if (empty($order['sales_rep_id'])) {
        $reasons[] = 'Sales representative must be assigned.';
    }

    $fxRate = $order['fx_rate'] !== null ? (float)$order['fx_rate'] : null;
    if (!$order['exchange_rate_id'] || !$fxRate || $fxRate <= 0) {
        $reasons[] = 'Valid USD → LBP exchange rate is required.';
    }

    $itemStmt = $pdo->prepare(
        'SELECT oi.product_id, oi.quantity, oi.unit_price_usd, oi.unit_price_lbp, p.item_name, p.quantity_on_hand
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = :order_id'
    );
    $itemStmt->execute([':order_id' => $orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        $reasons[] = 'Order must contain at least one item.';
    }

    $sumUsd = 0.0;
    $sumLbpManual = 0.0;

    foreach ($items as $item) {
        $qty = (float)$item['quantity'];
        $unitUsd = (float)$item['unit_price_usd'];
        $unitLbp = $item['unit_price_lbp'] !== null ? (float)$item['unit_price_lbp'] : null;
        $name = $item['item_name'] ?? ('Product #' . (int)$item['product_id']);

        if ($qty <= 0) {
            $reasons[] = sprintf('Item %s has invalid quantity.', $name);
        }

        if ($unitUsd <= 0) {
            $reasons[] = sprintf('Item %s is missing a USD price.', $name);
        }

        if ($fxRate && $fxRate > 0 && ($unitLbp === null || $unitLbp <= 0)) {
            $reasons[] = sprintf('Item %s is missing converted LBP pricing.', $name);
        }

        if ($item['quantity_on_hand'] !== null) {
            $available = (float)$item['quantity_on_hand'];
            if ($available - $qty < -0.0001) {
                $reasons[] = sprintf('Insufficient stock for %s.', $name);
            }
        }

        $sumUsd += $qty * max($unitUsd, 0);
        if ($unitLbp !== null) {
            $sumLbpManual += $qty * max($unitLbp, 0);
        }
    }

    $storedUsd = isset($order['total_usd']) ? (float)$order['total_usd'] : 0.0;
    if (abs($sumUsd - $storedUsd) > 0.05) {
        $reasons[] = 'Stored USD total does not match line items.';
    }

    $storedLbp = isset($order['total_lbp']) ? (float)$order['total_lbp'] : 0.0;
    if ($fxRate && $fxRate > 0) {
        $expectedLbp = round($sumUsd * $fxRate);
        if (abs($expectedLbp - $storedLbp) > 5) {
            $reasons[] = 'Stored LBP total does not match FX conversion.';
        }
    } elseif ($sumLbpManual > 0 && abs($sumLbpManual - $storedLbp) > 5) {
        $reasons[] = 'Stored LBP total does not match manual line totals.';
    }

    return [
        'ready' => empty($reasons),
        'reasons' => $reasons,
    ];
}

function orders_has_invoice_ready_column(PDO $pdo): bool
{
    static $hasColumn = null;

    if ($hasColumn === null) {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'invoice_ready'");
            $hasColumn = (bool)$columnStmt->fetch(PDO::FETCH_ASSOC);
            $columnStmt->closeCursor();
        } catch (PDOException $e) {
            $hasColumn = false;
        }
    }

    return $hasColumn;
}

function refresh_invoice_ready(PDO $pdo, int $orderId): array
{
    $evaluation = evaluate_invoice_ready($pdo, $orderId);
    if (orders_has_invoice_ready_column($pdo)) {
        $update = $pdo->prepare('UPDATE orders SET invoice_ready = :ready WHERE id = :id');
        $update->execute([
            ':ready' => $evaluation['ready'] ? 1 : 0,
            ':id' => $orderId,
        ]);
    }

    return $evaluation;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'create_order') {
        $customerMode = $_POST['customer_mode'] ?? 'existing';
        $customerMode = $customerMode === 'new' ? 'new' : 'existing';
        $selectedCustomerId = (int)($_POST['customer_id'] ?? 0);
        $customerUserId = (int)($_POST['customer_user_id'] ?? 0);
        $customerNameInput = trim($_POST['customer_name'] ?? '');
        $customerPhoneInput = trim($_POST['customer_phone'] ?? '');
        $salesRepInput = $_POST['sales_rep_id'] ?? '';
        $salesRepId = $salesRepInput !== '' ? (int)$salesRepInput : null;
        $totalUsd = 0.0;
        $totalLbp = 0.0;
        $notes = trim($_POST['notes'] ?? '');
        $initialStatus = $_POST['initial_status'] ?? 'on_hold';
        $statusNote = trim($_POST['status_note'] ?? '');
        $orderItemsPayload = $_POST['order_items'] ?? '[]';
        $postedExchangeRateId = (int)($_POST['exchange_rate_id'] ?? 0);
        $exchangeRateId = $activeExchangeRate['id'] !== null ? (int)$activeExchangeRate['id'] : null;
        $exchangeRateValue = (float)($activeExchangeRate['rate'] ?? 0.0);

        if ($salesRepId === null || $salesRepId <= 0) {
            flash('error', 'Assign a sales representative before creating the order.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        if ($postedExchangeRateId > 0 && $postedExchangeRateId !== $exchangeRateId) {
            $rateByIdStmt = $pdo->prepare("
                SELECT id, rate
                FROM exchange_rates
                WHERE id = :id AND UPPER(base_currency) = :base
            ");
            $rateByIdStmt->execute([
                ':id' => $postedExchangeRateId,
                ':base' => strtoupper($exchangeRateBaseCurrency),
            ]);
            $rateByIdRow = $rateByIdStmt->fetch(PDO::FETCH_ASSOC);
            if ($rateByIdRow) {
                $exchangeRateId = (int)$rateByIdRow['id'];
                $exchangeRateValue = (float)$rateByIdRow['rate'];
            }
        }

        if ($exchangeRateValue <= 0) {
            $exchangeRateValue = (float)($activeExchangeRate['rate'] ?? 0.0);
        }

        if (!is_finite($exchangeRateValue) || $exchangeRateValue <= 0) {
            $exchangeRateValue = 0.0;
            $exchangeRateId = $exchangeRateId ?: null;
        }

        if ($exchangeRateValue <= 0) {
            flash('error', 'Configure a valid USD → LBP exchange rate before creating an order.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        if (!isset($statusLabels[$initialStatus])) {
            $initialStatus = 'on_hold';
        }

        if ($customerMode === 'new') {
            $selectedCustomerId = 0;
            if ($customerNameInput === '') {
                flash('error', 'Provide a name for the new customer.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
            if ($customerPhoneInput !== '') {
                $phoneCheck = $pdo->prepare("SELECT id FROM customers WHERE phone = :phone LIMIT 1");
                $phoneCheck->execute([':phone' => $customerPhoneInput]);
                if ($phoneCheck->fetchColumn()) {
                    flash('error', 'A customer with this phone already exists. Select the existing record instead.');
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }
            }
        } else {
            // Ignore accidental population of new customer fields when using an existing customer.
            $customerNameInput = '';
            $customerPhoneInput = '';
        }

        $repCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'sales_rep'");
        $repCheck->execute([':id' => $salesRepId]);
        if ((int)$repCheck->fetchColumn() === 0) {  
            flash('error', 'Sales representative not found or inactive.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $decodedItems = json_decode($orderItemsPayload, true);
        if (!is_array($decodedItems)) {
            flash('error', 'Failed to parse order items. Please try again.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $sanitizedItems = [];
        $uniqueProductIds = [];

        foreach ($decodedItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
            $unitPriceUsd = isset($item['unit_price_usd']) && $item['unit_price_usd'] !== '' ? (float)$item['unit_price_usd'] : 0.0;
            $unitPriceLbp = isset($item['unit_price_lbp']) && $item['unit_price_lbp'] !== '' ? (float)$item['unit_price_lbp'] : null;

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            if ($unitPriceUsd < 0) {
                flash('error', 'Unit prices cannot be negative.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            if ($unitPriceLbp !== null && $unitPriceLbp < 0) {
                flash('error', 'Unit prices cannot be negative.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            $sanitizedItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price_usd' => $unitPriceUsd,
                'unit_price_lbp' => $unitPriceLbp,
            ];
            $uniqueProductIds[$productId] = true;
        }

        if (empty($sanitizedItems)) {
            flash('error', 'Add at least one item to the order.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $productIds = array_keys($uniqueProductIds);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productStmt = $pdo->prepare("
            SELECT id, item_name, sale_price_usd, wholesale_price_usd, quantity_on_hand
            FROM products
            WHERE id IN ($placeholders) AND is_active = 1
        ");
        $productStmt->execute($productIds);
        $productsMap = [];

        foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $productRow) {
            $productsMap[(int)$productRow['id']] = [
                'name' => $productRow['item_name'],
                'sale_price_usd' => $productRow['sale_price_usd'] !== null ? (float)$productRow['sale_price_usd'] : null,
                'wholesale_price_usd' => $productRow['wholesale_price_usd'] !== null ? (float)$productRow['wholesale_price_usd'] : null,
                'quantity_on_hand' => $productRow['quantity_on_hand'] !== null ? (float)$productRow['quantity_on_hand'] : null,
            ];
        }

        foreach ($productIds as $productId) {
            if (!isset($productsMap[$productId])) {
                flash('error', 'One or more selected products are unavailable. Please refresh and try again.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
        }

        foreach ($sanitizedItems as &$item) {
            if ($item['unit_price_usd'] <= 0) {
                $wholesale = $productsMap[$item['product_id']]['wholesale_price_usd'] ?? null;
                $sale = $productsMap[$item['product_id']]['sale_price_usd'] ?? null;
                $fallback = $wholesale !== null && $wholesale > 0 ? $wholesale : ($sale !== null && $sale > 0 ? $sale : null);
                if ($fallback !== null) {
                    $item['unit_price_usd'] = (float)$fallback;
                }
            }

            if ($item['unit_price_usd'] <= 0 && ($item['unit_price_lbp'] === null || $item['unit_price_lbp'] <= 0)) {
                flash('error', 'Each order item must have a price in USD or LBP.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            $item['unit_price_usd'] = $item['unit_price_usd'] > 0 ? round($item['unit_price_usd'], 2) : 0.0;
            if ($item['unit_price_usd'] <= 0) {
                flash('error', 'Each order item must include a positive USD price.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            if ($exchangeRateValue > 0 && $item['unit_price_usd'] > 0) {
                $item['unit_price_lbp'] = (float)round($item['unit_price_usd'] * $exchangeRateValue);
            } elseif ($item['unit_price_lbp'] !== null) {
                $item['unit_price_lbp'] = round($item['unit_price_lbp'], 2);
            }

            $available = $productsMap[$item['product_id']]['quantity_on_hand'] ?? null;
            if ($available !== null && ($available - $item['quantity']) < -0.0001) {
                flash('error', 'Insufficient stock for ' . $productsMap[$item['product_id']]['name'] . '.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            $lineUsd = $item['quantity'] * $item['unit_price_usd'];
            if ($item['unit_price_lbp'] !== null) {
                $lineLbp = $exchangeRateValue > 0
                    ? round($item['quantity'] * $item['unit_price_lbp'])
                    : $item['quantity'] * $item['unit_price_lbp'];
            } else {
                $lineLbp = 0.0;
            }

            $totalUsd += $lineUsd;
            $totalLbp += $lineLbp;
        }
        unset($item);

        $totalUsd = round($totalUsd, 2);
        if ($exchangeRateValue > 0) {
            $convertedTotalLbp = round($totalUsd * $exchangeRateValue);
            if ($convertedTotalLbp > 0) {
                $totalLbp = (float)$convertedTotalLbp;
            }
        }
        $totalLbp = $exchangeRateValue > 0 ? round($totalLbp) : round($totalLbp, 2);

        if ($totalUsd < 0 || $totalLbp < 0) {
            flash('error', 'Totals cannot be negative.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        if ($totalUsd <= 0 && $totalLbp <= 0) {
            flash('error', 'Order total must be greater than zero.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $postedTotalUsd = isset($_POST['total_usd']) ? (float)$_POST['total_usd'] : 0.0;
        if ($postedTotalUsd > 0 && abs($postedTotalUsd - $totalUsd) > 0.05) {
            flash('error', 'Displayed USD total did not match calculated amount. Please retry.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $postedTotalLbp = isset($_POST['total_lbp']) ? (float)$_POST['total_lbp'] : 0.0;
        if ($postedTotalLbp > 0 && abs($postedTotalLbp - $totalLbp) > 5) {
            flash('error', 'Displayed LBP total did not match calculated amount. Please retry.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }

        $resolveCustomer = static function (PDO $pdo, string $name, ?string $phone, ?int $userId = null): ?int {
            if ($userId !== null) {
                $byUser = $pdo->prepare("SELECT id FROM customers WHERE user_id = :user_id LIMIT 1");
                $byUser->execute([':user_id' => $userId]);
                $foundByUser = $byUser->fetchColumn();
                if ($foundByUser !== false) {
                    return (int)$foundByUser;
                }
            }

            if ($phone !== null && $phone !== '') {
                $byPhone = $pdo->prepare("SELECT id FROM customers WHERE phone = :phone LIMIT 1");
                $byPhone->execute([':phone' => $phone]);
                $found = $byPhone->fetchColumn();
                if ($found !== false) {
                    return (int)$found;
                }
            }

            $byName = $pdo->prepare("SELECT id FROM customers WHERE name = :name LIMIT 1");
            $byName->execute([':name' => $name]);
            $foundByName = $byName->fetchColumn();
            return $foundByName !== false ? (int)$foundByName : null;
        };

        try {
            $pdo->beginTransaction();

            $customerId = null;

            if ($selectedCustomerId > 0) {
                $customerCheck = $pdo->prepare("SELECT id FROM customers WHERE id = :id LIMIT 1");
                $customerCheck->execute([':id' => $selectedCustomerId]);
                $existingId = $customerCheck->fetchColumn();
                if ($existingId === false) {
                    throw new RuntimeException('Selected customer was not found. Please refresh and try again.');
                }
                $customerId = (int)$existingId;
            } elseif ($customerUserId > 0) {
                $userStmt = $pdo->prepare("
                    SELECT id, name, phone
                    FROM users
                    WHERE id = :id AND role = 'customer' AND is_active = 1
                ");
                $userStmt->execute([':id' => $customerUserId]);
                $customerUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                if (!$customerUser) {
                    throw new RuntimeException('Customer user not found or inactive.');
                }

                $candidateName = trim((string)$customerUser['name']);
                if ($candidateName === '') {
                    $candidateName = 'Customer #' . (int)$customerUser['id'];
                }
                $candidatePhone = trim((string)($customerUser['phone'] ?? ''));
                $candidatePhone = $candidatePhone !== '' ? $candidatePhone : null;

                $customerId = $resolveCustomer($pdo, $candidateName, $candidatePhone, (int)$customerUser['id']);
                if ($customerId === null) {
                    $insertCustomer = $pdo->prepare("
                        INSERT INTO customers (user_id, name, phone, assigned_sales_rep_id, location, shop_type, is_active, created_at, updated_at)
                        VALUES (:user_id, :name, :phone, :rep_id, :location, :shop_type, 1, NOW(), NOW())
                    ");
                    $insertCustomer->execute([
                        ':user_id' => (int)$customerUser['id'],
                        ':name' => $candidateName,
                        ':phone' => $candidatePhone,
                        ':rep_id' => $salesRepId,
                        ':location' => null,
                        ':shop_type' => null,
                    ]);
                    $customerId = (int)$pdo->lastInsertId();
                }
            } elseif ($customerNameInput !== '') {
                $candidateName = $customerNameInput;
                $candidatePhone = $customerPhoneInput !== '' ? $customerPhoneInput : null;

                $customerId = $resolveCustomer($pdo, $candidateName, $candidatePhone, null);
                if ($customerId === null) {
                    $insertCustomer = $pdo->prepare("
                        INSERT INTO customers (user_id, name, phone, assigned_sales_rep_id, location, shop_type, is_active, created_at, updated_at)
                        VALUES (:user_id, :name, :phone, :rep_id, :location, :shop_type, 1, NOW(), NOW())
                    ");
                    $insertCustomer->execute([
                        ':user_id' => null,
                        ':name' => $candidateName,
                        ':phone' => $candidatePhone,
                        ':rep_id' => $salesRepId,
                        ':location' => null,
                        ':shop_type' => null,
                    ]);
                    $customerId = (int)$pdo->lastInsertId();
                }
            } else {
                throw new RuntimeException('Select an existing customer or provide details for a new customer.');
            }

            $insertOrder = $pdo->prepare("
                INSERT INTO orders (order_number, customer_id, sales_rep_id, exchange_rate_id, total_usd, total_lbp, notes, created_at, updated_at)
                VALUES (NULL, :customer_id, :sales_rep_id, :exchange_rate_id, :total_usd, :total_lbp, :notes, NOW(), NOW())
            ");
            $insertOrder->execute([
                ':customer_id' => $customerId,
                ':sales_rep_id' => $salesRepId,
                ':exchange_rate_id' => $exchangeRateId,
                ':total_usd' => $totalUsd,
                ':total_lbp' => $totalLbp,
                ':notes' => $notes !== '' ? $notes : null,
            ]);

            $orderId = (int)$pdo->lastInsertId();
            $orderNumber = sprintf('ORD-%06d', $orderId);

            $pdo->prepare("UPDATE orders SET order_number = :order_number WHERE id = :id")
                ->execute([':order_number' => $orderNumber, ':id' => $orderId]);

            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, unit_price_usd, unit_price_lbp, discount_percent)
                VALUES (:order_id, :product_id, :quantity, :unit_price_usd, :unit_price_lbp, 0.00)
            ");

            $orderItemsForInvoice = [];

            foreach ($sanitizedItems as $item) {
                $unitPriceUsd = round($item['unit_price_usd'], 2);
                $unitPriceLbp = $item['unit_price_lbp'] !== null ? round($item['unit_price_lbp'], 2) : null;

                $itemStmt->execute([
                    ':order_id' => $orderId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit_price_usd' => $unitPriceUsd,
                    ':unit_price_lbp' => $unitPriceLbp,
                ]);

                $orderItemsForInvoice[] = [
                    'order_item_id' => (int)$pdo->lastInsertId(),
                    'product_id' => $item['product_id'],
                    'description' => $productsMap[$item['product_id']]['name'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price_usd' => $unitPriceUsd,
                    'unit_price_lbp' => $unitPriceLbp,
                ];
            }

            $invoicePlaceholder = str_replace('.', '', uniqid('INV-TMP-', true));
            $invoiceStmt = $pdo->prepare("
                INSERT INTO invoices (invoice_number, order_id, sales_rep_id, status, total_usd, total_lbp)
                VALUES (:invoice_number, :order_id, :sales_rep_id, :status, :total_usd, :total_lbp)
            ");
            $invoiceStmt->execute([
                ':invoice_number' => $invoicePlaceholder,
                ':order_id' => $orderId,
                ':sales_rep_id' => $salesRepId,
                ':status' => 'draft',
                ':total_usd' => $totalUsd,
                ':total_lbp' => $totalLbp,
            ]);

            $invoiceId = (int)$pdo->lastInsertId();
            $invoiceNumber = sprintf('INV-%06d', $invoiceId);

            $pdo->prepare("
                UPDATE invoices SET invoice_number = :invoice_number WHERE id = :id
            ")->execute([
                ':invoice_number' => $invoiceNumber,
                ':id' => $invoiceId,
            ]);

            if ($orderItemsForInvoice) {
                $invoiceItemStmt = $pdo->prepare("
                    INSERT INTO invoice_items (invoice_id, order_item_id, product_id, description, quantity, unit_price_usd, unit_price_lbp, discount_percent)
                    VALUES (:invoice_id, :order_item_id, :product_id, :description, :quantity, :unit_price_usd, :unit_price_lbp, 0.00)
                ");

                foreach ($orderItemsForInvoice as $invoiceItem) {
                    $invoiceItemStmt->execute([
                        ':invoice_id' => $invoiceId,
                        ':order_item_id' => $invoiceItem['order_item_id'] > 0 ? $invoiceItem['order_item_id'] : null,
                        ':product_id' => $invoiceItem['product_id'],
                        ':description' => $invoiceItem['description'],
                        ':quantity' => $invoiceItem['quantity'],
                        ':unit_price_usd' => $invoiceItem['unit_price_usd'],
                        ':unit_price_lbp' => $invoiceItem['unit_price_lbp'],
                    ]);
                }
            }

            $statusStmt = $pdo->prepare("
                INSERT INTO order_status_events (order_id, status, actor_user_id, note)
                VALUES (:order_id, :status, :actor_user_id, :note)
            ");
            $statusStmt->execute([
                ':order_id' => $orderId,
                ':status' => $initialStatus,
                ':actor_user_id' => (int)$user['id'],
                ':note' => $statusNote !== '' ? $statusNote : null,
            ]);

            refresh_invoice_ready($pdo, $orderId);

            $pdo->commit();
            flash('success', 'Order created successfully and draft invoice prepared.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Failed to create order.');
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'set_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $note = trim($_POST['note'] ?? '');

        if ($orderId > 0 && isset($statusLabels[$newStatus])) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO order_status_events (order_id, status, actor_user_id, note)
                    VALUES (:order_id, :status, :actor_user_id, :note)
                ");
                $stmt->execute([
                    ':order_id' => $orderId,
                    ':status' => $newStatus,
                    ':actor_user_id' => (int)$user['id'],
                    ':note' => $note !== '' ? $note : null,
                ]);

                $pdo->prepare("UPDATE orders SET updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $orderId]);

                refresh_invoice_ready($pdo, $orderId);

                $pdo->commit();
                flash('success', 'Order status updated.');
            } catch (PDOException $e) {
                $pdo->rollBack();
                flash('error', 'Failed to update order status.');
            }
        } else {
            flash('error', 'Invalid status update request.');
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'reassign_sales_rep') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newRepId = $_POST['sales_rep_id'] ?? '';
        $repId = null;

        if ($newRepId !== '') {
            $repId = (int)$newRepId;
            if ($repId <= 0) {
                $repId = null;
            }
        }

        if ($orderId > 0) {
            if ($repId !== null) {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'sales_rep'");
                $checkStmt->execute([':id' => $repId]);
                if ((int)$checkStmt->fetchColumn() === 0) {
                    flash('error', 'Sales rep not found.');
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }
            }

            try {
                $pdo->prepare("UPDATE orders SET sales_rep_id = :rep_id, updated_at = NOW() WHERE id = :id")
                    ->execute([':rep_id' => $repId, ':id' => $orderId]);
                refresh_invoice_ready($pdo, $orderId);
                flash('success', 'Sales rep updated for order.');
            } catch (PDOException $e) {
                flash('error', 'Failed to reassign sales rep.');
            }
        } else {
            flash('error', 'Invalid order id for reassignment.');
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$validDateFrom = null;
if ($dateFrom !== '') {
    $df = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if ($df && $df->format('Y-m-d') === $dateFrom) {
        $validDateFrom = $df->format('Y-m-d');
    } else {
        $dateFrom = '';
    }
}

$validDateTo = null;
if ($dateTo !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $dateTo);
    if ($dt && $dt->format('Y-m-d') === $dateTo) {
        $validDateTo = $dt->format('Y-m-d');
    } else {
        $dateTo = '';
    }
}

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(o.order_number LIKE :search OR c.name LIKE :search OR c.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== '') {
    if ($statusFilter === 'none') {
        $where[] = "latest.status IS NULL";
    } else {
        $where[] = "latest.status = :status";
        $params[':status'] = $statusFilter;
    }
}

if ($repIdFilter !== null) {
    $where[] = "o.sales_rep_id = :rep_id";
    $params[':rep_id'] = $repIdFilter;
}

if ($validDateFrom !== null) {
    $where[] = "DATE(o.created_at) >= :date_from";
    $params[':date_from'] = $validDateFrom;
}

if ($validDateTo !== null) {
    $where[] = "DATE(o.created_at) <= :date_to";
    $params[':date_to'] = $validDateTo;
}

$whereClause = implode(' AND ', $where);

$countSql = "
    SELECT COUNT(*)
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users s ON s.id = o.sales_rep_id
    LEFT JOIN ($latestStatusSql) latest ON latest.order_id = o.id
    WHERE {$whereClause}
";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalMatches = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalMatches / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$ordersHasInvoiceReadyColumn = orders_has_invoice_ready_column($pdo);
$invoiceReadySelectSql = $ordersHasInvoiceReadyColumn
    ? 'o.invoice_ready AS invoice_ready'
    : 'NULL AS invoice_ready';

$ordersSql = "
    SELECT
        o.id,
        o.order_number,
        o.total_usd,
        o.total_lbp,
        o.exchange_rate_id,
        {$invoiceReadySelectSql},
        o.notes,
        o.created_at,
        o.updated_at,
        c.name AS customer_name,
        c.phone AS customer_phone,
        s.name AS sales_rep_name,
        s.id AS sales_rep_id,
        latest.status AS latest_status,
        latest.created_at AS status_changed_at,
        inv.invoice_total_usd,
        inv.invoice_total_lbp,
        inv.invoice_count,
        inv_last.id AS latest_invoice_id,
        inv_last.invoice_number AS latest_invoice_number,
        inv_last.status AS latest_invoice_status,
        inv_last.created_at AS last_invoice_created_at,
        pay.paid_usd,
        pay.paid_lbp,
        pay.last_payment_at,
        del.status AS delivery_status,
        del.scheduled_at AS delivery_scheduled_at,
        del.expected_at AS delivery_expected_at,
        del.driver_user_id
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users s ON s.id = o.sales_rep_id
    LEFT JOIN ($latestStatusSql) latest ON latest.order_id = o.id
    LEFT JOIN (
        SELECT order_id,
               SUM(total_usd) AS invoice_total_usd,
               SUM(total_lbp) AS invoice_total_lbp,
               COUNT(*) AS invoice_count
        FROM invoices
        GROUP BY order_id
    ) inv ON inv.order_id = o.id
    LEFT JOIN (
        SELECT i.*
        FROM invoices i
        INNER JOIN (
            SELECT order_id, MAX(id) AS latest_invoice_id
            FROM invoices
            GROUP BY order_id
        ) il ON il.order_id = i.order_id AND il.latest_invoice_id = i.id
    ) inv_last ON inv_last.order_id = o.id
    LEFT JOIN (
        SELECT i.order_id,
               SUM(p.amount_usd) AS paid_usd,
               SUM(p.amount_lbp) AS paid_lbp,
               MAX(p.created_at) AS last_payment_at
        FROM payments p
        INNER JOIN invoices i ON i.id = p.invoice_id
        GROUP BY i.order_id
    ) pay ON pay.order_id = o.id
    LEFT JOIN (
        SELECT d.*
        FROM deliveries d
        INNER JOIN (
            SELECT order_id, MAX(id) AS latest_id
            FROM deliveries
            GROUP BY order_id
        ) ld ON ld.order_id = d.order_id AND ld.latest_id = d.id
    ) del ON del.order_id = o.id
    WHERE {$whereClause}
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset
";
$ordersStmt = $pdo->prepare($ordersSql);
foreach ($params as $key => $value) {
    $ordersStmt->bindValue($key, $value);
}
$ordersStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$ordersStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$ordersStmt->execute();
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$orderReadiness = [];
foreach ($orders as $orderRow) {
    $orderIdKey = (int)($orderRow['id'] ?? 0);
    if ($orderIdKey <= 0) {
        continue;
    }
    if ($ordersHasInvoiceReadyColumn && (int)($orderRow['invoice_ready'] ?? 0) === 1) {
        $orderReadiness[$orderIdKey] = ['ready' => true, 'reasons' => []];
    } else {
        $orderReadiness[$orderIdKey] = evaluate_invoice_ready($pdo, $orderIdKey);
    }
}

$salesRepsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' AND is_active = 1 ORDER BY name");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

$customersStmt = $pdo->query("SELECT id, name, phone, is_active FROM customers ORDER BY name LIMIT 250");
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$customerUsersStmt = $pdo->query("
    SELECT id, name, email, phone
    FROM users
    WHERE role = 'customer' AND is_active = 1
    ORDER BY name
    LIMIT 250
");
$customerUsers = $customerUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

$statusTotals = [];
$statusTotalsStmt = $pdo->query("
    SELECT latest.status AS status_key, COUNT(*) AS total
    FROM orders o
    LEFT JOIN ($latestStatusSql) latest ON latest.order_id = o.id
    GROUP BY status_key
");
foreach ($statusTotalsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $statusTotals[$row['status_key'] ?? 'none'] = (int)$row['total'];
}

$awaitingApproval = $statusTotals['on_hold'] ?? 0;
$preparingPipeline = ($statusTotals['approved'] ?? 0) + ($statusTotals['preparing'] ?? 0) + ($statusTotals['ready'] ?? 0);
$inTransitCount = $statusTotals['in_transit'] ?? 0;

$deliveredThisWeekStmt = $pdo->query("
    SELECT COUNT(*)
    FROM orders o
    INNER JOIN ($latestStatusSql) latest ON latest.order_id = o.id
    WHERE latest.status = 'delivered' AND latest.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$deliveredThisWeek = (int)$deliveredThisWeekStmt->fetchColumn();

$flashes = consume_flashes();

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Orders',
    'subtitle' => 'Command center for approvals, fulfillment and delivery status.',
    'active' => 'orders',
    'user' => $user,
]);
?>

<style>
    .flash {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 16px;
        font-size: 0.9rem;
        border: 1px solid var(--bd);
        background: var(--chip);
        color: var(--ink);
    }
    .flash-success {
        border-color: rgba(6, 95, 70, 0.25);
        background: rgba(6, 95, 70, 0.09);
        color: var(--ok);
    }
    .flash-error {
        border-color: rgba(153, 27, 27, 0.2);
        background: rgba(153, 27, 27, 0.08);
        color: var(--err);
    }
    .flash-info {
        border-color: rgba(31, 111, 235, 0.2);
        background: rgba(31, 111, 235, 0.08);
        color: var(--brand);
    }
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 18px;
        margin-bottom: 28px;
    }
    .metric-card {
        background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
        border: 1px solid var(--bd);
        border-radius: 16px;
        padding: 22px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: all 0.3s ease;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }
    .metric-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
        border-color: rgba(31, 111, 235, 0.3);
    }
    .metric-label {
        color: var(--muted);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .metric-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.1;
    }
    .filters {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 24px;
        align-items: center;
        padding: 20px;
        background: var(--panel);
        border: 1px solid var(--bd);
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
    }
    .filter-input {
        padding: 10px 14px;
        border-radius: 10px;
        border: 1px solid var(--bd);
        background: #fff;
        color: var(--ink);
        min-width: 180px;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }
    .filter-input:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(31, 111, 235, 0.1);
    }
    .filter-input::placeholder {
        color: var(--muted);
    }
    .orders-table {
        overflow-x: auto;
        border-radius: 16px;
        border: 1px solid var(--bd);
        background: var(--panel);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
    }
    .orders-table table {
        width: 100%;
        border-collapse: collapse;
    }
    .orders-table thead {
        background: linear-gradient(180deg, #f9fafb 0%, #f3f4f6 100%);
        border-bottom: 2px solid var(--bd);
    }
    .orders-table th {
        padding: 16px 14px;
        border-bottom: 2px solid var(--bd);
        text-align: left;
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        vertical-align: top;
        color: var(--ink);
    }
    .orders-table td {
        padding: 16px 14px;
        border-bottom: 1px solid var(--bd);
        text-align: left;
        font-size: 0.92rem;
        vertical-align: top;
        color: var(--ink);
    }
    .orders-table tbody tr {
        transition: background-color 0.15s ease;
    }
    .orders-table tbody tr:last-child td {
        border-bottom: none;
    }
    .orders-table tbody tr:hover {
        background: rgba(31, 111, 235, 0.06);
    }
    .orders-table small {
        color: var(--muted);
        display: block;
        margin-top: 4px;
        line-height: 1.4;
        font-size: 0.8rem;
    }
    .empty-state {
        text-align: center;
        padding: 48px 0;
        color: var(--muted);
    }
    .pagination {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 28px;
        flex-wrap: wrap;
    }
    .pagination a,
    .pagination span {
        padding: 10px 14px;
        border-radius: 10px;
        background: var(--chip);
        color: var(--ink);
        font-size: 0.9rem;
        font-weight: 600;
        border: 1px solid var(--bd);
        transition: all 0.2s ease;
        min-width: 42px;
        text-align: center;
    }
    .pagination a:hover {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
        text-decoration: none;
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(31, 111, 235, 0.2);
    }
    .pagination span.active {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
        box-shadow: 0 2px 8px rgba(31, 111, 235, 0.2);
    }
    select.filter-input,
    select.tiny-select {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        padding-right: 34px;
        background-color: #fff;
        border: 1px solid var(--bd);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7280' d='M6 8 0 0h12z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }
    .order-form-card {
        margin-bottom: 28px;
        background: var(--panel);
        border: 1px solid var(--bd);
        border-radius: 16px;
        padding: 28px;
        display: flex;
        flex-direction: column;
        gap: 24px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
    }
    .order-form-card h3 {
        margin: 0 0 4px 0;
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--ink);
    }
    .order-form-card p {
        margin: 0;
        color: var(--muted);
        font-size: 0.95rem;
        line-height: 1.5;
    }
    .order-form-layout {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(260px, 340px);
        gap: 24px;
        align-items: start;
    }
    .order-form-column {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .order-form-column--side {
        position: sticky;
        top: 88px;
        align-self: flex-start;
    }
    .form-field {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .form-field label {
        font-weight: 600;
        color: var(--ink);
        font-size: 0.9rem;
    }
    .form-field input:focus,
    .form-field select:focus,
    .form-field textarea:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(31, 111, 235, 0.1);
        outline: none;
    }
    .customer-selector {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .btn-tonal {
        border-radius: 10px;
        border: 1px solid var(--bd);
        background: var(--chip);
        color: var(--ink);
        padding: 0.6rem 0.85rem;
        font-weight: 600;
        cursor: pointer;
        min-height: 44px;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border: 1px solid var(--bd);
        border-radius: 10px;
        background: #fff;
        color: var(--ink);
        font-weight: 600;
        cursor: pointer;
        min-height: 44px;
        transition: all 0.2s ease;
        text-decoration: none;
        font-size: 0.95rem;
    }
    .btn:hover:not(:disabled) {
        background: var(--chip);
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
    }
    .btn-primary {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
    }
    .btn-primary:hover:not(:disabled) {
        box-shadow: 0 4px 12px rgba(31, 111, 235, 0.25);
        transform: translateY(-1px);
    }
    .btn[disabled],
    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        box-shadow: none;
        transform: none;
    }
    .btn-compact {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.5rem 0.85rem;
        border: 1px solid var(--bd);
        border-radius: 10px;
        background: #fff;
        color: var(--ink);
        font-weight: 600;
        min-height: 40px;
        cursor: pointer;
        text-decoration: none;
    }
    .btn-compact.btn-primary {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
    }
    .btn-compact[aria-disabled="true"] {
        opacity: 0.6;
        cursor: not-allowed;
        pointer-events: none;
    }
    .btn-tonal:hover {
        background: rgba(31, 111, 235, 0.1);
    }
    .suggestions {
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid var(--bd);
        border-radius: 12px;
        margin-top: 2px;
        box-shadow: 0 18px 30px rgba(15, 23, 42, 0.18);
        max-height: 260px;
        overflow-y: auto;
        z-index: 20;
    }
    .suggestion-item {
        padding: 10px 14px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        gap: 2px;
        color: var(--ink);
    }
    .suggestion-item strong {
        font-size: 0.95rem;
    }
    .suggestion-item span {
        font-size: 0.78rem;
        color: var(--muted);
    }
    .suggestion-item:hover,
    .suggestion-item.active {
        background: rgba(31, 111, 235, 0.08);
    }
    .hidden {
        display: none !important;
    }
    .order-items-container {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .order-items-search {
        position: relative;
    }
    .order-items-table-wrapper {
        border: 1px solid var(--bd);
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }
    .order-items-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
    }
    .order-items-table th,
    .order-items-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid var(--bd);
        vertical-align: middle;
    }
    .order-items-table tbody tr:last-child td {
        border-bottom: none;
    }
    .order-items-table input[type="number"] {
        min-width: 0;
        text-align: right;
    }
    .order-items-empty td {
        text-align: center;
        color: var(--muted);
        font-style: italic;
    }
    .order-item-meta {
        display: block;
        color: var(--muted);
        font-size: 0.75rem;
        margin-top: 4px;
    }
    .helper-checklist {
        border: 1px solid var(--bd);
        border-radius: 12px;
        padding: 12px 16px;
        background: #f9fafb;
        font-size: 0.85rem;
        color: var(--muted);
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .helper-checklist ul {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .helper-checklist li {
        display: flex;
        align-items: center;
        gap: 8px;
        line-height: 1.4;
    }
    .helper-checklist li::before {
        content: '○';
        font-size: 0.85rem;
        color: var(--muted);
    }
    .helper-checklist li.is-complete {
        color: var(--ok, #047857);
    }
    .helper-checklist li.is-complete::before {
        content: '✓';
        color: var(--ok, #047857);
    }
    .helper-title {
        font-weight: 600;
        color: var(--ink);
        font-size: 0.85rem;
    }
    .helper-checklist.is-ready {
        border-color: var(--ok, #047857);
        background: rgba(4, 120, 87, 0.08);
        color: var(--ink);
    }
    .helper-checklist.is-ready .helper-title {
        color: var(--ok, #047857);
    }
    .price-display,
    .line-total {
        font-variant-numeric: tabular-nums;
        text-align: right;
        white-space: nowrap;
    }
    .btn-icon {
        background: none;
        border: none;
        color: var(--muted);
        padding: 6px;
        cursor: pointer;
        border-radius: 6px;
    }
    .btn-icon:hover {
        background: rgba(31, 111, 235, 0.12);
        color: var(--ink);
    }
    .order-summary {
        display: grid;
        gap: 12px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--bd);
        border-radius: 14px;
        padding: 18px;
    }
    .order-summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.95rem;
        color: var(--ink);
    }
    .order-summary-label {
        color: var(--muted);
    }
    .order-summary-value {
        font-weight: 600;
    }
    .helper {
        font-size: 0.78rem;
        color: var(--muted);
    }
    .tooltip {
        position: relative;
        cursor: help;
    }
    .tooltip-text {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 8px);
        transform: translateX(-50%);
        background: var(--ink);
        color: #fff;
        padding: 8px 10px;
        border-radius: 8px;
        font-size: 0.75rem;
        line-height: 1.3;
        max-width: 220px;
        white-space: normal;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.25);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s ease;
        pointer-events: none;
        z-index: 30;
    }
    .tooltip:hover .tooltip-text,
    .tooltip:focus-within .tooltip-text {
        opacity: 1;
        visibility: visible;
    }
    @media (max-width: 1024px) {
        .order-form-layout {
            grid-template-columns: 1fr;
        }
        .order-form-column--side {
            order: -1;
        }
    }
    @media (max-width: 640px) {
        .order-form-card {
            padding: 20px;
        }
        .filters {
            flex-direction: column;
            align-items: stretch;
        }
        .filter-input {
            width: 100%;
        }
        .customer-selector {
            flex-direction: column;
            align-items: stretch;
        }
    }

    /* Missing CSS classes - Status badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1;
        border: 1px solid;
    }
    .status-warning {
        background: rgba(146, 64, 14, 0.1);
        border-color: rgba(146, 64, 14, 0.25);
        color: var(--warn);
    }
    .status-info {
        background: rgba(31, 111, 235, 0.1);
        border-color: rgba(31, 111, 235, 0.25);
        color: #1d4ed8;
    }
    .status-accent {
        background: rgba(124, 58, 237, 0.1);
        border-color: rgba(124, 58, 237, 0.25);
        color: #6d28d9;
    }
    .status-success {
        background: rgba(6, 95, 70, 0.1);
        border-color: rgba(6, 95, 70, 0.25);
        color: var(--ok);
    }
    .status-danger {
        background: rgba(153, 27, 27, 0.1);
        border-color: rgba(153, 27, 27, 0.25);
        color: var(--err);
    }
    .status-default {
        background: var(--chip);
        border-color: var(--bd);
        color: var(--muted);
    }

    /* Action stack for table */
    .action-stack {
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-width: 200px;
    }

    /* Inline forms */
    .inline-form {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Tiny select */
    .tiny-select {
        padding: 6px 28px 6px 10px;
        font-size: 0.85rem;
        min-height: 36px;
        border-radius: 8px;
        flex: 1;
    }

    /* Quick create card */
    .quick-create-card {
        margin-bottom: 32px;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }

    /* Additional responsive improvements */
    @media (max-width: 1024px) {
        .action-stack {
            min-width: 160px;
        }
        .filters {
            padding: 16px;
        }
        .order-form-column--side {
            position: static;
            top: auto;
        }
    }

    @media (max-width: 640px) {
        .metric-card {
            padding: 18px;
        }
        .metric-value {
            font-size: 1.75rem;
        }
        .orders-table th,
        .orders-table td {
            padding: 12px 10px;
            font-size: 0.85rem;
        }
        .action-stack {
            min-width: 140px;
        }
        .inline-form {
            flex-direction: column;
            align-items: stretch;
        }
        .tiny-select {
            width: 100%;
        }
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            font-size: 0.85rem;
        }
    }
</style>

<?php foreach ($flashes as $flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endforeach; ?>

<section class="card">
    <div class="metric-grid">
        <div class="metric-card">
            <span class="metric-label">Total orders</span>
            <span class="metric-value"><?= number_format($totalOrders) ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Awaiting approval</span>
            <span class="metric-value"><?= number_format($awaitingApproval) ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Preparing / ready</span>
            <span class="metric-value"><?= number_format($preparingPipeline) ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">In transit</span>
            <span class="metric-value"><?= number_format($inTransitCount) ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Delivered (7 days)</span>
            <span class="metric-value"><?= number_format($deliveredThisWeek) ?></span>
        </div>
    </div>

    <div class="quick-create-card">
        <div>
            <h3>Quick create order</h3>
            <p>Capture incoming requests without leaving the command center. Detailed line items and adjustments can follow later.</p>
        </div>
        <?php if (!$customers && !$customerUsers): ?>
            <p class="helper">No saved customers yet. Use the new customer toggle below to capture the contact before saving the order.</p>
        <?php endif; ?>
        <form method="post" id="quick-create-form" autocomplete="off"
            data-exchange-rate="<?= htmlspecialchars(number_format((float)($activeExchangeRate['rate'] ?? 0), 6, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
            data-exchange-rate-id="<?= htmlspecialchars($activeExchangeRate['id'] !== null ? (string)(int)$activeExchangeRate['id'] : '', ENT_QUOTES, 'UTF-8') ?>"
            data-customer-search-endpoint="<?= htmlspecialchars($customerSearchEndpointValue, ENT_QUOTES, 'UTF-8') ?>"
            data-product-search-endpoint="<?= htmlspecialchars($productLookupEndpoint, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create_order">
            <input type="hidden" name="customer_id" id="customer_id">
            <input type="hidden" name="customer_mode" id="customer_mode" value="existing">
            <input type="hidden" name="order_items" id="order_items_input" value="[]">
            <input type="hidden" name="exchange_rate_id" id="exchange_rate_id" value="<?= $activeExchangeRate['id'] !== null ? (int)$activeExchangeRate['id'] : '' ?>">
            <?= csrf_field() ?>
            <div class="order-form-layout">
                <section class="order-form-column order-form-column--primary">
                    <div class="form-field" id="existing-customer-fields">
                        <label for="customer_search">Customer</label>
                        <div class="customer-selector">
                            <input type="search" id="customer_search" name="customer_search" placeholder="Start typing to search customers…" autocomplete="off" spellcheck="false" aria-describedby="customer-search-help">
                            <button type="button" id="toggle-new-customer" class="btn btn-tonal" aria-expanded="false" aria-controls="new-customer-fields">
                                + New Customer
                            </button>
                        </div>
                        <div class="suggestions hidden" id="customer-suggestions" role="listbox" aria-label="Customer matches"></div>
                        <p class="helper" id="customer-search-help">Search by customer name or phone number.</p>
                    </div>

                    <div class="form-field" style="width: 200px;">
                        <label for="customer_phone_display" >Customer phone</label>
                        <input type="text" id="customer_phone_display" placeholder="Auto-filled from selection" readonly>
                    </div>



                    <div class="form-field new-customer-field hidden" id="new-customer-name-field">
                        <label for="customer_name">New customer name</label>
                        <input type="text" id="customer_name" name="customer_name" data-new-customer-required placeholder="e.g. Market ABC">
                    </div>

                    <div class="form-field new-customer-field hidden" id="new-customer-phone-field">
                        <label for="customer_phone">New customer phone</label>
                        <input type="tel" id="customer_phone"  name="customer_phone" data-new-customer-required placeholder="+961 70 123 456" aria-describedby="customer-phone-help">
                        <p class="helper" id="customer-phone-help">Phone numbers must be unique.</p>
                    </div>

                    <div class="form-field">
                        <label for="product_search">Order items</label>
                        <div class="order-items-container">
                            <div class="order-items-search">
                                <input type="search" id="product_search" placeholder="Search by product name or SKU…" autocomplete="off" spellcheck="false" aria-describedby="product-search-help">
                                <div class="suggestions hidden" id="product-suggestions" role="listbox" aria-label="Product matches"></div>
                                <p class="helper" id="product-search-help">Press Enter to add the highlighted product.</p>
                            </div>
                            <div class="order-items-table-wrapper" role="region" aria-live="polite">
                                <table class="order-items-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" style="width: 34%;">Product</th>
                                            <th scope="col" style="width: 12%;">SKU / Unit</th>
                                            <th scope="col" style="width: 12%;">Qty</th>
                                            <th scope="col" style="width: 14%;">Unit USD</th>
                                            <th scope="col" style="width: 14%;">Unit LBP</th>
                                            <th scope="col" style="width: 14%;">Line Total</th>
                                        </tr>
                                    </thead>
                                    <tbody id="order-items-body">
                                        <tr class="order-items-empty">
                                            <td colspan="6">Search for a product to add it to the order.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="notes">Order notes</label>
                        <textarea name="notes" id="notes" placeholder="Special instructions or pricing agreements"></textarea>
                    </div>
                </section>

                <aside class="order-form-column order-form-column--side">
                    <div class="form-field">
                        <label for="sales_rep_id">Sales rep</label>
                        <select name="sales_rep_id" id="sales_rep_id" required>
                            <option value="">Select a sales representative…</option>
                            <?php foreach ($salesReps as $rep): ?>
                                <option value="<?= (int)$rep['id'] ?>">
                                    <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="helper">Orders must be assigned before invoicing.</p>
                    </div>

                    <div class="order-summary">
                        <div class="order-summary-row">
                            <span class="order-summary-label">Total USD</span>
                            <span class="order-summary-value" id="summary_total_usd">USD 0.00</span>
                        </div>
                        <div class="order-summary-row">
                            <span class="order-summary-label">Total LBP</span>
                            <span class="order-summary-value" id="summary_total_lbp">LBP 0</span>
                        </div>
                        <p class="helper">
                            <?= $activeExchangeRate['rate'] > 0 ? 'Exchange rate: 1 USD = ' . number_format((float)$activeExchangeRate['rate'], 2) . ' LBP.' : 'Add an exchange rate to enable LBP totals.' ?>
                        </p>
                    </div>

                    <div class="form-field">
                        <label for="initial_status">Initial status</label>
                        <select name="initial_status" id="initial_status">
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === 'on_hold' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="status_note">Status note (optional)</label>
                        <input type="text" name="status_note" id="status_note" placeholder="Short handover note">
                    </div>

                    <div class="form-field">
                        <button type="submit" class="btn btn-primary" id="create-order-btn" disabled>Place order</button>
                        <p class="helper">Order number is assigned automatically.</p>
                        <div class="helper-checklist" id="order-checklist">
                            <span class="helper-title">Before placing the order:</span>
                            <ul>
                                <li id="checklist-customer">Select or add a customer</li>
                                <li id="checklist-rep">Assign a sales rep</li>
                                <li id="checklist-items">Add at least one product</li>
                                <li id="checklist-total">Confirm totals above zero</li>
                            </ul>
                        </div>
                    </div>

                    <input type="hidden" name="total_usd" id="total_usd" value="0.00">
                    <input type="hidden" name="total_lbp" id="total_lbp" value="0">
                </aside>
            </div>
        </form>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.getElementById('quick-create-form');
                if (!form) {
                    return;
                }

                const searchInput = document.getElementById('customer_search');
                const suggestionsBox = document.getElementById('customer-suggestions');
                const customerIdInput = document.getElementById('customer_id');
                const customerModeInput = document.getElementById('customer_mode');
                const phoneDisplay = document.getElementById('customer_phone_display');
                const customerUserSelect = document.getElementById('customer_user_id');
                const toggleButton = document.getElementById('toggle-new-customer');
                const existingBlock = document.getElementById('existing-customer-fields');
                const newCustomerFields = document.querySelectorAll('.new-customer-field');
                const newCustomerInputs = form.querySelectorAll('[data-new-customer-required]');
                const productSearchInput = document.getElementById('product_search');
                const productSuggestionsBox = document.getElementById('product-suggestions');
                const orderItemsInput = document.getElementById('order_items_input');
                const orderItemsBody = document.getElementById('order-items-body');
                const totalUsdField = document.getElementById('total_usd');
                const totalLbpField = document.getElementById('total_lbp');
                const summaryTotalUsd = document.getElementById('summary_total_usd');
                const summaryTotalLbp = document.getElementById('summary_total_lbp');
                const createOrderBtn = document.getElementById('create-order-btn');
                const salesRepSelect = document.getElementById('sales_rep_id');
                const customerNameInput = document.getElementById('customer_name');
                const customerPhoneInput = document.getElementById('customer_phone');
                const checklist = {
                    container: document.getElementById('order-checklist'),
                    customer: document.getElementById('checklist-customer'),
                    rep: document.getElementById('checklist-rep'),
                    items: document.getElementById('checklist-items'),
                    total: document.getElementById('checklist-total'),
                };
                const exchangeRateValue = Number.parseFloat(form.dataset.exchangeRate || '0') || 0;
                const customerSearchEndpoint = form.dataset.customerSearchEndpoint || window.location.href;
                const productSearchEndpoint = form.dataset.productSearchEndpoint || 'ajax_product_lookup.php';

                let suggestions = [];
                let activeIndex = -1;
                let newCustomerMode = false;
                let productSuggestions = [];
                let productActiveIndex = -1;
                let orderItems = [];
                let customerSearchTimer = null;
                let productSearchTimer = null;

                function clearSuggestions() {
                    suggestions = [];
                    suggestionsBox.innerHTML = '';
                    suggestionsBox.classList.add('hidden');
                    activeIndex = -1;
                }

                function setActiveSuggestion(index) {
                    const items = suggestionsBox.querySelectorAll('.suggestion-item');
                    items.forEach((item, idx) => {
                        item.classList.toggle('active', idx === index);
                    });
                    activeIndex = index;
                }

                function renderSuggestions(items) {
                    suggestionsBox.innerHTML = '';
                    if (!items.length) {
                        clearSuggestions();
                        return;
                    }

                    suggestions = items;
                    items.forEach((item, index) => {
                        const option = document.createElement('div');
                        option.className = 'suggestion-item';
                        option.dataset.index = String(index);

                        const title = document.createElement('strong');
                        title.textContent = item.name;
                        option.appendChild(title);

                        if (item.phone) {
                            const phoneSpan = document.createElement('span');
                            phoneSpan.textContent = item.phone;
                            option.appendChild(phoneSpan);
                        }

                        if (item.user_name) {
                            const userSpan = document.createElement('span');
                            userSpan.textContent = 'Linked user: ' + item.user_name;
                            option.appendChild(userSpan);
                        }

                        option.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            selectSuggestion(item);
                        });

                        suggestionsBox.appendChild(option);
                    });

                    suggestionsBox.classList.remove('hidden');
                    activeIndex = -1;
                }

                function clearProductSuggestions() {
                    if (!productSuggestionsBox) {
                        return;
                    }
                    productSuggestions = [];
                    productSuggestionsBox.innerHTML = '';
                    productSuggestionsBox.classList.add('hidden');
                    productActiveIndex = -1;
                }

                function setActiveProductSuggestion(index) {
                    if (!productSuggestionsBox) {
                        return;
                    }
                    const items = productSuggestionsBox.querySelectorAll('.suggestion-item');
                    items.forEach((item, idx) => {
                        item.classList.toggle('active', idx === index);
                    });
                    productActiveIndex = index;
                }

                function renderProductSuggestions(items) {
                    if (!productSuggestionsBox) {
                        return;
                    }
                    productSuggestionsBox.innerHTML = '';
                    if (!items.length) {
                        clearProductSuggestions();
                        return;
                    }

                    productSuggestions = items;
                    items.forEach((item, index) => {
                        const option = document.createElement('div');
                        option.className = 'suggestion-item';
                        option.dataset.index = String(index);

                        const title = document.createElement('strong');
                        title.textContent = item.name;
                        option.appendChild(title);

                        if (item.sku) {
                            const skuSpan = document.createElement('span');
                            skuSpan.textContent = 'SKU: ' + item.sku;
                            option.appendChild(skuSpan);
                        }

                        const defaultPrice = defaultUnitPriceUsd(item);
                        if (defaultPrice > 0) {
                            const priceSpan = document.createElement('span');
                            priceSpan.textContent = 'USD ' + defaultPrice.toFixed(2);
                            option.appendChild(priceSpan);
                        }

                        option.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                            selectProductSuggestion(item);
                        });

                        productSuggestionsBox.appendChild(option);
                    });

                    productSuggestionsBox.classList.remove('hidden');
                    productActiveIndex = -1;
                }

                function defaultUnitPriceUsd(product) {
                    const wholesale = parseFloat(product.wholesale_price_usd);
                    if (Number.isFinite(wholesale) && wholesale > 0) {
                        return wholesale;
                    }
                    const sale = parseFloat(product.sale_price_usd);
                    if (Number.isFinite(sale) && sale > 0) {
                        return sale;
                    }
                    return 0;
                }

                function formatLineTotalDisplay(totals) {
                    const parts = [];
                    if (totals.usd > 0) {
                        parts.push('USD ' + totals.usd.toFixed(2));
                    }
                    if (totals.lbp > 0) {
                        parts.push('LBP ' + Math.round(totals.lbp).toLocaleString());
                    }
                    return parts.length ? parts.join(' · ') : '—';
                }

                function calculateLineTotals(item) {
                    const quantity = Number(item.quantity) || 0;
                    const priceUsd = Number(item.unit_price_usd) || 0;
                    const priceLbp = exchangeRateValue > 0
                        ? priceUsd * exchangeRateValue
                        : (item.unit_price_lbp === null ? 0 : Number(item.unit_price_lbp) || 0);
                    return {
                        usd: quantity * priceUsd,
                        lbp: exchangeRateValue > 0 ? Math.round(quantity * priceUsd * exchangeRateValue) : quantity * priceLbp,
                    };
                }

                function applyExchangeRateToItem(item) {
                    if (exchangeRateValue > 0) {
                        const priceUsd = Number(item.unit_price_usd) || 0;
                        item.unit_price_lbp = priceUsd > 0 ? Math.round(priceUsd * exchangeRateValue) : 0;
                    }
                }

                function formatLbpUnit(value) {
                    return value > 0 ? 'LBP ' + Math.round(value).toLocaleString() : 'LBP 0';
                }

                function hasValidCustomerSelection() {
                    if (customerModeInput.value === 'new') {
                        const name = customerNameInput ? customerNameInput.value.trim() : '';
                        const phone = customerPhoneInput ? customerPhoneInput.value.trim() : '';
                        return name.length > 1 && phone.length >= 6;
                    }
                    return customerIdInput.value !== '';
                }

                function itemsAreValid() {
                    if (!orderItems.length) {
                        return false;
                    }
                    return orderItems.every(item => {
                        const qty = Number(item.quantity) || 0;
                        const priceUsd = Number(item.unit_price_usd) || 0;
                        return qty > 0 && priceUsd > 0;
                    });
                }

                function validateForm() {
                    const hasCustomer = hasValidCustomerSelection();
                    const repSelected = salesRepSelect && salesRepSelect.value !== '';
                    const itemsValid = itemsAreValid();
                    const totalsValid = Number(totalUsdField.value) > 0;
                    const enable = hasCustomer && repSelected && itemsValid && totalsValid;
                    if (createOrderBtn) {
                        createOrderBtn.disabled = !enable;
                    }
                    if (checklist.customer) {
                        checklist.customer.classList.toggle('is-complete', hasCustomer);
                    }
                    if (checklist.rep) {
                        checklist.rep.classList.toggle('is-complete', repSelected);
                    }
                    if (checklist.items) {
                        checklist.items.classList.toggle('is-complete', itemsValid);
                    }
                    if (checklist.total) {
                        checklist.total.classList.toggle('is-complete', totalsValid);
                    }
                    if (checklist.container) {
                        checklist.container.classList.toggle('is-ready', enable);
                    }
                }

                function syncOrderItemsInput() {
                    if (!orderItemsInput) {
                        return;
                    }
                    const payload = orderItems.map(item => ({
                        product_id: item.product_id,
                        quantity: Number(item.quantity) || 0,
                        unit_price_usd: Number(item.unit_price_usd) || 0,
                        unit_price_lbp: exchangeRateValue > 0
                            ? Math.round((Number(item.unit_price_usd) || 0) * exchangeRateValue)
                            : (item.unit_price_lbp === null ? 0 : Number(item.unit_price_lbp) || 0),
                    }));
                    orderItemsInput.value = JSON.stringify(payload);
                }

                function updateTotals() {
                    let sumUsd = 0;
                    let sumLbp = 0;

                    orderItems.forEach(item => {
                        applyExchangeRateToItem(item);
                        const totals = calculateLineTotals(item);
                        sumUsd += totals.usd;
                        sumLbp += totals.lbp;
                    });

                    if (exchangeRateValue > 0) {
                        sumLbp = sumUsd * exchangeRateValue;
                    }

                    if (totalUsdField) {
                        totalUsdField.value = sumUsd > 0 ? sumUsd.toFixed(2) : '0.00';
                    }
                    if (totalLbpField) {
                        const roundedLbp = sumLbp > 0 ? Math.round(sumLbp) : 0;
                        totalLbpField.value = String(roundedLbp);
                        if (summaryTotalLbp) {
                            summaryTotalLbp.textContent = 'LBP ' + (roundedLbp > 0 ? roundedLbp.toLocaleString() : '0');
                        }
                    }
                    if (summaryTotalUsd) {
                        summaryTotalUsd.textContent = 'USD ' + (sumUsd > 0 ? sumUsd.toFixed(2) : '0.00');
                    }

                    syncOrderItemsInput();
                    validateForm();
                }

                function renderOrderItems() {
                    if (!orderItemsBody) {
                        return;
                    }

                    orderItemsBody.innerHTML = '';

                    if (!orderItems.length) {
                        const emptyRow = document.createElement('tr');
                        emptyRow.className = 'order-items-empty';
                        const emptyCell = document.createElement('td');
                        emptyCell.colSpan = 6;
                        emptyCell.textContent = 'Search for a product to add it to the order.';
                        emptyRow.appendChild(emptyCell);
                        orderItemsBody.appendChild(emptyRow);
                        updateTotals();
                        return;
                    }

                    orderItems.forEach((item, index) => {
                        const row = document.createElement('tr');
                        row.dataset.index = String(index);

                        const productCell = document.createElement('td');
                        const productTitle = document.createElement('strong');
                        productTitle.textContent = item.name;
                        productCell.appendChild(productTitle);
                        if (item.unit) {
                            const unitMeta = document.createElement('span');
                            unitMeta.className = 'order-item-meta';
                            unitMeta.textContent = item.unit;
                            productCell.appendChild(unitMeta);
                        }
                        row.appendChild(productCell);

                        const skuCell = document.createElement('td');
                        skuCell.textContent = item.sku || '—';
                        row.appendChild(skuCell);

                        const qtyCell = document.createElement('td');
                        const qtyInput = document.createElement('input');
                        qtyInput.type = 'number';
                        qtyInput.min = '0.001';
                        qtyInput.step = '0.001';
                        qtyInput.value = item.quantity;
                        qtyInput.addEventListener('input', function (event) {
                            const value = parseFloat(event.target.value);
                            orderItems[index].quantity = Number.isFinite(value) && value > 0 ? value : 0;
                            const totals = calculateLineTotals(orderItems[index]);
                            lineTotalValue.textContent = formatLineTotalDisplay(totals);
                            updateTotals();
                        });
                        qtyInput.addEventListener('blur', function (event) {
                            let value = parseFloat(event.target.value);
                            if (!Number.isFinite(value) || value <= 0) {
                                value = 1;
                            }
                            orderItems[index].quantity = value;
                            event.target.value = value;
                            const totals = calculateLineTotals(orderItems[index]);
                            lineTotalValue.textContent = formatLineTotalDisplay(totals);
                            updateTotals();
                        });
                        qtyCell.appendChild(qtyInput);
                        row.appendChild(qtyCell);

                        let lbpValue;
                        const usdCell = document.createElement('td');
                        const usdInput = document.createElement('input');
                        usdInput.type = 'number';
                        usdInput.min = '0';
                        usdInput.step = '0.01';
                        usdInput.value = Number(item.unit_price_usd || 0).toFixed(2);
                        usdInput.addEventListener('input', function (event) {
                            const value = parseFloat(event.target.value);
                            orderItems[index].unit_price_usd = Number.isFinite(value) && value >= 0 ? value : 0;
                            applyExchangeRateToItem(orderItems[index]);
                            if (lbpValue) {
                                if (exchangeRateValue > 0 && !(lbpValue instanceof HTMLInputElement)) {
                                    lbpValue.textContent = formatLbpUnit(Number(orderItems[index].unit_price_lbp || 0));
                                } else if (exchangeRateValue <= 0 && lbpValue instanceof HTMLInputElement) {
                                    lbpValue.value = Number(orderItems[index].unit_price_lbp || 0).toFixed(2);
                                }
                            }
                            const totals = calculateLineTotals(orderItems[index]);
                            lineTotalValue.textContent = formatLineTotalDisplay(totals);
                            updateTotals();
                        });
                        usdInput.addEventListener('blur', function (event) {
                            let value = parseFloat(event.target.value);
                            if (!Number.isFinite(value) || value < 0) {
                                value = 0;
                            }
                            orderItems[index].unit_price_usd = value;
                            event.target.value = value.toFixed(2);
                            applyExchangeRateToItem(orderItems[index]);
                            if (lbpValue) {
                                if (exchangeRateValue > 0 && !(lbpValue instanceof HTMLInputElement)) {
                                    lbpValue.textContent = formatLbpUnit(Number(orderItems[index].unit_price_lbp || 0));
                                } else if (exchangeRateValue <= 0 && lbpValue instanceof HTMLInputElement) {
                                    lbpValue.value = Number(orderItems[index].unit_price_lbp || 0).toFixed(2);
                                }
                            }
                            const totals = calculateLineTotals(orderItems[index]);
                            lineTotalValue.textContent = formatLineTotalDisplay(totals);
                            updateTotals();
                        });
                        usdCell.appendChild(usdInput);
                        row.appendChild(usdCell);

                        const lbpCell = document.createElement('td');
                        let lbpInput;
                        if (exchangeRateValue > 0) {
                            lbpValue = document.createElement('div');
                            lbpValue.className = 'price-display';
                            applyExchangeRateToItem(orderItems[index]);
                            const initialLbp = Number(orderItems[index].unit_price_lbp || 0);
                            lbpValue.textContent = formatLbpUnit(initialLbp);
                            lbpCell.appendChild(lbpValue);
                        } else {
                            lbpInput = document.createElement('input');
                            lbpInput.type = 'number';
                            lbpInput.min = '0';
                            lbpInput.step = '0.01';
                            if (item.unit_price_lbp !== null && item.unit_price_lbp !== undefined && item.unit_price_lbp !== '') {
                                lbpInput.value = Number(item.unit_price_lbp).toFixed(2);
                            }
                            lbpInput.addEventListener('input', function (event) {
                                const raw = event.target.value;
                                if (raw === '') {
                                    orderItems[index].unit_price_lbp = null;
                                } else {
                                    const value = parseFloat(raw);
                                    orderItems[index].unit_price_lbp = Number.isFinite(value) && value >= 0 ? value : 0;
                                }
                                const totals = calculateLineTotals(orderItems[index]);
                                lineTotalValue.textContent = formatLineTotalDisplay(totals);
                                updateTotals();
                            });
                            lbpInput.addEventListener('blur', function (event) {
                                if (event.target.value === '') {
                                    return;
                                }
                                let value = parseFloat(event.target.value);
                                if (!Number.isFinite(value) || value < 0) {
                                    value = 0;
                                }
                                orderItems[index].unit_price_lbp = value;
                                event.target.value = value.toFixed(2);
                                const totals = calculateLineTotals(orderItems[index]);
                                lineTotalValue.textContent = formatLineTotalDisplay(totals);
                                updateTotals();
                            });
                            lbpCell.appendChild(lbpInput);
                            lbpValue = lbpInput;
                        }
                        row.appendChild(lbpCell);

                        const totalCell = document.createElement('td');
                        const lineTotalValue = document.createElement('div');
                        lineTotalValue.className = 'line-total';
                        lineTotalValue.textContent = formatLineTotalDisplay(calculateLineTotals(item));
                        totalCell.appendChild(lineTotalValue);

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.className = 'btn-icon';
                        removeButton.setAttribute('aria-label', 'Remove item');
                        removeButton.innerHTML = '&times;';
                        removeButton.addEventListener('click', function () {
                            orderItems = orderItems.filter((_, itemIndex) => itemIndex !== index);
                            renderOrderItems();
                        });
                        totalCell.appendChild(removeButton);

                        row.appendChild(totalCell);

                        orderItemsBody.appendChild(row);
                    });

                    updateTotals();
                }

                function selectProductSuggestion(item) {
                    if (!item) {
                        return;
                    }
                    const existingIndex = orderItems.findIndex(existing => String(existing.product_id) === String(item.id));
                    if (existingIndex >= 0) {
                        orderItems[existingIndex].quantity = Number(orderItems[existingIndex].quantity) + 1;
                        applyExchangeRateToItem(orderItems[existingIndex]);
                    } else {
                        orderItems.push({
                            product_id: item.id,
                            name: item.name,
                            sku: item.sku || '',
                            unit: item.unit || '',
                            quantity: 1,
                            unit_price_usd: defaultUnitPriceUsd(item),
                            unit_price_lbp: null,
                        });
                        applyExchangeRateToItem(orderItems[orderItems.length - 1]);
                    }
                    renderOrderItems();
                    clearProductSuggestions();
                    if (productSearchInput) {
                        productSearchInput.value = '';
                        productSearchInput.focus();
                    }
                }

                function fetchProductSuggestions(term) {
                    if (!productSearchInput) {
                        return;
                    }
                    if (term.length < 2) {
                        clearProductSuggestions();
                        return;
                    }
                    const url = new URL(productSearchEndpoint, window.location.href);
                    url.searchParams.set('term', term);

                    fetch(url.toString(), {
                        credentials: 'same-origin'
                    })
                        .then(response => response.ok ? response.json() : Promise.reject())
                        .then(data => {
                            if (!data || !Array.isArray(data.results)) {
                                clearProductSuggestions();
                                return;
                            }
                            renderProductSuggestions(data.results);
                        })
                        .catch(() => clearProductSuggestions());
                }

                function selectSuggestion(item) {
                    setNewCustomerMode(false);
                    customerIdInput.value = item.id;
                    searchInput.value = item.name;
                    if (phoneDisplay) {
                        phoneDisplay.value = item.phone || '';
                    }
                    if (customerUserSelect && item.user_id) {
                        const optionExists = Array.from(customerUserSelect.options).some(opt => String(opt.value) === String(item.user_id));
                        if (optionExists) {
                            customerUserSelect.value = String(item.user_id);
                        }
                    }
                    clearSuggestions();
                    validateForm();
                }

                function fetchSuggestions(term) {
                    if (term.length < 2) {
                        clearSuggestions();
                        return;
                    }
                    const url = new URL(customerSearchEndpoint, window.location.href);
                    url.searchParams.set('ajax', 'customer_search');
                    url.searchParams.set('term', term);

                    fetch(url.toString(), {
                        credentials: 'same-origin'
                    })
                        .then(response => response.ok ? response.json() : Promise.reject())
                        .then(data => {
                            if (!Array.isArray(data.results)) {
                                clearSuggestions();
                                return;
                            }
                            renderSuggestions(data.results);
                        })
                        .catch(() => clearSuggestions());
                }

                function setNewCustomerMode(enable) {
                    newCustomerMode = enable;
                    customerModeInput.value = enable ? 'new' : 'existing';
                    if (toggleButton) {
                        toggleButton.textContent = enable ? 'Use Existing' : '+ New Customer';
                        toggleButton.setAttribute('aria-expanded', enable ? 'true' : 'false');
                    }
                    if (enable) {
                        if (existingBlock) {
                            existingBlock.classList.add('hidden');
                        }
                        newCustomerFields.forEach(field => field.classList.remove('hidden'));
                        newCustomerInputs.forEach(input => input.setAttribute('required', 'required'));
                        customerIdInput.value = '';
                        searchInput.value = '';
                        if (phoneDisplay) {
                            phoneDisplay.value = '';
                        }
                        if (customerUserSelect) {
                            customerUserSelect.value = '';
                        }
                        clearSuggestions();
                    } else {
                        if (existingBlock) {
                            existingBlock.classList.remove('hidden');
                        }
                        newCustomerFields.forEach(field => field.classList.add('hidden'));
                        newCustomerInputs.forEach(input => {
                            input.removeAttribute('required');
                            input.value = '';
                        });
                    }
                    validateForm();
                }

                if (toggleButton) {
                    toggleButton.addEventListener('click', function () {
                        setNewCustomerMode(!newCustomerMode);
                        if (!newCustomerMode) {
                            searchInput.focus();
                        }
                    });
                }

                if (searchInput) {
                    searchInput.addEventListener('input', function (event) {
                        const value = event.target.value.trim();
                        customerIdInput.value = '';
                        if (phoneDisplay) {
                            phoneDisplay.value = '';
                        }
                        if (customerSearchTimer) {
                            clearTimeout(customerSearchTimer);
                        }
                        customerSearchTimer = setTimeout(function () {
                            fetchSuggestions(value);
                        }, 220);
                        validateForm();
                    });

                    searchInput.addEventListener('keydown', function (event) {
                        if (suggestionsBox.classList.contains('hidden')) {
                            return;
                        }
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            const nextIndex = activeIndex + 1 >= suggestions.length ? 0 : activeIndex + 1;
                            setActiveSuggestion(nextIndex);
                        } else if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            const prevIndex = activeIndex - 1 < 0 ? suggestions.length - 1 : activeIndex - 1;
                            setActiveSuggestion(prevIndex);
                        } else if (event.key === 'Enter') {
                            if (activeIndex >= 0 && suggestions[activeIndex]) {
                                event.preventDefault();
                                selectSuggestion(suggestions[activeIndex]);
                            }
                        } else if (event.key === 'Escape') {
                            clearSuggestions();
                        }
                    });

                    searchInput.addEventListener('focus', function () {
                        if (suggestions.length) {
                            suggestionsBox.classList.remove('hidden');
                        }
                    });
                }

                if (productSearchInput) {
                    productSearchInput.addEventListener('input', function (event) {
                        const value = event.target.value.trim();
                        if (productSearchTimer) {
                            clearTimeout(productSearchTimer);
                        }
                        productSearchTimer = setTimeout(function () {
                            fetchProductSuggestions(value);
                        }, 220);
                    });

                    productSearchInput.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter') {
                            if (productSuggestions.length) {
                                event.preventDefault();
                                const selected = productActiveIndex >= 0 ? productSuggestions[productActiveIndex] : productSuggestions[0];
                                selectProductSuggestion(selected);
                            }
                            return;
                        }

                        if (!productSuggestionsBox || productSuggestionsBox.classList.contains('hidden')) {
                            return;
                        }

                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            const nextIndex = productActiveIndex + 1 >= productSuggestions.length ? 0 : productActiveIndex + 1;
                            setActiveProductSuggestion(nextIndex);
                        } else if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            const prevIndex = productActiveIndex - 1 < 0 ? productSuggestions.length - 1 : productActiveIndex - 1;
                            setActiveProductSuggestion(prevIndex);
                        } else if (event.key === 'Escape') {
                            clearProductSuggestions();
                        }
                    });

                    productSearchInput.addEventListener('focus', function () {
                        if (productSuggestions.length) {
                            productSuggestionsBox.classList.remove('hidden');
                        }
                    });
                }

                document.addEventListener('click', function (event) {
                    if (suggestionsBox && !suggestionsBox.contains(event.target) && event.target !== searchInput) {
                        clearSuggestions();
                    }
                    if (productSuggestionsBox && !productSuggestionsBox.contains(event.target) && event.target !== productSearchInput) {
                        clearProductSuggestions();
                    }
                });

                if (salesRepSelect) {
                    salesRepSelect.addEventListener('change', validateForm);
                }
                if (customerNameInput) {
                    customerNameInput.addEventListener('input', validateForm);
                }
                if (customerPhoneInput) {
                    customerPhoneInput.addEventListener('input', validateForm);
                }

                setNewCustomerMode(false);
                renderOrderItems();
                validateForm();
            });
        </script>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="path" value="admin/orders">
        <input type="text" name="search" placeholder="Search order #, customer, phone" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="filter-input" style="flex: 1; min-width: 220px;">
        <select name="status" class="filter-input">
            <option value="">All statuses</option>
            <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
            <option value="none" <?= $statusFilter === 'none' ? 'selected' : '' ?>>No status logged</option>
        </select>
        <select name="rep" class="filter-input">
            <option value="">All sales reps</option>
            <?php foreach ($salesReps as $rep): ?>
                <option value="<?= (int)$rep['id'] ?>" <?= (string)$repFilter === (string)$rep['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="filter-input">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="filter-input">
        <button type="submit" class="btn">Filter</button>
        <a href="?path=admin/orders" class="btn">Clear</a>
    </form>

    <div class="orders-table">
        <table>
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Sales rep</th>
                    <th>Status</th>
                    <th>Financials</th>
                    <th>Delivery</th>
                    <th style="width: 220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            No orders match the current filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $latestStatus = $order['latest_status'] ?? null;
                        $statusClass = $statusBadgeClasses[$latestStatus] ?? 'status-default';
                        $statusLabel = $statusLabels[$latestStatus] ?? 'No status logged';

                        $invoiceStatus = $order['latest_invoice_status'] ?? null;
                        $invoiceLabel = $invoiceStatusLabels[$invoiceStatus] ?? null;

                        $invoiceTotalUsd = $order['invoice_total_usd'] !== null ? (float)$order['invoice_total_usd'] : 0.0;
                        $invoiceTotalLbp = $order['invoice_total_lbp'] !== null ? (float)$order['invoice_total_lbp'] : 0.0;
                        $paidUsd = $order['paid_usd'] !== null ? (float)$order['paid_usd'] : 0.0;
                        $paidLbp = $order['paid_lbp'] !== null ? (float)$order['paid_lbp'] : 0.0;
                        $balanceUsd = max(0, $invoiceTotalUsd - $paidUsd);
                        $balanceLbp = max(0, $invoiceTotalLbp - $paidLbp);

                        $deliveryStatus = $order['delivery_status'] ?? null;
                        $invoiceCount = (int)($order['invoice_count'] ?? 0);
                        $latestInvoiceId = isset($order['latest_invoice_id']) ? (int)$order['latest_invoice_id'] : 0;
                        $latestInvoiceNumber = $order['latest_invoice_number'] ?? null;
                        $invoiceReadiness = $orderReadiness[(int)$order['id']] ?? ['ready' => false, 'reasons' => []];
                        $isInvoiceReady = !empty($invoiceReadiness['ready']);
                        $invoiceReasons = $invoiceReadiness['reasons'] ?? [];
                        $readinessTooltip = '';
                        if (!$isInvoiceReady && $invoiceReasons) {
                            $readinessTooltip = implode('<br>', array_map(static function (string $reason): string {
                                return htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
                            }, $invoiceReasons));
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($order['order_number'] ?: 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <small>Created <?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                <?php if (!empty($order['notes'])): ?>
                                    <small>Note: <?= htmlspecialchars($order['notes'], ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($order['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($order['customer_phone'])): ?>
                                    <small><?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($order['sales_rep_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if (!empty($order['status_changed_at'])): ?>
                                    <small>Updated <?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['status_changed_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                                <?php if ($invoiceLabel): ?>
                                    <small>Invoice: <?= htmlspecialchars($invoiceLabel, ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>
                                    <?php if ($isInvoiceReady): ?>
                                        <span class="chip badge-success" aria-label="Invoice ready">Invoice ready</span>
                                    <?php elseif ($readinessTooltip !== ''): ?>
                                        <span class="chip tooltip">Pending invoice
                                            <span class="tooltip-text"><?= $readinessTooltip ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="chip">Pending invoice</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="chip">USD <?= number_format($invoiceTotalUsd, 2) ?></span>
                                    <small>Paid <?= number_format($paidUsd, 2) ?> · Balance <?= number_format($balanceUsd, 2) ?></small>
                                </div>
                                <div>
                                    <span class="chip">LBP <?= number_format($invoiceTotalLbp, 0) ?></span>
                                    <small>Paid <?= number_format($paidLbp, 0) ?> · Balance <?= number_format($balanceLbp, 0) ?></small>
                                </div>
                                <?php if ($invoiceCount > 0 && $latestInvoiceNumber): ?>
                                    <small>Latest invoice <?= htmlspecialchars($latestInvoiceNumber, ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                                <?php if ($invoiceCount > 0): ?>
                                    <small><?= $invoiceCount ?> invoice<?= $invoiceCount === 1 ? ' ' : 's' ?>
                                    <?php if (!empty($order['last_invoice_created_at'])): ?> · last <?= htmlspecialchars(date('Y-m-d', strtotime($order['last_invoice_created_at'])), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></small>
                                <?php endif; ?>
                                <?php if (!empty($order['last_payment_at'])): ?>
                                    <small>Last payment <?= htmlspecialchars(date('Y-m-d', strtotime($order['last_payment_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($deliveryStatus): ?>
                                    <span class="chip"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $deliveryStatus)), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($order['delivery_scheduled_at'])): ?>
                                        <small>Scheduled <?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['delivery_scheduled_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($order['delivery_expected_at'])): ?>
                                        <small>Expected <?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['delivery_expected_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <small>No delivery record</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-stack">
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                        <select name="new_status" class="tiny-select">
                                            <?php foreach ($statusLabels as $value => $label): ?>
                                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $latestStatus ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-compact">Update</button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reassign_sales_rep">
                                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                        <select name="sales_rep_id" class="tiny-select">
                                            <option value="">Unassigned</option>
                                            <?php foreach ($salesReps as $rep): ?>
                                                <option value="<?= (int)$rep['id'] ?>" <?= (string)$order['sales_rep_id'] === (string)$rep['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn-compact">Assign</button>
                                    </form>
                                    <?php if ($invoiceCount > 0 && $latestInvoiceId > 0): ?>
                                        <a class="btn-compact btn-primary" href="invoices.php?path=admin/invoices&amp;order_id=<?= (int)$order['id'] ?>">View invoice</a>
                                    <?php elseif ($isInvoiceReady): ?>
                                        <a class="btn-compact btn-primary" href="invoices.php?path=admin/invoices&amp;order_id=<?= (int)$order['id'] ?>">Issue invoice</a>
                                    <?php else: ?>
                                        <span class="btn-compact tooltip" aria-disabled="true">Issue invoice
                                            <?php if ($readinessTooltip !== ''): ?>
                                                <span class="tooltip-text"><?= $readinessTooltip ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?path=admin/orders&page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $repFilter !== '' ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">First</a>
                <a href="?path=admin/orders&page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $repFilter !== '' ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">Prev</a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <?php if ($i === $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?path=admin/orders&page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $repFilter !== '' ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?path=admin/orders&page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $repFilter !== '' ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">Next</a>
                <a href="?path=admin/orders&page=<?= $totalPages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $repFilter !== '' ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">Last</a>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

<?php
admin_render_layout_end();
