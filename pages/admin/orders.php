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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
