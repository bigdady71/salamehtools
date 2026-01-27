<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';

$user = require_accounting_access();
$pdo = db();

// Filters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$salesRepId = trim($_GET['sales_rep'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Get sales reps for filter
$salesRepsStmt = $pdo->query("SELECT id, name FROM users WHERE role = 'sales_rep' ORDER BY name");
$salesReps = $salesRepsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build conditions
$conditions = ['1=1'];
$params = [];

if ($search !== '') {
    $conditions[] = "(i.invoice_number LIKE :search OR c.name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($status !== '') {
    $conditions[] = "i.status = :status";
    $params[':status'] = $status;
}

if ($salesRepId !== '') {
    $conditions[] = "o.sales_rep_id = :sales_rep_id";
    $params[':sales_rep_id'] = $salesRepId;
}

if ($dateFrom !== '') {
    $conditions[] = "i.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $conditions[] = "i.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $conditions);

// Get totals
$totalsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_invoices,
        COALESCE(SUM(i.total_usd), 0) as total_usd,
        COALESCE(SUM(i.total_lbp), 0) as total_lbp,
        SUM(CASE WHEN i.status = 'issued' THEN 1 ELSE 0 END) as issued_count,
        SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) as paid_count
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    JOIN customers c ON c.id = o.customer_id
    WHERE $whereClause
");
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

$totalInvoices = (int)$totals['total_invoices'];
$totalPages = ceil($totalInvoices / $perPage);

// Get invoices with payment info
$stmt = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.created_at,
        i.total_usd,
        i.total_lbp,
        i.status,
        o.order_number,
        c.id as customer_id,
        c.name as customer_name,
        c.phone as customer_phone,
        COALESCE(u.name, 'N/A') as sales_rep_name,
        COALESCE(p.paid_usd, 0) as paid_usd,
        COALESCE(p.paid_lbp, 0) as paid_lbp,
        (i.total_usd - COALESCE(p.paid_usd, 0)) as outstanding_usd
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = o.sales_rep_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
        FROM payments GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE $whereClause
    ORDER BY i.created_at DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $pdo->prepare("
        SELECT
            i.invoice_number,
            DATE(i.created_at) as invoice_date,
            c.name as customer,
            COALESCE(u.name, 'N/A') as sales_rep,
            i.total_usd,
            i.total_lbp,
            COALESCE(p.paid_usd, 0) as paid_usd,
            (i.total_usd - COALESCE(p.paid_usd, 0)) as outstanding_usd,
            i.status
        FROM invoices i
        JOIN orders o ON o.id = i.order_id
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users u ON u.id = o.sales_rep_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd FROM payments GROUP BY invoice_id
        ) p ON p.invoice_id = i.id
        WHERE $whereClause
        ORDER BY i.created_at DESC
    ");
    foreach ($params as $key => $value) {
        $exportStmt->bindValue($key, $value);
    }
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="invoices_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Invoice #', 'Date', 'Customer', 'Sales Rep', 'Total USD', 'Total LBP', 'Paid USD', 'Outstanding USD', 'Status']);

    foreach ($exportData as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

accounting_render_layout_start([
    'title' => 'Invoices',
    'heading' => 'Invoices',
    'subtitle' => 'View and manage all invoices',
    'active' => 'invoices',
    'user' => $user,
]);

accounting_render_flashes(consume_flashes());
?>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Total Invoices</div>
        <div class="value"><?= number_format($totalInvoices) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Total Value (USD)</div>
        <div class="value"><?= format_currency_usd((float)$totals['total_usd']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Issued</div>
        <div class="value" style="color: #3b82f6;"><?= number_format((int)$totals['issued_count']) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Paid</div>
        <div class="value text-success"><?= number_format((int)$totals['paid_count']) ?></div>
    </div>
</div>

<div class="card">
    <form method="GET" class="filters">
        <input type="text" name="search" placeholder="Search invoice # or customer..." value="<?= htmlspecialchars($search) ?>" class="filter-input" style="width: 200px;">

        <select name="status" class="filter-input">
            <option value="">All Status</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>Issued</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="voided" <?= $status === 'voided' ? 'selected' : '' ?>>Voided</option>
        </select>

        <select name="sales_rep" class="filter-input">
            <option value="">All Sales Reps</option>
            <?php foreach ($salesReps as $rep): ?>
                <option value="<?= (int)$rep['id'] ?>" <?= $salesRepId == $rep['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rep['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="filter-input" placeholder="From">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="filter-input" placeholder="To">

        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="invoices.php" class="btn">Clear</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn" style="margin-left: auto;">Export CSV</a>
        <a href="pdf_archive.php" class="btn" style="background: #dc2626; color: white;">📁 PDF Archive</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Sales Rep</th>
                <th class="text-right">Total</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Outstanding</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted">No invoices found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                    <?php
                    $outstanding = (float)$invoice['outstanding_usd'];
                    $statusBadge = match($invoice['status']) {
                        'draft' => 'badge-neutral',
                        'pending' => 'badge-warning',
                        'issued' => 'badge-info',
                        'paid' => 'badge-success',
                        'voided' => 'badge-danger',
                        default => 'badge-neutral'
                    };
                    ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($invoice['invoice_number'] ?? '-') ?></code>
                            <?php if ($invoice['order_number']): ?>
                                <br><small class="text-muted">Order: <?= htmlspecialchars($invoice['order_number']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M j, Y', strtotime($invoice['created_at'])) ?></td>
                        <td>
                            <?= htmlspecialchars($invoice['customer_name']) ?>
                            <?php if ($invoice['customer_phone']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($invoice['customer_phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($invoice['sales_rep_name']) ?></td>
                        <td class="text-right">
                            <?= format_currency_usd((float)$invoice['total_usd']) ?>
                            <?php if ((float)$invoice['total_lbp'] > 0): ?>
                                <br><small class="text-muted"><?= format_currency_lbp((float)$invoice['total_lbp']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-success"><?= format_currency_usd((float)$invoice['paid_usd']) ?></td>
                        <td class="text-right <?= $outstanding > 0 ? 'text-danger' : '' ?>" style="font-weight: <?= $outstanding > 0 ? '600' : '400' ?>;">
                            <?= format_currency_usd($outstanding) ?>
                        </td>
                        <td>
                            <span class="badge <?= $statusBadge ?>"><?= ucfirst($invoice['status']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
        <div style="margin-top: 20px; display: flex; gap: 8px; justify-content: center;">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-sm">Previous</a>
            <?php endif; ?>

            <span style="padding: 6px 12px; color: var(--muted);">
                Page <?= $page ?> of <?= $totalPages ?>
            </span>

            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-sm">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
accounting_render_layout_end();
