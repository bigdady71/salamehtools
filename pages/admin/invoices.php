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
$title = 'Admin · Invoices';

$statusLabels = [
    'draft' => 'Draft',
    'issued' => 'Issued',
    'paid' => 'Paid',
    'voided' => 'Voided',
];

$statusBadgeClasses = [
    'draft' => 'badge-warning',
    'issued' => 'badge-info',
    'paid' => 'badge-success',
    'voided' => 'badge-neutral',
];

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$customerFilter = (int)($_GET['customer'] ?? 0);
$repFilter = (int)($_GET['rep'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$orderFilter = (int)($_GET['order_id'] ?? 0);

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(i.invoice_number LIKE :search OR o.order_number LIKE :search OR c.name LIKE :search OR c.phone LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($statusFilter !== '' && isset($statusLabels[$statusFilter])) {
    $where[] = "i.status = :status";
    $params[':status'] = $statusFilter;
}

if ($customerFilter > 0) {
    $where[] = "c.id = :customer_id";
    $params[':customer_id'] = $customerFilter;
}

if ($repFilter > 0) {
    $where[] = "i.sales_rep_id = :rep_id";
    $params[':rep_id'] = $repFilter;
}

if ($orderFilter > 0) {
    $where[] = "i.order_id = :filter_order_id";
    $params[':filter_order_id'] = $orderFilter;
}

if ($dateFrom !== '') {
    $fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if ($fromDate && $fromDate->format('Y-m-d') === $dateFrom) {
        $where[] = "DATE(i.issued_at) >= :date_from";
        $params[':date_from'] = $dateFrom;
    } else {
        $dateFrom = '';
    }
}

if ($dateTo !== '') {
    $toDate = DateTime::createFromFormat('Y-m-d', $dateTo);
    if ($toDate && $toDate->format('Y-m-d') === $dateTo) {
        $where[] = "DATE(i.issued_at) <= :date_to";
        $params[':date_to'] = $dateTo;
    } else {
        $dateTo = '';
    }
}

$whereClause = implode(' AND ', $where);

$countSql = "
    SELECT COUNT(*)
    FROM invoices i
    LEFT JOIN orders o ON o.id = i.order_id
    LEFT JOIN customers c ON c.id = o.customer_id
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

$invoiceSql = "
    SELECT
        i.id,
        i.invoice_number,
        i.status,
        i.total_usd,
        i.total_lbp,
        i.issued_at,
        i.created_at,
        o.id AS order_id,
        o.order_number,
        c.id AS customer_id,
        c.name AS customer_name,
        c.phone AS customer_phone,
        rep.name AS sales_rep_name,
        pay.paid_usd,
        pay.paid_lbp,
        pay.last_payment_at
    FROM invoices i
    LEFT JOIN orders o ON o.id = i.order_id
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users rep ON rep.id = i.sales_rep_id
    LEFT JOIN (
        SELECT
            invoice_id,
            SUM(amount_usd) AS paid_usd,
            SUM(amount_lbp) AS paid_lbp,
            MAX(created_at) AS last_payment_at
        FROM payments
        GROUP BY invoice_id
    ) pay ON pay.invoice_id = i.id
    WHERE {$whereClause}
    ORDER BY i.issued_at DESC, i.id DESC
    LIMIT :limit OFFSET :offset
";
$invoiceStmt = $pdo->prepare($invoiceSql);
foreach ($params as $key => $value) {
    $invoiceStmt->bindValue($key, $value);
}
$invoiceStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$invoiceStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$invoiceStmt->execute();
$invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
$filteredOrderLabel = null;
if ($orderFilter > 0) {
    if (!empty($invoices)) {
        $firstInvoice = $invoices[0];
        $candidateNumber = trim((string)($firstInvoice['order_number'] ?? ''));
        $fallbackOrderNumber = 'ORD-' . str_pad((string)(int)($firstInvoice['order_id'] ?? $orderFilter), 6, '0', STR_PAD_LEFT);
        $filteredOrderLabel = $candidateNumber !== '' ? $candidateNumber : $fallbackOrderNumber;
    } else {
        $orderLabelStmt = $pdo->prepare("SELECT order_number FROM orders WHERE id = :order_id LIMIT 1");
        $orderLabelStmt->execute([':order_id' => $orderFilter]);
        $orderNumber = trim((string)$orderLabelStmt->fetchColumn());
        $filteredOrderLabel = $orderNumber !== '' ? $orderNumber : 'ORD-' . str_pad((string)$orderFilter, 6, '0', STR_PAD_LEFT);
    }
}

$totalsStmt = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status IN ('draft','issued') THEN 1 ELSE 0 END) AS open_total FROM invoices");
$totalsRow = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'open_total' => 0];
$totalInvoices = (int)$totalsRow['total'];
$openInvoices = (int)$totalsRow['open_total'];

$balanceStmt = $pdo->query("
    SELECT
        SUM(GREATEST(i.total_usd - COALESCE(pay.paid_usd, 0), 0)) AS outstanding_usd,
        SUM(GREATEST(i.total_lbp - COALESCE(pay.paid_lbp, 0), 0)) AS outstanding_lbp
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) AS paid_usd, SUM(amount_lbp) AS paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) pay ON pay.invoice_id = i.id
    WHERE i.status IN ('draft','issued','paid')
");
$balanceRow = $balanceStmt->fetch(PDO::FETCH_ASSOC) ?: ['outstanding_usd' => 0, 'outstanding_lbp' => 0];
$outstandingUsd = (float)$balanceRow['outstanding_usd'];
$outstandingLbp = (float)$balanceRow['outstanding_lbp'];

$recentPaymentsStmt = $pdo->query("
    SELECT
        COALESCE(SUM(amount_usd), 0) AS recent_usd,
        COALESCE(SUM(amount_lbp), 0) AS recent_lbp
    FROM payments
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$recentPayments = $recentPaymentsStmt->fetch(PDO::FETCH_ASSOC) ?: ['recent_usd' => 0, 'recent_lbp' => 0];

$customersStmt = $pdo->query("
    SELECT id, name
    FROM customers
    WHERE name IS NOT NULL AND name != ''
    ORDER BY name ASC
    LIMIT 250
");
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$salesRepsStmt = $pdo->query("
    SELECT id, name
    FROM users
    WHERE role = 'sales_rep' AND is_active = 1
    ORDER BY name ASC
");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

$flashes = consume_flashes();

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Invoices',
    'subtitle' => 'Track billing progress, balances and collections health.',
    'active' => 'invoices',
    'user' => $user,
]);
?>

<style>
    /* Flash messages */
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
    .flash-warning {
        border-color: rgba(146, 64, 14, 0.25);
        background: rgba(146, 64, 14, 0.1);
        color: var(--warn);
    }

    /* Metric cards */
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
    .metric-card .label {
        color: var(--muted);
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .metric-card .value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.1;
    }

    /* Filters */
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
        outline: none;
    }
    .filter-input::placeholder {
        color: var(--muted);
    }
    select.filter-input {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        padding-right: 34px;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7280' d='M6 8 0 0h12z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
    }

    /* Buttons */
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
        text-decoration: none;
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

    /* Invoice table */
    .invoice-table {
        overflow-x: auto;
        border-radius: 16px;
        border: 1px solid var(--bd);
        background: var(--panel);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
    }
    table.invoice-list {
        width: 100%;
        border-collapse: collapse;
    }
    .invoice-list thead {
        background: linear-gradient(180deg, #f9fafb 0%, #f3f4f6 100%);
        border-bottom: 2px solid var(--bd);
    }
    .invoice-list th {
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
    .invoice-list td {
        padding: 16px 14px;
        border-bottom: 1px solid var(--bd);
        text-align: left;
        font-size: 0.92rem;
        vertical-align: top;
        color: var(--ink);
    }
    .invoice-list tbody tr {
        transition: background-color 0.15s ease;
    }
    .invoice-list tbody tr:last-child td {
        border-bottom: none;
    }
    .invoice-list tbody tr:hover {
        background: rgba(31, 111, 235, 0.06);
    }
    .invoice-list tbody tr.selected-order {
        border-left: 4px solid var(--brand);
        background: rgba(31, 111, 235, 0.12);
    }

    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1;
        border: 1px solid;
    }
    .badge-info {
        background: rgba(31, 111, 235, 0.1);
        border-color: rgba(31, 111, 235, 0.25);
        color: #1d4ed8;
    }
    .badge-success {
        background: rgba(6, 95, 70, 0.1);
        border-color: rgba(6, 95, 70, 0.25);
        color: var(--ok);
    }
    .badge-warning {
        background: rgba(146, 64, 14, 0.1);
        border-color: rgba(146, 64, 14, 0.25);
        color: var(--warn);
    }
    .badge-neutral {
        background: var(--chip);
        border-color: var(--bd);
        color: var(--muted);
    }

    /* Utility classes */
    .money {
        font-variant-numeric: tabular-nums;
    }
    .muted {
        color: var(--muted);
        font-size: 0.8rem;
    }
    .amount-stack {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .amount-stack strong {
        font-size: 0.9rem;
        color: var(--ink);
    }
    .amount-stack span {
        font-size: 0.8rem;
        color: var(--muted);
    }
    .empty-state {
        text-align: center;
        padding: 48px 0;
        color: var(--muted);
    }

    /* Pagination */
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

    /* Responsive */
    @media (max-width: 640px) {
        .metric-card {
            padding: 18px;
        }
        .metric-card .value {
            font-size: 1.75rem;
        }
        .filters {
            flex-direction: column;
            align-items: stretch;
            padding: 16px;
        }
        .filter-input {
            width: 100%;
        }
        .invoice-list th,
        .invoice-list td {
            padding: 12px 10px;
            font-size: 0.85rem;
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
            <span class="label">Total invoices</span>
            <span class="value"><?= number_format($totalInvoices) ?></span>
        </div>
        <div class="metric-card">
            <span class="label">Open invoices</span>
            <span class="value"><?= number_format($openInvoices) ?></span>
        </div>
        <div class="metric-card">
            <span class="label">Outstanding USD</span>
            <span class="value money">$<?= number_format($outstandingUsd, 2) ?></span>
        </div>
        <div class="metric-card">
            <span class="label">Outstanding LBP</span>
            <span class="value money">ل.ل <?= number_format($outstandingLbp, 0) ?></span>
        </div>
        <div class="metric-card">
            <span class="label">Collections (7 days)</span>
            <span class="value">
                $<?= number_format((float)$recentPayments['recent_usd'], 2) ?>
                <small class="muted" style="display:block;">ل.ل <?= number_format((float)$recentPayments['recent_lbp'], 0) ?></small>
            </span>
        </div>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="path" value="admin/invoices">
        <?php if ($orderFilter > 0): ?>
            <input type="hidden" name="order_id" value="<?= (int)$orderFilter ?>">
        <?php endif; ?>
        <input type="text" name="search" placeholder="Search invoice #, customer, phone" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="filter-input" style="flex: 1; min-width: 220px;">
        <select name="status" class="filter-input">
            <option value="">All statuses</option>
            <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <select name="customer" class="filter-input">
            <option value="0">All customers</option>
            <?php foreach ($customers as $cust): ?>
                <option value="<?= (int)$cust['id'] ?>" <?= $customerFilter === (int)$cust['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cust['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <select name="rep" class="filter-input">
            <option value="0">All sales reps</option>
            <?php foreach ($salesReps as $rep): ?>
                <option value="<?= (int)$rep['id'] ?>" <?= $repFilter === (int)$rep['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rep['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" class="filter-input">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" class="filter-input">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="?path=admin/invoices" class="btn">Clear</a>
    </form>

    <?php if ($orderFilter > 0): ?>
        <div class="flash flash-info" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <?php if (!empty($invoices)): ?>
                <span>Showing invoices linked to <?= htmlspecialchars($filteredOrderLabel, ENT_QUOTES, 'UTF-8') ?>.</span>
            <?php else: ?>
                <span>No invoices recorded yet for <?= htmlspecialchars($filteredOrderLabel, ENT_QUOTES, 'UTF-8') ?>.</span>
            <?php endif; ?>
            <a class="btn btn-primary" href="orders.php?path=admin/orders&amp;search=<?= urlencode($filteredOrderLabel ?? '') ?>">Back to orders</a>
        </div>
    <?php endif; ?>

    <div class="invoice-table">
        <table class="invoice-list">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Totals</th>
                    <th>Collected / Balance</th>
                    <th>Status</th>
                    <th>Issued</th>
                    <th style="width: 160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$invoices): ?>
                    <tr>
                        <td colspan="7" class="empty-state">No invoices found. Adjust your filters or create a new invoice.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $paidUsd = (float)($invoice['paid_usd'] ?? 0);
                        $paidLbp = (float)($invoice['paid_lbp'] ?? 0);
                        $totalUsd = (float)($invoice['total_usd'] ?? 0);
                        $totalLbp = (float)($invoice['total_lbp'] ?? 0);
                        $balanceUsd = max($totalUsd - $paidUsd, 0);
                        $balanceLbp = max($totalLbp - $paidLbp, 0);
                        $issuedAt = $invoice['issued_at'] ? date('Y-m-d', strtotime($invoice['issued_at'])) : '—';
                        $status = $invoice['status'] ?? 'draft';
                        $statusClass = $statusBadgeClasses[$status] ?? 'badge-neutral';
                        ?>
                        <tr<?= $orderFilter > 0 && (int)($invoice['order_id'] ?? 0) === $orderFilter ? ' class="selected-order"' : '' ?>>
                            <td>
                                <strong><?= htmlspecialchars($invoice['invoice_number'] ?? 'Invoice #' . $invoice['id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($invoice['order_number'])): ?>
                                    <div class="muted">Order: <?= htmlspecialchars($invoice['order_number'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!empty($invoice['sales_rep_name'])): ?>
                                    <div class="muted">Rep: <?= htmlspecialchars($invoice['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($invoice['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($invoice['customer_phone'])): ?>
                                    <div class="muted"><?= htmlspecialchars($invoice['customer_phone'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="amount-stack">
                                    <strong>$<?= number_format($totalUsd, 2) ?></strong>
                                    <span>ل.ل <?= number_format($totalLbp, 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="amount-stack">
                                    <strong>Collected: $<?= number_format($paidUsd, 2) ?></strong>
                                    <span>Balance: $<?= number_format($balanceUsd, 2) ?></span>
                                </div>
                                <div class="amount-stack" style="margin-top:6px;">
                                    <strong>Collected: ل.ل <?= number_format($paidLbp, 0) ?></strong>
                                    <span>Balance: ل.ل <?= number_format($balanceLbp, 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabels[$status] ?? ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($invoice['last_payment_at'])): ?>
                                    <div class="muted">Last payment <?= htmlspecialchars(date('Y-m-d', strtotime($invoice['last_payment_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($issuedAt, ENT_QUOTES, 'UTF-8') ?><br>
                                <span class="muted">Created <?= htmlspecialchars(date('Y-m-d', strtotime($invoice['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td>
                                <div class="amount-stack" style="gap:8px;">
                                    <a class="btn btn-primary" href="invoices_view.php?id=<?= (int)$invoice['id'] ?>">View</a>
                                    <a class="btn" href="invoices_export.php?id=<?= (int)$invoice['id'] ?>">Download PDF</a>
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
                <a href="?path=admin/invoices&page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $customerFilter ? '&customer=' . urlencode((string)$customerFilter) : '' ?><?= $repFilter ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">First</a>
                <a href="?path=admin/invoices&page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $customerFilter ? '&customer=' . urlencode((string)$customerFilter) : '' ?><?= $repFilter ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">Prev</a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <?php if ($i === $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?path=admin/invoices&page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $customerFilter ? '&customer=' . urlencode((string)$customerFilter) : '' ?><?= $repFilter ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?path=admin/invoices&page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $customerFilter ? '&customer=' . urlencode((string)$customerFilter) : '' ?><?= $repFilter ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">Next</a>
                <a href="?path=admin/invoices&page=<?= $totalPages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $customerFilter ? '&customer=' . urlencode((string)$customerFilter) : '' ?><?= $repFilter ? '&rep=' . urlencode((string)$repFilter) : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">Last</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php
admin_render_layout_end();
