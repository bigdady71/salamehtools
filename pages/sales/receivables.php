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
$title = 'Sales · Account Receivables';
$repId = (int)$user['id'];

// Handle follow-up creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_followup') {
    if (!verify_csrf($_POST['_csrf'] ?? '')) {
        flash('error', 'Invalid CSRF token');
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        $dueDate = $_POST['due_date'] ?? null;

        // Verify customer belongs to rep
        $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id");
        $checkStmt->execute([':id' => $customerId, ':rep_id' => $repId]);

        if ($checkStmt->fetch() && $note !== '') {
            $followupStmt = $pdo->prepare("
                INSERT INTO ar_followups (customer_id, note, due_date, created_by_user_id, assigned_to_user_id, created_at)
                VALUES (:customer_id, :note, :due_date, :created_by, :assigned_to, NOW())
            ");
            $followupStmt->execute([
                ':customer_id' => $customerId,
                ':note' => $note,
                ':due_date' => $dueDate ?: null,
                ':created_by' => $repId,
                ':assigned_to' => $repId
            ]);
            flash('success', 'Follow-up note added successfully');
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . ($customerId ? '?customer_id=' . $customerId : ''));
    exit;
}

$customerFilter = (int)($_GET['customer_id'] ?? 0);

// Get aging buckets (only for rep's customers)
$agingQuery = "
    SELECT
        SUM(CASE WHEN days_old BETWEEN 0 AND 30 THEN balance_usd ELSE 0 END) AS bucket_0_30_usd,
        SUM(CASE WHEN days_old BETWEEN 0 AND 30 THEN balance_lbp ELSE 0 END) AS bucket_0_30_lbp,
        SUM(CASE WHEN days_old BETWEEN 0 AND 30 THEN 1 ELSE 0 END) AS bucket_0_30_count,

        SUM(CASE WHEN days_old BETWEEN 31 AND 60 THEN balance_usd ELSE 0 END) AS bucket_31_60_usd,
        SUM(CASE WHEN days_old BETWEEN 31 AND 60 THEN balance_lbp ELSE 0 END) AS bucket_31_60_lbp,
        SUM(CASE WHEN days_old BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS bucket_31_60_count,

        SUM(CASE WHEN days_old BETWEEN 61 AND 90 THEN balance_usd ELSE 0 END) AS bucket_61_90_usd,
        SUM(CASE WHEN days_old BETWEEN 61 AND 90 THEN balance_lbp ELSE 0 END) AS bucket_61_90_lbp,
        SUM(CASE WHEN days_old BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS bucket_61_90_count,

        SUM(CASE WHEN days_old > 90 THEN balance_usd ELSE 0 END) AS bucket_90_plus_usd,
        SUM(CASE WHEN days_old > 90 THEN balance_lbp ELSE 0 END) AS bucket_90_plus_lbp,
        SUM(CASE WHEN days_old > 90 THEN 1 ELSE 0 END) AS bucket_90_plus_count
    FROM (
        SELECT
            i.id,
            DATEDIFF(NOW(), i.issued_at) as days_old,
            (i.total_usd - COALESCE(pay.paid_usd, 0)) as balance_usd,
            (i.total_lbp - COALESCE(pay.paid_lbp, 0)) as balance_lbp
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        INNER JOIN customers c ON c.id = o.customer_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
            FROM payments
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = i.id
        WHERE c.assigned_sales_rep_id = :rep_id
          AND i.status IN ('issued', 'paid')
          AND (i.total_usd - COALESCE(pay.paid_usd, 0) > 0.01 OR i.total_lbp - COALESCE(pay.paid_lbp, 0) > 0.01)
    ) aging_data
";
$agingStmt = $pdo->prepare($agingQuery);
$agingStmt->execute([':rep_id' => $repId]);
$aging = $agingStmt->fetch(PDO::FETCH_ASSOC);

// Get customers with outstanding balances
$customersQuery = "
    SELECT
        c.id,
        c.name,
        c.phone,
        c.location,
        SUM(i.total_usd - COALESCE(pay.paid_usd, 0)) as balance_usd,
        SUM(i.total_lbp - COALESCE(pay.paid_lbp, 0)) as balance_lbp,
        COUNT(DISTINCT i.id) as invoice_count,
        MIN(DATEDIFF(NOW(), i.issued_at)) as days_overdue,
        MAX(p.received_at) as last_payment_date
    FROM customers c
    INNER JOIN orders o ON o.customer_id = c.id
    INNER JOIN invoices i ON i.order_id = o.id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) pay ON pay.invoice_id = i.id
    LEFT JOIN payments p ON p.invoice_id = i.id
    WHERE c.assigned_sales_rep_id = :rep_id
      AND i.status IN ('issued', 'paid')
    GROUP BY c.id
    HAVING balance_usd > 0.01 OR balance_lbp > 0.01
    ORDER BY days_overdue DESC, balance_usd DESC
";
$customersStmt = $pdo->prepare($customersQuery);
$customersStmt->execute([':rep_id' => $repId]);
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// If customer selected, get invoice details and follow-ups
$selectedCustomer = null;
$customerInvoices = [];
$followups = [];

if ($customerFilter > 0) {
    $selectedStmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id");
    $selectedStmt->execute([':id' => $customerFilter, ':rep_id' => $repId]);
    $selectedCustomer = $selectedStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedCustomer) {
        // Get invoices
        $invoicesQuery = "
            SELECT
                i.id,
                i.invoice_number,
                i.issued_at,
                i.total_usd,
                i.total_lbp,
                COALESCE(pay.paid_usd, 0) as paid_usd,
                COALESCE(pay.paid_lbp, 0) as paid_lbp,
                (i.total_usd - COALESCE(pay.paid_usd, 0)) as balance_usd,
                (i.total_lbp - COALESCE(pay.paid_lbp, 0)) as balance_lbp,
                DATEDIFF(NOW(), i.issued_at) as days_old
            FROM invoices i
            INNER JOIN orders o ON o.id = i.order_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
                FROM payments
                GROUP BY invoice_id
            ) pay ON pay.invoice_id = i.id
            WHERE o.customer_id = :customer_id
              AND i.status IN ('issued', 'paid')
              AND (i.total_usd - COALESCE(pay.paid_usd, 0) > 0.01 OR i.total_lbp - COALESCE(pay.paid_lbp, 0) > 0.01)
            ORDER BY i.issued_at ASC
        ";
        $invoicesStmt = $pdo->prepare($invoicesQuery);
        $invoicesStmt->execute([':customer_id' => $customerFilter]);
        $customerInvoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get follow-ups
        $followupsStmt = $pdo->prepare("
            SELECT f.*, u.name as created_by_name
            FROM ar_followups f
            LEFT JOIN users u ON u.id = f.created_by_user_id
            WHERE f.customer_id = :customer_id
            ORDER BY f.created_at DESC
            LIMIT 10
        ");
        $followupsStmt->execute([':customer_id' => $customerFilter]);
        $followups = $followupsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

sales_portal_render_layout_start(['title' => $title]);
?>

<div class="page-header">
    <div class="page-title">
        <h1>💰 Account Receivables & Collections</h1>
        <p class="subtitle">Track outstanding payments and manage follow-ups for your customers</p>
    </div>
</div>

<!-- Flash Messages -->
<?php
$messages = flash_get_all();
foreach ($messages as $msg):
?>
<div class="alert alert-<?= $msg['type'] ?>" style="margin-bottom: 16px;">
    <?= htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endforeach; ?>

<!-- Aging Buckets -->
<div style="margin-bottom: 32px;">
    <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 16px;">Aging Analysis</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
        <div style="background: white; border: 1px solid #10b981; border-radius: 8px; padding: 20px;">
            <div style="color: #10b981; font-weight: 600; margin-bottom: 8px;">0-30 Days (Current)</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;">$<?= number_format((float)$aging['bucket_0_30_usd'], 2) ?></div>
            <div style="font-size: 0.9rem; color: #6b7280; margin-top: 4px;"><?= (int)$aging['bucket_0_30_count'] ?> invoices</div>
        </div>
        <div style="background: white; border: 1px solid #f59e0b; border-radius: 8px; padding: 20px;">
            <div style="color: #f59e0b; font-weight: 600; margin-bottom: 8px;">31-60 Days</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #f59e0b;">$<?= number_format((float)$aging['bucket_31_60_usd'], 2) ?></div>
            <div style="font-size: 0.9rem; color: #6b7280; margin-top: 4px;"><?= (int)$aging['bucket_31_60_count'] ?> invoices</div>
        </div>
        <div style="background: white; border: 1px solid #ea580c; border-radius: 8px; padding: 20px;">
            <div style="color: #ea580c; font-weight: 600; margin-bottom: 8px;">61-90 Days</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #ea580c;">$<?= number_format((float)$aging['bucket_61_90_usd'], 2) ?></div>
            <div style="font-size: 0.9rem; color: #6b7280; margin-top: 4px;"><?= (int)$aging['bucket_61_90_count'] ?> invoices</div>
        </div>
        <div style="background: white; border: 1px solid #dc2626; border-radius: 8px; padding: 20px;">
            <div style="color: #dc2626; font-weight: 600; margin-bottom: 8px;">90+ Days (Critical)</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #dc2626;">$<?= number_format((float)$aging['bucket_90_plus_usd'], 2) ?></div>
            <div style="font-size: 0.9rem; color: #6b7280; margin-top: 4px;"><?= (int)$aging['bucket_90_plus_count'] ?> invoices</div>
        </div>
    </div>
</div>

<!-- Customers with Outstanding Balances -->
<?php if ($selectedCustomer): ?>
<!-- Customer Detail View -->
<div style="margin-bottom: 24px;">
    <a href="?" class="btn" style="margin-bottom: 16px;">← Back to All Customers</a>

    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 24px;">
        <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 4px;"><?= htmlspecialchars($selectedCustomer['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <div style="color: #6b7280; font-size: 0.9rem;">
            📞 <?= htmlspecialchars($selectedCustomer['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?> |
            📍 <?= htmlspecialchars($selectedCustomer['location'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

    <!-- Outstanding Invoices -->
    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 12px;">Outstanding Invoices</h3>
    <?php if (!empty($customerInvoices)): ?>
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 24px;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Issued Date</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Outstanding</th>
                    <th class="text-center">Days Old</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customerInvoices as $inv):
                    $daysOld = (int)$inv['days_old'];
                    if ($daysOld <= 30) {
                        $daysColor = '#10b981';
                    } elseif ($daysOld <= 60) {
                        $daysColor = '#f59e0b';
                    } elseif ($daysOld <= 90) {
                        $daysColor = '#ea580c';
                    } else {
                        $daysColor = '#dc2626';
                    }
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= date('M d, Y', strtotime($inv['issued_at'])) ?></td>
                    <td class="text-right">$<?= number_format((float)$inv['total_usd'], 2) ?></td>
                    <td class="text-right"><strong style="color: #ea580c;">$<?= number_format((float)$inv['balance_usd'], 2) ?></strong></td>
                    <td class="text-center">
                        <span style="color: <?= $daysColor ?>; font-weight: 600;"><?= $daysOld ?> days</span>
                    </td>
                    <td class="text-center">
                        <a href="invoices.php?invoice_id=<?= $inv['id'] ?>" class="btn" style="font-size: 0.85rem; padding: 6px 12px;">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color: #6b7280; margin-bottom: 24px;">No outstanding invoices</p>
    <?php endif; ?>

    <!-- Add Follow-up -->
    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 12px;">Add Follow-up Note</h3>
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_followup">
            <input type="hidden" name="customer_id" value="<?= $selectedCustomer['id'] ?>">

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 500; margin-bottom: 6px;">Note</label>
                <textarea name="note" rows="3" class="form-control" placeholder="Record your follow-up activity..." required></textarea>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-weight: 500; margin-bottom: 6px;">Due Date (Optional)</label>
                <input type="date" name="due_date" class="form-control">
            </div>

            <button type="submit" class="btn btn-primary">Add Follow-up</button>
        </form>
    </div>

    <!-- Follow-up History -->
    <h3 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 12px;">Follow-up History</h3>
    <?php if (!empty($followups)): ?>
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;">
        <?php foreach ($followups as $followup): ?>
        <div style="border-bottom: 1px solid #e5e7eb; padding: 12px 0;">
            <div style="font-size: 0.85rem; color: #6b7280; margin-bottom: 4px;">
                <?= date('M d, Y g:i A', strtotime($followup['created_at'])) ?> by <?= htmlspecialchars($followup['created_by_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                <?php if ($followup['due_date']): ?>
                    | Due: <?= date('M d, Y', strtotime($followup['due_date'])) ?>
                <?php endif; ?>
            </div>
            <div><?= nl2br(htmlspecialchars($followup['note'], ENT_QUOTES, 'UTF-8')) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color: #6b7280;">No follow-up history</p>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Customers List -->
<h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 16px;">Customers with Outstanding Balances</h2>
<?php if (!empty($customers)): ?>
<div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Phone</th>
                <th class="text-right">Outstanding (USD)</th>
                <th class="text-center">Invoices</th>
                <th class="text-center">Days Overdue</th>
                <th>Last Payment</th>
                <th class="text-center">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $customer):
                $daysOverdue = (int)$customer['days_overdue'];
                if ($daysOverdue <= 30) {
                    $daysColor = '#10b981';
                } elseif ($daysOverdue <= 60) {
                    $daysColor = '#f59e0b';
                } elseif ($daysOverdue <= 90) {
                    $daysColor = '#ea580c';
                } else {
                    $daysColor = '#dc2626';
                }
            ?>
            <tr>
                <td>
                    <div style="font-weight: 500;"><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size: 0.85rem; color: #6b7280;"><?= htmlspecialchars($customer['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-right"><strong style="color: #ea580c;">$<?= number_format((float)$customer['balance_usd'], 2) ?></strong></td>
                <td class="text-center"><?= (int)$customer['invoice_count'] ?></td>
                <td class="text-center">
                    <span style="color: <?= $daysColor ?>; font-weight: 600;"><?= $daysOverdue ?> days</span>
                </td>
                <td><?= $customer['last_payment_date'] ? date('M d, Y', strtotime($customer['last_payment_date'])) : '—' ?></td>
                <td class="text-center">
                    <a href="?customer_id=<?= $customer['id'] ?>" class="btn" style="font-size: 0.85rem; padding: 6px 12px;">View Details</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="empty-state" style="background: white; border-radius: 8px; padding: 48px; text-align: center; border: 1px solid #e5e7eb;">
    <div style="font-size: 3rem; margin-bottom: 16px;">✅</div>
    <h3 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 8px;">All Caught Up!</h3>
    <p style="color: #6b7280;">No outstanding receivables for your customers.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
sales_portal_render_layout_end();
?>
