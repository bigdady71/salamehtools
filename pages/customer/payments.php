<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

// Get filter
$method = trim($_GET['method'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = ['o.customer_id = ?'];
$params = [$customerId];

if ($method !== '' && $method !== 'all') {
    $where[] = 'p.method = ?';
    $params[] = $method;
}

$whereClause = implode(' AND ', $where);

// Get total count for pagination
$countQuery = "SELECT COUNT(p.id) FROM payments p INNER JOIN invoices i ON i.id = p.invoice_id INNER JOIN orders o ON o.id = i.order_id WHERE {$whereClause}";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalPayments = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalPayments / $perPage);

// Fetch payments
$paymentsQuery = "
    SELECT
        p.id,
        p.method,
        p.amount_usd,
        p.amount_lbp,
        p.received_at,
        p.external_ref,
        i.id as invoice_id,
        i.invoice_number,
        u.name as received_by_name
    FROM payments p
    INNER JOIN invoices i ON i.id = p.invoice_id
    INNER JOIN orders o ON o.id = i.order_id
    LEFT JOIN users u ON u.id = p.received_by_user_id
    WHERE {$whereClause}
    ORDER BY p.received_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$paymentsStmt = $pdo->prepare($paymentsQuery);
$paymentsStmt->execute($params);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment statistics
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_payments,
        COALESCE(SUM(p.amount_usd), 0) as total_paid_usd,
        COUNT(CASE WHEN p.method = 'cash' THEN 1 END) as cash_count,
        COUNT(CASE WHEN p.method = 'card' THEN 1 END) as card_count,
        COUNT(CASE WHEN p.method = 'bank' THEN 1 END) as bank_count,
        COUNT(CASE WHEN p.method = 'qr_cash' THEN 1 END) as qr_count
    FROM payments p
    INNER JOIN invoices i ON i.id = p.invoice_id
    INNER JOIN orders o ON o.id = i.order_id
    WHERE o.customer_id = ?
");
$statsStmt->execute([$customerId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
$stats['total_payments'] = (int)$stats['total_payments'];
$stats['total_paid_usd'] = (float)$stats['total_paid_usd'];
$stats['cash_count'] = (int)$stats['cash_count'];
$stats['card_count'] = (int)$stats['card_count'];
$stats['bank_count'] = (int)$stats['bank_count'];
$stats['qr_count'] = (int)$stats['qr_count'];

$title = 'Payment History - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Payment History',
    'subtitle' => 'View all your payment transactions',
    'customer' => $customer,
    'active' => 'payments',
    'actions' => [
        ['label' => '📄 View Invoices', 'href' => 'invoices.php'],
    ]
]);

?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
.filters-card {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 24px;
    border: 1px solid var(--border);
    margin-bottom: 24px;
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
.form-group select {
    width: 100%;
    max-width: 300px;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    transition: all 0.2s;
}
.form-group select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}
.payments-table {
    width: 100%;
    border-collapse: collapse;
}
.payments-table th {
    text-align: left;
    padding: 14px 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
    font-size: 0.9rem;
}
.payments-table td {
    padding: 18px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.payments-table tr:hover {
    background: var(--bg-panel-alt);
}
.payment-method-badge {
    display: inline-block;
    padding: 4px 10px;
    background: var(--bg-panel-alt);
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
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
</style>

<!-- Payment Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <p class="value"><?= $stats['total_payments'] ?></p>
        <p class="label">Total Payments</p>
    </div>
    <div class="stat-card">
        <p class="value">$<?= number_format($stats['total_paid_usd'], 2) ?></p>
        <p class="label">Total Amount Paid</p>
    </div>
    <div class="stat-card">
        <p class="value"><?= $stats['cash_count'] ?></p>
        <p class="label">Cash Payments</p>
    </div>
    <div class="stat-card">
        <p class="value"><?= $stats['card_count'] ?></p>
        <p class="label">Card Payments</p>
    </div>
    <div class="stat-card">
        <p class="value"><?= $stats['bank_count'] ?></p>
        <p class="label">Bank Transfers</p>
    </div>
</div>

<!-- Filters -->
<div class="filters-card">
    <form method="get" action="payments.php">
        <div class="form-group">
            <label for="method">Filter by Payment Method</label>
            <select id="method" name="method" onchange="this.form.submit()">
                <option value="all" <?= $method === 'all' || $method === '' ? 'selected' : '' ?>>All Methods</option>
                <option value="cash" <?= $method === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="card" <?= $method === 'card' ? 'selected' : '' ?>>Card</option>
                <option value="bank" <?= $method === 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                <option value="qr_cash" <?= $method === 'qr_cash' ? 'selected' : '' ?>>QR Cash</option>
                <option value="other" <?= $method === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
        </div>
    </form>
</div>

<!-- Payments Table -->
<div class="card">
    <h2>Payment Transactions</h2>
    <?php if (count($payments) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Invoice</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Received By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $receivedAt = date('M d, Y g:i A', strtotime($payment['received_at']));
                        $invoiceId = (int)$payment['invoice_id'];
                        $invoiceNumber = htmlspecialchars($payment['invoice_number'], ENT_QUOTES, 'UTF-8');
                        $paymentMethod = ucfirst(str_replace('_', ' ', $payment['method']));
                        $externalRef = htmlspecialchars($payment['external_ref'] ?? '-', ENT_QUOTES, 'UTF-8');
                        $amount = (float)$payment['amount_usd'];
                        $receivedBy = htmlspecialchars($payment['received_by_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td>
                                <strong><?= date('M d, Y', strtotime($payment['received_at'])) ?></strong><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">
                                    <?= date('g:i A', strtotime($payment['received_at'])) ?>
                                </span>
                            </td>
                            <td>
                                <a href="invoice_details.php?id=<?= $invoiceId ?>" style="color: var(--accent); font-weight: 600;">
                                    <?= $invoiceNumber ?>
                                </a>
                            </td>
                            <td>
                                <span class="payment-method-badge"><?= $paymentMethod ?></span>
                            </td>
                            <td style="font-size: 0.9rem; color: var(--muted);">
                                <?= $externalRef ?>
                            </td>
                            <td>
                                <strong style="color: var(--accent); font-size: 1.15rem;">
                                    $<?= number_format($amount, 2) ?>
                                </strong>
                            </td>
                            <td style="font-size: 0.9rem; color: var(--muted);">
                                <?= $receivedBy ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 32px; flex-wrap: wrap;">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="btn">« First</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn">‹ Previous</a>
                <?php endif; ?>

                <span style="padding: 8px 16px; color: var(--muted); font-weight: 600;">
                    Page <?= $page ?> of <?= $totalPages ?> (<?= $totalPayments ?> total)
                </span>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn">Next ›</a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="btn">Last »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state">
            <h3>💳 No Payments Found</h3>
            <?php if ($method !== '' && $method !== 'all'): ?>
                <p>No payments found with this payment method.</p>
                <a href="payments.php" class="btn btn-primary">View All Payments</a>
            <?php else: ?>
                <p>You haven't made any payments yet.</p>
                <a href="invoices.php" class="btn btn-primary">View Invoices</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php

customer_portal_render_layout_end();
