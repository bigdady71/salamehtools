<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/customer_portal.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/InvoicePDF.php';

$customer = customer_portal_bootstrap();
$customerId = (int)$customer['id'];
$pdo = db();

// Get invoice ID from query parameter
$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$action = $_GET['action'] ?? 'download'; // view, download

if ($invoiceId <= 0) {
    http_response_code(400);
    die('Invalid invoice ID');
}

// Verify this invoice belongs to the logged-in customer
$verifyStmt = $pdo->prepare("
    SELECT i.id
    FROM invoices i
    JOIN orders o ON i.order_id = o.id
    WHERE i.id = :invoice_id AND o.customer_id = :customer_id
");
$verifyStmt->execute([
    ':invoice_id' => $invoiceId,
    ':customer_id' => $customerId
]);

if (!$verifyStmt->fetch()) {
    http_response_code(403);
    die('Access denied: This invoice does not belong to your account.');
}

$pdfGenerator = new InvoicePDF($pdo);

switch ($action) {
    case 'download':
        // Stream PDF as download
        $pdfGenerator->streamPDF($invoiceId, null, true);
        break;

    default:
        // View PDF in browser
        $pdfGenerator->streamPDF($invoiceId, null, false);
        break;
}
