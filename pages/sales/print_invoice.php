<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/db.php';

$user = sales_portal_bootstrap();
$pdo = db();

// Get invoice ID from query parameter
$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;

if ($invoiceId <= 0) {
    die('Invalid invoice ID');
}

// Fetch invoice details
$invoiceStmt = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.issued_at,
        i.total_usd,
        i.total_lbp,
        o.order_number,
        o.notes,
        c.name AS customer_name,
        c.phone AS customer_phone,
        c.location AS customer_city,
        u.name AS sales_rep_name
    FROM invoices i
    JOIN orders o ON i.order_id = o.id
    JOIN customers c ON o.customer_id = c.id
    JOIN users u ON o.sales_rep_id = u.id
    WHERE i.id = :invoice_id AND o.sales_rep_id = :rep_id
");
$invoiceStmt->execute([
    ':invoice_id' => $invoiceId,
    ':rep_id' => $user['id']
]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die('Invoice not found or access denied');
}

// Fetch invoice items
$itemsStmt = $pdo->prepare("
    SELECT
        oi.quantity,
        oi.unit_price_usd,
        oi.unit_price_lbp,
        oi.discount_percent,
        p.item_name,
        p.sku
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = (SELECT order_id FROM invoices WHERE id = :invoice_id)
    ORDER BY oi.id
");
$itemsStmt->execute([':invoice_id' => $invoiceId]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate subtotals for each item
foreach ($items as &$item) {
    $itemSubtotal = $item['unit_price_usd'] * $item['quantity'];
    $item['subtotal_usd'] = $itemSubtotal * (1 - $item['discount_percent'] / 100);

    $itemSubtotalLbp = $item['unit_price_lbp'] * $item['quantity'];
    $item['subtotal_lbp'] = $itemSubtotalLbp * (1 - $item['discount_percent'] / 100);
}
unset($item); // Break reference

// Calculate totals
$subtotal = 0;
$totalDiscount = 0;
foreach ($items as $item) {
    $itemSubtotal = $item['unit_price_usd'] * $item['quantity'];
    $itemDiscount = $itemSubtotal * ($item['discount_percent'] / 100);
    $subtotal += $itemSubtotal;
    $totalDiscount += $itemDiscount;
}
$finalTotal = $invoice['total_usd'];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة - <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></title>
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
            background: white;
            padding: 20px;
            direction: rtl;
            text-align: right;
        }

        .invoice-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 20mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .company-name {
            font-size: 28px;
            font-weight: 700;
            color: #000;
            margin-bottom: 8px;
            letter-spacing: 2px;
        }

        .company-contact {
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }

        .divider {
            border-top: 2px dashed #666;
            margin: 15px 0;
        }

        .invoice-info {
            margin-bottom: 20px;
        }

        .invoice-number {
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            padding: 10px;
            background: #f0f0f0;
            border: 2px solid #000;
            margin-bottom: 15px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .info-label {
            font-weight: 600;
            color: #333;
        }

        .info-value {
            color: #000;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 13px;
        }

        .items-table th {
            background: #000;
            color: white;
            padding: 10px 8px;
            text-align: center;
            font-weight: 600;
            border: 1px solid #000;
        }

        .items-table td {
            padding: 8px;
            text-align: center;
            border: 1px solid #ccc;
        }

        .items-table tbody tr:nth-child(even) {
            background: #f9f9f9;
        }

        .totals {
            margin-top: 20px;
            border-top: 3px solid #000;
            padding-top: 15px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 16px;
        }

        .total-row.final {
            font-size: 20px;
            font-weight: 700;
            background: #f0f0f0;
            padding: 12px 10px;
            border: 2px solid #000;
            margin-top: 10px;
        }

        .notes {
            margin-top: 20px;
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .notes-title {
            font-weight: 700;
            margin-bottom: 8px;
            color: #333;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }

        .print-button {
            position: fixed;
            top: 20px;
            left: 20px;
            background: #059669;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            z-index: 1000;
        }

        .print-button:hover {
            background: #047857;
        }

        .back-button {
            position: fixed;
            top: 20px;
            left: 160px;
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            z-index: 1000;
            text-decoration: none;
            display: inline-block;
        }

        .back-button:hover {
            background: #4b5563;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">🖨️ طباعة</button>
    <a href="van_stock_sales.php" class="back-button no-print">← رجوع</a>

    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            <div class="company-name">SALAMEH TOOLS</div>
            <div class="company-contact">TEL: 71/394022 - 71/404393</div>
        </div>

        <div class="divider"></div>

        <!-- Invoice Number -->
        <div class="invoice-number">
            فاتورة رقم: <?= htmlspecialchars($invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="divider"></div>

        <!-- Invoice Info -->
        <div class="invoice-info">
            <div class="info-row">
                <span class="info-label">التاريخ:</span>
                <span class="info-value"><?= date('d/m/Y', strtotime($invoice['issued_at'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">مندوب المبيعات:</span>
                <span class="info-value"><?= htmlspecialchars($invoice['sales_rep_name'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">العميل:</span>
                <span class="info-value"><?= htmlspecialchars($invoice['customer_name'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if ($invoice['customer_phone']): ?>
            <div class="info-row">
                <span class="info-label">الهاتف:</span>
                <span class="info-value"><?= htmlspecialchars($invoice['customer_phone'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($invoice['customer_city']): ?>
            <div class="info-row">
                <span class="info-label">المدينة:</span>
                <span class="info-value"><?= htmlspecialchars($invoice['customer_city'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="divider"></div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>الصنف</th>
                    <th>الكمية</th>
                    <th>السعر</th>
                    <th>الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td style="text-align: right; padding-right: 10px;">
                        <?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?>
                        <br>
                        <small style="color: #666;">SKU: <?= htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') ?></small>
                    </td>
                    <td><?= number_format((float)$item['quantity'], 2) ?></td>
                    <td>$<?= number_format((float)$item['unit_price_usd'], 2) ?></td>
                    <td><strong>$<?= number_format((float)$item['subtotal_usd'], 2) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="divider"></div>

        <!-- Totals -->
        <div class="totals">
            <div class="total-row final">
                <span>المجموع الكلي:</span>
                <span>$<?= number_format((float)$finalTotal, 2) ?> USD</span>
            </div>
            <div class="total-row" style="font-size: 14px; color: #666; margin-top: 5px;">
                <span>بالليرة اللبنانية:</span>
                <span><?= number_format((float)$invoice['total_lbp'], 0) ?> ل.ل.</span>
            </div>
        </div>

        <?php if ($invoice['notes']): ?>
        <div class="notes">
            <div class="notes-title">ملاحظات:</div>
            <div><?= nl2br(htmlspecialchars($invoice['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
        </div>
        <?php endif; ?>

        <div class="footer">
            شكراً لتعاملكم معنا - SALAMEH TOOLS<br>
            طبع بتاريخ: <?= date('d/m/Y H:i') ?>
            <p>you can also visite our website on <br> www.salameh-tools.com</p>
        </div>
    </div>

    <script>
        // Auto-print on load (optional)
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>
