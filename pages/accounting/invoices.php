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

// Get totals (exclude voided invoices from value totals)
$totalsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_invoices,
        COALESCE(SUM(CASE WHEN i.status != 'voided' THEN i.total_usd ELSE 0 END), 0) as total_usd,
        COALESCE(SUM(CASE WHEN i.status != 'voided' THEN i.total_lbp ELSE 0 END), 0) as total_lbp,
        SUM(CASE WHEN i.status = 'issued' THEN 1 ELSE 0 END) as issued_count,
        SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN i.status = 'voided' THEN 1 ELSE 0 END) as voided_count
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
    <p class="mb-1 text-muted" style="font-size: 0.9rem; font-weight: 600;">Search & filter</p>
    <form method="GET" class="filters">
        <input type="text" name="search" placeholder="Invoice # or customer name..." value="<?= htmlspecialchars($search) ?>" class="filter-input" style="min-width: 220px;">

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
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted">No invoices found</td>
                </tr>
            <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                    <?php
                    $outstanding = (float)$invoice['outstanding_usd'];
                    $statusBadge = match ($invoice['status']) {
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
                        <td>
                            <button type="button" class="btn btn-sm invoice-info-btn" data-invoice-id="<?= (int)$invoice['id'] ?>" title="View invoice summary">Info</button>
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

<!-- Invoice Info Modal -->
<div id="invoice-info-modal" class="accounting-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="invoice-info-title">
    <div class="accounting-modal-box">
        <div class="accounting-modal-header">
            <h3 id="invoice-info-title">Invoice summary</h3>
            <button type="button" id="invoice-info-modal-close" class="accounting-modal-close" title="Close" aria-label="Close">&times;</button>
        </div>
        <div id="invoice-info-content" class="accounting-modal-body"></div>
    </div>
</div>

<script>
    (function() {
        var modal = document.getElementById('invoice-info-modal');
        var content = document.getElementById('invoice-info-content');
        var closeBtn = document.getElementById('invoice-info-modal-close');

        function showModal() {
            modal.classList.add('is-open');
        }

        function hideModal() {
            modal.classList.remove('is-open');
        }

        closeBtn.addEventListener('click', hideModal);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) hideModal();
        });

        document.querySelectorAll('.invoice-info-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-invoice-id');
                if (!id) return;
                content.innerHTML = '<p style="color: #6b7280;">Loading…</p>';
                showModal();
                var xhr = new XMLHttpRequest();
                xhr.open('GET', 'invoice_info.php?invoice_id=' + encodeURIComponent(id));
                xhr.onload = function() {
                    if (xhr.status !== 200) {
                        content.innerHTML = '<p style="color: #dc2626;">Failed to load invoice.</p>';
                        return;
                    }
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) {
                            content.innerHTML = '<p style="color: #dc2626;">' + (data.error || 'Error') + '</p>';
                            return;
                        }
                        var html = '<table style="width:100%; border-collapse: collapse; font-size: 0.9rem;">';
                        html += '<tr><td style="padding:6px 8px 6px 0; color:#6b7280;">Invoice #</td><td style="padding:6px 0;"><strong>' + (data.invoice_number || '') + '</strong></td></tr>';
                        html += '<tr><td style="padding:6px 8px 6px 0; color:#6b7280;">Date</td><td style="padding:6px 0;">' + (data.created_at ? new Date(data.created_at).toLocaleDateString() : '') + '</td></tr>';
                        html += '<tr><td style="padding:6px 8px 6px 0; color:#6b7280;">Customer</td><td style="padding:6px 0;">' + (data.customer_name || '') + (data.customer_phone ? ' &middot; ' + data.customer_phone : '') + '</td></tr>';
                        html += '<tr><td style="padding:6px 8px 6px 0; color:#6b7280;">Sales Rep</td><td style="padding:6px 0;">' + (data.sales_rep_name || 'N/A') + '</td></tr>';
                        html += '<tr><td style="padding:6px 8px 6px 0; color:#6b7280;">Status</td><td style="padding:6px 0;">' + (data.status || '') + '</td></tr>';
                        html += '</table>';
                        if (data.items && data.items.length) {
                            html += '<div style="margin-top:16px; border-top:1px solid #e5e7eb; padding-top:12px;"><strong style="color:#374151;">Line items</strong></div>';
                            html += '<table style="width:100%; border-collapse: collapse; margin-top:8px; font-size: 0.85rem;">';
                            html += '<thead><tr style="border-bottom:1px solid #e5e7eb;"><th style="text-align:left; padding:6px 4px;">Item</th><th style="text-align:center; padding:6px 4px;">Qty</th><th style="text-align:right; padding:6px 4px;">Price</th><th style="text-align:right; padding:6px 4px;">Total</th></tr></thead><tbody>';
                            for (var i = 0; i < data.items.length; i++) {
                                var it = data.items[i];
                                html += '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:6px 4px;">' + (it.item_name || '') + (it.sku ? ' <small style="color:#6b7280;">' + it.sku + '</small>' : '') + '</td><td style="text-align:center; padding:6px 4px;">' + (it.quantity || '') + '</td><td style="text-align:right; padding:6px 4px;">$' + parseFloat(it.unit_price_usd || 0).toFixed(2) + '</td><td style="text-align:right; padding:6px 4px;">$' + parseFloat(it.subtotal_usd || 0).toFixed(2) + '</td></tr>';
                            }
                            html += '</tbody></table>';
                        }
                        html += '<div style="margin-top:16px; padding-top:12px; border-top:2px solid #e5e7eb;">';
                        html += '<table style="width:100%; font-size: 0.9rem;"><tr><td style="padding:4px 0; color:#6b7280;">Total (USD)</td><td style="text-align:right; font-weight:600;">$' + parseFloat(data.total_usd || 0).toFixed(2) + '</td></tr>';
                        if (parseFloat(data.total_lbp) > 0) html += '<tr><td style="padding:4px 0; color:#6b7280;">Total (LBP)</td><td style="text-align:right; white-space:nowrap;">' + Number(data.total_lbp).toLocaleString('en') + ' ل.ل.</td></tr>';
                        html += '<tr><td style="padding:4px 0; color:#6b7280;">Paid</td><td style="text-align:right; color:#059669;">$' + parseFloat(data.paid_usd || 0).toFixed(2) + '</td></tr>';
                        html += '<tr><td style="padding:4px 0; color:#6b7280;">Outstanding</td><td style="text-align:right; font-weight:600;">$' + parseFloat(data.outstanding_usd || 0).toFixed(2) + '</td></tr></table></div>';
                        content.innerHTML = html;
                    } catch (e) {
                        content.innerHTML = '<p style="color: #dc2626;">Invalid response.</p>';
                    }
                };
                xhr.onerror = function() {
                    content.innerHTML = '<p style="color: #dc2626;">Network error.</p>';
                };
                xhr.send();
            });
        });
    })();
</script>

<?php
accounting_render_layout_end();
