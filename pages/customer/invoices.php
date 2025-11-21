<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get filters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

// Build query
$where = ['o.customer_id = ?'];
$params = [$customerId];

if ($status !== '' && $status !== 'all') {
    $where[] = 'i.status = ?';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = '(i.invoice_number LIKE ? OR i.id = ?)';
    $searchTerm = '%' . $search . '%';
    $invoiceId = is_numeric($search) ? (int)$search : 0;
    $params[] = $searchTerm;
    $params[] = $invoiceId;
}

$whereClause = implode(' AND ', $where);

// Fetch invoices
$invoicesQuery = "
    SELECT
        i.id,
        i.invoice_number,
        i.order_id,
        i.issued_at,
        i.due_date,
        i.status,
        i.total_amount_usd,
        i.total_amount_lbp,
        i.paid_amount_usd,
        i.paid_amount_lbp,
        DATEDIFF(NOW(), i.due_date) as days_overdue,
        o.order_date
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    WHERE {$whereClause}
    ORDER BY i.issued_at DESC, i.id DESC
    LIMIT 50
";

$invoicesStmt = $pdo->prepare($invoicesQuery);
$invoicesStmt->execute($params);
$invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get invoice statistics
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_invoices,
        COUNT(CASE WHEN i.status = 'unpaid' THEN 1 END) as unpaid_count,
        COUNT(CASE WHEN i.status = 'partial' THEN 1 END) as partial_count,
        COUNT(CASE WHEN i.status = 'paid' THEN 1 END) as paid_count,
        COALESCE(SUM(CASE WHEN i.status != 'paid' THEN (i.total_amount_usd - i.paid_amount_usd) END), 0) as total_outstanding,
        COALESCE(SUM(i.total_amount_usd), 0) as total_invoiced
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    WHERE o.customer_id = ?
");
$statsStmt->execute([$customerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
$stats['total_invoices'] = (int)$stats['total_invoices'];
$stats['unpaid_count'] = (int)$stats['unpaid_count'];
$stats['partial_count'] = (int)$stats['partial_count'];
$stats['paid_count'] = (int)$stats['paid_count'];
$stats['total_outstanding'] = (float)$stats['total_outstanding'];
$stats['total_invoiced'] = (float)$stats['total_invoiced'];

$title = 'Invoices - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Invoices',
    'subtitle' => 'View and manage your invoices',
    'customer' => $customer,
    'active' => 'invoices',
    'actions' => [
        ['label' => '💳 Payment History', 'href' => 'payments.php'],
    ]
]);

?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card {
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    transition: all 0.2s;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.12);
}
.stat-card .value {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--accent);
    margin: 0 0 4px;
}
.stat-card .label {
    font-size: 0.85rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.stat-card.warning .value {
    color: #d97706;
}
.filters-card {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    margin-bottom: 24px;
}
.filters-grid {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 16px;
    align-items: end;
}
@media (max-width: 900px) {
    .filters-grid {
        grid-template-columns: 1fr;
    }
}
.form-group {
    margin: 0;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 0.92rem;
    color: var(--text);
}
.form-group input,
.form-group select {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.2s;
}
.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.invoices-table {
    width: 100%;
    border-collapse: collapse;
}
.invoices-table th {
    text-align: left;
    padding: 14px 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
    font-size: 0.9rem;
}
.invoices-table td {
    padding: 18px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.invoices-table tr:hover {
    background: var(--bg-panel-alt);
}
.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.badge-unpaid {
    background: #fee2e2;
    color: #991b1b;
}
.badge-partial {
    background: #fef3c7;
    color: #92400e;
}
.badge-paid {
    background: #d1fae5;
    color: #065f46;
}
.badge-overdue {
    background: #991b1b;
    color: #ffffff;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}
.empty-state {
    text-align: center;
    padding: 64px 24px;
    color: var(--muted);
}
.empty-state h3 {
    font-size: 1.4rem;
    margin: 0 0 12px;
    color: var(--text);
}
.invoice-number-link {
    font-weight: 600;
    color: var(--accent);
    text-decoration: none;
    font-size: 1.05rem;
}
.invoice-number-link:hover {
    text-decoration: underline;
}
</style>

<!-- Invoice Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <p class="value"><?= $stats['total_invoices'] ?></p>
        <p class="label">Total Invoices</p>
    </div>
    <div class="stat-card">
        <p class="value"><?= $stats['unpaid_count'] ?></p>
        <p class="label">Unpaid</p>
    </div>
    <div class="stat-card">
        <p class="value"><?= $stats['partial_count'] ?></p>
        <p class="label">Partially Paid</p>
    </div>
    <div class="stat-card warning">
        <p class="value">$<?= number_format($stats['total_outstanding'], 2) ?></p>
        <p class="label">Outstanding Balance</p>
    </div>
    <div class="stat-card">
        <p class="value">$<?= number_format($stats['total_invoiced'], 2) ?></p>
        <p class="label">Total Invoiced</p>
    </div>
</div>

<!-- Filters -->
<div class="filters-card">
    <form method="get" action="invoices.php">
        <div class="filters-grid">
            <div class="form-group">
                <label for="search">Search Invoices</label>
                <input
                    type="text"
                    id="search"
                    name="search"
                    placeholder="Search by invoice number..."
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label for="status">Invoice Status</label>
                <select id="status" name="status">
                    <option value="all" <?= $status === 'all' || $status === '' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="unpaid" <?= $status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                </select>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
        </div>
    </form>
</div>

<!-- Invoices Table -->
<div class="card">
    <h2>Invoice History</h2>
    <?php if (count($invoices) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="invoices-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Order #</th>
                        <th>Issued Date</th>
                        <th>Due Date</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $invoiceId = (int)$invoice['id'];
                        $invoiceNumber = htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8');
                        $orderId = (int)$invoice['order_id'];
                        $issuedDate = date('M d, Y', strtotime($invoice['issued_at']));
                        $dueDate = $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : 'N/A';
                        $totalAmount = (float)$invoice['total_amount_usd'];
                        $paidAmount = (float)$invoice['paid_amount_usd'];
                        $balance = $totalAmount - $paidAmount;
                        $status = htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8');
                        $daysOverdue = (int)($invoice['days_overdue'] ?? 0);

                        $statusBadgeClass = 'badge-' . strtolower($status);
                        if ($daysOverdue > 0 && $status !== 'paid') {
                            $statusBadgeClass = 'badge-overdue';
                            $statusDisplay = 'Overdue (' . $daysOverdue . 'd)';
                        } else {
                            $statusDisplay = ucfirst($status);
                        }
                        ?>
                        <tr>
                            <td>
                                <a href="invoice_details.php?id=<?= $invoiceId ?>" class="invoice-number-link">
                                    <?= $invoiceNumber ?>
                                </a>
                            </td>
                            <td>
                                <a href="order_details.php?id=<?= $orderId ?>" style="color: var(--accent);">
                                    #<?= $orderId ?>
                                </a>
                            </td>
                            <td><?= $issuedDate ?></td>
                            <td><?= $dueDate ?></td>
                            <td><strong>$<?= number_format($totalAmount, 2) ?></strong></td>
                            <td style="color: var(--accent);">$<?= number_format($paidAmount, 2) ?></td>
                            <td>
                                <strong style="color: <?= $balance > 0 ? '#d97706' : 'var(--accent)' ?>;">
                                    $<?= number_format($balance, 2) ?>
                                </strong>
                            </td>
                            <td><span class="badge <?= $statusBadgeClass ?>"><?= $statusDisplay ?></span></td>
                            <td>
                                <a href="invoice_details.php?id=<?= $invoiceId ?>" class="btn" style="padding: 8px 14px; font-size: 0.85rem;">
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>📄 No Invoices Found</h3>
            <?php if ($status !== '' || $search !== ''): ?>
                <p>Try adjusting your search or filters.</p>
                <a href="invoices.php" class="btn btn-primary">Clear Filters</a>
            <?php else: ?>
                <p>You don't have any invoices yet. Invoices are created after your orders are processed.</p>
                <a href="orders.php" class="btn btn-primary">View Orders</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php

customer_portal_render_layout_end();
