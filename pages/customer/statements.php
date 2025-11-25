<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

// Get date range filter
$period = trim($_GET['period'] ?? '30');
$startDate = match($period) {
    '7' => date('Y-m-d', strtotime('-7 days')),
    '30' => date('Y-m-d', strtotime('-30 days')),
    '90' => date('Y-m-d', strtotime('-90 days')),
    'year' => date('Y-m-d', strtotime('-1 year')),
    'all' => '2000-01-01',
    default => date('Y-m-d', strtotime('-30 days'))
};

// Fetch account transactions (invoices and payments)
$transactionsQuery = "
    SELECT
        'invoice' as type,
        i.id as transaction_id,
        i.invoice_number as reference,
        i.issued_at as transaction_date,
        i.total_usd as debit,
        0 as credit,
        i.status
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    WHERE o.customer_id = ? AND DATE(i.issued_at) >= ?

    UNION ALL

    SELECT
        'payment' as type,
        p.id as transaction_id,
        i.invoice_number as reference,
        p.received_at as transaction_date,
        0 as debit,
        p.amount_usd as credit,
        NULL as status
    FROM payments p
    INNER JOIN invoices i ON i.id = p.invoice_id
    INNER JOIN orders o ON o.id = i.order_id
    WHERE o.customer_id = ? AND DATE(p.received_at) >= ?

    ORDER BY transaction_date DESC, type DESC
";

$transactionsStmt = $pdo->prepare($transactionsQuery);
$transactionsStmt->execute([$customerId, $startDate, $customerId, $startDate]);
$transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate running balance
$currentBalance = (float)$customer['account_balance_lbp']; // Starting from current balance
$runningBalance = $currentBalance;

// Reverse transactions to calculate from oldest to newest
$reversedTransactions = array_reverse($transactions);
$balance = 0;
foreach ($reversedTransactions as &$transaction) {
    $debit = (float)$transaction['debit'];
    $credit = (float)$transaction['credit'];
    $balance += $debit - $credit;
    $transaction['running_balance'] = $balance;
}
unset($transaction);

// Reverse back to show newest first
$transactions = array_reverse($reversedTransactions);

// Get summary statistics
$totalInvoiced = 0;
$totalPaid = 0;
foreach ($transactions as $trans) {
    $totalInvoiced += (float)$trans['debit'];
    $totalPaid += (float)$trans['credit'];
}

$title = 'Account Statements - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Account Statements',
    'subtitle' => 'View your account activity and balance',
    'customer' => $customer,
    'active' => 'statements'
]);

?>

<style>
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}
.summary-card {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(16, 185, 129, 0.2);
}
.summary-card.secondary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
}
.summary-card.warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}
.summary-card h3 {
    margin: 0 0 8px;
    font-size: 0.85rem;
    font-weight: 500;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.summary-card .value {
    font-size: 2rem;
    font-weight: 800;
    margin: 0;
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
.transactions-table {
    width: 100%;
    border-collapse: collapse;
}
.transactions-table th {
    text-align: left;
    padding: 14px 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
    font-size: 0.9rem;
}
.transactions-table td {
    padding: 18px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.transactions-table tr:hover {
    background: var(--bg-panel-alt);
}
.transaction-type {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}
.type-invoice {
    background: #fef3c7;
    color: #92400e;
}
.type-payment {
    background: #d1fae5;
    color: #065f46;
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

<!-- Summary Cards -->
<div class="summary-cards">
    <div class="summary-card">
        <h3>Current Balance</h3>
        <p class="value">$<?= number_format($balance, 2) ?></p>
    </div>
    <div class="summary-card secondary">
        <h3>Total Invoiced</h3>
        <p class="value">$<?= number_format($totalInvoiced, 2) ?></p>
    </div>
    <div class="summary-card warning">
        <h3>Total Paid</h3>
        <p class="value">$<?= number_format($totalPaid, 2) ?></p>
    </div>
</div>

<!-- Filters -->
<div class="filters-card">
    <form method="get" action="statements.php">
        <div class="form-group">
            <label for="period">Statement Period</label>
            <select id="period" name="period" onchange="this.form.submit()">
                <option value="7" <?= $period === '7' ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="30" <?= $period === '30' ? 'selected' : '' ?>>Last 30 Days</option>
                <option value="90" <?= $period === '90' ? 'selected' : '' ?>>Last 90 Days</option>
                <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Last Year</option>
                <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All Time</option>
            </select>
        </div>
    </form>
</div>

<!-- Transactions Table -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0;">Account Activity</h2>
        <span style="font-size: 0.9rem; color: var(--muted);">
            Period: <?= date('M d, Y', strtotime($startDate)) ?> - <?= date('M d, Y') ?>
        </span>
    </div>

    <?php if (count($transactions) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Debit</th>
                        <th>Credit</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                        $transDate = date('M d, Y', strtotime($transaction['transaction_date']));
                        $type = htmlspecialchars($transaction['type'], ENT_QUOTES, 'UTF-8');
                        $typeDisplay = ucfirst($type);
                        $typeClass = 'type-' . $type;
                        $reference = htmlspecialchars($transaction['reference'], ENT_QUOTES, 'UTF-8');
                        $debit = (float)$transaction['debit'];
                        $credit = (float)$transaction['credit'];
                        $runningBalance = (float)$transaction['running_balance'];
                        ?>
                        <tr>
                            <td><?= $transDate ?></td>
                            <td>
                                <span class="transaction-type <?= $typeClass ?>">
                                    <?= $typeDisplay ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($type === 'invoice'): ?>
                                    <a href="invoice_details.php?id=<?= (int)$transaction['transaction_id'] ?>" style="color: var(--accent); font-weight: 600;">
                                        <?= $reference ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--muted);"><?= $reference ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($debit > 0): ?>
                                    <strong style="color: #d97706;">$<?= number_format($debit, 2) ?></strong>
                                <?php else: ?>
                                    <span style="color: var(--muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($credit > 0): ?>
                                    <strong style="color: var(--accent);">$<?= number_format($credit, 2) ?></strong>
                                <?php else: ?>
                                    <span style="color: var(--muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: <?= $runningBalance > 0 ? '#d97706' : 'var(--accent)' ?>;">
                                    $<?= number_format($runningBalance, 2) ?>
                                </strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 24px; padding: 20px; background: var(--bg-panel-alt); border-radius: 10px; border: 1px solid var(--border);">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div>
                    <p style="margin: 0 0 4px; font-size: 0.85rem; color: var(--muted); text-transform: uppercase;">Total Debits</p>
                    <p style="margin: 0; font-size: 1.3rem; font-weight: 700; color: #d97706;">
                        $<?= number_format($totalInvoiced, 2) ?>
                    </p>
                </div>
                <div>
                    <p style="margin: 0 0 4px; font-size: 0.85rem; color: var(--muted); text-transform: uppercase;">Total Credits</p>
                    <p style="margin: 0; font-size: 1.3rem; font-weight: 700; color: var(--accent);">
                        $<?= number_format($totalPaid, 2) ?>
                    </p>
                </div>
                <div>
                    <p style="margin: 0 0 4px; font-size: 0.85rem; color: var(--muted); text-transform: uppercase;">Net Change</p>
                    <p style="margin: 0; font-size: 1.3rem; font-weight: 700; color: <?= $balance > 0 ? '#d97706' : 'var(--accent)' ?>;">
                        $<?= number_format($balance, 2) ?>
                    </p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>📈 No Transactions Found</h3>
            <p>No account activity for the selected period.</p>
            <a href="statements.php?period=all" class="btn btn-primary">View All Time</a>
        </div>
    <?php endif; ?>
</div>

<?php

customer_portal_render_layout_end();
