<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/admin_page.php';

use SalamehTools\Middleware\RBACMiddleware;

require_login();
RBACMiddleware::requireRole('sales_rep', 'Access denied. Sales representatives only.');

$user = auth_user();
$pdo = db();
$title = 'Collections';

$repId = $user['id'];

// Handle payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    try {
        $pdo->beginTransaction();

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $amountUsd = (float)($_POST['amount_usd'] ?? 0);
        $amountLbp = (float)($_POST['amount_lbp'] ?? 0);
        $method = $_POST['payment_method'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');

        if (empty($invoiceId)) {
            throw new Exception('Invalid invoice');
        }

        if ($amountUsd <= 0 && $amountLbp <= 0) {
            throw new Exception('Please enter a payment amount');
        }

        // Record payment
        $insertPayment = $pdo->prepare("
            INSERT INTO payments (invoice_id, amount_usd, amount_lbp, payment_method, received_by_user_id, received_at, notes, created_at)
            VALUES (:invoice_id, :amount_usd, :amount_lbp, :method, :user_id, NOW(), :notes, NOW())
        ");
        $insertPayment->execute([
            ':invoice_id' => $invoiceId,
            ':amount_usd' => $amountUsd,
            ':amount_lbp' => $amountLbp,
            ':method' => $method,
            ':user_id' => $repId,
            ':notes' => $notes
        ]);

        // Check if invoice is fully paid and update status
        $checkBalance = $pdo->prepare("
            SELECT
                i.total_usd,
                i.total_lbp,
                COALESCE(SUM(p.amount_usd), 0) as paid_usd,
                COALESCE(SUM(p.amount_lbp), 0) as paid_lbp
            FROM invoices i
            LEFT JOIN payments p ON p.invoice_id = i.id
            WHERE i.id = :invoice_id
            GROUP BY i.id
        ");
        $checkBalance->execute([':invoice_id' => $invoiceId]);
        $balance = $checkBalance->fetch(PDO::FETCH_ASSOC);

        if ($balance && $balance['paid_usd'] >= $balance['total_usd'] && $balance['paid_lbp'] >= $balance['total_lbp']) {
            // Fully paid
            $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = :id")->execute([':id' => $invoiceId]);
        }

        $pdo->commit();

        flash('success', 'Payment recorded successfully!');
        header('Location: collections.php');
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Failed to record payment: ' . $e->getMessage());
    }
}

// Get invoices with outstanding balances for this sales rep
$invoices = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.total_usd,
        i.total_lbp,
        i.status,
        i.created_at,
        c.name as customer_name,
        c.phone as customer_phone,
        COALESCE(paid.paid_usd, 0) as paid_usd,
        COALESCE(paid.paid_lbp, 0) as paid_lbp,
        (i.total_usd - COALESCE(paid.paid_usd, 0)) as balance_usd,
        (i.total_lbp - COALESCE(paid.paid_lbp, 0)) as balance_lbp
    FROM invoices i
    INNER JOIN orders o ON o.id = i.order_id
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) as paid_usd, SUM(amount_lbp) as paid_lbp
        FROM payments
        GROUP BY invoice_id
    ) paid ON paid.invoice_id = i.id
    WHERE o.sales_rep_id = :rep_id
      AND i.status IN ('issued', 'paid')
      AND (i.total_usd > COALESCE(paid.paid_usd, 0) OR i.total_lbp > COALESCE(paid.paid_lbp, 0))
    ORDER BY i.created_at DESC
    LIMIT 50
");
$invoices->execute([':rep_id' => $repId]);
$pendingInvoices = $invoices->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalOutstanding = array_sum(array_column($pendingInvoices, 'balance_usd'));

// If invoice_id specified, get full details
$selectedInvoice = null;
if (isset($_GET['invoice_id'])) {
    $invoiceId = (int)$_GET['invoice_id'];
    foreach ($pendingInvoices as $inv) {
        if ((int)$inv['id'] === $invoiceId) {
            $selectedInvoice = $inv;
            break;
        }
    }
}

admin_render_layout_start(['title' => $title, 'user' => $user]);
?>

<style>
.stat-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 30px;
}
.stat-card h3 {
    margin: 0 0 8px 0;
    font-size: 14px;
    font-weight: 500;
    color: #64748b;
    text-transform: uppercase;
}
.stat-card .value {
    font-size: 32px;
    font-weight: 700;
    color: #dc2626;
}
.section {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 24px;
}
.section h2 {
    margin: 0 0 20px 0;
    font-size: 18px;
    font-weight: 600;
    color: #1e293b;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th {
    text-align: left;
    padding: 12px;
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
}
.table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 14px;
}
.table tr:hover {
    background: #f8fafc;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #475569;
}
.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
}
.btn {
    padding: 10px 20px;
    border-radius: 6px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
}
.btn-primary {
    background: #3b82f6;
    color: white;
}
.btn-secondary {
    background: #64748b;
    color: white;
}
.empty-state {
    text-align: center;
    padding: 40px;
    color: #64748b;
}
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal.active {
    display: flex;
}
.modal-content {
    background: white;
    border-radius: 8px;
    padding: 24px;
    max-width: 500px;
    width: 90%;
}
</style>

<h1>Collections</h1>
<p style="color: #64748b; margin-bottom: 30px;">Record customer payments</p>

<!-- Total Outstanding -->
<div class="stat-card">
    <h3>Total Outstanding</h3>
    <div class="value">$<?= number_format($totalOutstanding, 2) ?></div>
</div>

<!-- Pending Invoices -->
<div class="section">
    <h2>Pending Collections (<?= count($pendingInvoices) ?>)</h2>
    <?php if (empty($pendingInvoices)): ?>
        <div class="empty-state">
            <p>All invoices are paid! Great work!</p>
        </div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance Due</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingInvoices as $inv): ?>
                <tr>
                    <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                    <td>
                        <?= htmlspecialchars($inv['customer_name'] ?? 'N/A') ?>
                        <?php if ($inv['customer_phone']): ?>
                            <br><small style="color: #64748b;"><?= htmlspecialchars($inv['customer_phone']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= date('M j, Y', strtotime($inv['created_at'])) ?></td>
                    <td>
                        $<?= number_format($inv['total_usd'], 2) ?>
                        <?php if ($inv['total_lbp'] > 0): ?>
                            <br><small><?= number_format($inv['total_lbp'], 0) ?> LBP</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        $<?= number_format($inv['paid_usd'], 2) ?>
                        <?php if ($inv['paid_lbp'] > 0): ?>
                            <br><small><?= number_format($inv['paid_lbp'], 0) ?> LBP</small>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight: 600; color: #dc2626;">
                        $<?= number_format($inv['balance_usd'], 2) ?>
                        <?php if ($inv['balance_lbp'] > 0): ?>
                            <br><small><?= number_format($inv['balance_lbp'], 0) ?> LBP</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button onclick="openPaymentModal(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>', <?= $inv['balance_usd'] ?>, <?= $inv['balance_lbp'] ?>)" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                            Record Payment
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div style="margin-top: 30px;">
    <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <h2 style="margin-top: 0;">Record Payment</h2>
        <form method="POST">
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="invoice_id" id="modal_invoice_id">

            <div class="form-group">
                <label>Invoice: <strong id="modal_invoice_number"></strong></label>
                <label>Balance: <strong style="color: #dc2626;">$<span id="modal_balance_usd"></span></strong></label>
            </div>

            <div class="form-group">
                <label for="amount_usd">Amount (USD) *</label>
                <input type="number" name="amount_usd" id="amount_usd" class="form-control" step="0.01" min="0" required>
            </div>

            <div class="form-group">
                <label for="amount_lbp">Amount (LBP)</label>
                <input type="number" name="amount_lbp" id="amount_lbp" class="form-control" step="1" min="0" value="0">
            </div>

            <div class="form-group">
                <label for="payment_method">Payment Method *</label>
                <select name="payment_method" id="payment_method" class="form-control" required>
                    <option value="cash">Cash</option>
                    <option value="qr">QR Code</option>
                    <option value="card">Credit Card</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="check">Check</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label for="notes">Notes (Optional)</label>
                <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Reference number, check number, etc..."></textarea>
            </div>

            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" class="btn btn-primary">Record Payment</button>
                <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPaymentModal(invoiceId, invoiceNumber, balanceUsd, balanceLbp) {
    document.getElementById('modal_invoice_id').value = invoiceId;
    document.getElementById('modal_invoice_number').textContent = invoiceNumber;
    document.getElementById('modal_balance_usd').textContent = balanceUsd.toFixed(2);
    document.getElementById('amount_usd').value = balanceUsd.toFixed(2);
    document.getElementById('amount_lbp').value = balanceLbp > 0 ? balanceLbp : 0;
    document.getElementById('paymentModal').classList.add('active');
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('active');
}

// Close modal on background click
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePaymentModal();
    }
});

// Auto-open modal if invoice_id in URL
<?php if ($selectedInvoice): ?>
openPaymentModal(
    <?= $selectedInvoice['id'] ?>,
    '<?= htmlspecialchars($selectedInvoice['invoice_number']) ?>',
    <?= $selectedInvoice['balance_usd'] ?>,
    <?= $selectedInvoice['balance_lbp'] ?>
);
<?php endif; ?>
</script>

<?php admin_render_layout_end(); ?>
