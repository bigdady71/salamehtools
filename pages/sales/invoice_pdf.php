<?php

declare(strict_types=1);

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/sales_portal.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/InvoicePDF.php';

$user = sales_portal_bootstrap();
$pdo = db();

// Get invoice ID from query parameter
$invoiceId = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$action = $_GET['action'] ?? 'view'; // view, download, save

if ($invoiceId <= 0) {
    http_response_code(400);
    die('Invalid invoice ID');
}

try {
    $pdfGenerator = new InvoicePDF($pdo);
} catch (Exception $e) {
    http_response_code(500);
    error_log('InvoicePDF initialization error: ' . $e->getMessage());
    die('Error initializing PDF generator: ' . $e->getMessage());
}

try {
    switch ($action) {
        case 'download':
            // Stream PDF as download
            $pdfGenerator->streamPDF($invoiceId, $user['id'], true);
            break;

    case 'save':
        // Save PDF to storage and return path
        $filepath = $pdfGenerator->savePDF($invoiceId, $user['id']);
        if ($filepath) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'path' => $filepath]);
        } else {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invoice not found']);
        }
        break;

    case 'whatsapp':
        // Save PDF and redirect to WhatsApp with PDF link
        $invoice = $pdfGenerator->getInvoiceData($invoiceId, $user['id']);
        if (!$invoice) {
            http_response_code(404);
            die('Invoice not found');
        }

        // Save PDF for sharing
        $filepath = $pdfGenerator->savePDF($invoiceId, $user['id']);

        // Format phone for WhatsApp
        $whatsappPhone = InvoicePDF::formatPhoneForWhatsApp($invoice['customer_phone'] ?? '');

        // Build WhatsApp message with PDF link
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $pdfUrl = $baseUrl . '/pages/sales/invoice_pdf.php?invoice_id=' . $invoiceId . '&action=download';

        $message = "🧾 *فاتورة من SALAMEH TOOLS*\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "📋 رقم الفاتورة: " . $invoice['invoice_number'] . "\n";
        $message .= "📅 التاريخ: " . date('d/m/Y', strtotime($invoice['issued_at'])) . "\n";
        $message .= "👤 العميل: " . $invoice['customer_name'] . "\n";
        $message .= "━━━━━━━━━━━━━━━━━\n\n";

        $message .= "*المنتجات:*\n";
        foreach ($invoice['items'] as $item) {
            $message .= "• " . $item['item_name'] . "\n";
            $message .= "   " . number_format((float)$item['quantity'], 2) . " × $" . number_format((float)$item['unit_price_usd'], 2);
            $message .= " = $" . number_format((float)$item['subtotal_usd'], 2) . "\n";
        }

        $invoiceTotal = (float)$invoice['total_usd'];
        $invoicePaid = (float)$invoice['paid_usd'];
        $invoiceRemaining = max(0, $invoiceTotal - $invoicePaid);
        $isFullyPaid = $invoiceRemaining < 0.01;

        $centsDiscount = $invoice['cents_discount'];
        $originalTotal = $invoiceTotal + $centsDiscount;

        $message .= "\n━━━━━━━━━━━━━━━━━\n";
        if ($centsDiscount > 0) {
            $message .= "المجموع قبل الخصم: $" . number_format($originalTotal, 2) . "\n";
            $message .= "خصم القروش: -$" . number_format($centsDiscount, 2) . "\n";
        }
        $message .= "*المجموع الكلي: $" . number_format($invoiceTotal, 2) . "*\n";
        $message .= "بالليرة: " . number_format((float)$invoice['total_lbp'], 0) . " ل.ل.\n";

        if (!$isFullyPaid) {
            $message .= "\n⚠️ المتبقي: $" . number_format($invoiceRemaining, 2) . "\n";
        } else {
            $message .= "\n✅ مدفوعة بالكامل\n";
        }

        $message .= "\n━━━━━━━━━━━━━━━━━\n";
        $message .= "📥 لتحميل الفاتورة PDF:\n" . $pdfUrl . "\n\n";
        $message .= "شكراً لتعاملكم معنا 🙏\n";
        $message .= "SALAMEH TOOLS\n";
        $message .= "Tel: 71/394022 - 71/404393";

        $whatsappUrl = 'https://wa.me/' . $whatsappPhone . '?text=' . rawurlencode($message);
        header('Location: ' . $whatsappUrl);
        exit;

    default:
        // View PDF in browser
        $pdfGenerator->streamPDF($invoiceId, $user['id'], false);
        break;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('PDF generation error: ' . $e->getMessage());
    die('Error generating PDF: ' . $e->getMessage());
}
