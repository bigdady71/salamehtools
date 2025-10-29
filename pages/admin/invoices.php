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
        gap: 6px;
    }
    .metric-card .label {
        font-size: 0.8rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .metric-card .value {
        font-size: 1.4rem;
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
        min-width: 180px;
    }
    .filter-input::placeholder {
        color: var(--muted);
    }
    .btn {
        display: inline-flex;
        align-items: center;
        padding: 10px 14px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
    }
    .btn:hover {
        background: rgba(255, 255, 255, 0.15);
    }
    .btn-primary {
        background: var(--accent);
        color: #000;
    }
    .invoice-table {
        overflow-x: auto;
        border-radius: 16px;
        border: 1px solid var(--border);
        background: var(--bg-panel);
    }
    table.invoice-list {
        width: 100%;
        border-collapse: collapse;
    }
    .invoice-list th,
    .invoice-list td {
        padding: 14px 12px;
        border-bottom: 1px solid var(--border);
        text-align: left;
        font-size: 0.92rem;
        vertical-align: top;
    }
    .invoice-list thead {
        background: rgba(255, 255, 255, 0.03);
        text-transform: uppercase;
        letter-spacing: .04em;
        font-size: 0.75rem;
        color: var(--muted);
    }
    .invoice-list tbody tr:hover {
        background: rgba(74, 125, 255, 0.08);
    }
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: .02em;
    }
    .badge-info {
        background: rgba(74, 125, 255, 0.18);
        color: #8ea8ff;
    }
    .badge-success {
        background: rgba(110, 231, 183, 0.2);
        color: #6ee7b7;
    }
    .badge-warning {
        background: rgba(255, 193, 7, 0.26);
        color: #ffd54f;
    }
    .badge-neutral {
        background: rgba(255, 255, 255, 0.08);
        color: var(--muted);
    }
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
        color: #000;
        font-weight: 600;
    }
    .flash {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 16px;
        border: 1px solid rgba(255, 255, 255, 0.12);
    }
    .flash-success {
        background: rgba(0, 255, 136, 0.12);
        color: #64f0b8;
    }
    .flash-error {
        background: rgba(255, 92, 122, 0.12);
        color: #ff9db0;
    }
    .flash-info {
        background: rgba(74, 125, 255, 0.12);
        color: #9ab4ff;
    }
    .flash-warning {
        background: rgba(255, 193, 7, 0.12);
        color: #ffdf7e;
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
                        <tr>
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
