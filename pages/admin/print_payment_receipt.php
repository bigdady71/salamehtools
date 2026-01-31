<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/sales_portal.php';

$user = sales_portal_bootstrap();
$pdo = db();

// Get receipt data from session
if (!isset($_SESSION['payment_receipt'])) {
    header('Location: collect_payment.php');
    exit;
}

$receipt = $_SESSION['payment_receipt'];

// Get updated customer balance
$customerId = (int)$receipt['customer_id'];
$balanceStmt = $pdo->prepare("
    SELECT
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
    WHERE c.id = :customer_id
");
$balanceStmt->execute([':customer_id' => $customerId]);
$customerBalance = $balanceStmt->fetch(PDO::FETCH_ASSOC);

$remainingBalance = (float)($customerBalance['outstanding_usd'] ?? 0);
$creditBalance = (float)($customerBalance['credit_lbp'] ?? 0);
$exchangeRate = (float)$receipt['exchange_rate'];

// Clear session after reading
unset($_SESSION['payment_receipt']);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إيصال دفعة - <?= htmlspecialchars($receipt['receipt_number'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            @page {
                size: A4;
                margin: 10mm;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            direction: rtl;
            text-align: right;
        }

        .receipt {
            max-width: 400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px dashed #ddd;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 1.8rem;
            color: #166534;
            margin-bottom: 8px;
        }

        .header .subtitle {
            font-size: 1.2rem;
            color: #059669;
            font-weight: 600;
        }

        .receipt-number {
            background: #dcfce7;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
            font-family: monospace;
            font-size: 1rem;
            color: #166534;
        }

        .section {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .section-title {
            font-size: 0.9rem;
            color: #6b7280;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 1rem;
        }

        .row.highlight {
            background: #f0fdf4;
            margin: 0 -10px;
            padding: 12px 10px;
            border-radius: 8px;
            font-weight: 700;
            color: #166534;
            font-size: 1.2rem;
        }

        .row.warning {
            background: #fef3c7;
            margin: 0 -10px;
            padding: 12px 10px;
            border-radius: 8px;
            font-weight: 700;
            color: #92400e;
        }

        .row.danger {
            background: #fee2e2;
            margin: 0 -10px;
            padding: 12px 10px;
            border-radius: 8px;
            font-weight: 700;
            color: #991b1b;
        }

        .row.success {
            background: #dcfce7;
            margin: 0 -10px;
            padding: 12px 10px;
            border-radius: 8px;
            font-weight: 700;
            color: #166534;
        }

        .invoices-list {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
        }

        .invoice-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.9rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .invoice-item:last-child {
            border-bottom: none;
        }

        .invoice-item .status {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }

        .invoice-item .status.paid {
            background: #dcfce7;
            color: #166534;
        }

        .invoice-item .status.partial {
            background: #fef3c7;
            color: #92400e;
        }

        .notes {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #475569;
            margin-top: 10px;
        }

        .footer {
            text-align: center;
            padding-top: 20px;
            border-top: 2px dashed #ddd;
            margin-top: 20px;
        }

        .footer .date {
            font-size: 0.9rem;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .footer .rep {
            font-size: 0.85rem;
            color: #9ca3af;
        }

        .footer .thank-you {
            font-size: 1.1rem;
            color: #059669;
            font-weight: 600;
            margin-top: 16px;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        .btn {
            flex: 1;
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
        }

        .btn-print {
            background: #22c55e;
            color: white;
        }

        .btn-print:hover {
            background: #16a34a;
        }

        .btn-back {
            background: #6b7280;
            color: white;
        }

        .btn-back:hover {
            background: #4b5563;
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <h1>Salameh Tools</h1>
            <div class="subtitle">ايصال دفعة</div>
            <div class="subtitle">Payment Receipt</div>
        </div>

        <div class="receipt-number">
            <?= htmlspecialchars($receipt['receipt_number'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="section">
            <div class="section-title">العميل</div>
            <div class="row">
                <span>الاسم:</span>
                <strong><?= htmlspecialchars($receipt['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>

        <div class="section">
            <div class="section-title">تفاصيل الدفع</div>
            <?php if ($receipt['payment_usd'] > 0): ?>
            <div class="row">
                <span>نقداً بالدولار:</span>
                <span>$<?= number_format($receipt['payment_usd'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($receipt['payment_lbp'] > 0): ?>
            <div class="row">
                <span>نقداً بالليرة:</span>
                <span>L.L. <?= number_format($receipt['payment_lbp'], 0) ?></span>
            </div>
            <div class="row" style="font-size:0.85rem; color:#6b7280;">
                <span>ما يعادل بالدولار:</span>
                <span>$<?= number_format($receipt['payment_lbp'] / $exchangeRate, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="row highlight">
                <span>المبلغ المستلم:</span>
                <span>$<?= number_format($receipt['total_usd'], 2) ?></span>
            </div>
        </div>

        <?php if (!empty($receipt['paid_invoices'])): ?>
        <div class="section">
            <div class="section-title">تم تطبيقه على الفواتير</div>
            <div class="invoices-list">
                <?php foreach ($receipt['paid_invoices'] as $inv): ?>
                <div class="invoice-item">
                    <span>
                        <?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES, 'UTF-8') ?>
                        <span class="status <?= $inv['fully_paid'] ? 'paid' : 'partial' ?>">
                            <?= $inv['fully_paid'] ? 'مدفوعة' : 'جزئي' ?>
                        </span>
                    </span>
                    <span>$<?= number_format($inv['amount'], 2) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($receipt['credit_added'] > 0): ?>
        <div class="section">
            <div class="row success">
                <span>اضيف للرصيد:</span>
                <span>$<?= number_format($receipt['credit_added'], 2) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-title">رصيد الحساب</div>
            <?php if ($remainingBalance > 0): ?>
            <div class="row danger">
                <span>المتبقي عليه:</span>
                <span>$<?= number_format($remainingBalance, 2) ?></span>
            </div>
            <?php else: ?>
            <div class="row success">
                <span>الرصيد المستحق:</span>
                <span>$0.00 - مدفوع بالكامل!</span>
            </div>
            <?php endif; ?>

            <?php if ($creditBalance > 0): ?>
            <div class="row" style="color:#059669;">
                <span>رصيد دائن:</span>
                <span>L.L. <?= number_format($creditBalance, 0) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($receipt['notes'])): ?>
        <div class="section">
            <div class="section-title">ملاحظات</div>
            <div class="notes">
                <?= nl2br(htmlspecialchars($receipt['notes'], ENT_QUOTES, 'UTF-8')) ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer">
            <div class="date">
                <?= date('Y-m-d H:i', strtotime($receipt['date'])) ?>
            </div>
            <div class="rep">
                استلمها: <?= htmlspecialchars($receipt['sales_rep'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="rep">
                سعر الصرف: 1 USD = L.L. <?= number_format($exchangeRate, 0) ?>
            </div>
            <div class="thank-you">
                شكراً لك!
            </div>
        </div>

        <div class="action-buttons no-print">
            <button class="btn btn-print" onclick="window.print()">
                🖨️ طباعة الإيصال
            </button>
            <a href="collect_payment.php" class="btn btn-back">
                → رجوع
            </a>
        </div>
    </div>

    <script>
        // Auto-print on load
        window.onload = function() {
            // Small delay to ensure page is fully rendered
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
