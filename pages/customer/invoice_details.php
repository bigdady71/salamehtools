<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];

// Get database connection
$pdo = db();

$invoiceId = (int)($_GET['id'] ?? 0);

if ($invoiceId === 0) {
    header('Location: invoices.php');
    exit;
}

// Fetch invoice details
$invoiceStmt = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.order_id,
        i.issued_at,
        i.due_date,
        i.status,
        i.total_usd as total_amount_usd,
        i.total_lbp as total_amount_lbp,
        COALESCE(SUM(p.amount_usd), 0) as paid_amount_usd,
        COALESCE(SUM(p.amount_lbp), 0) as paid_amount_lbp,
        DATEDIFF(NOW(), i.due_date) as days_overdue,
        o.customer_id,
        o.created_at,
        o.order_type
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    LEFT JOIN payments p ON p.invoice_id = i.id
    WHERE i.id = ? AND o.customer_id = ?
    GROUP BY i.id
    LIMIT 1
");
$invoiceStmt->execute([$invoiceId, $customerId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Location: invoices.php');
    exit;
}

// Fetch invoice items (order items)
$itemsStmt = $pdo->prepare("
    SELECT
        oi.id,
        oi.quantity,
        oi.unit_price_usd,
        oi.subtotal_usd,
        p.id as product_id,
        p.sku,
        p.item_name,
        p.unit
    FROM order_items oi
    INNER JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
$itemsStmt->execute([(int)$invoice['order_id']]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch payment history
$paymentsStmt = $pdo->prepare("
    SELECT
        p.id,
        p.method,
        p.amount_usd,
        p.amount_lbp,
        p.received_at,
        p.external_ref,
        u.name as received_by_name
    FROM payments p
    LEFT JOIN users u ON u.id = p.received_by_user_id
    WHERE p.invoice_id = ?
    ORDER BY p.received_at DESC
");
$paymentsStmt->execute([$invoiceId]);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = (float)$invoice['total_amount_usd'];
$paidAmount = (float)$invoice['paid_amount_usd'];
$balance = $totalAmount - $paidAmount;
$daysOverdue = (int)($invoice['days_overdue'] ?? 0);

$title = 'Invoice ' . $invoice['invoice_number'] . ' - Customer Portal';

customer_portal_render_layout_start([
    'title' => $title,
    'heading' => 'Invoice ' . $invoice['invoice_number'],
    'subtitle' => 'View invoice details and payment history',
    'customer' => $customer,
    'active' => 'invoices',
    'actions' => [
        ['label' => '← Back to Invoices', 'href' => 'invoices.php'],
    ]
]);

$statusClass = 'badge-' . strtolower($invoice['status']);
if ($daysOverdue > 0 && $invoice['status'] !== 'paid') {
    $statusClass = 'badge-overdue';
    $statusDisplay = 'Overdue (' . $daysOverdue . ' days)';
} else {
    $statusDisplay = ucfirst($invoice['status']);
}

?>

<style>
.invoice-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
}
@media (max-width: 900px) {
    .invoice-grid {
        grid-template-columns: 1fr;
    }
}
.badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 0.85rem;
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
}
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
.items-table th {
    text-align: left;
    padding: 12px;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    color: var(--text);
    font-size: 0.9rem;
}
.items-table td {
    padding: 16px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 14px 0;
    border-bottom: 1px solid var(--border);
}
.info-row:last-child {
    border-bottom: none;
}
.info-row .label {
    font-weight: 600;
    color: var(--text);
}
.info-row .value {
    color: var(--muted);
    text-align: right;
}
.total-row {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--accent);
    padding-top: 18px;
    margin-top: 12px;
    border-top: 2px solid var(--border);
}
.payment-item {
    padding: 16px;
    background: var(--bg-panel-alt);
    border-radius: 10px;
    border: 1px solid var(--border);
    margin-bottom: 12px;
}
.payment-item:last-child {
    margin-bottom: 0;
}
.payment-method {
    display: inline-block;
    padding: 4px 10px;
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
}
.alert {
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 24px;
    font-size: 0.92rem;
}
.alert-warning {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}
</style>

<?php if ($daysOverdue > 0 && $invoice['status'] !== 'paid'): ?>
    <div class="alert alert-warning">
        <strong>⚠️ Payment Overdue:</strong> This invoice is <?= $daysOverdue ?> day<?= $daysOverdue !== 1 ? 's' : '' ?> overdue.
        Please contact your sales representative to arrange payment.
    </div>
<?php endif; ?>

<div class="invoice-grid">
    <!-- Main Content -->
    <div>
        <!-- Invoice Information -->
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                <div>
                    <h2 style="margin: 0 0 8px;"><?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p style="margin: 0; color: var(--muted); font-size: 0.9rem;">
                        Issued on <?= date('F d, Y', strtotime($invoice['issued_at'])) ?>
                    </p>
                    <?php if ($invoice['due_date']): ?>
                        <p style="margin: 4px 0 0; color: var(--muted); font-size: 0.9rem;">
                            Due: <?= date('F d, Y', strtotime($invoice['due_date'])) ?>
                        </p>
                    <?php endif; ?>
                </div>
                <span class="badge <?= $statusClass ?>">
                    <?= $statusDisplay ?>
                </span>
            </div>

            <div style="padding: 16px; background: var(--bg-panel-alt); border-radius: 10px; border: 1px solid var(--border);">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                    <div>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Related Order</p>
                        <p style="margin: 4px 0 0; font-size: 1.1rem; font-weight: 600;">
                            <a href="order_details.php?id=<?= (int)$invoice['order_id'] ?>" style="color: var(--accent);">
                                #<?= (int)$invoice['order_id'] ?>
                            </a>
                        </p>
                    </div>
                    <div>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Total Amount</p>
                        <p style="margin: 4px 0 0; font-size: 1.1rem; font-weight: 600; color: var(--text);">
                            $<?= number_format($totalAmount, 2) ?>
                        </p>
                    </div>
                    <div>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Amount Paid</p>
                        <p style="margin: 4px 0 0; font-size: 1.1rem; font-weight: 600; color: var(--accent);">
                            $<?= number_format($paidAmount, 2) ?>
                        </p>
                    </div>
                    <div>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--muted); text-transform: uppercase;">Balance Due</p>
                        <p style="margin: 4px 0 0; font-size: 1.1rem; font-weight: 600; color: <?= $balance > 0 ? '#d97706' : 'var(--accent)' ?>;">
                            $<?= number_format($balance, 2) ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Items -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Invoice Items</h2>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Unit Price</th>
                        <th>Quantity</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemName = htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8');
                        $sku = htmlspecialchars($item['sku'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                        $unit = htmlspecialchars($item['unit'] ?? 'unit', ENT_QUOTES, 'UTF-8');
                        $unitPrice = (float)$item['unit_price_usd'];
                        $quantity = (float)$item['quantity'];
                        $subtotal = (float)$item['subtotal_usd'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= $itemName ?></strong><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">SKU: <?= $sku ?></span>
                            </td>
                            <td>
                                $<?= number_format($unitPrice, 2) ?><br>
                                <span style="font-size: 0.8rem; color: var(--muted);">per <?= $unit ?></span>
                            </td>
                            <td><?= number_format($quantity, 2) ?> <?= $unit ?></td>
                            <td><strong style="color: var(--accent);">$<?= number_format($subtotal, 2) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Payment History -->
        <div class="card">
            <h2>Payment History</h2>
            <?php if (count($payments) > 0): ?>
                <div style="margin-top: 20px;">
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $paymentAmount = (float)$payment['amount_usd'];
                        $paymentMethod = ucfirst(str_replace('_', ' ', $payment['method']));
                        $receivedAt = date('M d, Y g:i A', strtotime($payment['received_at']));
                        $receivedBy = htmlspecialchars($payment['received_by_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                        $externalRef = htmlspecialchars($payment['external_ref'] ?? '', ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="payment-item">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                <div>
                                    <span class="payment-method"><?= $paymentMethod ?></span>
                                    <?php if ($externalRef !== ''): ?>
                                        <span style="font-size: 0.8rem; color: var(--muted); margin-left: 8px;">
                                            Ref: <?= $externalRef ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <strong style="font-size: 1.2rem; color: var(--accent);">
                                    $<?= number_format($paymentAmount, 2) ?>
                                </strong>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--muted);">
                                <p style="margin: 4px 0 0;">
                                    Received on <?= $receivedAt ?> by <?= $receivedBy ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="margin: 20px 0 0; color: var(--muted);">
                    No payments recorded yet.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div>
        <!-- Payment Summary -->
        <div class="card" style="margin-bottom: 24px;">
            <h2>Payment Summary</h2>
            <div style="margin-top: 20px;">
                <div class="info-row">
                    <span class="label">Total Amount</span>
                    <span class="value">$<?= number_format($totalAmount, 2) ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Amount Paid</span>
                    <span class="value" style="color: var(--accent);">$<?= number_format($paidAmount, 2) ?></span>
                </div>
                <div class="info-row total-row">
                    <span>Balance Due</span>
                    <span style="color: <?= $balance > 0 ? '#d97706' : 'var(--accent)' ?>;">
                        $<?= number_format($balance, 2) ?>
                    </span>
                </div>
            </div>

            <?php if ($balance > 0): ?>
                <div style="margin-top: 24px; padding: 16px; background: var(--bg-panel-alt); border-radius: 10px; border: 1px solid var(--border);">
                    <p style="margin: 0 0 12px; font-weight: 600; color: var(--text);">Need to make a payment?</p>
                    <p style="margin: 0; font-size: 0.88rem; color: var(--muted); line-height: 1.6;">
                        Please contact your sales representative to arrange payment for this invoice.
                    </p>
                    <a href="contact.php" class="btn btn-primary" style="width: 100%; text-align: center; margin-top: 12px;">
                        📞 Contact Sales Rep
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Invoice Details -->
        <div class="card">
            <h2>Invoice Details</h2>
            <div style="margin-top: 20px;">
                <div class="info-row">
                    <span class="label">Invoice #</span>
                    <span class="value"><?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Order #</span>
                    <span class="value">
                        <a href="order_details.php?id=<?= (int)$invoice['order_id'] ?>">
                            #<?= (int)$invoice['order_id'] ?>
                        </a>
                    </span>
                </div>
                <div class="info-row">
                    <span class="label">Issued Date</span>
                    <span class="value"><?= date('M d, Y', strtotime($invoice['issued_at'])) ?></span>
                </div>
                <?php if ($invoice['due_date']): ?>
                    <div class="info-row">
                        <span class="label">Due Date</span>
                        <span class="value"><?= date('M d, Y', strtotime($invoice['due_date'])) ?></span>
                    </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="label">Status</span>
                    <span class="value">
                        <span class="badge <?= $statusClass ?>" style="font-size: 0.75rem;">
                            <?= $statusDisplay ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="label">Payments Made</span>
                    <span class="value"><?= count($payments) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php

customer_portal_render_layout_end();
