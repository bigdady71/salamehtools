<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$repId = (int)$user['id'];

$pdo = db();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$flashes = [];

// Get active exchange rate
$exchangeRate = null;
$exchangeRateId = null;
$exchangeRateError = false;

try {
    $rateStmt = $pdo->prepare("
        SELECT id, rate
        FROM exchange_rates
        WHERE UPPER(base_currency) = 'USD'
          AND UPPER(quote_currency) IN ('LBP', 'LEBP')
        ORDER BY valid_from DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    $rateStmt->execute();
    $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if ($rateRow && (float)$rateRow['rate'] > 0) {
        $exchangeRate = (float)$rateRow['rate'];
        $exchangeRateId = (int)$rateRow['id'];
    } else {
        $exchangeRateError = true;
    }
} catch (PDOException $e) {
    $exchangeRateError = true;
    error_log("Failed to fetch exchange rate: " . $e->getMessage());
}

// Handle payment collection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'collect_payment') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $flashes[] = [
            'type' => 'error',
            'title' => 'Security Error',
            'message' => 'Invalid or expired CSRF token. Please try again.',
        ];
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $paymentUSD = (float)($_POST['payment_usd'] ?? 0);
        $paymentLBP = (float)($_POST['payment_lbp'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));

        $errors = [];

        // Validate customer
        if ($customerId <= 0) {
            $errors[] = 'Please select a customer.';
        } else {
            // Verify customer is assigned to this sales rep
            $customerStmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = :id AND assigned_sales_rep_id = :rep_id AND is_active = 1");
            $customerStmt->execute([':id' => $customerId, ':rep_id' => $repId]);
            $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                $errors[] = 'Invalid customer selected or customer not assigned to you.';
            }
        }

        // Convert LBP to USD equivalent
        $paymentLBPinUSD = $exchangeRate > 0 ? $paymentLBP / $exchangeRate : 0;
        $totalPaymentUSD = $paymentUSD + $paymentLBPinUSD;

        if ($totalPaymentUSD <= 0) {
            $errors[] = 'Please enter a payment amount.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                // Get customer's unpaid invoices ordered by oldest first
                $unpaidStmt = $pdo->prepare("
                    SELECT
                        i.id as invoice_id,
                        i.invoice_number,
                        i.total_usd,
                        COALESCE(pay.paid_usd, 0) as paid_usd,
                        (i.total_usd - COALESCE(pay.paid_usd, 0)) as remaining_usd
                    FROM invoices i
                    INNER JOIN orders o ON o.id = i.order_id
                    LEFT JOIN (
                        SELECT invoice_id, SUM(amount_usd) as paid_usd
                        FROM payments
                        GROUP BY invoice_id
                    ) pay ON pay.invoice_id = i.id
                    WHERE o.customer_id = :customer_id
                      AND i.status IN ('issued', 'paid')
                      AND (i.total_usd - COALESCE(pay.paid_usd, 0)) > 0.01
                    ORDER BY i.issued_at ASC
                ");
                $unpaidStmt->execute([':customer_id' => $customerId]);
                $unpaidInvoices = $unpaidStmt->fetchAll(PDO::FETCH_ASSOC);

                $remainingPayment = $totalPaymentUSD;
                $paidInvoices = [];

                // Apply payment to invoices (oldest first)
                $paymentStmt = $pdo->prepare("
                    INSERT INTO payments (
                        invoice_id, method, amount_usd, amount_lbp,
                        received_by_user_id, received_at
                    ) VALUES (
                        :invoice_id, 'cash', :amount_usd, :amount_lbp,
                        :received_by, NOW()
                    )
                ");

                $updateInvoiceStmt = $pdo->prepare("
                    UPDATE invoices SET status = 'paid' WHERE id = :id
                ");

                foreach ($unpaidInvoices as $invoice) {
                    if ($remainingPayment <= 0.01) break;

                    $invoiceRemaining = (float)$invoice['remaining_usd'];
                    $paymentForThisInvoice = min($remainingPayment, $invoiceRemaining);

                    // Record payment for this invoice
                    $paymentStmt->execute([
                        ':invoice_id' => $invoice['invoice_id'],
                        ':amount_usd' => $paymentForThisInvoice,
                        ':amount_lbp' => $paymentForThisInvoice * $exchangeRate,
                        ':received_by' => $repId,
                    ]);

                    // Check if invoice is now fully paid
                    $newRemaining = $invoiceRemaining - $paymentForThisInvoice;
                    if ($newRemaining < 0.01) {
                        $updateInvoiceStmt->execute([':id' => $invoice['invoice_id']]);
                    }

                    $paidInvoices[] = [
                        'invoice_number' => $invoice['invoice_number'],
                        'amount' => $paymentForThisInvoice,
                        'fully_paid' => $newRemaining < 0.01,
                    ];

                    $remainingPayment -= $paymentForThisInvoice;
                }

                // If there's overpayment, add to customer's credit balance
                if ($remainingPayment > 0.01) {
                    $creditLBP = $remainingPayment * $exchangeRate;
                    $balanceStmt = $pdo->prepare("
                        UPDATE customers
                        SET account_balance_lbp = COALESCE(account_balance_lbp, 0) + :balance
                        WHERE id = :customer_id
                    ");
                    $balanceStmt->execute([
                        ':balance' => $creditLBP,
                        ':customer_id' => $customerId,
                    ]);
                }

                // Generate receipt number
                $receiptNumber = 'RCP-' . $customerId . '-' . $repId . '-' . date('YmdHis');

                $pdo->commit();

                // Redirect to print receipt
                $_SESSION['payment_receipt'] = [
                    'receipt_number' => $receiptNumber,
                    'customer_id' => $customerId,
                    'customer_name' => $customer['name'],
                    'payment_usd' => $paymentUSD,
                    'payment_lbp' => $paymentLBP,
                    'total_usd' => $totalPaymentUSD,
                    'paid_invoices' => $paidInvoices,
                    'credit_added' => $remainingPayment > 0.01 ? $remainingPayment : 0,
                    'notes' => $notes,
                    'date' => date('Y-m-d H:i:s'),
                    'sales_rep' => $user['name'],
                    'exchange_rate' => $exchangeRate,
                ];

                header('Location: print_payment_receipt.php');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Failed to collect payment: " . $e->getMessage());
                $flashes[] = [
                    'type' => 'error',
                    'title' => 'Database Error',
                    'message' => 'Unable to process payment. Please try again.',
                ];
            }
        } else {
            $flashes[] = [
                'type' => 'error',
                'title' => 'Validation Failed',
                'message' => implode(' ', $errors),
            ];
        }
    }
}

// Get sales rep's customers with their balances
$customersStmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.phone,
        c.location,
        COALESCE(c.account_balance_lbp, 0) as credit_lbp,
        COALESCE(outstanding.total_due, 0) as outstanding_usd
    FROM customers c
    LEFT JOIN (
        SELECT
            o.customer_id,
            SUM(i.total_usd - COALESCE(pay.paid_usd, 0)) as total_due
        FROM invoices i
        INNER JOIN orders o ON o.id = i.order_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_usd) as paid_usd
            FROM payments
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = i.id
        WHERE i.status IN ('issued', 'paid')
          AND (i.total_usd - COALESCE(pay.paid_usd, 0)) > 0.01
        GROUP BY o.customer_id
    ) outstanding ON outstanding.customer_id = c.id
    WHERE c.assigned_sales_rep_id = :rep_id AND c.is_active = 1
    ORDER BY c.name
");
$customersStmt->execute([':rep_id' => $repId]);
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = csrf_token();

sales_portal_render_layout_start([
    'title' => 'Collect Payment',
    'heading' => 'Collect Payment',
    'subtitle' => 'Record payments from customers',
    'active' => 'collect_payment',
    'user' => $user,
    'extra_head' => '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no"><style>
        .payment-form {
            background: var(--bg-panel);
            border-radius: 14px;
            padding: 28px;
            border: 1px solid var(--border);
            max-width: 600px;
            margin: 0 auto;
        }
        .form-section {
            margin-bottom: 28px;
        }
        .form-section h3 {
            margin: 0 0 16px;
            font-size: 1.1rem;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.1);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .customer-card {
            background: #dcfce7;
            border: 2px solid #22c55e;
            border-radius: 12px;
            padding: 16px;
            margin-top: 12px;
            display: none;
        }
        .customer-card.show {
            display: block;
        }
        .customer-card h4 {
            margin: 0 0 8px;
            color: #166534;
        }
        .customer-card .balance-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        .customer-card .balance-row:last-child {
            border-bottom: none;
        }
        .customer-card .balance-row.outstanding {
            color: #dc2626;
            font-weight: 700;
        }
        .customer-card .balance-row.credit {
            color: #059669;
            font-weight: 700;
        }
        .payment-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .payment-summary {
            background: #f0f9ff;
            border: 2px solid #0ea5e9;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }
        .payment-summary h4 {
            margin: 0 0 12px;
            color: #0369a1;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 1rem;
        }
        .summary-row.total {
            font-size: 1.3rem;
            font-weight: 800;
            color: #166534;
            border-top: 2px solid #0ea5e9;
            margin-top: 8px;
            padding-top: 12px;
        }
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1.3rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 20px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.4);
        }
        .btn-submit:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .flash {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        .flash.error {
            background: #fee2e2;
            border-color: #dc2626;
            color: #991b1b;
        }
        .flash h4 {
            margin: 0 0 8px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 16px;
        }
        @media (max-width: 600px) {
            .payment-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>',
]);

// Display flash messages
foreach ($flashes as $flash) {
    $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($flash['title'], ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');

    echo '<div class="flash ' . $type . '">';
    echo '<h4>' . $title . '</h4>';
    echo '<p>' . $message . '</p>';
    echo '</div>';
}

if ($exchangeRateError || $exchangeRate === null) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">⚠️</div>';
    echo '<h3>System Unavailable</h3>';
    echo '<p>Exchange rate is not configured. Please contact your administrator.</p>';
    echo '</div>';
} elseif (empty($customers)) {
    echo '<div class="empty-state">';
    echo '<div class="empty-state-icon">👥</div>';
    echo '<h3>No Customers</h3>';
    echo '<p>You need to have customers assigned to you to collect payments.</p>';
    echo '</div>';
} else {
?>
    <form method="POST" id="paymentForm" class="payment-form">
        <input type="hidden" name="action" value="collect_payment">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-section">
            <h3>👤 Select Customer</h3>
            <div class="form-group">
                <label>Customer <span style="color:red;">*</span></label>
                <select name="customer_id" id="customerSelect" required onchange="updateCustomerInfo()">
                    <option value="">-- Select a customer --</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= $customer['id'] ?>"
                                data-name="<?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-phone="<?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-outstanding="<?= (float)$customer['outstanding_usd'] ?>"
                                data-credit="<?= (float)$customer['credit_lbp'] ?>">
                            <?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($customer['outstanding_usd'] > 0): ?>
                                - Owes: $<?= number_format((float)$customer['outstanding_usd'], 2) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="customer-card" id="customerCard">
                <h4 id="customerName"></h4>
                <div class="balance-row">
                    <span>Phone:</span>
                    <span id="customerPhone"></span>
                </div>
                <div class="balance-row outstanding">
                    <span>Outstanding Balance:</span>
                    <span id="customerOutstanding">$0.00</span>
                </div>
                <div class="balance-row credit">
                    <span>Credit Balance:</span>
                    <span id="customerCredit">L.L. 0</span>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h3>💵 Payment Amount</h3>
            <div class="payment-grid">
                <div class="form-group">
                    <label>USD $</label>
                    <input type="number" name="payment_usd" id="paymentUSD" step="0.01" min="0" placeholder="0.00" oninput="updatePaymentSummary()">
                </div>
                <div class="form-group">
                    <label>LBP L.L.</label>
                    <input type="number" name="payment_lbp" id="paymentLBP" step="1000" min="0" placeholder="0" oninput="updatePaymentSummary()">
                </div>
            </div>

            <div class="payment-summary" id="paymentSummary" style="display:none;">
                <h4>Payment Summary</h4>
                <div class="summary-row">
                    <span>USD Payment:</span>
                    <span id="summaryUSD">$0.00</span>
                </div>
                <div class="summary-row">
                    <span>LBP Payment:</span>
                    <span id="summaryLBP">L.L. 0</span>
                </div>
                <div class="summary-row">
                    <span>LBP in USD equivalent:</span>
                    <span id="summaryLBPinUSD">$0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total Payment:</span>
                    <span id="summaryTotal">$0.00</span>
                </div>
                <div class="summary-row" id="remainingRow" style="display:none;">
                    <span>Remaining Balance After:</span>
                    <span id="summaryRemaining">$0.00</span>
                </div>
                <div class="summary-row" id="creditRow" style="display:none; color:#059669;">
                    <span>Added to Credit:</span>
                    <span id="summaryCredit">$0.00</span>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h3>📝 Notes (Optional)</h3>
            <div class="form-group">
                <textarea name="notes" placeholder="Add any notes about this payment..."></textarea>
            </div>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn" disabled>
            💵 Record Payment & Print Receipt
        </button>
    </form>

    <script>
        const exchangeRate = <?= $exchangeRate ?>;
        let selectedCustomer = null;

        function updateCustomerInfo() {
            const select = document.getElementById('customerSelect');
            const card = document.getElementById('customerCard');
            const option = select.options[select.selectedIndex];

            if (!option.value) {
                card.classList.remove('show');
                selectedCustomer = null;
                updatePaymentSummary();
                return;
            }

            selectedCustomer = {
                id: option.value,
                name: option.dataset.name,
                phone: option.dataset.phone,
                outstanding: parseFloat(option.dataset.outstanding) || 0,
                credit: parseFloat(option.dataset.credit) || 0,
            };

            document.getElementById('customerName').textContent = selectedCustomer.name;
            document.getElementById('customerPhone').textContent = selectedCustomer.phone || 'N/A';
            document.getElementById('customerOutstanding').textContent = '$' + selectedCustomer.outstanding.toFixed(2);

            const creditUSD = selectedCustomer.credit / exchangeRate;
            document.getElementById('customerCredit').textContent = 'L.L. ' + Math.round(selectedCustomer.credit).toLocaleString() + ' ($' + creditUSD.toFixed(2) + ')';

            card.classList.add('show');
            updatePaymentSummary();
        }

        function updatePaymentSummary() {
            const paymentUSD = parseFloat(document.getElementById('paymentUSD').value) || 0;
            const paymentLBP = parseFloat(document.getElementById('paymentLBP').value) || 0;
            const summary = document.getElementById('paymentSummary');
            const submitBtn = document.getElementById('submitBtn');

            const paymentLBPinUSD = paymentLBP / exchangeRate;
            const totalPayment = paymentUSD + paymentLBPinUSD;

            if (totalPayment > 0) {
                summary.style.display = 'block';

                document.getElementById('summaryUSD').textContent = '$' + paymentUSD.toFixed(2);
                document.getElementById('summaryLBP').textContent = 'L.L. ' + Math.round(paymentLBP).toLocaleString();
                document.getElementById('summaryLBPinUSD').textContent = '$' + paymentLBPinUSD.toFixed(2);
                document.getElementById('summaryTotal').textContent = '$' + totalPayment.toFixed(2);

                if (selectedCustomer) {
                    const remaining = Math.max(0, selectedCustomer.outstanding - totalPayment);
                    const credit = Math.max(0, totalPayment - selectedCustomer.outstanding);

                    document.getElementById('remainingRow').style.display = remaining > 0 ? 'flex' : 'none';
                    document.getElementById('summaryRemaining').textContent = '$' + remaining.toFixed(2);

                    document.getElementById('creditRow').style.display = credit > 0 ? 'flex' : 'none';
                    document.getElementById('summaryCredit').textContent = '$' + credit.toFixed(2);
                }

                submitBtn.disabled = !selectedCustomer;
            } else {
                summary.style.display = 'none';
                submitBtn.disabled = true;
            }
        }

        // Form validation
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            const customerId = document.getElementById('customerSelect').value;
            const paymentUSD = parseFloat(document.getElementById('paymentUSD').value) || 0;
            const paymentLBP = parseFloat(document.getElementById('paymentLBP').value) || 0;

            if (!customerId) {
                e.preventDefault();
                alert('Please select a customer.');
                return false;
            }

            if (paymentUSD <= 0 && paymentLBP <= 0) {
                e.preventDefault();
                alert('Please enter a payment amount.');
                return false;
            }

            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').textContent = 'Processing...';
        });
    </script>
<?php
}

sales_portal_render_layout_end();
