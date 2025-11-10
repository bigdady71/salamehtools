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
    'draft' => 'Pending',
    'pending' => 'Pending',
    'issued' => 'Issued',
    'paid' => 'Paid',
    'voided' => 'Voided',
];

$statusBadgeClasses = [
    'draft' => 'badge-warning',
    'pending' => 'badge-warning',
    'issued' => 'badge-info',
    'paid' => 'badge-success',
    'voided' => 'badge-neutral',
];
/*  */
// Handle POST requests for invoice actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token. Please try again.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    // Change invoice status
    if ($action === 'change_status') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';

        if ($invoiceId > 0 && isset($statusLabels[$newStatus])) {
            try {
                $pdo->beginTransaction();

                // Get current invoice details
                $invoiceStmt = $pdo->prepare("SELECT invoice_number, status, order_id FROM invoices WHERE id = :id");
                $invoiceStmt->execute([':id' => $invoiceId]);
                $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

                if ($invoice) {
                    $oldStatus = $invoice['status'];

                    // Update invoice status
                    $updateStmt = $pdo->prepare("UPDATE invoices SET status = :status, issued_at = CASE WHEN :status = 'issued' AND status = 'draft' THEN NOW() ELSE issued_at END WHERE id = :id");
                    $updateStmt->execute([
                        ':status' => $newStatus,
                        ':id' => $invoiceId
                    ]);

                    $pdo->commit();

                    flash('success', sprintf(
                        'Invoice %s status changed from %s to %s.',
                        $invoice['invoice_number'],
                        $statusLabels[$oldStatus] ?? ucfirst($oldStatus),
                        $statusLabels[$newStatus]
                    ));
                } else {
                    flash('error', 'Invoice not found.');
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Failed to change invoice status: ' . $e->getMessage());
            }
        } else {
            flash('error', 'Invalid status change request.');
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Record payment
    if ($action === 'record_payment') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $amountUsd = (float)($_POST['amount_usd'] ?? 0);
        $amountLbp = (float)($_POST['amount_lbp'] ?? 0);
        $method = $_POST['method'] ?? 'cash';
        $receivedAt = $_POST['received_at'] ?? date('Y-m-d H:i:s');

        $validMethods = ['cash', 'qr_cash', 'card', 'bank', 'other'];
        if (!in_array($method, $validMethods, true)) {
            $method = 'cash';
        }

        if ($invoiceId > 0 && ($amountUsd > 0 || $amountLbp > 0)) {
            try {
                $pdo->beginTransaction();

                // Get invoice details
                $invoiceStmt = $pdo->prepare("
                    SELECT i.invoice_number, i.status, i.total_usd, i.total_lbp,
                           COALESCE(SUM(p.amount_usd), 0) AS paid_usd,
                           COALESCE(SUM(p.amount_lbp), 0) AS paid_lbp
                    FROM invoices i
                    LEFT JOIN payments p ON p.invoice_id = i.id
                    WHERE i.id = :id
                    GROUP BY i.id
                ");
                $invoiceStmt->execute([':id' => $invoiceId]);
                $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

                if (!$invoice) {
                    flash('error', 'Invoice not found.');
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

                if ($newPaidUsd > $totalUsd + 0.01 || $newPaidLbp > $totalLbp + 0.01) {
                    flash('warning', 'Payment amount exceeds invoice total. Payment recorded but may result in overpayment.');
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
                    ':user_id' => (int)$user['id'],
                    ':received_at' => $receivedAt
                ]);

                // Auto-update invoice status to paid if fully paid
                if ($newPaidUsd >= $totalUsd - 0.01 && $newPaidLbp >= $totalLbp - 0.01 && $invoice['status'] !== 'paid') {
                    $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = :id")
                        ->execute([':id' => $invoiceId]);

                    $paymentMsg = [];
                    if ($amountUsd > 0) $paymentMsg[] = 'USD ' . number_format($amountUsd, 2);
                    if ($amountLbp > 0) $paymentMsg[] = 'LBP ' . number_format($amountLbp, 0);

                    flash('success', sprintf(
                        'Payment of %s recorded for invoice %s via %s. Invoice marked as PAID.',
                        implode(' + ', $paymentMsg),
                        $invoice['invoice_number'],
                        ucfirst(str_replace('_', ' ', $method))
                    ));
                } else {
                    $paymentMsg = [];
                    if ($amountUsd > 0) $paymentMsg[] = 'USD ' . number_format($amountUsd, 2);
                    if ($amountLbp > 0) $paymentMsg[] = 'LBP ' . number_format($amountLbp, 0);

                    $remainingUsd = max(0, $totalUsd - $newPaidUsd);
                    $remainingLbp = max(0, $totalLbp - $newPaidLbp);
                    $balanceMsg = [];
                    if ($remainingUsd > 0.01) $balanceMsg[] = 'USD ' . number_format($remainingUsd, 2);
                    if ($remainingLbp > 0.01) $balanceMsg[] = 'LBP ' . number_format($remainingLbp, 0);

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
            flash('error', 'Invalid payment details.');
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    // Void invoice
    if ($action === 'void_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);

        if ($invoiceId > 0) {
            try {
                $pdo->beginTransaction();

                $invoiceStmt = $pdo->prepare("SELECT invoice_number, status FROM invoices WHERE id = :id");
                $invoiceStmt->execute([':id' => $invoiceId]);
                $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

                if ($invoice) {
                    if ($invoice['status'] === 'voided') {
                        flash('warning', 'Invoice is already voided.');
                    } else {
                        $pdo->prepare("UPDATE invoices SET status = 'voided' WHERE id = :id")
                            ->execute([':id' => $invoiceId]);

                        flash('success', sprintf('Invoice %s has been voided.', $invoice['invoice_number']));
                    }
                } else {
                    flash('error', 'Invoice not found.');
                }

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('error', 'Failed to void invoice: ' . $e->getMessage());
            }
        } else {
            flash('error', 'Invalid void request.');
        }

        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$customerFilter = (int)($_GET['customer'] ?? 0);
$repFilter = (int)($_GET['rep'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$orderFilter = (int)($_GET['order_id'] ?? 0);
$invoiceId = (int)($_GET['id'] ?? 0);

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

if ($invoiceId > 0) {
    $where[] = "i.id = :filter_invoice_id";
    $params[':filter_invoice_id'] = $invoiceId;
    $page = 1;
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
$invoiceFocusData = null;
if ($invoiceId > 0) {
    foreach ($invoices as $invoiceRow) {
        if ((int)($invoiceRow['id'] ?? 0) === $invoiceId) {
            $invoiceFocusData = [
                'invoice_number' => $invoiceRow['invoice_number'] ?? sprintf('INV-%06d', $invoiceId),
                'status' => $invoiceRow['status'] ?? 'draft',
                'status_label' => $statusLabels[$invoiceRow['status'] ?? 'draft'] ?? ucwords((string)($invoiceRow['status'] ?? '')),
                'status_class' => $statusBadgeClasses[$invoiceRow['status'] ?? 'draft'] ?? 'badge-info',
                'total_usd' => (float)($invoiceRow['total_usd'] ?? 0),
                'total_lbp' => (float)($invoiceRow['total_lbp'] ?? 0),
                'order_id' => $invoiceRow['order_id'] ?? null,
                'order_number' => $invoiceRow['order_number'] ?? null,
                'customer_name' => $invoiceRow['customer_name'] ?? null,
                'issued_at' => $invoiceRow['issued_at'] ?? null,
                'created_at' => $invoiceRow['created_at'] ?? null,
            ];
            break;
        }
    }

    if ($invoiceFocusData === null) {
        $focusStmt = $pdo->prepare("
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
                c.name AS customer_name
            FROM invoices i
            LEFT JOIN orders o ON o.id = i.order_id
            LEFT JOIN customers c ON c.id = o.customer_id
            WHERE i.id = :focus_id
            LIMIT 1
        ");
        $focusStmt->execute([':focus_id' => $invoiceId]);
        $focusRow = $focusStmt->fetch(PDO::FETCH_ASSOC);
        if ($focusRow) {
            $invoiceFocusData = [
                'invoice_number' => $focusRow['invoice_number'] ?? sprintf('INV-%06d', $invoiceId),
                'status' => $focusRow['status'] ?? 'draft',
                'status_label' => $statusLabels[$focusRow['status'] ?? 'draft'] ?? ucwords((string)($focusRow['status'] ?? '')),
                'status_class' => $statusBadgeClasses[$focusRow['status'] ?? 'draft'] ?? 'badge-info',
                'total_usd' => (float)($focusRow['total_usd'] ?? 0),
                'total_lbp' => (float)($focusRow['total_lbp'] ?? 0),
                'order_id' => $focusRow['order_id'] ?? null,
                'order_number' => $focusRow['order_number'] ?? null,
                'customer_name' => $focusRow['customer_name'] ?? null,
                'issued_at' => $focusRow['issued_at'] ?? null,
                'created_at' => $focusRow['created_at'] ?? null,
            ];
        }
    }
}
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
    <small style="color: #6b7280; font-size: 0.85rem;">Toggle to show/hide Lebanese Pound values and exchange rates</small>
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

    /* Hide LBP elements by default */
    body:not(.show-lbp) .lbp-value,
    body:not(.show-lbp) .lbp-field,
    body:not(.show-lbp) .lbp-column {
        display: none !important;
    }
</style>

<script>
(function() {
    const lbpToggle = document.getElementById('lbpToggle');
    const savedState = localStorage.getItem('showLBP');

    // Restore saved state
    if (savedState === 'true') {
        lbpToggle.checked = true;
        document.body.classList.add('show-lbp');
    }

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

<style>
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
        border: 1px solid #6666ff;
        border-radius: 10px;
        background: #6666ff;
        color: #ffffff;
        font-weight: 600;
        cursor: pointer;
        min-height: 44px;
        transition: all 0.2s ease;
        text-decoration: none;
        font-size: 0.95rem;
    }
    .btn:hover:not(:disabled) {
        background: #003366;
        border-color: #003366;
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
        text-decoration: none;
    }
    .btn-primary {
        background: #6666ff;
        border-color: #6666ff;
        color: #ffffff;
    }
    .btn-primary:hover:not(:disabled) {
        background: #003366;
        border-color: #003366;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(0, 51, 102, 0.25);
        transform: translateY(-1px);
    }

    /* Compact buttons for inline actions */
    .btn-compact {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 12px;
        border: 1px solid #6666ff;
        border-radius: 8px;
        background: #6666ff;
        color: #ffffff;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.8rem;
        transition: all 0.2s ease;
        text-decoration: none;
    }
    .btn-compact:hover:not(:disabled) {
        background: #003366;
        border-color: #003366;
        color: #ffffff;
        transform: translateY(-1px);
        text-decoration: none;
    }
    .btn-compact.btn-primary {
        background: #6666ff;
        border-color: #6666ff;
    }
    .btn-compact.btn-danger {
        background: #dc2626;
        border-color: #dc2626;
        color: #ffffff;
    }
    .btn-compact.btn-danger:hover:not(:disabled) {
        background: #991b1b;
        border-color: #991b1b;
    }

    /* Inline action forms */
    .inline-action-form {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 4px;
    }
    .invoice-actions {
        display: flex;
        flex-direction: column;
        gap: 6px;
        min-width: 200px;
    }
    .tiny-select {
        padding: 6px 8px;
        border-radius: 6px;
        border: 1px solid var(--bd);
        background: #fff;
        color: var(--ink);
        font-size: 0.8rem;
        min-width: 120px;
    }

    /* Payment Modal */
    .payment-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .modal-content {
        background: #ffffff;
        border-radius: 16px;
        padding: 32px;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        position: relative;
    }
    .modal-content h3 {
        margin: 0 0 24px 0;
        color: var(--ink);
        font-size: 1.5rem;
    }
    .modal-close {
        position: absolute;
        top: 16px;
        right: 20px;
        font-size: 28px;
        font-weight: bold;
        color: var(--muted);
        cursor: pointer;
        transition: color 0.2s;
    }
    .modal-close:hover {
        color: var(--ink);
    }
    .form-row {
        margin-bottom: 18px;
    }
    .form-row label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: var(--ink);
        font-size: 0.9rem;
    }
    .form-input {
        width: 100%;
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid var(--bd);
        background: #fff;
        color: var(--ink);
        font-size: 0.95rem;
        box-sizing: border-box;
    }
    .form-input:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(31, 111, 235, 0.1);
        outline: none;
    }
    .form-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
    }
    .form-actions .btn {
        flex: 1;
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
    .amount-stack a{
        font-size: small;
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
        <div class="metric-card lbp-value">
            <span class="label">Outstanding LBP</span>
            <span class="value money">ل.ل <?= number_format($outstandingLbp, 0) ?></span>
        </div>
        <div class="metric-card">
            <span class="label">Collections (7 days)</span>
            <span class="value">
                $<?= number_format((float)$recentPayments['recent_usd'], 2) ?>
                <small class="muted lbp-value" style="display:block;">ل.ل <?= number_format((float)$recentPayments['recent_lbp'], 0) ?></small>
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
                                    <span class="lbp-value">ل.ل <?= number_format($totalLbp, 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="amount-stack">
                                    <strong>Collected: $<?= number_format($paidUsd, 2) ?></strong>
                                    <span>Balance: $<?= number_format($balanceUsd, 2) ?></span>
                                </div>
                                <div class="amount-stack lbp-value" style="margin-top:6px;">
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
                                <div class="invoice-actions">
                                    <!-- Status Change Form -->
                                    <?php if ($status !== 'voided'): ?>
                                        <form method="post" action="" class="inline-action-form" onsubmit="var sel = this.querySelector('select[name=new_status]'); if (sel.value === '<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>') { alert('Please select a different status.'); return false; } this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').textContent = 'Updating...';">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                            <select name="new_status" class="tiny-select" required>
                                                <?php foreach ($statusLabels as $sVal => $sLabel): ?>
                                                    <option value="<?= htmlspecialchars($sVal, ENT_QUOTES, 'UTF-8') ?>" <?= $sVal === $status ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($sLabel, ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-compact">Change Status</button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Payment Form (only for draft/issued invoices with balance) -->
                                    <?php if ($status !== 'voided' && $status !== 'paid' && $balanceUsd > 0.01): ?>
                                        <button type="button" class="btn-compact btn-primary" onclick="document.getElementById('payment-modal-<?= (int)$invoice['id'] ?>').style.display='block'">Record Payment</button>
                                    <?php endif; ?>

                                    <!-- View/Download -->
                                    <a class="btn-compact" href="invoices_view.php?id=<?= (int)$invoice['id'] ?>">View</a>

                                    <!-- Void Button -->
                                    <?php if ($status !== 'voided' && $status !== 'paid'): ?>
                                        <form method="post" action="" class="inline-action-form" onsubmit="return confirm('Are you sure you want to void this invoice? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="void_invoice">
                                            <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                            <button type="submit" class="btn-compact btn-danger">Void</button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <!-- Payment Modal -->
                                <div id="payment-modal-<?= (int)$invoice['id'] ?>" class="payment-modal" style="display:none;">
                                    <div class="modal-content">
                                        <span class="modal-close" onclick="document.getElementById('payment-modal-<?= (int)$invoice['id'] ?>').style.display='none'">&times;</span>
                                        <h3>Record Payment for <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <form method="post" action="">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="record_payment">
                                            <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">

                                            <div class="form-row">
                                                <label>Amount USD</label>
                                                <input type="number" name="amount_usd" step="0.01" min="0" max="<?= $balanceUsd ?>" value="<?= number_format($balanceUsd, 2, '.', '') ?>" class="form-input">
                                            </div>

                                            <div class="form-row lbp-field">
                                                <label>Amount LBP</label>
                                                <input type="number" name="amount_lbp" step="0.01" min="0" max="<?= $balanceLbp ?>" value="<?= number_format($balanceLbp, 2, '.', '') ?>" class="form-input">
                                            </div>

                                            <div class="form-row">
                                                <label>Payment Method</label>
                                                <select name="method" class="form-input" required>
                                                    <option value="cash">Cash</option>
                                                    <option value="qr_cash">QR / Cash App</option>
                                                    <option value="card">Card</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>

                                            <div class="form-row">
                                                <label>Received At</label>
                                                <input type="datetime-local" name="received_at" value="<?= date('Y-m-d\TH:i') ?>" class="form-input" required>
                                            </div>

                                            <div class="form-actions">
                                                <button type="submit" class="btn btn-primary">Record Payment</button>
                                                <button type="button" class="btn" onclick="document.getElementById('payment-modal-<?= (int)$invoice['id'] ?>').style.display='none'">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
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
