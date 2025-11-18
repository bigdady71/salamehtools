<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Get customer detail (AJAX endpoint)
if ($action === 'get_customer_detail') {
    header('Content-Type: application/json');
    $customerId = (int)($_GET['id'] ?? 0);

    if ($customerId <= 0) {
        echo json_encode(['error' => 'Invalid customer ID']);
        exit;
    }

    // Verify customer belongs to this sales rep
    $customerStmt = $pdo->prepare("
        SELECT id, name, phone, location, shop_type, customer_tier, created_at
        FROM customers
        WHERE id = :id AND assigned_sales_rep_id = :rep_id
    ");
    $customerStmt->execute([':id' => $customerId, ':rep_id' => $repId]);
    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        echo json_encode(['error' => 'Customer not found or access denied']);
        exit;
    }

    // Get customer stats
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(o.total_usd), 0) as total_revenue,
            MAX(o.created_at) as last_order_date,
            SUM(CASE WHEN i.status != 'paid' THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as outstanding
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd
            FROM payments
            GROUP BY invoice_id
        ) p ON p.invoice_id = i.id
        WHERE o.customer_id = :customer_id
    ");
    $statsStmt->execute([':customer_id' => $customerId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Get recent orders (last 10)
    $ordersStmt = $pdo->prepare("
        SELECT
            o.id,
            o.order_number,
            o.status,
            o.total_usd,
            o.created_at,
            (i.total_usd - COALESCE(p.paid_usd, 0)) as outstanding
        FROM orders o
        LEFT JOIN invoices i ON i.order_id = o.id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd
            FROM payments
            GROUP BY invoice_id
        ) p ON p.invoice_id = i.id
        WHERE o.customer_id = :customer_id
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    $ordersStmt->execute([':customer_id' => $customerId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'customer' => $customer,
        'stats' => $stats,
        'orders' => $orders
    ]);
    exit;
}

// Export to CSV
if ($action === 'export') {
    $searchFilter = trim((string)($_GET['search'] ?? ''));
    $statusFilter = (string)($_GET['status'] ?? 'all');
    $cityFilter = trim((string)($_GET['city'] ?? ''));

    $whereClauses = ["c.assigned_sales_rep_id = :rep_id"];
    $params = [':rep_id' => $repId];

    if ($searchFilter !== '') {
        $whereClauses[] = "(c.name LIKE :search OR c.phone LIKE :search OR c.location LIKE :search)";
        $params[':search'] = '%' . $searchFilter . '%';
    }

    if ($statusFilter === 'active') {
        $whereClauses[] = "c.is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $whereClauses[] = "c.is_active = 0";
    }

    if ($cityFilter !== '') {
        $whereClauses[] = "c.location = :city";
        $params[':city'] = $cityFilter;
    }

    $whereSQL = implode(' AND ', $whereClauses);

    $exportQuery = "
        SELECT
            c.id,
            c.name,
            c.phone,
            c.location,
            c.shop_type,
            COUNT(DISTINCT o.id) as order_count,
            COALESCE(SUM(o.total_usd), 0) as total_revenue_usd,
            CASE WHEN c.is_active = 1 THEN 'Active' ELSE 'Inactive' END as status,
            c.created_at
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id
        WHERE {$whereSQL}
        GROUP BY c.id
        ORDER BY c.name ASC
    ";

    $exportStmt = $pdo->prepare($exportQuery);
    $exportStmt->execute($params);
    $customers = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=my_customers_' . date('Y-m-d_His') . '.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($output, ['ID', 'Customer Name', 'Phone', 'Location', 'Shop Type', 'Orders', 'Revenue (USD)', 'Status', 'Created Date']);

    foreach ($customers as $customer) {
        fputcsv($output, [
            $customer['id'],
            $customer['name'],
            $customer['phone'] ?? '',
            $customer['location'] ?? '',
            $customer['shop_type'] ?? '',
            $customer['order_count'],
            number_format((float)$customer['total_revenue_usd'], 2),
            $customer['status'],
            $customer['created_at'] ? date('Y-m-d H:i:s', strtotime($customer['created_at'])) : ''
        ]);
    }

    fclose($output);
    exit;
}

// API endpoint for fetching customer data for edit modal
if ($action === 'get_customer' && isset($_GET['id'])) {
    $customerId = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT id, name, phone, location, shop_type
        FROM customers
        WHERE id = :id AND assigned_sales_rep_id = :rep_id
    ");
    $stmt->execute([':id' => $customerId, ':rep_id' => $repId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($customer ?: []);
    exit;
}

// Handle customer creation (auto-assigned to current sales rep)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_customer') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $shopType = trim((string)($_POST['shop_type'] ?? ''));

        $errors = [];

        if ($name === '') {
            $errors[] = 'Customer name is required.';
        }

        // Check for duplicate phone within this rep's customers only (prevent enumeration)
        if ($phone !== '') {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE phone = :phone AND assigned_sales_rep_id = :rep_id");
            $checkStmt->execute([':phone' => $phone, ':rep_id' => $repId]);
            if ((int)$checkStmt->fetchColumn() > 0) {
                $errors[] = 'You already have a customer with this phone number.';
            }
        }

        if ($errors) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => 'Unable to create customer. Please fix the errors below:',
                'list' => $errors,
                'dismissible' => true,
            ];
        } else {
            try {
                $pdo->beginTransaction();

                $insertStmt = $pdo->prepare("
                    INSERT INTO customers (name, phone, location, shop_type, assigned_sales_rep_id, is_active, created_at)
                    VALUES (:name, :phone, :location, :shop_type, :rep_id, 1, NOW())
                ");

                $insertStmt->execute([
                    ':name' => $name,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':location' => $location !== '' ? $location : null,
                    ':shop_type' => $shopType !== '' ? $shopType : null,
                    ':rep_id' => $repId, // Auto-assign to current sales rep
                ]);

                $pdo->commit();

                $flashes[] = [
                    'type' => 'success',
                    'title' => 'Customer Created',
                    'message' => "Customer \"{$name}\" has been successfully created and assigned to you.",
                    'dismissible' => true,
                ];
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to create customer: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to create customer. Please try again.',
                    'dismissible' => true,
                ];
            }
        }
    }
}

// Handle customer update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_customer') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $shopType = trim((string)($_POST['shop_type'] ?? ''));

        // Verify customer belongs to sales rep
        $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id");
        $checkStmt->execute([':id' => $customerId, ':rep_id' => $repId]);

        if (!$checkStmt->fetch()) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Access Denied',
                'message' => 'You can only update customers assigned to you.',
                'dismissible' => true,
            ];
        } else {
            $errors = [];

            if ($name === '') {
                $errors[] = 'Customer name is required.';
            }

            // Check for duplicate phone within this rep's customers only (excluding current customer, prevent enumeration)
            if ($phone !== '') {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE phone = :phone AND id != :id AND assigned_sales_rep_id = :rep_id");
                $checkStmt->execute([':phone' => $phone, ':id' => $customerId, ':rep_id' => $repId]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $errors[] = 'You already have another customer with this phone number.';
                }
            }

            if ($errors) {
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Validation Failed',
                    'message' => 'Unable to update customer. Please fix the errors below:',
                    'list' => $errors,
                    'dismissible' => true,
                ];
            } else {
                try {
                    $updateStmt = $pdo->prepare("
                        UPDATE customers
                        SET name = :name,
                            phone = :phone,
                            location = :location,
                            shop_type = :shop_type,
                            updated_at = NOW()
                        WHERE id = :id AND assigned_sales_rep_id = :rep_id
                    ");

                    $updateStmt->execute([
                        ':name' => $name,
                        ':phone' => $phone !== '' ? $phone : null,
                        ':location' => $location !== '' ? $location : null,
                        ':shop_type' => $shopType !== '' ? $shopType : null,
                        ':id' => $customerId,
                        ':rep_id' => $repId,
                    ]);

                    $flashes[] = [
                        'type' => 'success',
                        'title' => 'Customer Updated',
                        'message' => "Customer \"{$name}\" has been successfully updated.",
                        'dismissible' => true,
                    ];
                } catch (Exception $e) {
                    error_log("Failed to update customer: " . $e->getMessage());
                    $flashes[] = [
                        'type' => 'error',
                        'title' => 'Database Error',
                        'message' => 'Unable to update customer. Please try again.',
                        'dismissible' => true,
                    ];
                }
            }
        }
    }
}

// Handle toggle active status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle_status') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
            'dismissible' => true,
        ];
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);

        // Verify customer belongs to sales rep
        $checkStmt = $pdo->prepare("SELECT is_active FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id");
        $checkStmt->execute([':id' => $customerId, ':rep_id' => $repId]);
        $current = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Access Denied',
                'message' => 'You can only modify customers assigned to you.',
                'dismissible' => true,
            ];
        } else {
            $newStatus = $current['is_active'] ? 0 : 1;
            $updateStmt = $pdo->prepare("UPDATE customers SET is_active = :status WHERE id = :id");
            $updateStmt->execute([':status' => $newStatus, ':id' => $customerId]);

            $statusLabel = $newStatus ? 'activated' : 'deactivated';
            $flashes[] = [
                'type' => 'success',
                'title' => 'Status Updated',
                'message' => "Customer has been {$statusLabel}.",
                'dismissible' => true,
            ];
        }
    }
}

// Get filter parameters
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$cityFilter = trim((string)($_GET['city'] ?? ''));

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$where = ['c.assigned_sales_rep_id = :rep_id'];
$params = [':rep_id' => $repId];

if ($search !== '') {
    $where[] = '(c.name LIKE :search OR c.phone LIKE :search OR c.location LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter === 'active') {
    $where[] = 'c.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'c.is_active = 0';
}

if ($cityFilter !== '') {
    $where[] = 'c.location LIKE :city';
    $params[':city'] = '%' . $cityFilter . '%';
}

$whereClause = implode(' AND ', $where);

// Statistics
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN c.is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN c.is_active = 0 THEN 1 ELSE 0 END) as inactive
    FROM customers c
    WHERE c.assigned_sales_rep_id = :rep_id
");
$statsStmt->execute([':rep_id' => $repId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get total count for pagination
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereClause}");
$countStmt->execute($params);
$totalCustomers = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalCustomers / $perPage);

// Fetch customers with order statistics and segmentation
$customersStmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.phone,
        c.location,
        c.shop_type,
        c.customer_tier,
        c.tags,
        c.notes,
        c.is_active,
        c.created_at,
        COUNT(DISTINCT o.id) as order_count,
        COALESCE(SUM(o.total_usd), 0) as total_revenue_usd,
        COALESCE(c.account_balance_lbp, 0) as account_balance_lbp,
        MAX(o.created_at) as last_order_date,
        DATEDIFF(NOW(), MAX(o.created_at)) as days_since_last_order,
        SUM(CASE WHEN i.status != 'paid' THEN (i.total_usd - COALESCE(p.paid_usd, 0)) ELSE 0 END) as outstanding_usd
    FROM customers c
    LEFT JOIN orders o ON o.customer_id = c.id
    LEFT JOIN invoices i ON i.order_id = o.id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd
        FROM payments
        GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE {$whereClause}
    GROUP BY c.id, c.name, c.phone, c.location, c.shop_type, c.customer_tier, c.tags, c.notes, c.is_active, c.account_balance_lbp, c.created_at
    ORDER BY c.created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $customersStmt->bindValue($key, $value);
}
$customersStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$customersStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$customersStmt->execute();
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique locations for filter dropdown
$citiesStmt = $pdo->prepare("
    SELECT DISTINCT location
    FROM customers
    WHERE assigned_sales_rep_id = :rep_id AND location IS NOT NULL AND location != ''
    ORDER BY location
");
$citiesStmt->execute([':rep_id' => $repId]);
$cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'My Customers',
    'heading' => 'My Customers',
    'subtitle' => 'Manage customers assigned to you',
    'active' => 'users',
    'user' => $user,
    'extra_head' => '<style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .stat-label {
            font-size: 0.85rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent);
        }
        .filter-bar {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 22px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 180px;
        }
        .filter-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
        }
        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            background: var(--bg);
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        .btn-filter,
        .btn-create {
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-filter {
            background: var(--accent);
            color: #fff;
        }
        .btn-filter:hover {
            opacity: 0.9;
        }
        .btn-create {
            background: #10b981;
            color: #fff;
        }
        .btn-create:hover {
            opacity: 0.9;
        }
        .btn-clear {
            background: #6b7280;
            color: #fff;
        }
        .customers-table {
            background: var(--bg-panel);
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .customers-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .customers-table th {
            background: var(--bg-panel-alt);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }
        .customers-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
        }
        .customers-table tr:last-child td {
            border-bottom: none;
        }
        .customers-table tr:hover {
            background: var(--bg-panel-alt);
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-active {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .customer-actions {
            display: flex;
            gap: 8px;
        }
        .btn-sm {
            padding: 6px 10px;
            font-size: 0.85rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            cursor: pointer;
        }
        .btn-sm:hover {
            background: var(--bg);
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }
        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            text-decoration: none;
            background: var(--bg-panel);
            color: var(--text);
            font-weight: 600;
        }
        .pagination a:hover {
            background: var(--accent);
            color: #fff;
        }
        .pagination .current {
            background: var(--accent);
            color: #fff;
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
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: var(--bg-panel);
            border-radius: 16px;
            padding: 32px;
            max-width: 600px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: var(--muted);
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
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
        .form-group input,
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
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .btn-submit {
            background: var(--accent);
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-submit:hover {
            opacity: 0.9;
        }
        .btn-cancel {
            background: #6b7280;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-cancel:hover {
            opacity: 0.9;
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

// Statistics cards
echo '<div class="stats-grid">';
echo '<div class="stat-card">';
echo '<div class="stat-label">Total Customers</div>';
echo '<div class="stat-value">', number_format((int)$stats['total']), '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-label">Active</div>';
echo '<div class="stat-value">', number_format((int)$stats['active']), '</div>';
echo '</div>';
echo '<div class="stat-card">';
echo '<div class="stat-label">Inactive</div>';
echo '<div class="stat-value">', number_format((int)$stats['inactive']), '</div>';
echo '</div>';
echo '</div>';

// Filter bar
echo '<div class="filter-bar">';
echo '<div class="filter-group">';
echo '<label>Search</label>';
echo '<input type="text" id="filter-search" placeholder="Name, email, or phone..." value="', htmlspecialchars($search, ENT_QUOTES, 'UTF-8'), '">';
echo '</div>';
echo '<div class="filter-group">';
echo '<label>Status</label>';
echo '<select id="filter-status">';
echo '<option value="">All</option>';
echo '<option value="active"', $statusFilter === 'active' ? ' selected' : '', '>Active</option>';
echo '<option value="inactive"', $statusFilter === 'inactive' ? ' selected' : '', '>Inactive</option>';
echo '</select>';
echo '</div>';
echo '<div class="filter-group">';
echo '<label>City</label>';
echo '<select id="filter-city">';
echo '<option value="">All Cities</option>';
foreach ($cities as $city) {
    $selected = $cityFilter === $city ? ' selected' : '';
    echo '<option value="', htmlspecialchars($city, ENT_QUOTES, 'UTF-8'), '"', $selected, '>', htmlspecialchars($city, ENT_QUOTES, 'UTF-8'), '</option>';
}
echo '</select>';
echo '</div>';
echo '<div class="filter-actions">';
echo '<button class="btn-filter" onclick="applyFilters()">Apply Filters</button>';
if ($search !== '' || $statusFilter !== '' || $cityFilter !== '') {
    echo '<button class="btn-clear btn-filter" onclick="clearFilters()">Clear</button>';
}
// Export button with current filters
$exportUrl = '?action=export';
if ($search !== '') {
    $exportUrl .= '&search=' . urlencode($search);
}
if ($statusFilter !== '') {
    $exportUrl .= '&status=' . urlencode($statusFilter);
}
if ($cityFilter !== '') {
    $exportUrl .= '&city=' . urlencode($cityFilter);
}
echo '<a href="', htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8'), '" class="btn-filter" style="text-decoration: none; display: inline-block;">📊 Export CSV</a>';
echo '<button class="btn-create" onclick="openCreateModal()">+ Create Customer</button>';
echo '</div>';
echo '</div>';

// Customers table
echo '<div class="customers-table">';

if (!$customers) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">👥</div>';
    echo '<h3>No customers found</h3>';
    echo '<p>Create your first customer to get started.</p>';
    echo '</div>';
} else {
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Customer</th>';
    echo '<th>Tier</th>';
    echo '<th>Phone / Shop Type</th>';
    echo '<th>Location</th>';
    echo '<th>Orders</th>';
    echo '<th>Last Order</th>';
    echo '<th>Revenue</th>';
    echo '<th>Outstanding</th>';
    echo '<th>Status</th>';
    echo '<th>Actions</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($customers as $customer) {
        $id = (int)$customer['id'];
        $name = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
        $phone = $customer['phone'] ? htmlspecialchars($customer['phone'], ENT_QUOTES, 'UTF-8') : '-';
        $location = $customer['location'] ? htmlspecialchars($customer['location'], ENT_QUOTES, 'UTF-8') : '-';
        $shopType = $customer['shop_type'] ? htmlspecialchars($customer['shop_type'], ENT_QUOTES, 'UTF-8') : '-';
        $tier = $customer['customer_tier'] ?? 'medium';
        $isActive = (bool)$customer['is_active'];
        $orderCount = (int)$customer['order_count'];
        $revenue = (float)$customer['total_revenue_usd'];
        $balance = (float)$customer['account_balance_lbp'];
        $outstanding = (float)($customer['outstanding_usd'] ?? 0);
        $daysSinceLastOrder = $customer['days_since_last_order'];
        $lastOrderDate = $customer['last_order_date'];

        // Determine segment
        $segment = 'active';
        if ($orderCount === 0) {
            $segment = 'new';
        } elseif ($daysSinceLastOrder !== null && $daysSinceLastOrder > 90) {
            $segment = 'dormant';
        } elseif ($outstanding > 500) {
            $segment = 'at-risk';
        }

        // Tier badges
        $tierColors = [
            'vip' => 'background: rgba(168, 85, 247, 0.15); color: #7c3aed;',
            'high' => 'background: rgba(34, 197, 94, 0.15); color: #15803d;',
            'medium' => 'background: rgba(59, 130, 246, 0.15); color: #1d4ed8;',
            'low' => 'background: rgba(156, 163, 175, 0.15); color: #4b5563;'
        ];

        echo '<tr>';
        echo '<td><strong>', $name, '</strong>';
        // Segment badge
        if ($segment === 'new') {
            echo '<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;font-size:0.75rem;background:rgba(59,130,246,0.15);color:#1d4ed8;">NEW</span>';
        } elseif ($segment === 'dormant') {
            echo '<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;font-size:0.75rem;background:rgba(239,68,68,0.15);color:#991b1b;">DORMANT</span>';
        } elseif ($segment === 'at-risk') {
            echo '<span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;font-size:0.75rem;background:rgba(251,191,36,0.15);color:#b45309;">AT RISK</span>';
        }
        echo '</td>';
        echo '<td><span style="display:inline-block;padding:4px 8px;border-radius:6px;font-size:0.85rem;font-weight:600;', $tierColors[$tier], '">', strtoupper($tier), '</span></td>';
        echo '<td>';
        echo '<div>', $phone, '</div>';
        echo '<div style="font-size:0.85rem;color:var(--muted);">', $shopType, '</div>';
        echo '</td>';
        echo '<td>', $location, '</td>';
        echo '<td>', number_format($orderCount), '</td>';
        echo '<td>';
        if ($lastOrderDate) {
            $daysAgo = (int)$daysSinceLastOrder;
            if ($daysAgo === 0) {
                echo '<span style="color:#059669;font-weight:600;">Today</span>';
            } elseif ($daysAgo === 1) {
                echo '<span>Yesterday</span>';
            } elseif ($daysAgo <= 7) {
                echo '<span style="color:#059669;">', $daysAgo, ' days ago</span>';
            } elseif ($daysAgo <= 30) {
                echo '<span style="color:#ca8a04;">', $daysAgo, ' days ago</span>';
            } else {
                echo '<span style="color:#dc2626;">', $daysAgo, ' days ago</span>';
            }
        } else {
            echo '<span style="color:#9ca3af;">Never</span>';
        }
        echo '</td>';
        echo '<td>$', number_format($revenue, 2), '</td>';
        echo '<td>';
        if ($outstanding > 0.01) {
            echo '<span style="color:#dc2626;font-weight:600;">$', number_format($outstanding, 2), '</span>';
        } else {
            echo '<span style="color:#6b7280;">$0.00</span>';
        }
        echo '</td>';
        echo '<td>';
        if ($isActive) {
            echo '<span class="badge badge-active">Active</span>';
        } else {
            echo '<span class="badge badge-inactive">Inactive</span>';
        }
        echo '</td>';
        echo '<td><div class="customer-actions">';
        echo '<button class="btn-sm" onclick="openCustomerDetail(', $id, ')" style="background:#0ea5e9;color:white;border-color:#0ea5e9;">View</button>';
        echo '<button class="btn-sm" onclick="openEditModal(', $id, ')">Edit</button>';
        echo '</div></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

echo '</div>';

// Pagination
if ($totalPages > 1) {
    echo '<div class="pagination">';
    if ($page > 1) {
        $prevUrl = '?page=' . ($page - 1);
        if ($search) {
            $prevUrl .= '&search=' . urlencode($search);
        }
        if ($statusFilter) {
            $prevUrl .= '&status=' . urlencode($statusFilter);
        }
        if ($cityFilter) {
            $prevUrl .= '&city=' . urlencode($cityFilter);
        }
        echo '<a href="', htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8'), '">Previous</a>';
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    for ($i = $start; $i <= $end; $i++) {
        $pageUrl = '?page=' . $i;
        if ($search) {
            $pageUrl .= '&search=' . urlencode($search);
        }
        if ($statusFilter) {
            $pageUrl .= '&status=' . urlencode($statusFilter);
        }
        if ($cityFilter) {
            $pageUrl .= '&city=' . urlencode($cityFilter);
        }

        if ($i === $page) {
            echo '<span class="current">', $i, '</span>';
        } else {
            echo '<a href="', htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'), '">', $i, '</a>';
        }
    }

    if ($page < $totalPages) {
        $nextUrl = '?page=' . ($page + 1);
        if ($search) {
            $nextUrl .= '&search=' . urlencode($search);
        }
        if ($statusFilter) {
            $nextUrl .= '&status=' . urlencode($statusFilter);
        }
        if ($cityFilter) {
            $nextUrl .= '&city=' . urlencode($cityFilter);
        }
        echo '<a href="', htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'), '">Next</a>';
    }
    echo '</div>';
}

// Create Customer Modal
echo '<div id="createModal" class="modal">';
echo '<div class="modal-content">';
echo '<div class="modal-header">';
echo '<h2>Create New Customer</h2>';
echo '<button class="modal-close" onclick="closeCreateModal()">&times;</button>';
echo '</div>';
echo '<form method="POST">';
echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
echo '<input type="hidden" name="action" value="create_customer">';
echo '<div class="form-group">';
echo '<label>Customer Name <span style="color:red;">*</span></label>';
echo '<input type="text" name="name" required>';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Email</label>';
echo '<input type="email" name="email">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Phone</label>';
echo '<input type="text" name="phone">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Address</label>';
echo '<input type="text" name="address">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>City</label>';
echo '<input type="text" name="city">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Country</label>';
echo '<input type="text" name="country">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Notes</label>';
echo '<textarea name="notes"></textarea>';
echo '</div>';
echo '<div class="form-actions">';
echo '<button type="button" class="btn-cancel" onclick="closeCreateModal()">Cancel</button>';
echo '<button type="submit" class="btn-submit">Create Customer</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

// Edit Customer Modal
echo '<div id="editModal" class="modal">';
echo '<div class="modal-content">';
echo '<div class="modal-header">';
echo '<h2>Edit Customer</h2>';
echo '<button class="modal-close" onclick="closeEditModal()">&times;</button>';
echo '</div>';
echo '<form method="POST">';
echo '<input type="hidden" name="csrf_token" value="', htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'), '">';
echo '<input type="hidden" name="action" value="update_customer">';
echo '<input type="hidden" name="customer_id" id="edit-customer-id">';
echo '<div class="form-group">';
echo '<label>Customer Name <span style="color:red;">*</span></label>';
echo '<input type="text" name="name" id="edit-name" required>';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Email</label>';
echo '<input type="email" name="email" id="edit-email">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Phone</label>';
echo '<input type="text" name="phone" id="edit-phone">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Address</label>';
echo '<input type="text" name="address" id="edit-address">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>City</label>';
echo '<input type="text" name="city" id="edit-city">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Country</label>';
echo '<input type="text" name="country" id="edit-country">';
echo '</div>';
echo '<div class="form-group">';
echo '<label>Notes</label>';
echo '<textarea name="notes" id="edit-notes"></textarea>';
echo '</div>';
echo '<div class="form-actions">';
echo '<button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>';
echo '<button type="submit" class="btn-submit">Update Customer</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

// Customer Detail Modal
echo '<div id="customerDetailModal" class="modal">';
echo '<div class="modal-content" style="max-width: 900px;">';
echo '<div class="modal-header">';
echo '<h2 id="detail-customer-name">Customer Details</h2>';
echo '<button class="modal-close" onclick="closeCustomerDetail()">&times;</button>';
echo '</div>';
echo '<div id="customerDetailContent" style="max-height: 600px; overflow-y: auto;">';
echo '<div style="text-align: center; padding: 40px; color: var(--muted);">Loading...</div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<script>';
echo 'function applyFilters() {';
echo '  const search = document.getElementById("filter-search").value;';
echo '  const status = document.getElementById("filter-status").value;';
echo '  const city = document.getElementById("filter-city").value;';
echo '  let url = "?page=1";';
echo '  if (search) url += "&search=" + encodeURIComponent(search);';
echo '  if (status) url += "&status=" + encodeURIComponent(status);';
echo '  if (city) url += "&city=" + encodeURIComponent(city);';
echo '  window.location.href = url;';
echo '}';
echo 'function clearFilters() {';
echo '  window.location.href = "?";';
echo '}';
echo 'function openCreateModal() {';
echo '  document.getElementById("createModal").classList.add("active");';
echo '}';
echo 'function closeCreateModal() {';
echo '  document.getElementById("createModal").classList.remove("active");';
echo '}';
echo 'function openEditModal(id) {';
echo '  fetch("?action=get_customer&id=" + id)';
echo '    .then(r => r.json())';
echo '    .then(data => {';
echo '      document.getElementById("edit-customer-id").value = data.id;';
echo '      document.getElementById("edit-name").value = data.name || "";';
echo '      document.getElementById("edit-email").value = data.email || "";';
echo '      document.getElementById("edit-phone").value = data.phone || "";';
echo '      document.getElementById("edit-address").value = data.address || "";';
echo '      document.getElementById("edit-city").value = data.city || "";';
echo '      document.getElementById("edit-country").value = data.country || "";';
echo '      document.getElementById("edit-notes").value = data.notes || "";';
echo '      document.getElementById("editModal").classList.add("active");';
echo '    });';
echo '}';
echo 'function closeEditModal() {';
echo '  document.getElementById("editModal").classList.remove("active");';
echo '}';
echo 'function openCustomerDetail(customerId) {';
echo '  document.getElementById("customerDetailModal").classList.add("active");';
echo '  document.getElementById("customerDetailContent").innerHTML = "<div style=\"text-align: center; padding: 40px; color: var(--muted);\">Loading...</div>";';
echo '  fetch("?action=get_customer_detail&id=" + customerId)';
echo '    .then(r => r.json())';
echo '    .then(data => {';
echo '      if (data.error) {';
echo '        document.getElementById("customerDetailContent").innerHTML = "<div style=\"text-align: center; padding: 40px; color: #dc2626;\">Error: " + data.error + "</div>";';
echo '        return;';
echo '      }';
echo '      document.getElementById("detail-customer-name").textContent = data.customer.name;';
echo '      let html = "<div style=\"display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px;\">";';
echo '      html += "<div><h3 style=\"margin: 0 0 16px 0; font-size: 1.1rem; border-bottom: 2px solid var(--accent); padding-bottom: 8px;\">Customer Info</h3>";';
echo '      html += "<div style=\"display: flex; flex-direction: column; gap: 12px;\">";';
echo '      html += "<div><strong>Phone:</strong> " + (data.customer.phone || "-") + "</div>";';
echo '      html += "<div><strong>Location:</strong> " + (data.customer.location || "-") + "</div>";';
echo '      html += "<div><strong>Shop Type:</strong> " + (data.customer.shop_type || "-") + "</div>";';
echo '      html += "<div><strong>Customer Since:</strong> " + new Date(data.customer.created_at).toLocaleDateString() + "</div>";';
echo '      html += "</div></div>";';
echo '      html += "<div><h3 style=\"margin: 0 0 16px 0; font-size: 1.1rem; border-bottom: 2px solid var(--accent); padding-bottom: 8px;\">Summary</h3>";';
echo '      html += "<div style=\"display: flex; flex-direction: column; gap: 12px;\">";';
echo '      html += "<div><strong>Total Orders:</strong> " + data.stats.order_count + "</div>";';
echo '      html += "<div><strong>Total Revenue:</strong> $" + parseFloat(data.stats.total_revenue).toFixed(2) + "</div>";';
echo '      html += "<div><strong>Outstanding:</strong> <span style=\"color: " + (data.stats.outstanding > 0 ? "#dc2626" : "#059669") + ";\">$" + parseFloat(data.stats.outstanding).toFixed(2) + "</span></div>";';
echo '      html += "<div><strong>Last Order:</strong> " + (data.stats.last_order_date ? new Date(data.stats.last_order_date).toLocaleDateString() : "Never") + "</div>";';
echo '      html += "</div></div>";';
echo '      html += "</div>";';
echo '      html += "<h3 style=\"margin: 24px 0 16px 0; font-size: 1.1rem; border-bottom: 2px solid var(--accent); padding-bottom: 8px;\">Recent Orders</h3>";';
echo '      if (data.orders.length === 0) {';
echo '        html += "<div style=\"text-align: center; padding: 24px; color: var(--muted);\">No orders yet</div>";';
echo '      } else {';
echo '        html += "<table style=\"width: 100%; border-collapse: collapse;\"><thead><tr style=\"background: var(--bg); text-align: left;\">";';
echo '        html += "<th style=\"padding: 10px;\">Order #</th><th>Date</th><th>Status</th><th>Total</th><th>Outstanding</th></tr></thead><tbody>";';
echo '        data.orders.forEach(order => {';
echo '          const outstanding = parseFloat(order.outstanding || 0);';
echo '          html += "<tr style=\"border-bottom: 1px solid var(--border);\">";';
echo '          html += "<td style=\"padding: 10px;\"><strong>" + order.order_number + "</strong></td>";';
echo '          html += "<td>" + new Date(order.created_at).toLocaleDateString() + "</td>";';
echo '          html += "<td><span style=\"padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; background: rgba(59, 130, 246, 0.15); color: #1d4ed8;\">" + order.status + "</span></td>";';
echo '          html += "<td>$" + parseFloat(order.total_usd).toFixed(2) + "</td>";';
echo '          html += "<td><span style=\"color: " + (outstanding > 0 ? "#dc2626" : "#059669") + ";\">$" + outstanding.toFixed(2) + "</span></td>";';
echo '          html += "</tr>";';
echo '        });';
echo '        html += "</tbody></table>";';
echo '      }';
echo '      document.getElementById("customerDetailContent").innerHTML = html;';
echo '    })';
echo '    .catch(err => {';
echo '      document.getElementById("customerDetailContent").innerHTML = "<div style=\"text-align: center; padding: 40px; color: #dc2626;\">Error loading customer details</div>";';
echo '    });';
echo '}';
echo 'function closeCustomerDetail() {';
echo '  document.getElementById("customerDetailModal").classList.remove("active");';
echo '}';
echo 'document.getElementById("filter-search").addEventListener("keypress", function(e) {';
echo '  if (e.key === "Enter") applyFilters();';
echo '});';
echo '</script>';

sales_portal_render_layout_end();
