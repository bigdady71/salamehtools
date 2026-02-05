<?php

declare(strict_types=1);

/**
 * Returns JSON for a single invoice (header + line items) for the Info modal.
 * Admin version - can access all invoices.
 */

require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../includes/db.php';

// Admin authentication
require_login();
$user = auth_user();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Admin access only']);
    exit;
}

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
        o.id AS order_id,
        c.name AS customer_name,
        c.phone AS customer_phone,
        c.location AS customer_location,
        u.name AS sales_rep_name,
        COALESCE(p.paid_usd, 0) AS paid_usd,
        COALESCE(p.paid_lbp, 0) AS paid_lbp,
        (i.total_usd - COALESCE(p.paid_usd, 0)) AS outstanding_usd,
        (i.total_lbp - COALESCE(p.paid_lbp, 0)) AS outstanding_lbp
    FROM invoices i
    JOIN orders o ON o.id = i.order_id
    JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = o.sales_rep_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_usd) AS paid_usd, SUM(amount_lbp) AS paid_lbp 
        FROM payments 
        GROUP BY invoice_id
    ) p ON p.invoice_id = i.id
    WHERE i.id = ?
");
$stmt->execute([$invoiceId]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invoice not found']);
    exit;
}

// Get line items
$itemsStmt = $pdo->prepare("
    SELECT 
        oi.quantity, 
        oi.unit_price_usd, 
        oi.discount_percent, 
        p.item_name, 
        p.sku,
        (oi.quantity * oi.unit_price_usd * (1 - COALESCE(oi.discount_percent, 0) / 100)) AS subtotal_usd
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.id
");
$itemsStmt->execute([$invoice['order_id']]);
$invoice['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment history
$paymentsStmt = $pdo->prepare("
    SELECT 
        p.amount_usd,
        p.amount_lbp,
        p.method,
        p.received_at,
        u.name AS received_by
    FROM payments p
    LEFT JOIN users u ON u.id = p.received_by_user_id
    WHERE p.invoice_id = ?
    ORDER BY p.received_at DESC
");
$paymentsStmt->execute([$invoiceId]);
$invoice['payments'] = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($invoice, JSON_UNESCAPED_UNICODE);
