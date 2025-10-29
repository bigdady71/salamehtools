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

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$repFilter = $_GET['rep'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$validStatusFilters = array_merge(array_keys($statusLabels), ['none', '']);
if (!in_array($statusFilter, $validStatusFilters, true)) {
    $statusFilter = '';
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

if (($_GET['ajax'] ?? '') === 'product_search') {
    $term = trim((string)($_GET['term'] ?? ''));
    header('Content-Type: application/json');

    if ($term === '') {
        echo json_encode(['results' => []]);
        exit;
    }

    $likeTerm = '%' . $term . '%';
    $searchStmt = $pdo->prepare("
        SELECT
            p.id,
            p.item_name,
            p.sku,
            p.sale_price_usd,
            p.wholesale_price_usd,
            p.unit
        FROM products p
        WHERE p.is_active = 1
          AND (p.item_name LIKE :term OR p.sku LIKE :term OR p.code_clean LIKE :term)
        ORDER BY p.item_name ASC
        LIMIT 15
    ");
    $searchStmt->execute([':term' => $likeTerm]);

    $results = [];
    foreach ($searchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'id' => (int)$row['id'],
            'name' => $row['item_name'],
            'sku' => $row['sku'],
            'unit' => $row['unit'],
            'sale_price_usd' => $row['sale_price_usd'] !== null ? (float)$row['sale_price_usd'] : null,
            'wholesale_price_usd' => $row['wholesale_price_usd'] !== null ? (float)$row['wholesale_price_usd'] : null,
        ];
    }

    echo json_encode(['results' => $results]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
        } else {
            // Ignore accidental population of new customer fields when using an existing customer.
            $customerNameInput = '';
            $customerPhoneInput = '';
        }

        if ($salesRepId !== null) {
            $repCheck = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = :id AND role = 'sales_rep'");
            $repCheck->execute([':id' => $salesRepId]);
            if ((int)$repCheck->fetchColumn() === 0) {
                flash('error', 'Sales rep not found.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }
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
            SELECT id, item_name, sale_price_usd, wholesale_price_usd
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
                $fallback = $productsMap[$item['product_id']]['sale_price_usd'] ?? $productsMap[$item['product_id']]['wholesale_price_usd'] ?? null;
                if ($fallback !== null) {
                    $item['unit_price_usd'] = (float)$fallback;
                }
            }

            if ($item['unit_price_usd'] <= 0 && ($item['unit_price_lbp'] === null || $item['unit_price_lbp'] <= 0)) {
                flash('error', 'Each order item must have a price in USD or LBP.');
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            }

            $lineUsd = $item['quantity'] * $item['unit_price_usd'];
            $lineLbp = $item['unit_price_lbp'] !== null ? $item['quantity'] * $item['unit_price_lbp'] : 0.0;

            $totalUsd += $lineUsd;
            $totalLbp += $lineLbp;
        }
        unset($item);

        $totalUsd = round($totalUsd, 2);
        $totalLbp = round($totalLbp, 2);

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
                INSERT INTO orders (order_number, customer_id, sales_rep_id, total_usd, total_lbp, notes, created_at, updated_at)
                VALUES (NULL, :customer_id, :sales_rep_id, :total_usd, :total_lbp, :notes, NOW(), NOW())
            ");
            $insertOrder->execute([
                ':customer_id' => $customerId,
                ':sales_rep_id' => $salesRepId,
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

            foreach ($sanitizedItems as $item) {
                $itemStmt->execute([
                    ':order_id' => $orderId,
                    ':product_id' => $item['product_id'],
                    ':quantity' => $item['quantity'],
                    ':unit_price_usd' => round($item['unit_price_usd'], 2),
                    ':unit_price_lbp' => $item['unit_price_lbp'] !== null ? round($item['unit_price_lbp'], 2) : null,
                ]);
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

            $pdo->commit();
            flash('success', 'Order created successfully.');
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

$ordersSql = "
    SELECT
        o.id,
        o.order_number,
        o.total_usd,
        o.total_lbp,
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
    }
    .flash-success {
        background: rgba(110, 231, 183, 0.2);
        color: #6ee7b7;
        border: 1px solid rgba(110, 231, 183, 0.3);
    }
    .flash-error {
        background: rgba(255, 92, 122, 0.2);
        color: #ff5c7a;
        border: 1px solid rgba(255, 92, 122, 0.3);
    }
    .flash-info {
        background: rgba(74, 125, 255, 0.18);
        color: #8ea8ff;
        border: 1px solid rgba(74, 125, 255, 0.3);
    }
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }
    .metric-card {
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 18px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .metric-label {
        color: var(--muted);
        font-size: 0.85rem;
    }
    .metric-value {
        font-size: 1.6rem;
        font-weight: 700;
    }
    .filters {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 20px;
        align-items: center;
    }
    .filter-input {
        padding: 8px 12px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: rgba(255, 255, 255, 0.04);
        color: var(--text);
        min-width: 160px;
    }
    .filter-input::placeholder {
        color: var(--muted);
    }
    .orders-table {
        overflow-x: auto;
        border-radius: 16px;
        border: 1px solid var(--border);
        background: var(--bg-panel);
    }
    .orders-table table {
        width: 100%;
        border-collapse: collapse;
    }
    .orders-table thead {
        background: rgba(255, 255, 255, 0.03);
    }
    .orders-table th,
    .orders-table td {
        padding: 14px 12px;
        border-bottom: 1px solid var(--border);
        text-align: left;
        font-size: 0.92rem;
        vertical-align: top;
    }
    .orders-table tbody tr:hover {
        background: rgba(74, 125, 255, 0.06);
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .status-default {
        background: rgba(255, 255, 255, 0.06);
        color: var(--muted);
    }
    .status-warning {
        background: rgba(255, 193, 7, 0.18);
        color: #ffd54f;
    }
    .status-info {
        background: rgba(74, 125, 255, 0.18);
        color: #8ea8ff;
    }
    .status-accent {
        background: rgba(0, 255, 136, 0.18);
        color: #38f3a1;
    }
    .status-success {
        background: rgba(110, 231, 183, 0.2);
        color: #6ee7b7;
    }
    .status-danger {
        background: rgba(255, 92, 122, 0.18);
        color: #ff5c7a;
    }
    .chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.8rem;
        background: rgba(255, 255, 255, 0.06);
    }
    .inline-form {
        display: flex;
        gap: 6px;
        align-items: center;
        flex-wrap: wrap;
    }
    .tiny-select {
        padding: 6px 8px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: rgba(255, 255, 255, 0.05);
        color: var(--text);
        font-size: 0.85rem;
    }
    .btn-compact {
        padding: 6px 10px;
        font-size: 0.8rem;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid transparent;
        color: inherit;
        cursor: pointer;
    }
    .btn-compact:hover {
        background: rgba(255, 255, 255, 0.18);
    }
    .action-stack {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .orders-table small {
        color: var(--muted);
        display: block;
        margin-top: 4px;
        line-height: 1.4;
    }
    .empty-state {
        text-align: center;
        padding: 48px 0;
        color: var(--muted);
    }
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 24px;
        flex-wrap: wrap;
    }
    .pagination a,
    .pagination span {
        padding: 8px 12px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.06);
        color: var(--text);
        font-size: 0.85rem;
        border: 1px solid transparent;
    }
    .pagination span.active {
        background: var(--accent);
    }
    select.filter-input,
    select.tiny-select {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        padding-right: 34px;
        background-color: var(--bg-panel-alt);
        border: 1px solid rgba(255, 255, 255, 0.12);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23aab6ee' d='M6 8 0 0h12z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }
    select.filter-input:focus,
    select.tiny-select:focus {
        outline: none;
        border-color: rgba(74, 125, 255, 0.6);
        box-shadow: 0 0 0 2px rgba(74, 125, 255, 0.15);
    }
    select.filter-input option,
    select.tiny-select option {
        background-color: #1b2042;
        color: #f4f6ff;
    }
    .quick-create-card {
        margin-bottom: 24px;
        background: var(--bg-panel-alt);
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .quick-create-card h3 {
        margin: 0;
        font-size: 1.1rem;
    }
    .quick-create-card p {
        margin: 0;
        color: var(--muted);
        font-size: 0.9rem;
    }
    .quick-create-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 14px;
    }
    .quick-create-grid--balanced {
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    }
    .quick-create-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 0.8rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        position: relative;
    }
    .quick-create-card input,
    .quick-create-card select,
    .quick-create-card textarea {
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: rgba(255, 255, 255, 0.05);
        color: var(--text);
        font-size: 0.95rem;
        resize: vertical;
        min-height: 44px;
    }
    .quick-create-card textarea {
        min-height: 80px;
    }
    .quick-create-card input:focus,
    .quick-create-card select:focus,
    .quick-create-card textarea:focus {
        outline: none;
        border-color: rgba(74, 125, 255, 0.6);
        box-shadow: 0 0 0 2px rgba(74, 125, 255, 0.15);
    }
    .quick-create-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }
    .quick-create-card .helper {
        font-size: 0.75rem;
        color: var(--muted);
    }
    .span-2 {
        grid-column: span 2;
    }
    @media (max-width: 640px) {
        .span-2 {
            grid-column: span 1;
        }
    }
    .customer-selector {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .btn-tonal {
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.12);
        color: var(--text);
        border-radius: 8px;
    }
    .btn-tonal:hover {
        background: rgba(255, 255, 255, 0.16);
    }
    .btn-small {
        padding: 6px 12px;
        font-size: 0.75rem;
        line-height: 1;
        font-weight: 600;
        cursor: pointer;
    }
    .suggestions {
        position: absolute;
        top: calc(100% - 4px);
        left: 0;
        right: 0;
        background: var(--bg-panel);
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-top: 6px;
        box-shadow: 0 14px 30px rgba(0, 0, 0, 0.35);
        max-height: 220px;
        overflow-y: auto;
        z-index: 20;
    }
    .suggestion-item {
        padding: 10px 14px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .suggestion-item strong {
        font-size: 0.9rem;
        color: var(--text);
    }
    .suggestion-item span {
        font-size: 0.74rem;
        color: var(--muted);
    }
    .suggestion-item:hover,
    .suggestion-item.active {
        background: rgba(74, 125, 255, 0.18);
    }
    .hidden {
        display: none !important;
    }
    .new-customer-field input {
        background: rgba(0, 255, 136, 0.05);
        border-color: rgba(0, 255, 136, 0.2);
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
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        overflow: hidden;
    }
    .order-items-table {
        width: 100%;
        border-collapse: collapse;
        background: rgba(255, 255, 255, 0.03);
    }
    .order-items-table th,
    .order-items-table td {
        padding: 10px 12px;
        text-align: left;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        vertical-align: middle;
    }
    .order-items-table th:last-child,
    .order-items-table td:last-child {
        text-align: right;
    }
    .order-items-table tbody tr:last-child td {
        border-bottom: none;
    }
    .order-items-table input[type="number"] {
        width: 100%;
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
    .btn-icon {
        background: none;
        border: none;
        color: inherit;
        padding: 6px;
        cursor: pointer;
        border-radius: 6px;
    }
    .btn-icon:hover {
        background: rgba(255, 255, 255, 0.12);
    }
    .line-total {
        font-variant-numeric: tabular-nums;
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
        <form method="post" id="quick-create-form" autocomplete="off">
            <input type="hidden" name="action" value="create_order">
            <input type="hidden" name="customer_id" id="customer_id">
            <input type="hidden" name="customer_mode" id="customer_mode" value="existing">
            <input type="hidden" name="order_items" id="order_items_input" value="[]">
            <div class="quick-create-grid quick-create-grid--balanced">
                <label class="quick-create-field span-2" id="existing-customer-fields">
                    Customer
                    <div class="customer-selector">
                        <input type="text" id="customer_search" name="customer_search" placeholder="Start typing to search customers…" autocomplete="off" spellcheck="false">
                        <button type="button" id="toggle-new-customer" class="btn btn-tonal btn-small" aria-expanded="false" aria-controls="new-customer-fields">
                            + New Customer
                        </button>
                    </div>
                    <div class="suggestions hidden" id="customer-suggestions"></div>
                    <span class="helper">Search by customer name or phone number.</span>
                </label>
                <label class="quick-create-field">
                    Customer phone
                    <input type="text" id="customer_phone_display" placeholder="Auto-filled from selection" readonly>
                </label>
                <label class="quick-create-field">
                    Customer user (optional)
                    <select id="customer_user_id" name="customer_user_id">
                        <option value="">Link existing portal account…</option>
                        <?php foreach ($customerUsers as $customerUser): ?>
                            <option value="<?= (int)$customerUser['id'] ?>">
                                <?= htmlspecialchars($customerUser['name'] ?: $customerUser['email'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($customerUser['phone'])): ?>
                                    (<?= htmlspecialchars($customerUser['phone'], ENT_QUOTES, 'UTF-8') ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="helper">Auto-link to an existing customer login if applicable.</span>
                </label>
                <label class="quick-create-field new-customer-field hidden" id="new-customer-name-field">
                    New customer name
                    <input type="text" id="customer_name" name="customer_name" data-new-customer-required placeholder="e.g. Market ABC">
                </label>
                <label class="quick-create-field new-customer-field hidden" id="new-customer-phone-field">
                    New customer phone
                    <input type="text" id="customer_phone" name="customer_phone" data-new-customer-required placeholder="+961 70 123 456">
                </label>
                <label class="quick-create-field">
                    Sales rep
                    <select name="sales_rep_id" id="sales_rep_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($salesReps as $rep): ?>
                            <option value="<?= (int)$rep['id'] ?>">
                                <?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="quick-create-field span-2">
                    Order items
                    <div class="order-items-container">
                        <div class="order-items-search">
                            <input type="text" id="product_search" placeholder="Search by product name or SKU…" autocomplete="off" spellcheck="false">
                            <div class="suggestions hidden" id="product-suggestions"></div>
                            <span class="helper">Press enter to add the highlighted product.</span>
                        </div>
                        <div class="order-items-table-wrapper">
                            <table class="order-items-table">
                                <thead>
                                    <tr>
                                        <th style="width: 34%;">Product</th>
                                        <th style="width: 12%;">SKU / Unit</th>
                                        <th style="width: 12%;">Qty</th>
                                        <th style="width: 14%;">Unit USD</th>
                                        <th style="width: 14%;">Unit LBP</th>
                                        <th style="width: 14%;">Line Total</th>
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
                <label class="quick-create-field">
                    Total USD
                    <input type="number" name="total_usd" id="total_usd" step="0.01" min="0" value="0.00" readonly>
                </label>
                <label class="quick-create-field">
                    Total LBP
                    <input type="number" name="total_lbp" id="total_lbp" step="0.01" min="0" value="0.00" readonly>
                </label>
                <label class="quick-create-field">
                    Initial status
                    <select name="initial_status">
                        <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $value === 'on_hold' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="quick-create-field span-2">
                    Status note (optional)
                    <input type="text" name="status_note" placeholder="Short handover note">
                </label>
                <label class="quick-create-field span-2">
                    Order notes
                    <textarea name="notes" placeholder="Special instructions or pricing agreements"></textarea>
                </label>
            </div>
            <div class="quick-create-actions">
                <button type="submit" class="btn btn-primary">Create order</button>
                <span class="helper">Order number is assigned automatically.</span>
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

                let suggestions = [];
                let activeIndex = -1;
                let newCustomerMode = false;
                let productSuggestions = [];
                let productActiveIndex = -1;
                let orderItems = [];

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
                    if (typeof product.sale_price_usd === 'number') {
                        return product.sale_price_usd;
                    }
                    if (typeof product.sale_price_usd === 'string' && product.sale_price_usd !== '') {
                        const parsed = parseFloat(product.sale_price_usd);
                        if (!Number.isNaN(parsed)) {
                            return parsed;
                        }
                    }
                    if (typeof product.wholesale_price_usd === 'number') {
                        return product.wholesale_price_usd;
                    }
                    if (typeof product.wholesale_price_usd === 'string' && product.wholesale_price_usd !== '') {
                        const parsed = parseFloat(product.wholesale_price_usd);
                        if (!Number.isNaN(parsed)) {
                            return parsed;
                        }
                    }
                    return 0;
                }

                function formatLineTotalDisplay(totals) {
                    const parts = [];
                    if (totals.usd > 0) {
                        parts.push('USD ' + totals.usd.toFixed(2));
                    }
                    if (totals.lbp > 0) {
                        parts.push('LBP ' + totals.lbp.toFixed(2));
                    }
                    return parts.length ? parts.join(' · ') : '—';
                }

                function calculateLineTotals(item) {
                    const quantity = Number(item.quantity) || 0;
                    const priceUsd = Number(item.unit_price_usd) || 0;
                    const priceLbp = item.unit_price_lbp === null ? 0 : Number(item.unit_price_lbp) || 0;
                    return {
                        usd: quantity * priceUsd,
                        lbp: quantity * priceLbp,
                    };
                }

                function syncOrderItemsInput() {
                    if (!orderItemsInput) {
                        return;
                    }
                    const payload = orderItems.map(item => ({
                        product_id: item.product_id,
                        quantity: Number(item.quantity) || 0,
                        unit_price_usd: Number(item.unit_price_usd) || 0,
                        unit_price_lbp: item.unit_price_lbp === null ? '' : Number(item.unit_price_lbp) || 0,
                    }));
                    orderItemsInput.value = JSON.stringify(payload);
                }

                function updateTotals() {
                    let sumUsd = 0;
                    let sumLbp = 0;

                    orderItems.forEach(item => {
                        const totals = calculateLineTotals(item);
                        sumUsd += totals.usd;
                        sumLbp += totals.lbp;
                    });

                    if (totalUsdField) {
                        totalUsdField.value = sumUsd > 0 ? sumUsd.toFixed(2) : '0.00';
                    }
                    if (totalLbpField) {
                        totalLbpField.value = sumLbp > 0 ? sumLbp.toFixed(2) : '0.00';
                    }

                    syncOrderItemsInput();
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

                        const usdCell = document.createElement('td');
                        const usdInput = document.createElement('input');
                        usdInput.type = 'number';
                        usdInput.min = '0';
                        usdInput.step = '0.01';
                        usdInput.value = Number(item.unit_price_usd || 0).toFixed(2);
                        usdInput.addEventListener('input', function (event) {
                            const value = parseFloat(event.target.value);
                            orderItems[index].unit_price_usd = Number.isFinite(value) && value >= 0 ? value : 0;
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
                            const totals = calculateLineTotals(orderItems[index]);
                            lineTotalValue.textContent = formatLineTotalDisplay(totals);
                            updateTotals();
                        });
                        usdCell.appendChild(usdInput);
                        row.appendChild(usdCell);

                        const lbpCell = document.createElement('td');
                        const lbpInput = document.createElement('input');
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
                    const url = new URL(window.location.href);
                    url.searchParams.set('ajax', 'product_search');
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
                }

                function fetchSuggestions(term) {
                    if (term.length < 2) {
                        clearSuggestions();
                        return;
                    }
                    const url = new URL(window.location.href);
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
                        fetchSuggestions(value);
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
                        fetchProductSuggestions(value);
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

                setNewCustomerMode(false);
                renderOrderItems();
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
                                    <span class="chip">USD <?= number_format($invoiceTotalUsd, 2) ?></span>
                                    <small>Paid <?= number_format($paidUsd, 2) ?> · Balance <?= number_format($balanceUsd, 2) ?></small>
                                </div>
                                <div>
                                    <span class="chip">LBP <?= number_format($invoiceTotalLbp, 0) ?></span>
                                    <small>Paid <?= number_format($paidLbp, 0) ?> · Balance <?= number_format($balanceLbp, 0) ?></small>
                                </div>
                                <?php if ($invoiceCount > 0): ?>
                                    <small><?= $invoiceCount ?> invoice<?= $invoiceCount === 1 ? '' : 's' ?><?php if (!empty($order['last_invoice_created_at'])): ?> · last <?= htmlspecialchars(date('Y-m-d', strtotime($order['last_invoice_created_at'])), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></small>
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
    <?php endif; ?>
</section>

<?php
admin_render_layout_end();
