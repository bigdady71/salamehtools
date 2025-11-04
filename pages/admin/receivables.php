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
$title = 'Admin · Receivables';

// Handle follow-up actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf((string)($_POST['_csrf'] ?? ''))) {
        flash('error', 'Invalid CSRF token.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'add_followup') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $dueAt = !empty($_POST['due_at']) ? $_POST['due_at'] : null;

        if ($customerId > 0 && $note !== '') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ar_followups (customer_id, assigned_to, note, due_at, created_by)
                    VALUES (:customer_id, :assigned_to, :note, :due_at, :created_by)
                ");
                $stmt->execute([
                    ':customer_id' => $customerId,
                    ':assigned_to' => $assignedTo,
                    ':note' => $note,
                    ':due_at' => $dueAt,
                    ':created_by' => $user['id']
                ]);
                flash('success', 'Follow-up note added successfully.');
            } catch (Exception $e) {
                flash('error', 'Failed to add follow-up: ' . $e->getMessage());
            }
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Get customer filter
$customerFilter = isset($_GET['customer']) ? (int)$_GET['customer'] : 0;

// Get aging buckets with outstanding amounts
$agingQuery = "
    SELECT
        CASE
            WHEN DATEDIFF(CURDATE(), i.created_at) <= 30 THEN '0-30'
            WHEN DATEDIFF(CURDATE(), i.created_at) <= 60 THEN '31-60'
            WHEN DATEDIFF(CURDATE(), i.created_at) <= 90 THEN '61-90'
            ELSE '90+'
        END AS bucket,
        SUM(i.total_usd - COALESCE(paid.paid_usd, 0)) AS outstanding_usd,
        SUM(i.total_lbp - COALESCE(paid.paid_lbp, 0)) AS outstanding_lbp,
        COUNT(*) AS invoice_count
    FROM invoices i
    INNER JOIN orders o ON i.order_id = o.id
    LEFT JOIN (
        SELECT invoice_id,
               SUM(amount_usd) AS paid_usd,
               SUM(amount_lbp) AS paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
        AND (i.total_usd - COALESCE(paid.paid_usd, 0) > 0.01 OR i.total_lbp - COALESCE(paid.paid_lbp, 0) > 0.01)
    GROUP BY bucket
    ORDER BY FIELD(bucket, '0-30', '31-60', '61-90', '90+')
";
$agingBuckets = $pdo->query($agingQuery)->fetchAll(PDO::FETCH_ASSOC);

// Get customers with outstanding balances
$customersQuery = "
    SELECT
        c.id,
        c.name,
        c.phone,
        c.assigned_sales_rep_id,
        u.name AS sales_rep_name,
        SUM(i.total_usd - COALESCE(paid.paid_usd, 0)) AS outstanding_usd,
        SUM(i.total_lbp - COALESCE(paid.paid_lbp, 0)) AS outstanding_lbp,
        COUNT(DISTINCT i.id) AS invoice_count,
        MAX(i.created_at) AS largest_invoice_date,
        MAX(p.created_at) AS last_payment_date,
        DATEDIFF(CURDATE(), MAX(i.created_at)) AS days_overdue
    FROM customers c
    INNER JOIN orders o ON o.customer_id = c.id
    INNER JOIN invoices i ON i.order_id = o.id
    LEFT JOIN users u ON c.assigned_sales_rep_id = u.id
    LEFT JOIN (
        SELECT invoice_id,
               SUM(amount_usd) AS paid_usd,
               SUM(amount_lbp) AS paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    LEFT JOIN payments p ON p.invoice_id = i.id
    WHERE i.status IN ('issued', 'paid')
        AND (i.total_usd - COALESCE(paid.paid_usd, 0) > 0.01 OR i.total_lbp - COALESCE(paid.paid_lbp, 0) > 0.01)
";

if ($customerFilter > 0) {
    $customersQuery .= " AND c.id = :customer_filter";
}

$customersQuery .= "
    GROUP BY c.id
    ORDER BY outstanding_usd DESC, outstanding_lbp DESC
    LIMIT 100
";

$stmt = $pdo->prepare($customersQuery);
if ($customerFilter > 0) {
    $stmt->execute([':customer_filter' => $customerFilter]);
} else {
    $stmt->execute();
}
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all customers for filter dropdown
$allCustomers = $pdo->query("SELECT id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get users for assignment
$assignableUsers = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// If customer drill-down is requested
$drilldownCustomer = null;
$drilldownInvoices = [];
$drilldownFollowups = [];
if ($customerFilter > 0 && !empty($customers)) {
    $drilldownCustomer = $customers[0];

    // Get outstanding invoices for this customer
    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.invoice_number,
            i.created_at,
            i.total_usd,
            i.total_lbp,
            COALESCE(paid.paid_usd, 0) AS paid_usd,
            COALESCE(paid.paid_lbp, 0) AS paid_lbp,
            (i.total_usd - COALESCE(paid.paid_usd, 0)) AS outstanding_usd,
            (i.total_lbp - COALESCE(paid.paid_lbp, 0)) AS outstanding_lbp,
            i.status,
            DATEDIFF(CURDATE(), i.created_at) AS days_old
        FROM invoices i
        INNER JOIN orders o ON i.order_id = o.id
        LEFT JOIN (
            SELECT invoice_id,
                   SUM(amount_usd) AS paid_usd,
                   SUM(amount_lbp) AS paid_lbp
            FROM payments
            GROUP BY invoice_id
        ) paid ON paid.invoice_id = i.id
        WHERE o.customer_id = :customer_id
            AND i.status IN ('issued', 'paid')
            AND (i.total_usd - COALESCE(paid.paid_usd, 0) > 0.01 OR i.total_lbp - COALESCE(paid.paid_lbp, 0) > 0.01)
        ORDER BY i.created_at ASC
    ");
    $stmt->execute([':customer_id' => $customerFilter]);
    $drilldownInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get follow-ups for this customer
    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.note,
            f.due_at,
            f.created_at,
            u_created.name AS created_by_name,
            u_assigned.name AS assigned_to_name
        FROM ar_followups f
        LEFT JOIN users u_created ON f.created_by = u_created.id
        LEFT JOIN users u_assigned ON f.assigned_to = u_assigned.id
        WHERE f.customer_id = :customer_id
        ORDER BY f.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([':customer_id' => $customerFilter]);
    $drilldownFollowups = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

admin_render_layout_start([
    'title' => $title,
    'heading' => 'Receivables',
    'subtitle' => 'Collections cockpit - Monitor overdue balances and follow-ups',
    'active' => 'receivables',
    'user' => $user,
]);

$flashes = consume_flashes();
?>

<style>
    .aging-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 32px;
    }
    .aging-card {
        background: var(--bg-panel);
        border-radius: 12px;
        padding: 20px;
        border-left: 4px solid var(--accent);
    }
    .aging-card.bucket-0-30 { border-left-color: #6ee7b7; }
    .aging-card.bucket-31-60 { border-left-color: #ffd166; }
    .aging-card.bucket-61-90 { border-left-color: #ff9f66; }
    .aging-card.bucket-90-plus { border-left-color: #ff5c7a; }
    .aging-label {
        font-size: 0.85rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 8px;
    }
    .aging-amount {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 4px;
    }
    .aging-count {
        font-size: 0.85rem;
        color: var(--muted);
    }
    .customers-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--bg-panel);
        border-radius: 12px;
        overflow: hidden;
    }
    .customers-table th {
        background: var(--bg-panel-alt);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--muted);
    }
    .customers-table td {
        padding: 12px;
        border-top: 1px solid var(--border);
    }
    .customers-table tr:hover {
        background: var(--bg-panel-alt);
    }
    .customer-name {
        font-weight: 600;
        color: var(--text);
    }
    .customer-phone {
        font-size: 0.85rem;
        color: var(--muted);
    }
    .amount {
        font-family: 'Courier New', monospace;
        font-weight: 600;
    }
    .overdue-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .overdue-30 { background: rgba(110, 231, 183, 0.2); color: #6ee7b7; }
    .overdue-60 { background: rgba(255, 209, 102, 0.2); color: #ffd166; }
    .overdue-90 { background: rgba(255, 159, 102, 0.2); color: #ff9f66; }
    .overdue-90plus { background: rgba(255, 92, 122, 0.2); color: #ff5c7a; }
    .drilldown-section {
        background: var(--bg-panel);
        border-radius: 12px;
        padding: 24px;
        margin: 24px 0;
    }
    .drilldown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
    }
    .followup-form {
        background: var(--bg-panel-alt);
        border-radius: 8px;
        padding: 16px;
        margin-top: 16px;
    }
    .form-row {
        margin-bottom: 12px;
    }
    .form-label {
        display: block;
        font-weight: 600;
        margin-bottom: 4px;
        font-size: 0.9rem;
    }
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 8px 12px;
        background: var(--bg-panel);
        border: 1px solid var(--border);
        border-radius: 6px;
        color: var(--text);
    }
    .form-textarea {
        min-height: 80px;
        resize: vertical;
    }
    .btn {
        padding: 10px 20px;
        background: #6666ff;
        color: #ffffff;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn:hover {
        background: #003366;
    }
    .btn-secondary {
        background: var(--bg-panel-alt);
        color: var(--text);
        border: 1px solid var(--border);
    }
    .btn-secondary:hover {
        background: var(--bg-panel);
    }
    .followup-list {
        margin-top: 16px;
    }
    .followup-item {
        background: var(--bg-panel-alt);
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 8px;
    }
    .followup-meta {
        font-size: 0.85rem;
        color: var(--muted);
        margin-bottom: 6px;
    }
    .followup-note {
        color: var(--text);
    }
    .export-btn {
        padding: 8px 16px;
        background: var(--accent);
        color: #000;
        text-decoration: none;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.9rem;
    }
    .filter-bar {
        background: var(--bg-panel);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        align-items: center;
    }
    .result-box {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 16px;
    }
    .result-success {
        background: rgba(110, 231, 183, 0.2);
        border: 1px solid rgba(110, 231, 183, 0.3);
        color: #6ee7b7;
    }
    .result-error {
        background: rgba(255, 92, 122, 0.2);
        border: 1px solid rgba(255, 92, 122, 0.3);
        color: #ff5c7a;
    }
</style>

<?php foreach ($flashes as $flash): ?>
    <div class="result-box result-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endforeach; ?>

<div class="aging-grid">
    <?php
    $bucketLabels = ['0-30' => '0-30 Days', '31-60' => '31-60 Days', '61-90' => '61-90 Days', '90+' => '90+ Days'];
    $bucketData = array_column($agingBuckets, null, 'bucket');

    foreach ($bucketLabels as $key => $label):
        $data = $bucketData[$key] ?? ['outstanding_usd' => 0, 'outstanding_lbp' => 0, 'invoice_count' => 0];
        $classKey = str_replace(['-', '+'], ['', 'plus'], strtolower($key));
    ?>
        <div class="aging-card bucket-<?= $classKey ?>">
            <div class="aging-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="aging-amount">$<?= number_format((float)$data['outstanding_usd'], 2) ?></div>
            <div class="aging-amount" style="font-size: 1.1rem;">LBP <?= number_format((float)$data['outstanding_lbp'], 0) ?></div>
            <div class="aging-count"><?= number_format((int)$data['invoice_count']) ?> invoices</div>
        </div>
    <?php endforeach; ?>
</div>

<div class="filter-bar">
    <form method="get" style="display: flex; gap: 12px; flex: 1; align-items: center;">
        <label style="font-weight: 600;">Filter by Customer:</label>
        <select name="customer" class="form-select" onchange="this.form.submit()" style="width: auto; max-width: 300px;">
            <option value="0">All Customers</option>
            <?php foreach ($allCustomers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $customerFilter === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($customerFilter > 0): ?>
            <a href="receivables.php" class="btn-secondary" style="padding: 8px 16px; text-decoration: none; display: inline-block; border-radius: 6px;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($drilldownCustomer): ?>
    <div class="drilldown-section">
        <div class="drilldown-header">
            <div>
                <h2 class="section-title"><?= htmlspecialchars($drilldownCustomer['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                <?php if ($drilldownCustomer['phone']): ?>
                    <div style="color: var(--muted);"><?= htmlspecialchars($drilldownCustomer['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($drilldownCustomer['sales_rep_name']): ?>
                    <div style="color: var(--muted); margin-top: 4px;">Sales Rep: <?= htmlspecialchars($drilldownCustomer['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div style="text-align: right;">
                <div class="amount" style="font-size: 1.5rem; color: #ff5c7a;">$<?= number_format((float)$drilldownCustomer['outstanding_usd'], 2) ?></div>
                <div class="amount" style="font-size: 1.2rem; color: #ff5c7a;">LBP <?= number_format((float)$drilldownCustomer['outstanding_lbp'], 0) ?></div>
            </div>
        </div>

        <h3 style="margin-bottom: 12px; font-size: 1.1rem;">Outstanding Invoices</h3>
        <table class="customers-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Total USD</th>
                    <th>Total LBP</th>
                    <th>Outstanding USD</th>
                    <th>Outstanding LBP</th>
                    <th>Days Old</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($drilldownInvoices as $inv): ?>
                    <tr>
                        <td><?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(date('M d, Y', strtotime($inv['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="amount">$<?= number_format((float)$inv['total_usd'], 2) ?></td>
                        <td class="amount">LBP <?= number_format((float)$inv['total_lbp'], 0) ?></td>
                        <td class="amount" style="color: #ff5c7a;">$<?= number_format((float)$inv['outstanding_usd'], 2) ?></td>
                        <td class="amount" style="color: #ff5c7a;">LBP <?= number_format((float)$inv['outstanding_lbp'], 0) ?></td>
                        <td>
                            <?php
                            $days = (int)$inv['days_old'];
                            $badgeClass = $days <= 30 ? 'overdue-30' : ($days <= 60 ? 'overdue-60' : ($days <= 90 ? 'overdue-90' : 'overdue-90plus'));
                            ?>
                            <span class="overdue-badge <?= $badgeClass ?>"><?= $days ?> days</span>
                        </td>
                        <td>
                            <a href="invoices.php?id=<?= (int)$inv['id'] ?>" style="color: var(--accent); text-decoration: none;">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 style="margin: 24px 0 12px; font-size: 1.1rem;">Follow-Up Notes</h3>

        <details>
            <summary style="cursor: pointer; font-weight: 600; padding: 8px 0;">+ Add New Follow-Up</summary>
            <form method="post" class="followup-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_followup">
                <input type="hidden" name="customer_id" value="<?= (int)$customerFilter ?>">

                <div class="form-row">
                    <label class="form-label">Note *</label>
                    <textarea name="note" class="form-textarea" required placeholder="e.g., Customer promised payment by Friday"></textarea>
                </div>

                <div class="form-row">
                    <label class="form-label">Assign To</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">Unassigned</option>
                        <?php foreach ($assignableUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label">Due Date</label>
                    <input type="datetime-local" name="due_at" class="form-input">
                </div>

                <button type="submit" class="btn">Add Follow-Up</button>
            </form>
        </details>

        <div class="followup-list">
            <?php foreach ($drilldownFollowups as $followup): ?>
                <div class="followup-item">
                    <div class="followup-meta">
                        <?= htmlspecialchars(date('M d, Y g:i A', strtotime($followup['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                        by <?= htmlspecialchars($followup['created_by_name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($followup['assigned_to_name']): ?>
                            — Assigned to: <strong><?= htmlspecialchars($followup['assigned_to_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php endif; ?>
                        <?php if ($followup['due_at']): ?>
                            — Due: <strong><?= htmlspecialchars(date('M d, Y', strtotime($followup['due_at'])), ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php endif; ?>
                    </div>
                    <div class="followup-note"><?= htmlspecialchars($followup['note'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($drilldownFollowups)): ?>
                <p style="color: var(--muted); font-style: italic;">No follow-up notes yet.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <table class="customers-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Sales Rep</th>
                <th>Outstanding USD</th>
                <th>Outstanding LBP</th>
                <th>Invoices</th>
                <th>Days Overdue</th>
                <th>Last Payment</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <td>
                        <div class="customer-name"><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($customer['phone']): ?>
                            <div class="customer-phone"><?= htmlspecialchars($customer['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($customer['sales_rep_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="amount">$<?= number_format((float)$customer['outstanding_usd'], 2) ?></td>
                    <td class="amount">LBP <?= number_format((float)$customer['outstanding_lbp'], 0) ?></td>
                    <td><?= (int)$customer['invoice_count'] ?></td>
                    <td>
                        <?php
                        $days = (int)$customer['days_overdue'];
                        $badgeClass = $days <= 30 ? 'overdue-30' : ($days <= 60 ? 'overdue-60' : ($days <= 90 ? 'overdue-90' : 'overdue-90plus'));
                        ?>
                        <span class="overdue-badge <?= $badgeClass ?>"><?= $days ?> days</span>
                    </td>
                    <td><?= $customer['last_payment_date'] ? htmlspecialchars(date('M d, Y', strtotime($customer['last_payment_date'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td>
                        <a href="?customer=<?= (int)$customer['id'] ?>" style="color: var(--accent); text-decoration: none; font-weight: 600;">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 32px; color: var(--muted);">
                        No outstanding receivables found.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php admin_render_layout_end(); ?>
