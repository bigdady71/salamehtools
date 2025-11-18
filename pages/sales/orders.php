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
$title = 'Sales · My Orders';
$repId = (int)$user['id'];

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

// Handle POST requests (status update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';

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
                    'on_hold' => ['cancelled'],
                    'approved' => ['in_transit', 'cancelled'],
                    'preparing' => ['in_transit', 'cancelled'],
                    'ready' => ['in_transit', 'cancelled'],
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
                        $order['order_number'],
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
            $row['order_number'],
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

sales_portal_render_layout_start(['title' => $title]);
?>

<div class="page-header">
    <div class="page-title">
        <h1>📦 My Orders</h1>
        <p class="subtitle">View and manage orders for your customers</p>
    </div>
</div>

<!-- Summary Stats -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
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
        <div class="stat-value">$<?= number_format((float)$stats['total_value_usd'], 2) ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filters" style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #e5e7eb;">
    <form method="GET" action="">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div>
                <label style="display: block; font-weight: 500; margin-bottom: 6px;">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Order #, customer..." class="form-control">
            </div>
            <div>
                <label style="display: block; font-weight: 500; margin-bottom: 6px;">Status</label>
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
                <label style="display: block; font-weight: 500; margin-bottom: 6px;">Order Type</label>
                <select name="type" class="form-control">
                    <option value="">All Types</option>
                    <option value="company_order" <?= $typeFilter === 'company_order' ? 'selected' : '' ?>>Company Order</option>
                    <option value="van_stock_sale" <?= $typeFilter === 'van_stock_sale' ? 'selected' : '' ?>>Van Stock Sale</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-weight: 500; margin-bottom: 6px;">Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
            <div>
                <label style="display: block; font-weight: 500; margin-bottom: 6px;">Date To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
            </div>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="orders.php" class="btn">Clear</a>
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
            <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn" style="background: #10b981; color: white; border-color: #10b981;">📊 Export CSV</a>
        </div>
    </form>
</div>

<!-- Orders Table -->
<?php if (!empty($orders)): ?>
<div class="table-container" style="background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow-x: auto;">
    <table class="data-table" style="width: 100%;">
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
                <td><strong><?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
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
                    <?php
                    $canUpdateStatus = !in_array($order['status'], ['delivered', 'cancelled', 'returned'], true);
                    ?>
                    <?php if ($canUpdateStatus): ?>
                        <button onclick="openStatusModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>')" class="btn btn-sm">Update Status</button>
                    <?php else: ?>
                        <span style="color: #9ca3af; font-size: 0.85rem;">Completed</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 24px;">
    <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $typeFilter ? '&type=' . urlencode($typeFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>" class="btn">← Previous</a>
    <?php endif; ?>

    <span>Page <?= $page ?> of <?= $totalPages ?></span>

    <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $typeFilter ? '&type=' . urlencode($typeFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>" class="btn">Next →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="margin-top: 16px; color: #6b7280; font-size: 0.9rem; text-align: center;">
    Showing <?= count($orders) ?> of <?= number_format($totalMatches) ?> orders
</div>

<?php else: ?>
<div class="empty-state" style="background: white; border-radius: 8px; padding: 48px; text-align: center; border: 1px solid #e5e7eb;">
    <div style="font-size: 3rem; margin-bottom: 16px;">📦</div>
    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 8px;">No Orders Found</h3>
    <p style="color: #6b7280; margin-bottom: 24px;">Try adjusting your filters or create a new order.</p>
    <div style="display: flex; gap: 12px; justify-content: center;">
        <a href="orders/van_stock_sales.php" class="btn btn-primary">🚚 New Van Stock Sale</a>
        <a href="orders/company_order_request.php" class="btn">🏢 New Company Order</a>
    </div>
</div>
<?php endif; ?>

<!-- Status Update Modal -->
<div id="statusModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="modal-content" style="background: white; border-radius: 12px; padding: 32px; max-width: 500px; width: 90%;">
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h2 style="margin: 0; font-size: 1.5rem;">Update Order Status</h2>
            <button onclick="closeStatusModal()" style="background: none; border: none; font-size: 1.8rem; cursor: pointer; color: #9ca3af;">&times;</button>
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
                <button type="submit" class="btn btn-primary" style="flex: 1;">Update Status</button>
                <button type="button" onclick="closeStatusModal()" class="btn" style="flex: 1;">Cancel</button>
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

// Close modal on background click
document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeStatusModal();
    }
});
</script>

<?php
sales_portal_render_layout_end();
?>
