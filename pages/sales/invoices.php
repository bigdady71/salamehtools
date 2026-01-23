<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/admin_page.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/audit.php';

// Sales rep authentication
require_login();
$user = auth_user();
if (!$user || ($user['role'] ?? '') !== 'sales_rep') {
    http_response_code(403);
    echo 'Forbidden - Sales representatives only';
    exit;
}

$pdo = db();
$title = 'Sales · Invoices';
$repId = (int)$user['id'];

$statusLabels = [
    'draft' => 'Draft',
    'pending' => 'Pending',
    'issued' => 'Issued',
    'paid' => 'Paid',
    'voided' => 'Voided',
];

$statusBadgeStyles = [
    'draft' => 'background: rgba(156, 163, 175, 0.15); color: #4b5563;',
    'pending' => 'background: rgba(251, 191, 36, 0.15); color: #b45309;',
    'issued' => 'background: rgba(59, 130, 246, 0.15); color: #1d4ed8;',
    'paid' => 'background: rgba(34, 197, 94, 0.15); color: #15803d;',
    'voided' => 'background: rgba(239, 68, 68, 0.15); color: #991b1b;',
];

// Handle POST requests (payment recording)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Record payment
    if ($action === 'record_payment') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $amountUsd = (float)($_POST['amount_usd'] ?? 0);
        $amountLbp = (float)($_POST['amount_lbp'] ?? 0);
        $method = $_POST['method'] ?? 'cash';
        $receivedAt = $_POST['received_at'] ?? date('Y-m-d H:i:s');

        $validMethods = ['cash_usd', 'cash_lbp', 'cash', 'qr_cash', 'card', 'bank', 'other'];
        if (!in_array($method, $validMethods, true)) {
            $method = 'cash_usd';
        }

        if ($invoiceId > 0 && ($amountUsd > 0 || $amountLbp > 0)) {
            try {
                $pdo->beginTransaction();

                // Verify invoice belongs to sales rep's customer
                $invoiceStmt = $pdo->prepare("
                    SELECT
                        i.id,
                        i.invoice_number,
                        i.status,
                        i.total_usd,
                        i.total_lbp,
                        c.assigned_sales_rep_id,
                        COALESCE(SUM(p.amount_usd), 0) AS paid_usd,
                        COALESCE(SUM(p.amount_lbp), 0) AS paid_lbp
                    FROM invoices i
                    INNER JOIN orders o ON o.id = i.order_id
                    INNER JOIN customers c ON c.id = o.customer_id
                    LEFT JOIN payments p ON p.invoice_id = i.id
                    WHERE i.id = :id AND c.assigned_sales_rep_id = :rep_id
                    GROUP BY i.id
                ");
                $invoiceStmt->execute([':id' => $invoiceId, ':rep_id' => $repId]);
                $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

                if (!$invoice) {
                    flash('error', 'Invoice not found or you do not have permission to record payments for this invoice.');
                    $pdo->rollBack();
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }

                if ($invoice['status'] === 'voided') {
                    flash('error', 'Cannot record payment for voided invoice.');
                    $pdo->rollBack();
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }

                // Check if payment would exceed invoice total
                $newPaidUsd = (float)$invoice['paid_usd'] + $amountUsd;
                $newPaidLbp = (float)$invoice['paid_lbp'] + $amountLbp;
                $totalUsd = (float)$invoice['total_usd'];
                $totalLbp = (float)$invoice['total_lbp'];

                // Calculate remaining balance
                $balanceUsd = max(0, $totalUsd - (float)$invoice['paid_usd']);
                $balanceLbp = max(0, $totalLbp - (float)$invoice['paid_lbp']);

                // Reject payment if it exceeds the remaining balance (with 0.01 tolerance)
                if ($amountUsd > $balanceUsd + 0.01 || $amountLbp > $balanceLbp + 0.01) {
                    $balanceMsg = [];
                    if ($balanceUsd > 0.01) $balanceMsg[] = '$' . number_format($balanceUsd, 2);
                    if ($balanceLbp > 0.01) $balanceMsg[] = number_format($balanceLbp, 0) . ' LBP';

                    flash('error', sprintf(
                        'Payment rejected: Amount exceeds remaining balance of %s for invoice %s.',
                        implode(' + ', $balanceMsg),
                        $invoice['invoice_number']
                    ));
                    $pdo->rollBack();
                    header('Location: ' . $_SERVER['REQUEST_URI']);
                    exit;
                }

                // Insert payment
                $paymentStmt = $pdo->prepare("
                    INSERT INTO payments (invoice_id, method, amount_usd, amount_lbp, received_by_user_id, received_at)
                    VALUES (:invoice_id, :method, :amount_usd, :amount_lbp, :user_id, :received_at)
                ");
                $paymentStmt->execute([
                    ':invoice_id' => $invoiceId,
                    ':method' => $method,
                    ':amount_usd' => $amountUsd,
                    ':amount_lbp' => $amountLbp,
                    ':user_id' => $repId,
                    ':received_at' => $receivedAt
                ]);
                $paymentId = (int)$pdo->lastInsertId();

                // Audit log: payment recorded
                audit_log($pdo, $repId, 'payment_recorded', 'payments', $paymentId, [
                    'invoice_number' => $invoice['invoice_number'],
                    'invoice_id' => $invoiceId,
                    'amount_usd' => $amountUsd,
                    'amount_lbp' => $amountLbp,
                    'method' => $method,
                    'received_at' => $receivedAt
                ]);

                // Auto-update invoice status to paid if fully paid
                // Invoice is paid if EITHER USD is fully paid OR LBP is fully paid (they represent the same debt)
                $isFullyPaidUsd = $newPaidUsd >= $totalUsd - 0.01;
                $isFullyPaidLbp = $newPaidLbp >= $totalLbp - 0.01;

                if (($isFullyPaidUsd || $isFullyPaidLbp) && $invoice['status'] !== 'paid') {
                    $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = :id")
                        ->execute([':id' => $invoiceId]);

                    $paymentMsg = [];
                    if ($amountUsd > 0) $paymentMsg[] = '$' . number_format($amountUsd, 2);
                    if ($amountLbp > 0) $paymentMsg[] = number_format($amountLbp, 0) . ' LBP';

                    flash('success', sprintf(
                        'Payment of %s recorded for invoice %s via %s. Invoice marked as PAID.',
                        implode(' + ', $paymentMsg),
                        $invoice['invoice_number'],
                        ucfirst(str_replace('_', ' ', $method))
                    ));
                } else {
                    $paymentMsg = [];
                    if ($amountUsd > 0) $paymentMsg[] = '$' . number_format($amountUsd, 2);
                    if ($amountLbp > 0) $paymentMsg[] = number_format($amountLbp, 0) . ' LBP';

                    $remainingUsd = max(0, $totalUsd - $newPaidUsd);
                    $remainingLbp = max(0, $totalLbp - $newPaidLbp);
                    $balanceMsg = [];
                    if ($remainingUsd > 0.01) $balanceMsg[] = '$' . number_format($remainingUsd, 2);
                    if ($remainingLbp > 0.01) $balanceMsg[] = number_format($remainingLbp, 0) . ' LBP';

                    flash('success', sprintf(
                        'Payment of %s recorded for invoice %s via %s. Remaining balance: %s',
                        implode(' + ', $paymentMsg),
                        $invoice['invoice_number'],
                        ucfirst(str_replace('_', ' ', $method)),
                        !empty($balanceMsg) ? implode(' + ', $balanceMsg) : 'Fully paid'
                    ));
                }

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Failed to record payment: ' . $e->getMessage());
            }
        } else {
            flash('error', 'Invalid payment details. Please enter at least one currency amount.');
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
    $customerFilter = (int)($_GET['customer'] ?? 0);
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    $where = ['c.assigned_sales_rep_id = :rep_id'];
    $params = [':rep_id' => $repId];

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

    if ($dateFrom !== '') {
        $fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom);
        if ($fromDate && $fromDate->format('Y-m-d') === $dateFrom) {
            $where[] = "DATE(i.issued_at) >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
    }

    if ($dateTo !== '') {
        $toDate = DateTime::createFromFormat('Y-m-d', $dateTo);
        if ($toDate && $toDate->format('Y-m-d') === $dateTo) {
            $where[] = "DATE(i.issued_at) <= :date_to";
            $params[':date_to'] = $dateTo;
        }
    }

    $whereClause = implode(' AND ', $where);

    // Export query
    $exportStmt = $pdo->prepare("
        SELECT
            i.invoice_number,
            o.order_number,
            c.name as customer_name,
            c.phone as customer_phone,
            c.location as customer_location,
            i.status,
            i.total_usd,
            i.total_lbp,
            COALESCE(SUM(p.amount_usd), 0) AS paid_usd,
            COALESCE(SUM(p.amount_lbp), 0) AS paid_lbp,
            (i.total_usd - COALESCE(SUM(p.amount_usd), 0)) AS balance_usd,
            (i.total_lbp - COALESCE(SUM(p.amount_lbp), 0)) AS balance_lbp,
            i.issued_at,
            i.due_date,
            i.created_at
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN customers c ON c.id = o.customer_id
        LEFT JOIN payments p ON p.invoice_id = i.id
        WHERE {$whereClause}
        GROUP BY i.id
        ORDER BY i.issued_at DESC, i.id DESC
    ");

    foreach ($params as $key => $value) {
        $exportStmt->bindValue($key, $value);
    }
    $exportStmt->execute();
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=my_invoices_' . date('Y-m-d_His') . '.csv');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // Headers
    fputcsv($output, [
        'Invoice Number',
        'Order Number',
        'Customer Name',
        'Customer Phone',
        'Customer Location',
        'Status',
        'Total (USD)',
        'Total (LBP)',
        'Paid (USD)',
        'Paid (LBP)',
        'Balance (USD)',
        'Balance (LBP)',
        'Issued Date',
        'Due Date',
        'Created Date'
    ]);

    // Data rows
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['invoice_number'],
            $row['order_number'],
            $row['customer_name'],
            $row['customer_phone'] ?? '',
            $row['customer_location'] ?? '',
            ucfirst($row['status']),
            number_format((float)$row['total_usd'], 2),
            number_format((float)$row['total_lbp'], 0),
            number_format((float)$row['paid_usd'], 2),
            number_format((float)$row['paid_lbp'], 0),
            number_format((float)$row['balance_usd'], 2),
            number_format((float)$row['balance_lbp'], 0),
            $row['issued_at'] ? date('Y-m-d', strtotime($row['issued_at'])) : '',
            $row['due_date'] ? date('Y-m-d', strtotime($row['due_date'])) : '',
            $row['created_at'] ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : ''
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
$customerFilter = (int)($_GET['customer'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = ['c.assigned_sales_rep_id = :rep_id'];
$params = [':rep_id' => $repId];

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

// Get total count
$countSql = "
    SELECT COUNT(*)
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
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

// Get invoices
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
        pay.paid_usd,
        pay.paid_lbp,
        pay.last_payment_at
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
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

// Get summary statistics
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(i.id) AS total_invoices,
        SUM(CASE WHEN i.status = 'issued' THEN 1 ELSE 0 END) AS issued_count,
        SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE
            WHEN i.status = 'paid' THEN 0
            ELSE i.total_usd - COALESCE(pay.paid_usd, 0)
        END) AS outstanding_usd,
        SUM(CASE
            WHEN i.status = 'paid' THEN 0
            ELSE i.total_lbp - COALESCE(pay.paid_lbp, 0)
        END) AS outstanding_lbp
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    INNER JOIN customers c ON c.id = o.customer_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) AS paid_usd, SUM(amount_lbp) AS paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) pay ON pay.invoice_id = i.id
    WHERE c.assigned_sales_rep_id = :rep_id
      AND i.status IN ('issued', 'paid')
");
$statsStmt->execute([':rep_id' => $repId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get customer list for filter dropdown
$customersStmt = $pdo->prepare("
    SELECT id, name
    FROM customers
    WHERE assigned_sales_rep_id = :rep_id AND is_active = 1
    ORDER BY name ASC
");
$customersStmt->execute([':rep_id' => $repId]);
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$flashes = consume_flashes();

$extraHead = <<<'HTML'
<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
}
.stat-label {
    color: var(--muted);
    font-size: 0.9rem;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text);
}
.stat-value.green { color: #15803d; }
.stat-value.blue { color: #1d4ed8; }
.stat-value.orange { color: #ea580c; }
.filters-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 24px;
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}
.filter-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text);
}
.filter-field input,
.filter-field select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
    transition: border-color 0.2s;
}
.filter-field input:focus,
.filter-field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}
.filter-actions {
    display: flex;
    gap: 10px;
    align-items: end;
}
.table-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px;
    overflow-x: auto;
}
table {
    width: 100%;
    border-collapse: collapse;
}
thead tr {
    border-bottom: 2px solid var(--border);
}
th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
}
th.text-right { text-align: right; }
th.text-center { text-align: center; }
tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background 0.2s;
}
tbody tr:hover {
    background: var(--bg-panel-alt);
}
td {
    padding: 14px 12px;
    font-size: 0.95rem;
}
td.text-right { text-align: right; }
td.text-center { text-align: center; }
.invoice-number {
    font-weight: 600;
    color: var(--accent);
}
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--muted);
}
.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 16px;
    opacity: 0.3;
}
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 24px;
}
.pagination a,
.pagination span {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    text-decoration: none;
    color: var(--text);
    background: var(--bg-panel);
}
.pagination a:hover {
    background: var(--bg-panel-alt);
    border-color: var(--accent);
}
.pagination span.current {
    background: var(--accent);
    color: #fff;
    font-weight: 600;
}
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal.active {
    display: flex;
}
.modal-content {
    background: #fff;
    border-radius: 16px;
    padding: 32px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}
.modal-header h3 {
    margin: 0;
    font-size: 1.5rem;
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--muted);
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}
.modal-close:hover {
    background: var(--bg-panel-alt);
    color: var(--text);
}
.form-field {
    margin-bottom: 20px;
}
.form-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 0.9rem;
}
.form-field input,
.form-field select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.95rem;
}
.form-field input:focus,
.form-field select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
}
.form-field small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 0.85rem;
}
.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
}
</style>
HTML;

sales_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Invoices & Collections',
    'subtitle' => 'Manage invoices and record payments for your customers',
    'user' => $user,
    'active' => 'invoices',
    'extra_head' => $extraHead,
]);

admin_render_flashes($flashes);
?>

<!-- LBP Currency Toggle -->
<div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-weight: 600; color: #374151; font-size: 0.95rem;">Show LBP Values</span>
        <label class="currency-toggle-switch" style="position: relative; display: inline-block; width: 52px; height: 28px;">
            <input type="checkbox" id="lbpToggle" style="opacity: 0; width: 0; height: 0;">
            <span class="currency-toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; border-radius: 28px; transition: 0.3s;"></span>
        </label>
    </div>
    <small style="color: #6b7280; font-size: 0.85rem;">Toggle to show/hide Lebanese Pound values (Rate: 9,000 LBP = $1 USD)</small>
</div>

<style>
    .currency-toggle-switch input:checked + .currency-toggle-slider {
        background-color: #6666ff;
    }
    .currency-toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        border-radius: 50%;
        transition: 0.3s;
    }
    .currency-toggle-switch input:checked + .currency-toggle-slider:before {
        transform: translateX(24px);
    }

    .lbp-value {
        display: none;
    }
    body.show-lbp .lbp-value {
        display: inline;
    }
</style>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Invoices</div>
        <div class="stat-value"><?= number_format((int)($stats['total_invoices'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Issued</div>
        <div class="stat-value blue"><?= number_format((int)($stats['issued_count'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Paid</div>
        <div class="stat-value green"><?= number_format((int)($stats['paid_count'] ?? 0)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Outstanding</div>
        <div class="stat-value orange">
            $<?= number_format((float)($stats['outstanding_usd'] ?? 0), 2) ?>
            <?php if ((float)($stats['outstanding_lbp'] ?? 0) > 0): ?>
                <div class="lbp-value" style="font-size: 1rem; font-weight: 400; margin-top: 4px;">
                    <?= number_format((float)$stats['outstanding_lbp'], 0) ?> LBP
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filters-card">
    <form method="GET" action="invoices.php">
        <div class="filter-grid">
            <div class="filter-field">
                <label>Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Invoice #, order #, customer...">
            </div>

            <div class="filter-field">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label>Customer</label>
                <select name="customer">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id'] ?>" <?= $customerFilter === (int)$customer['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="filter-field">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-info">Apply Filters</button>
            <a href="invoices.php" class="btn btn-secondary">Clear</a>
            <?php
            // Build export URL with current filters
            $exportUrl = '?action=export';
            if ($search !== '') {
                $exportUrl .= '&search=' . urlencode($search);
            }
            if ($statusFilter !== '') {
                $exportUrl .= '&status=' . urlencode($statusFilter);
            }
            if ($customerFilter > 0) {
                $exportUrl .= '&customer=' . $customerFilter;
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

<!-- Invoices Table -->
<div class="table-card">
    <h2 style="margin: 0 0 20px 0; font-size: 1.3rem;">
        Invoices
        <span style="color: var(--muted); font-weight: 400; font-size: 1rem;">(<?= number_format($totalMatches) ?> total)</span>
    </h2>

    <?php if (empty($invoices)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📄</div>
            <h3 style="margin: 0 0 8px 0;">No invoices found</h3>
            <p style="margin: 0; color: var(--muted);">
                <?= $search || $statusFilter || $customerFilter || $dateFrom || $dateTo
                    ? 'Try adjusting your filters'
                    : 'Invoices will appear here when orders are processed' ?>
            </p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Order #</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Balance</th>
                    <th class="text-center">Status</th>
                    <th>Issued</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $invoice):
                    $balanceUsd = max(0, (float)$invoice['total_usd'] - (float)($invoice['paid_usd'] ?? 0));
                    $balanceLbp = max(0, (float)$invoice['total_lbp'] - (float)($invoice['paid_lbp'] ?? 0));
                    $issuedDate = $invoice['issued_at'] ? date('M d, Y', strtotime($invoice['issued_at'])) : '—';
                ?>
                <tr>
                    <td>
                        <span class="invoice-number"><?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td>
                        <?= htmlspecialchars($invoice['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($invoice['customer_phone']): ?>
                            <br><small style="color: var(--muted);"><?= htmlspecialchars($invoice['customer_phone'], ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($invoice['order_number']): ?>
                            <?= htmlspecialchars($invoice['order_number'], ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span style="color: var(--muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <strong>$<?= number_format((float)$invoice['total_usd'], 2) ?></strong>
                        <?php if ((float)$invoice['total_lbp'] > 0): ?>
                            <br><small class="lbp-value" style="color: var(--muted);"><?= number_format((float)$invoice['total_lbp'], 0) ?> LBP</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if ((float)($invoice['paid_usd'] ?? 0) > 0 || (float)($invoice['paid_lbp'] ?? 0) > 0): ?>
                            $<?= number_format((float)($invoice['paid_usd'] ?? 0), 2) ?>
                            <?php if ((float)($invoice['paid_lbp'] ?? 0) > 0): ?>
                                <br><small class="lbp-value" style="color: var(--muted);"><?= number_format((float)$invoice['paid_lbp'], 0) ?> LBP</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: var(--muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php
                        // Show "Paid" if status is paid OR if either currency is fully paid (since they represent the same debt)
                        $paidInFullUsd = (float)($invoice['paid_usd'] ?? 0) >= (float)$invoice['total_usd'] - 0.01;
                        $paidInFullLbp = (float)($invoice['paid_lbp'] ?? 0) >= (float)$invoice['total_lbp'] - 0.01;
                        $isFullyPaid = $invoice['status'] === 'paid' || $paidInFullUsd || $paidInFullLbp;
                        ?>
                        <?php if (!$isFullyPaid): ?>
                            <strong style="color: #ea580c;">$<?= number_format($balanceUsd, 2) ?></strong>
                            <?php if ($balanceLbp > 0.01): ?>
                                <br><small class="lbp-value" style="color: #ea580c;"><?= number_format($balanceLbp, 0) ?> LBP</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #15803d; font-weight: 600;">Paid</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge" style="<?= $statusBadgeStyles[$invoice['status']] ?? '' ?>">
                            <?= htmlspecialchars($statusLabels[$invoice['status']] ?? ucfirst($invoice['status']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td><?= $issuedDate ?></td>
                    <td class="text-center">
                        <?php if ($invoice['status'] !== 'voided' && $invoice['status'] !== 'draft' && $invoice['status'] !== 'paid' && ($balanceUsd > 0.01 || $balanceLbp > 0.01)): ?>
                            <button onclick="openPaymentModal(<?= $invoice['id'] ?>, '<?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?>', <?= $balanceUsd ?>, <?= $balanceLbp ?>)"
                                    class="btn btn-success btn-sm">
                                Record Payment
                            </button>
                        <?php else: ?>
                            <span style="color: var(--muted); font-size: 0.85rem;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $customerFilter ? '&customer=' . $customerFilter : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">
                        ← Previous
                    </a>
                <?php endif; ?>

                <span class="current">Page <?= $page ?> of <?= $totalPages ?></span>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $statusFilter ? '&status=' . urlencode($statusFilter) : '' ?><?= $customerFilter ? '&customer=' . $customerFilter : '' ?><?= $dateFrom ? '&date_from=' . urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to=' . urlencode($dateTo) : '' ?>">
                        Next →
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Record Payment</h3>
            <button type="button" class="modal-close" onclick="closePaymentModal()">&times;</button>
        </div>

        <form method="POST" action="invoices.php" onsubmit="return validatePayment()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="invoice_id" id="payment_invoice_id">

            <div style="background: var(--bg-panel-alt); padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                <div style="font-weight: 600; margin-bottom: 4px;">Invoice: <span id="payment_invoice_number"></span></div>
                <div style="color: var(--muted); font-size: 0.9rem;">
                    Balance: $<span id="payment_balance_usd"></span>
                    <span id="payment_balance_lbp_container" class="lbp-value"></span>
                </div>
            </div>

            <div class="form-field">
                <label>Payment Method</label>
                <select name="method" required>
                    <option value="cash_usd">Cash (USD)</option>
                    <option value="cash_lbp">Cash (LBP)</option>
                    <option value="qr_cash">QR / Cash App</option>
                    <option value="card">Card</option>
                    <option value="bank">Bank Transfer</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-field">
                <label>Amount (USD)</label>
                <input type="number" name="amount_usd" id="amount_usd" step="0.01" min="0" placeholder="0.00" onchange="convertUsdToLbp()">
                <small>Enter payment amount in US dollars (will auto-convert to LBP)</small>
            </div>

            <div class="form-field">
                <label>Amount (LBP)</label>
                <input type="number" name="amount_lbp" id="amount_lbp" step="1" min="0" placeholder="0" onchange="convertLbpToUsd()">
                <small>Enter payment amount in Lebanese pounds (Rate: 9,000 LBP = $1 USD)</small>
            </div>

            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px; margin-bottom: 16px;">
                <div style="font-size: 0.875rem; color: #1e40af; margin-bottom: 4px;">
                    <strong>💡 Tip:</strong> Enter amount in either currency
                </div>
                <div style="font-size: 0.8125rem; color: #3b82f6;">
                    The other currency will be automatically calculated at 9,000 LBP = $1 USD
                </div>
            </div>

            <div class="form-field">
                <label>Received Date & Time</label>
                <input type="datetime-local" name="received_at" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
// Store current invoice balance for validation
let currentBalanceUsd = 0;
let currentBalanceLbp = 0;

function openPaymentModal(invoiceId, invoiceNumber, balanceUsd, balanceLbp) {
    currentBalanceUsd = balanceUsd;
    currentBalanceLbp = balanceLbp;

    document.getElementById('payment_invoice_id').value = invoiceId;
    document.getElementById('payment_invoice_number').textContent = invoiceNumber;
    document.getElementById('payment_balance_usd').textContent = balanceUsd.toFixed(2);

    const lbpContainer = document.getElementById('payment_balance_lbp_container');
    if (balanceLbp > 0.01) {
        lbpContainer.textContent = ' + ' + balanceLbp.toFixed(0) + ' LBP';
        lbpContainer.style.display = 'inline';
    } else {
        lbpContainer.style.display = 'none';
    }

    // Set max values for inputs
    document.getElementById('amount_usd').max = balanceUsd.toFixed(2);
    document.getElementById('amount_lbp').max = balanceLbp.toFixed(0);

    // Reset input values
    document.getElementById('amount_usd').value = '';
    document.getElementById('amount_lbp').value = '';

    document.getElementById('paymentModal').classList.add('active');
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('active');
}

function validatePayment() {
    const usdInput = document.getElementById('amount_usd');
    const lbpInput = document.getElementById('amount_lbp');
    const usdValue = parseFloat(usdInput.value) || 0;
    const lbpValue = parseFloat(lbpInput.value) || 0;

    // Check if at least one amount is entered
    if (usdValue <= 0 && lbpValue <= 0) {
        alert('Please enter a payment amount in USD or LBP.');
        return false;
    }

    // Validate USD amount doesn't exceed balance
    if (usdValue > currentBalanceUsd + 0.01) {
        alert('USD payment amount ($' + usdValue.toFixed(2) + ') exceeds remaining balance ($' + currentBalanceUsd.toFixed(2) + ')');
        return false;
    }

    // Validate LBP amount doesn't exceed balance
    if (lbpValue > currentBalanceLbp + 0.01) {
        alert('LBP payment amount (' + lbpValue.toFixed(0) + ' LBP) exceeds remaining balance (' + currentBalanceLbp.toFixed(0) + ' LBP)');
        return false;
    }

    return true;
}

// Close modal when clicking outside
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePaymentModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePaymentModal();
    }
});

// Currency conversion functions
const EXCHANGE_RATE = 9000; // 9000 LBP = 1 USD

function convertUsdToLbp() {
    const usdInput = document.getElementById('amount_usd');
    const lbpInput = document.getElementById('amount_lbp');
    let usdValue = parseFloat(usdInput.value) || 0;

    // Validate against balance
    if (usdValue > currentBalanceUsd) {
        usdValue = currentBalanceUsd;
        usdInput.value = usdValue.toFixed(2);
        alert('Payment amount cannot exceed remaining balance of $' + currentBalanceUsd.toFixed(2));
    }

    if (usdValue > 0) {
        lbpInput.value = Math.round(usdValue * EXCHANGE_RATE);
    }
}

function convertLbpToUsd() {
    const usdInput = document.getElementById('amount_usd');
    const lbpInput = document.getElementById('amount_lbp');
    let lbpValue = parseFloat(lbpInput.value) || 0;

    // Validate against balance
    if (lbpValue > currentBalanceLbp) {
        lbpValue = currentBalanceLbp;
        lbpInput.value = Math.round(lbpValue);
        alert('Payment amount cannot exceed remaining balance of ' + currentBalanceLbp.toFixed(0) + ' LBP');
    }

    if (lbpValue > 0) {
        usdInput.value = (lbpValue / EXCHANGE_RATE).toFixed(2);
    }
}

// LBP Toggle functionality
(function() {
    const lbpToggle = document.getElementById('lbpToggle');
    const savedState = localStorage.getItem('showLBP');

    // Restore saved state
    if (savedState === 'true') {
        lbpToggle.checked = true;
        document.body.classList.add('show-lbp');
    }

    // Toggle on change
    lbpToggle.addEventListener('change', function() {
        if (this.checked) {
            document.body.classList.add('show-lbp');
            localStorage.setItem('showLBP', 'true');
        } else {
            document.body.classList.remove('show-lbp');
            localStorage.setItem('showLBP', 'false');
        }
    });
})();
</script>

<?php sales_portal_render_layout_end(); ?>
