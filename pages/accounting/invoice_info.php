<?php

declare(strict_types=1);

/**
 * Returns JSON for a single invoice (header + line items) for the Info modal.
 */

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/db.php';

$user = require_accounting_access();
$pdo = db();

$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
if ($invoiceId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid invoice ID']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        i.id,
        i.invoice_number,
        i.created_at,
        i.issued_at,
        i.total_usd,
        i.total_lbp,
        i.status,
        o.order_number,
        c.name AS customer_name,
        c.phone AS customer_phone,
        u.name AS sales_rep_name,
        COALESCE(p.paid_usd, 0) AS paid_usd,
        COALESCE(p.paid_lbp, 0) AS paid_lbp,
        (i.total_usd - COALESCE(p.paid_usd, 0)) AS outstanding_usd
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = o.sales_rep_id
    LEFT JOIN (SELECT invoice_id, SUM(amount_usd) AS paid_usd, SUM(amount_lbp) AS paid_lbp FROM payments GROUP BY invoice_id) p ON p.invoice_id = i.id
    WHERE i.id = ?
");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invoice not found']);
    exit;
}

$itemsStmt = $pdo->prepare("
    SELECT oi.quantity, oi.unit_price_usd, oi.discount_percent, p.item_name, p.sku,
           (oi.quantity * oi.unit_price_usd * (1 - COALESCE(oi.discount_percent, 0) / 100)) AS subtotal_usd
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = (SELECT order_id FROM invoices WHERE id = ?)
    ORDER BY oi.id
");
$itemsStmt->execute([$invoiceId]);
$invoice['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($invoice, JSON_UNESCAPED_UNICODE);
