<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/InvoicePDF.php';

$user = require_accounting_access();
$pdo = db();

// Get invoice ID from query parameter
$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$action = $_GET['action'] ?? 'view'; // view, download

if ($invoiceId <= 0) {
    http_response_code(400);
    die('Invalid invoice ID');
}

$pdfGenerator = new InvoicePDF($pdo);

switch ($action) {
    case 'download':
        // Stream PDF as download (no sales rep restriction for accounting)
        $pdfGenerator->streamPDF($invoiceId, null, true);
        break;

    default:
        // View PDF in browser
        $pdfGenerator->streamPDF($invoiceId, null, false);
        break;
}
