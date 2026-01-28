<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'المبيعات · طلباتي';
$repId = (int)$user['id'];

// Arabic status labels
$statusLabels = [
    'on_hold' => 'قيد الانتظار',
    'approved' => 'مُوافق عليه',
    'preparing' => 'قيد التحضير',
    'ready' => 'جاهز للاستلام',
    'in_transit' => 'قيد التوصيل',
    'delivered' => 'تم التسليم',
    'cancelled' => 'ملغى',
    'returned' => 'مرتجع',
];

$statusBadgeStyles = [
    'on_hold' => 'background: rgba(156, 163, 175, 0.15); color: #4b5563;',
    'approved' => 'background: rgba(59, 130, 246, 0.15); color: #1d4ed8;',
    'preparing' => 'background: rgba(251, 191, 36, 0.15); color: #b45309;',
    'ready' => 'background: rgba(34, 197, 94, 0.15); color: #15803d;',
    'in_transit' => 'background: rgba(139, 92, 246, 0.15); color: #6d28d9;',
    'delivered' => 'background: rgba(16, 185, 129, 0.15); color: #047857;',
    'cancelled' => 'background: rgba(239, 68, 68, 0.15); color: #991b1b;',
    'returned' => 'background: rgba(239, 68, 68, 0.15); color: #991b1b;',
];

// Handle POST requests (status update or OTP verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Handle OTP verification from sales rep side
    if ($action === 'verify_otp_salesrep') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $warehouseOtpInput = trim($_POST['warehouse_otp'] ?? '');

        if ($orderId > 0 && $warehouseOtpInput !== '') {
            try {
                $pdo->beginTransaction();

                // Verify order belongs to this sales rep
                $orderCheck = $pdo->prepare("
                    SELECT o.id FROM orders o
                    INNER JOIN customers c ON c.id = o.customer_id
                    WHERE o.id = ? AND c.assigned_sales_rep_id = ?
                ");
                $orderCheck->execute([$orderId, $repId]);
                if (!$orderCheck->fetch()) {
                    flash('error', 'Order not found or access denied.');
                    $pdo->rollBack();
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }

                // Get OTP record
                $otpStmt = $pdo->prepare("
                    SELECT * FROM order_transfer_otps
                    WHERE order_id = ? AND expires_at > NOW()
                ");
                $otpStmt->execute([$orderId]);
                $otpRecord = $otpStmt->fetch(PDO::FETCH_ASSOC);

                if (!$otpRecord) {
                    flash('error', 'OTP expired or not found.');
                } elseif ($otpRecord['warehouse_otp'] !== $warehouseOtpInput) {
                    flash('error', 'Invalid Warehouse OTP code!');
                } else {
                    // Mark sales rep as verified
                    $updateStmt = $pdo->prepare("
                        UPDATE order_transfer_otps
                        SET sales_rep_verified_at = NOW(),
                            sales_rep_verified_by = ?
                        WHERE order_id = ?
                    ");
                    $updateStmt->execute([$repId, $orderId]);

                    // Check if both parties have verified
                    $checkStmt = $pdo->prepare("
                        SELECT * FROM order_transfer_otps
                        WHERE order_id = ?
                        AND warehouse_verified_at IS NOT NULL
                        AND sales_rep_verified_at IS NOT NULL
                    ");
                    $checkStmt->execute([$orderId]);
                    $bothVerified = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($bothVerified) {
                        // Both verified - transfer stock
                        require_once __DIR__ . '/../../includes/stock_functions.php';
                        $pdo->commit();
                        $transferSuccess = transferStockToSalesRep($pdo, $orderId, $repId);

                        if ($transferSuccess) {
                            flash('success', 'OTP verified! Stock transferred to your van successfully.');
                        } else {
                            flash('error', 'OTP verified but stock transfer failed.');
                        }
                    } else {
                        $pdo->commit();
                        flash('success', 'Sales Rep OTP verified! Waiting for warehouse verification...');
                    }
                }

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Error: ' . $e->getMessage());
            }
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Update order status
    if ($action === 'update_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if ($orderId > 0 && isset($statusLabels[$newStatus])) {
            try {
                $pdo->beginTransaction();

                // Verify order belongs to sales rep's customer
                $orderStmt = $pdo->prepare("
                    SELECT o.id, o.order_number, o.status, o.order_type, c.assigned_sales_rep_id
                    FROM orders o
                    INNER JOIN customers c ON c.id = o.customer_id
                    WHERE o.id = :id AND c.assigned_sales_rep_id = :rep_id
                ");
                $orderStmt->execute([':id' => $orderId, ':rep_id' => $repId]);
                $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

                if (!$order) {
                    flash('error', 'Order not found or you do not have permission to update this order.');
                    $pdo->rollBack();
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }

                // Check if status transition is allowed
                $allowedTransitions = [
                    'pending' => ['on_hold', 'approved', 'preparing', 'in_transit', 'delivered', 'cancelled'],
                    'on_hold' => ['approved', 'preparing', 'in_transit', 'delivered', 'cancelled'],
                    'approved' => ['preparing', 'ready', 'in_transit', 'delivered', 'cancelled'],
                    'preparing' => ['ready', 'in_transit', 'delivered', 'cancelled'],
                    'ready' => ['in_transit', 'delivered', 'cancelled'],
                    'in_transit' => ['delivered', 'returned', 'cancelled'],
                    'delivered' => [],
                    'cancelled' => [],
                    'returned' => [],
                ];

                $currentStatus = $order['status'];

                // Allow sales rep to update only certain statuses
                if ($currentStatus === 'delivered' || $currentStatus === 'cancelled' || $currentStatus === 'returned') {
                    flash('error', 'Cannot update status of completed orders.');
                    $pdo->rollBack();
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }

                if ($newStatus !== $currentStatus && in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
                    $updateStmt = $pdo->prepare("
                        UPDATE orders
                        SET status = :status, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $updateStmt->execute([':status' => $newStatus, ':id' => $orderId]);

                    flash('success', sprintf(
                        'Order %s status updated to: %s',
                        $order['order_number'] ?? 'Order #' . $order['id'],
                        $statusLabels[$newStatus]
                    ));
                } else {
                    flash('error', 'Invalid status transition. Cannot change from ' . $statusLabels[$currentStatus] . ' to ' . $statusLabels[$newStatus]);
                }

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Failed to update order status: ' . $e->getMessage());
            }
        } else {
            flash('error', 'Invalid order or status.');
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Handle CSV export
$action = $_GET['action'] ?? '';
if ($action === 'export') {
    $search = trim($_GET['search'] ?? '');
    $statusFilter = $_GET['status'] ?? '';
    $typeFilter = $_GET['type'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    $where = ['c.assigned_sales_rep_id = :rep_id'];
    $params = [':rep_id' => $repId];

    if ($search !== '') {
        $where[] = "(o.order_number LIKE :search OR c.name LIKE :search OR c.phone LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    if ($statusFilter !== '' && isset($statusLabels[$statusFilter])) {
        $where[] = "o.status = :status";
        $params[':status'] = $statusFilter;
    }

    if ($typeFilter !== '') {
        $where[] = "o.order_type = :type";
        $params[':type'] = $typeFilter;
    }

    if ($dateFrom !== '') {
        $fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom);
        if ($fromDate && $fromDate->format('Y-m-d') === $dateFrom) {
            $where[] = "DATE(o.created_at) >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
    }

    if ($dateTo !== '') {
        $toDate = DateTime::createFromFormat('Y-m-d', $dateTo);
        if ($toDate && $toDate->format('Y-m-d') === $dateTo) {
            $where[] = "DATE(o.created_at) <= :date_to";
            $params[':date_to'] = $dateTo;
        }
    }

    $whereClause = implode(' AND ', $where);

    // Export query
    $exportStmt = $pdo->prepare("
        SELECT
            o.id,
            o.order_number,
            o.order_type,
            c.name as customer_name,
            c.phone as customer_phone,
            c.location as customer_location,
            o.status,
            o.total_usd,
            o.total_lbp,
            o.created_at,
            o.delivery_date,
            o.notes
        FROM orders o
        INNER JOIN customers c ON c.id = o.customer_id
        WHERE {$whereClause}
        ORDER BY o.created_at DESC
    ");

    foreach ($params as $key => $value) {
        $exportStmt->bindValue($key, $value);
    }
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=my_orders_' . date('Y-m-d_His') . '.csv');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // Headers
    fputcsv($output, [
        'Order Number',
        'Order Type',
        'Customer Name',
        'Customer Phone',
        'Customer Location',
        'Status',
        'Total (USD)',
        'Total (LBP)',
        'Created Date',
        'Delivery Date',
        'Notes'
    ]);

    // Data rows
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['order_number'] ?? 'Order #' . $row['id'],
            ucfirst(str_replace('_', ' ', $row['order_type'])),
            $row['customer_name'],
            $row['customer_phone'] ?? '',
            $row['customer_location'] ?? '',
            $statusLabels[$row['status']] ?? ucfirst($row['status']),
            number_format((float)$row['total_usd'], 2),
            number_format((float)$row['total_lbp'], 0),
            $row['created_at'] ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : '',
            $row['delivery_date'] ? date('Y-m-d', strtotime($row['delivery_date'])) : '',
            $row['notes'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

// Filtering and pagination
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = ['c.assigned_sales_rep_id = :rep_id'];
$params = [':rep_id' => $repId];

if ($search !== '') {
    $where[] = "(o.order_number LIKE :search OR c.name LIKE :search OR c.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== '' && isset($statusLabels[$statusFilter])) {
    $where[] = "o.status = :status";
    $params[':status'] = $statusFilter;
}

if ($typeFilter !== '') {
    $where[] = "o.order_type = :type";
    $params[':type'] = $typeFilter;
}

if ($dateFrom !== '') {
    $fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if ($fromDate && $fromDate->format('Y-m-d') === $dateFrom) {
        $where[] = "DATE(o.created_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    } else {
        $dateFrom = '';
    }
}

if ($dateTo !== '') {
    $toDate = DateTime::createFromFormat('Y-m-d', $dateTo);
    if ($toDate && $toDate->format('Y-m-d') === $dateTo) {
        $where[] = "DATE(o.created_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    } else {
        $dateTo = '';
    }
}

$whereClause = implode(' AND ', $where);

// Get total count
$countSql = "
    SELECT COUNT(*)
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE {$whereClause}
";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalMatches = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalMatches / $perPage);

// Get orders with pagination
$offset = ($page - 1) * $perPage;
$ordersSql = "
    SELECT
        o.id,
        o.order_number,
        o.order_type,
        o.status,
        o.total_usd,
        o.total_lbp,
        o.created_at,
        o.delivery_date,
        c.id as customer_id,
        c.name as customer_name,
        c.phone as customer_phone,
        COUNT(DISTINCT oi.id) as item_count
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE {$whereClause}
    GROUP BY o.id
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

// Get summary statistics
$statsSql = "
    SELECT
        COUNT(o.id) as total_orders,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
        SUM(CASE WHEN o.status IN ('on_hold', 'approved', 'preparing', 'ready', 'in_transit') THEN 1 ELSE 0 END) as active_orders,
        SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        SUM(o.total_usd) as total_value_usd
    FROM orders o
    INNER JOIN customers c ON c.id = o.customer_id
    WHERE c.assigned_sales_rep_id = :rep_id
";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute([':rep_id' => $repId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => 'طلباتي',
    'subtitle' => 'عرض وإدارة طلبات زبائنك',
    'user' => $user,
    'active' => 'orders',
    'extra_head' => '<style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .stat-label {
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 600;
            color: #111827;
        }
        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e5e7eb;
        }
        .filters label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #374151;
            font-size: 0.875rem;
        }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .table-container {
            background: white;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table thead {
            background: #f9fafb;
        }
        .data-table th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.8rem;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
        }
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.9rem;
        }
        .data-table tbody tr:hover {
            background: #f9fafb;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .empty-state {
            background: white;
            border-radius: 8px;
            padding: 48px 32px;
            text-align: center;
            border: 1px solid #e5e7eb;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
            padding: 16px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
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
            overflow-y: auto;
            padding: 20px;
        }
        .modal-content {
            background: white;
            border-radius: 8px;
            padding: 24px;
            max-width: 900px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            margin: auto;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 16px;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            color: #111827;
        }
    </style>'
]);
?>

<!-- Summary Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Orders</div>
        <div class="stat-value"><?= number_format((int)$stats['total_orders']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active</div>
        <div class="stat-value" style="color: #3b82f6;"><?= number_format((int)$stats['active_orders']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Delivered</div>
        <div class="stat-value" style="color: #10b981;"><?= number_format((int)$stats['delivered_orders']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cancelled</div>
        <div class="stat-value" style="color: #ef4444;"><?= number_format((int)$stats['cancelled_orders']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Value</div>
        <div class="stat-value" style="color: #059669;">$<?= number_format((float)$stats['total_value_usd'], 2) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filters">
    <form method="GET" action="">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div>
                <label>Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Order #, customer..." class="form-control">
            </div>
            <div>
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $statusFilter === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Order Type</label>
                <select name="type" class="form-control">
                    <option value="">All Types</option>
                    <option value="company_order" <?= $typeFilter === 'company_order' ? 'selected' : '' ?>>Company Order</option>
                    <option value="van_stock_sale" <?= $typeFilter === 'van_stock_sale' ? 'selected' : '' ?>>Van Stock Sale</option>
                </select>
            </div>
            <div>
                <label>Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div>
                <label>Date To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="submit" class="btn btn-info">Apply Filters</button>
            <a href="orders.php" class="btn btn-secondary">Clear</a>
            <?php
            // Build export URL with current filters
            $exportUrl = '?action=export';
            if ($search !== '') {
                $exportUrl .= '&search=' . urlencode($search);
            }
            if ($statusFilter !== '') {
                $exportUrl .= '&status=' . urlencode($statusFilter);
            }
            if ($typeFilter !== '') {
                $exportUrl .= '&type=' . urlencode($typeFilter);
            }
            if ($dateFrom !== '') {
                $exportUrl .= '&date_from=' . urlencode($dateFrom);
            }
            if ($dateTo !== '') {
                $exportUrl .= '&date_to=' . urlencode($dateTo);
            }
            ?>
            <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-success">📊 Export CSV</a>
        </div>
    </form>
</div>

<!-- Orders Table -->
<?php if (!empty($orders)): ?>
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Order Number</th>
                <th>Type</th>
                <th>Customer</th>
                <th class="text-right">Total (USD)</th>
                <th class="text-right">Total (LBP)</th>
                <th class="text-center">Items</th>
                <th class="text-center">Status</th>
                <th>Created</th>
                <th>Delivery</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
            <tr>
                <td><strong><?= htmlspecialchars($order['order_number'] ?? 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= $order['order_type'] === 'van_stock_sale' ? '🚚 Van Sale' : '🏢 Company' ?></td>
                <td>
                    <div style="font-weight: 500;"><?= htmlspecialchars($order['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($order['customer_phone']): ?>
                        <div style="font-size: 0.85rem; color: #6b7280;"><?= htmlspecialchars($order['customer_phone'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-right">$<?= number_format((float)$order['total_usd'], 2) ?></td>
                <td class="text-right"><?= number_format((float)$order['total_lbp'], 0) ?> LBP</td>
                <td class="text-center"><?= (int)$order['item_count'] ?></td>
                <td class="text-center">
                    <span class="badge" style="<?= $statusBadgeStyles[$order['status']] ?? '' ?>">
                        <?= htmlspecialchars($statusLabels[$order['status']] ?? ucfirst($order['status']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </td>
                <td><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
                <td><?= $order['delivery_date'] ? date('M d, Y', strtotime($order['delivery_date'])) : '—' ?></td>
                <td class="text-center">
                    <div style="display: flex; gap: 8px; justify-content: center;">
                        <button onclick="viewOrderDetails(<?= $order['id'] ?>)" class="btn btn-info btn-sm">View</button>
                        <?php
                        $canUpdateStatus = !in_array($order['status'], ['delivered', 'cancelled', 'returned'], true);
                        ?>
                        <?php if ($canUpdateStatus): ?>
                            <button onclick="openStatusModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number'] ?? 'Order #' . $order['id'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>')" class="btn btn-warning btn-sm">Update Status</button>
                        <?php else: ?>
                            <span style="color: #9ca3af; font-size: 0.85rem;">Completed</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $typeFilter ? '&type=' . urlencode($typeFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>" class="btn btn-secondary">← Previous</a>
    <?php endif; ?>

    <span style="font-weight: 600; color: #374151;">Page <?= $page ?> of <?= $totalPages ?></span>

    <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $typeFilter ? '&type=' . urlencode($typeFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>" class="btn btn-secondary">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="margin-top: 16px; color: #6b7280; font-size: 0.9rem; text-align: center; padding: 12px; background: white; border-radius: 8px;">
    Showing <strong><?= count($orders) ?></strong> of <strong><?= number_format($totalMatches) ?></strong> orders
</div>

<?php else: ?>
<div class="empty-state">
    <div style="font-size: 4rem; margin-bottom: 16px; opacity: 0.6;">📦</div>
    <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 12px; color: #111827;">No Orders Found</h3>
    <p style="color: #6b7280; margin-bottom: 32px; font-size: 1.05rem;">Try adjusting your filters or create a new order to get started.</p>
    <div style="display: flex; gap: 12px; justify-content: center;">
        <a href="van_stock_sales.php" class="btn btn-success">🚚 New Van Stock Sale</a>
        <a href="company_order_request.php" class="btn btn-info">🏢 New Company Order</a>
    </div>
</div>
<?php endif; ?>

<!-- Order Details Modal -->
<div id="orderDetailsModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h2>Order Details</h2>
            <button onclick="closeOrderDetailsModal()" style="background: none; border: none; font-size: 2rem; cursor: pointer; color: #9ca3af; line-height: 1; transition: color 0.2s;">&times;</button>
        </div>

        <div id="orderDetailsContent" style="min-height: 200px;">
            <div style="text-align: center; padding: 40px; color: #6b7280;">
                <div style="font-size: 2rem; margin-bottom: 12px;">⏳</div>
                Loading order details...
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div id="statusModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 style="font-size: 1.5rem;">Update Order Status</h2>
            <button onclick="closeStatusModal()" style="background: none; border: none; font-size: 1.8rem; cursor: pointer; color: #9ca3af; transition: color 0.2s;">&times;</button>
        </div>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" id="modal_order_id">

            <div style="margin-bottom: 16px;">
                <div style="font-weight: 500; margin-bottom: 4px;">Order: <span id="modal_order_number"></span></div>
                <div style="font-size: 0.9rem; color: #6b7280;">Current Status: <span id="modal_current_status"></span></div>
            </div>

            <div style="margin-bottom: 24px;">
                <label style="display: block; font-weight: 500; margin-bottom: 8px;">New Status</label>
                <select name="status" id="modal_new_status" class="form-control" required style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <option value="">Select status...</option>
                    <option value="on_hold">On Hold</option>
                    <option value="approved">Approved</option>
                    <option value="preparing">Preparing</option>
                    <option value="ready">Ready for Pickup</option>
                    <option value="in_transit">In Transit</option>
                    <option value="delivered">Delivered</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="returned">Returned</option>
                </select>
                <div style="margin-top: 12px; padding: 12px; background: #eff6ff; border-radius: 6px; font-size: 0.85rem; color: #1e40af;">
                    ℹ️ Only allowed status transitions will be permitted
                </div>
            </div>

            <div style="display: flex; gap: 12px;">
                <button type="submit" class="btn btn-warning" style="flex: 1;">Update Status</button>
                <button type="button" onclick="closeStatusModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStatusModal(orderId, orderNumber, currentStatus) {
    document.getElementById('modal_order_id').value = orderId;
    document.getElementById('modal_order_number').textContent = orderNumber;
    document.getElementById('modal_current_status').textContent = currentStatus.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    document.getElementById('statusModal').style.display = 'flex';
}

function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
}

function viewOrderDetails(orderId) {
    const modal = document.getElementById('orderDetailsModal');
    const content = document.getElementById('orderDetailsContent');

    modal.style.display = 'flex';
    content.innerHTML = '<div style="text-align: center; padding: 40px; color: #6b7280;"><div style="font-size: 2rem; margin-bottom: 12px;">⏳</div>Loading order details...</div>';

    // Fetch order details via AJAX
    fetch('ajax_order_details.php?order_id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                content.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc2626;"><div style="font-size: 2rem; margin-bottom: 12px;">⚠️</div>' + data.error + '</div>';
                return;
            }

            // Build order details HTML
            let html = '';

            // Order Header Info
            html += '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; margin-bottom: 32px; padding: 20px; background: #f9fafb; border-radius: 8px;">';
            html += '<div><div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Order Number</div><div style="font-size: 1.25rem; font-weight: 700;">' + data.order_number + '</div></div>';
            html += '<div><div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Order Type</div><div style="font-weight: 600;">' + (data.order_type === 'van_stock_sale' ? '🚚 Van Sale' : '🏢 Company') + '</div></div>';
            html += '<div><div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Status</div><div style="font-weight: 600;">' + data.status_label + '</div></div>';
            html += '<div><div style="font-size: 0.8rem; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;">Created</div><div>' + data.created_at + '</div></div>';
            html += '</div>';

            // Customer Info
            html += '<div style="margin-bottom: 32px;"><h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 16px; color: #111827;">Customer Information</h3>';
            html += '<div style="padding: 20px; background: white; border: 1px solid #e5e7eb; border-radius: 8px;">';
            html += '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;">';
            html += '<div><div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 4px;">Name</div><div style="font-weight: 600;">' + data.customer_name + '</div></div>';
            html += '<div><div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 4px;">Phone</div><div style="font-weight: 600;">' + (data.customer_phone || '—') + '</div></div>';
            if (data.customer_location) {
                html += '<div style="grid-column: 1 / -1;"><div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 4px;">Location</div><div>' + data.customer_location + '</div></div>';
            }
            html += '</div></div></div>';

            // Order Items
            html += '<div style="margin-bottom: 32px;"><h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 16px; color: #111827;">Order Items</h3>';
            html += '<div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;"><table style="width: 100%; border-collapse: collapse;">';
            html += '<thead><tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;"><th style="padding: 12px; text-align: left; font-weight: 600; font-size: 0.85rem; color: #6b7280;">SKU</th><th style="padding: 12px; text-align: left; font-weight: 600; font-size: 0.85rem; color: #6b7280;">Product</th><th style="padding: 12px; text-align: center; font-weight: 600; font-size: 0.85rem; color: #6b7280;">Qty</th><th style="padding: 12px; text-align: right; font-weight: 600; font-size: 0.85rem; color: #6b7280;">Unit Price</th><th style="padding: 12px; text-align: right; font-weight: 600; font-size: 0.85rem; color: #6b7280;">Total</th></tr></thead>';
            html += '<tbody>';

            data.items.forEach((item, index) => {
                html += '<tr style="border-bottom: 1px solid #e5e7eb;' + (index % 2 === 0 ? ' background: white;' : ' background: #fafafa;') + '">';
                html += '<td style="padding: 12px; font-family: monospace; font-size: 0.9rem;">' + item.sku + '</td>';
                html += '<td style="padding: 12px; font-weight: 500;">' + item.product_name + '</td>';
                html += '<td style="padding: 12px; text-align: center; font-weight: 600;">' + item.quantity + '</td>';
                html += '<td style="padding: 12px; text-align: right;">$' + parseFloat(item.unit_price_usd).toFixed(2) + '</td>';
                html += '<td style="padding: 12px; text-align: right; font-weight: 600;">$' + (item.quantity * item.unit_price_usd).toFixed(2) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div></div>';

            // OTP Verification Section (if order is ready)
            if (data.otp_data) {
                html += '<div style="margin-bottom: 32px;"><h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 16px; color: #111827;">🔐 Stock Transfer Verification</h3>';
                html += '<div style="padding: 20px; background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px;">';

                if (data.otp_data.both_verified) {
                    html += '<div style="text-align: center; padding: 16px; background: #22c55e; color: #fff; font-weight: 700; border-radius: 4px; font-size: 1.1rem;">';
                    html += '✅ STOCK SUCCESSFULLY TRANSFERRED TO YOUR VAN';
                    html += '</div>';
                } else {
                    // Sales Rep OTP Display
                    html += '<div style="background: white; padding: 16px; border-radius: 4px; margin-bottom: 16px; border: 2px solid #000;">';
                    html += '<div style="font-size: 0.85rem; font-weight: 600; color: #92400e; margin-bottom: 8px;">YOUR OTP (Show to Warehouse):</div>';
                    html += '<div style="font-size: 2.5rem; font-weight: 700; letter-spacing: 10px; color: #000; text-align: center; font-family: monospace;">';
                    html += data.otp_data.sales_rep_otp;
                    html += '</div></div>';

                    // Warehouse OTP Input Form
                    html += '<form method="POST" action="" style="background: white; padding: 16px; border-radius: 4px; border: 2px solid #000;">';
                    html += '<?= csrf_field() ?>';
                    html += '<input type="hidden" name="action" value="verify_otp_salesrep">';
                    html += '<input type="hidden" name="order_id" value="' + data.id + '">';
                    html += '<div style="margin-bottom: 12px;"><label style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 8px;">Enter Warehouse OTP:</label>';
                    html += '<input type="text" name="warehouse_otp" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required ';
                    html += 'style="width: 100%; padding: 16px; font-size: 2rem; text-align: center; letter-spacing: 10px; font-family: monospace; border: 2px solid #000; font-weight: 700;"';
                    html += (data.otp_data.sales_rep_verified ? ' disabled' : '') + '></div>';

                    if (!data.otp_data.sales_rep_verified) {
                        html += '<button type="submit" style="width: 100%; padding: 14px; background: #000; color: #fff; border: none; cursor: pointer; font-weight: 700; font-size: 1rem; border-radius: 4px;">';
                        html += 'VERIFY & RECEIVE STOCK';
                        html += '</button>';
                    } else {
                        html += '<div style="padding: 12px; background: #22c55e; color: #fff; font-weight: 700; text-align: center; border-radius: 4px;">';
                        html += '✓ Sales Rep Verified - Waiting for Warehouse';
                        html += '</div>';
                    }

                    html += '</form>';
                }

                html += '</div></div>';
            }

            // Order Totals
            html += '<div style="background: #f9fafb; padding: 20px; border-radius: 8px; border: 2px solid #e5e7eb;">';
            html += '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;"><span style="font-size: 1.1rem; font-weight: 600;">Total USD:</span><span style="font-size: 1.5rem; font-weight: 700; color: #111827;">$' + parseFloat(data.total_usd).toFixed(2) + '</span></div>';
            html += '<div style="display: flex; justify-content: space-between; align-items: center;"><span style="font-size: 1.1rem; font-weight: 600;">Total LBP:</span><span style="font-size: 1.5rem; font-weight: 700; color: #111827;">' + parseFloat(data.total_lbp).toLocaleString() + ' LBP</span></div>';
            html += '</div>';

            content.innerHTML = html;
        })
        .catch(error => {
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc2626;"><div style="font-size: 2rem; margin-bottom: 12px;">⚠️</div>Failed to load order details</div>';
            console.error('Error:', error);
        });
}

function closeOrderDetailsModal() {
    document.getElementById('orderDetailsModal').style.display = 'none';
}

// Close modal on background click
document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeStatusModal();
    }
});

document.getElementById('orderDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeOrderDetailsModal();
    }
});
</script>

<?php
sales_portal_render_layout_end();
?>
