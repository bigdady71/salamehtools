<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Invoice PDF Generator
 * Generates PDF invoices with Arabic RTL support
 */
class InvoicePDF
{
    private PDO $pdo;
    private string $storagePath;
    private $arabicShaper = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->storagePath = __DIR__ . '/../storage/invoices';

        // Ensure storage directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Get invoice data by ID
     */
    public function getInvoiceData(int $invoiceId, ?int $salesRepId = null): ?array
    {
        $sql = "
            SELECT
                i.id,
                i.invoice_number,
                i.issued_at,
                i.total_usd,
                i.total_lbp,
                o.order_number,
                o.notes,
                o.customer_id,
                c.name AS customer_name,
                c.phone AS customer_phone,
                c.location AS customer_city,
                u.name AS sales_rep_name,
                COALESCE(pay.paid_usd, 0) as paid_usd
            FROM invoices i
            JOIN orders o ON i.order_id = o.id
            JOIN customers c ON o.customer_id = c.id
            JOIN users u ON o.sales_rep_id = u.id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount_usd) as paid_usd
                FROM payments
                GROUP BY invoice_id
            ) pay ON pay.invoice_id = i.id
            WHERE i.id = :invoice_id
        ";

        $params = [':invoice_id' => $invoiceId];

        if ($salesRepId !== null) {
            $sql .= " AND o.sales_rep_id = :rep_id";
            $params[':rep_id'] = $salesRepId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            return null;
        }

        // Get payment breakdown
        $paymentStmt = $this->pdo->prepare("
            SELECT method, SUM(amount_usd) as total_usd, SUM(amount_lbp) as total_lbp
            FROM payments
            WHERE invoice_id = :invoice_id
            GROUP BY method
        ");
        $paymentStmt->execute([':invoice_id' => $invoiceId]);
        $paymentBreakdown = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

        $paidUSD = 0;
        $paidLBP = 0;
        $paidLBPasUSD = 0;

        foreach ($paymentBreakdown as $payment) {
            $method = $payment['method'];
            if ($method === 'cash_usd') {
                $paidUSD = (float)$payment['total_usd'];
            } elseif ($method === 'cash_lbp') {
                $paidLBP = (float)$payment['total_lbp'];
                $paidLBPasUSD = (float)$payment['total_usd'];
            } elseif ($method === 'cash') {
                $paidUSD += (float)$payment['total_usd'];
            }
        }

        $invoice['paid_usd_cash'] = $paidUSD;
        $invoice['paid_lbp'] = $paidLBP;
        $invoice['paid_lbp_as_usd'] = $paidLBPasUSD;

        // Get customer credit balance
        $creditStmt = $this->pdo->prepare("
            SELECT COALESCE(account_balance_lbp, 0) as credit_lbp
            FROM customers WHERE id = :customer_id
        ");
        $creditStmt->execute([':customer_id' => $invoice['customer_id']]);
        $invoice['customer_credit_lbp'] = (float)$creditStmt->fetchColumn();

        // Get exchange rate
        $rateStmt = $this->pdo->query("
            SELECT rate FROM exchange_rates
            WHERE UPPER(base_currency) = 'USD'
              AND UPPER(quote_currency) IN ('LBP', 'LEBP')
            ORDER BY valid_from DESC, created_at DESC, id DESC
            LIMIT 1
        ");
        $invoice['exchange_rate'] = (float)$rateStmt->fetchColumn();

        // Get invoice items
        $itemsStmt = $this->pdo->prepare("
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

        // Calculate item subtotals
        foreach ($items as &$item) {
            $itemSubtotal = $item['unit_price_usd'] * $item['quantity'];
            $item['subtotal_usd'] = $itemSubtotal * (1 - $item['discount_percent'] / 100);

            $itemSubtotalLbp = $item['unit_price_lbp'] * $item['quantity'];
            $item['subtotal_lbp'] = $itemSubtotalLbp * (1 - $item['discount_percent'] / 100);
        }
        unset($item);

        $invoice['items'] = $items;

        // Check for cents discount
        $centsDiscount = 0;
        if ($invoice['notes']) {
            if (preg_match('/خصم القروش:\s*\$?([\d.]+)/u', $invoice['notes'], $matches)) {
                $centsDiscount = (float)$matches[1];
            }
        }
        $invoice['cents_discount'] = $centsDiscount;

        return $invoice;
    }

    /**
     * Generate HTML for the invoice
     */
    public function generateHTML(array $invoice, bool $shapeArabic = false): string
    {
        $invoiceTotal = (float)$invoice['total_usd'];
        $invoicePaid = (float)$invoice['paid_usd'];
        $invoiceRemaining = max(0, $invoiceTotal - $invoicePaid);
        $isFullyPaid = $invoiceRemaining < 0.01;

        $centsDiscount = $invoice['cents_discount'];
        $originalTotal = $invoiceTotal + $centsDiscount;

        $customerCreditLBP = $invoice['customer_credit_lbp'];
        $exchangeRate = $invoice['exchange_rate'];
        $customerCreditUSD = $exchangeRate > 0 ? $customerCreditLBP / $exchangeRate : 0;

        // Filter cents discount from notes display
        $displayNotes = $invoice['notes'] ?? '';
        if ($centsDiscount > 0 && $displayNotes) {
            $displayNotes = preg_replace('/خصم القروش:\s*\$?[\d.]+\s*\n?/u', '', $displayNotes);
            $displayNotes = trim($displayNotes);
        }

        $html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>فاتورة - ' . $this->formatText($invoice['invoice_number'] ?? '', $shapeArabic) . '</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@400;700&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 0;
        }

        @media print {
            @page {
                margin: 0;
            }
            html, body {
                margin: 0 !important;
                padding: 0 !important;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Noto Sans Arabic", notosansarabic, "NotoSansArabic", "DejaVu Sans", Arial, sans-serif;
            background: white;
            padding: 10mm;
            direction: rtl;
            text-align: right;
            font-size: 12px;
            line-height: 1.6;
        }

        :lang(ar),
        [lang="ar"] {
            font-family: "Noto Sans Arabic", notosansarabic, "NotoSansArabic", "DejaVu Sans", Arial, sans-serif !important;
        }

        .invoice-container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
            padding: 15px;
        }

        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }

        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #000;
            margin-bottom: 5px;
            letter-spacing: 2px;
        }

        .company-contact {
            font-size: 12px;
            color: #333;
        }

        .divider {
            border-top: 2px dashed #666;
            margin: 12px 0;
        }

        .invoice-number {
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            padding: 8px;
            background: #f0f0f0;
            border: 2px solid #000;
            margin-bottom: 12px;
        }

        .info-row {
            margin-bottom: 6px;
            font-size: 12px;
            overflow: hidden;
        }

        .info-label {
            font-weight: bold;
            color: #333;
            float: right;
        }

        .info-value {
            color: #000;
            float: left;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 11px;
            direction: rtl;
        }

        .items-table th {
            background: #000;
            color: white;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #000;
        }

        .items-table td {
            padding: 6px 5px;
            text-align: center;
            border: 1px solid #ccc;
        }

        .items-table tbody tr:nth-child(even) {
            background: #f9f9f9;
        }

        .item-name {
            text-align: right !important;
            padding-right: 8px !important;
        }

        .item-sku {
            font-size: 9px;
            color: #666;
        }

        .totals {
            margin-top: 15px;
            border-top: 3px solid #000;
            padding-top: 12px;
        }

        .total-row {
            overflow: hidden;
            padding: 6px 0;
            font-size: 14px;
        }

        .total-row .label {
            float: right;
        }

        .total-row .value {
            float: left;
        }

        .total-row.final {
            font-size: 16px;
            font-weight: bold;
            background: #f0f0f0;
            padding: 10px 8px;
            border: 2px solid #000;
            margin-top: 8px;
        }

        .total-row.small {
            font-size: 12px;
        }

        .total-row.boxed {
            border: 1px solid #000;
            padding: 8px;
            margin-top: 8px;
        }

        .total-row.boxed-strong {
            border: 2px solid #000;
            padding: 10px;
            margin-top: 8px;
        }

        .total-row.dashed {
            border: 1px dashed #000;
            padding: 8px;
            margin-top: 8px;
        }

        .strikethrough {
            text-decoration: line-through;
            color: #666;
        }

        .notes {
            margin-top: 15px;
            padding: 12px;
            background: #f9f9f9;
            border: 1px solid #ddd;
        }

        .notes-title {
            font-weight: bold;
            margin-bottom: 6px;
            color: #333;
        }

        .footer {
            margin-top: 25px;
            text-align: center;
            font-size: 11px;
            color: #666;
            padding-top: 12px;
            border-top: 1px solid #ddd;
        }

        .clear {
            clear: both;
        }
    </style>
</head>
<body lang="ar" dir="rtl">
    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            <div class="company-name">SALAMEH TOOLS</div>
            <div class="company-contact">TEL: 71/394022 - 71/404393</div>
        </div>

        <div class="divider"></div>

        <!-- Invoice Number -->
        <div class="invoice-number">
            فاتورة رقم: ' . $this->formatText($invoice['invoice_number'] ?? '', $shapeArabic) . '
        </div>

        <div class="divider"></div>

        <!-- Invoice Info -->
        <div class="invoice-info">
            <div class="info-row">
                <span class="info-label">التاريخ:</span>
                <span class="info-value">' . date('d/m/Y', strtotime($invoice['issued_at'])) . '</span>
                <div class="clear"></div>
            </div>
            <div class="info-row">
                <span class="info-label">مندوب المبيعات:</span>
                <span class="info-value">' . $this->formatText($invoice['sales_rep_name'] ?? '', $shapeArabic) . '</span>
                <div class="clear"></div>
            </div>
            <div class="info-row">
                <span class="info-label">العميل:</span>
                <span class="info-value">' . $this->formatText($invoice['customer_name'] ?? '', $shapeArabic) . '</span>
                <div class="clear"></div>
            </div>';

        if (!empty($invoice['customer_phone'])) {
            $html .= '
            <div class="info-row">
                <span class="info-label">الهاتف:</span>
                <span class="info-value">' . $this->formatText($invoice['customer_phone'] ?? '', $shapeArabic) . '</span>
                <div class="clear"></div>
            </div>';
        }

        if (!empty($invoice['customer_city'])) {
            $html .= '
            <div class="info-row">
                <span class="info-label">المدينة:</span>
                <span class="info-value">' . $this->formatText($invoice['customer_city'] ?? '', $shapeArabic) . '</span>
                <div class="clear"></div>
            </div>';
        }

        $html .= '
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
            <tbody>';

        foreach ($invoice['items'] as $item) {
            $html .= '
                <tr>
                    <td class="item-name">
                        ' . $this->formatText($item['item_name'] ?? '', $shapeArabic) . '
                        <br><span class="item-sku">SKU: ' . $this->formatText($item['sku'] ?? '', $shapeArabic) . '</span>
                    </td>
                    <td>' . number_format((float)$item['quantity'], 2) . '</td>
                    <td>$' . number_format((float)$item['unit_price_usd'], 2) . '</td>
                    <td><strong>$' . number_format((float)$item['subtotal_usd'], 2) . '</strong></td>
                </tr>';
        }

        $html .= '
            </tbody>
        </table>

        <div class="divider"></div>

        <!-- Totals -->
        <div class="totals">';

        if ($centsDiscount > 0) {
            $html .= '
            <div class="total-row small">
                <span class="label">المجموع قبل الخصم:</span>
                <span class="value strikethrough">$' . number_format($originalTotal, 2) . '</span>
                <div class="clear"></div>
            </div>
            <div class="total-row small">
                <span class="label">خصم القروش:</span>
                <span class="value">-$' . number_format($centsDiscount, 2) . '</span>
                <div class="clear"></div>
            </div>';
        }

        $html .= '
            <div class="total-row final">
                <span class="label">المجموع الكلي:</span>
                <span class="value">$' . number_format((float)$invoiceTotal, 2) . ' USD</span>
                <div class="clear"></div>
            </div>
            <div class="total-row small">
                <span class="label">بالليرة اللبنانية:</span>
                <span class="value">' . number_format((float)$invoice['total_lbp'], 0) . ' ل.ل.</span>
                <div class="clear"></div>
            </div>
        </div>

        <!-- Payment Status -->
        <div class="divider"></div>
        <div class="payment-status">';

        $paidUSD = $invoice['paid_usd_cash'];
        $paidLBP = $invoice['paid_lbp'];

        if ($paidUSD > 0.01) {
            $html .= '
            <div class="total-row small">
                <span class="label">مدفوع بالدولار:</span>
                <span class="value"><strong>$' . number_format($paidUSD, 2) . '</strong></span>
                <div class="clear"></div>
            </div>';
        }

        if ($paidLBP > 1000) {
            $html .= '
            <div class="total-row small">
                <span class="label">مدفوع بالليرة:</span>
                <span class="value"><strong>' . number_format($paidLBP, 0) . ' ل.ل.</strong></span>
                <div class="clear"></div>
            </div>';
        }

        if ($invoicePaid > 0.01) {
            $html .= '
            <div class="total-row boxed">
                <span class="label">إجمالي المدفوع:</span>
                <span class="value"><strong>$' . number_format($invoicePaid, 2) . '</strong></span>
                <div class="clear"></div>
            </div>';
        }

        if (!$isFullyPaid) {
            $html .= '
            <div class="total-row boxed-strong">
                <span class="label">المتبقي على هذه الفاتورة:</span>
                <span class="value"><strong>$' . number_format($invoiceRemaining, 2) . '</strong></span>
                <div class="clear"></div>
            </div>';
        } else {
            $html .= '
            <div class="total-row boxed-strong">
                <span class="label">حالة الفاتورة:</span>
                <span class="value"><strong>✓ مدفوعة بالكامل</strong></span>
                <div class="clear"></div>
            </div>';
        }

        if ($customerCreditLBP > 0.01) {
            $html .= '
            <div class="total-row dashed">
                <span class="label">رصيد العميل:</span>
                <span class="value"><strong>$' . number_format($customerCreditUSD, 2) . '</strong></span>
                <div class="clear"></div>
            </div>';
        } elseif ($customerCreditLBP < -0.01) {
            $html .= '
            <div class="total-row dashed">
                <span class="label">رصيد العميل:</span>
                <span class="value"><strong>$' . number_format(abs($customerCreditUSD), 2) . '</strong></span>
                <div class="clear"></div>
            </div>';
        } elseif ($isFullyPaid) {
            $html .= '
            <div class="total-row boxed" style="text-align: center;">
                <span style="font-weight: bold;">لا يوجد رصيد</span>
                <div class="clear"></div>
            </div>';
        }

        $html .= '
        </div>';

        if (!empty($displayNotes)) {
            $html .= '
        <div class="notes">
            <div class="notes-title">ملاحظات:</div>
            <div>' . nl2br($this->formatText($displayNotes, $shapeArabic)) . '</div>
        </div>';
        }

        $html .= '
        <div class="footer">
            شكراً لتعاملكم معنا - SALAMEH TOOLS<br>
            طبع بتاريخ: ' . date('d/m/Y H:i') . '<br>
            www.salameh-tools.com
        </div>
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Generate PDF and return as string
     */
    public function generatePDF(array $invoice): string
    {
        $html = $this->generateHTML($invoice, false);
        // Use mPDF for Arabic support (Chrome headless has file access issues on Windows)
        $useMpdf = class_exists(Mpdf::class);
        if ($useMpdf) {
            return $this->generatePDFWithMpdf($html);
        }

        // Fallback to Chrome if mPDF not available
        $chromePdf = $this->generatePDFWithChrome($html);
        if ($chromePdf !== null) {
            return $chromePdf;
        }

        $html = $this->generateHTML($invoice, true);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'NotoSansArabic');
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', __DIR__ . '/..');
        $fontDir = __DIR__ . '/../fonts';
        $options->set('fontDir', $fontDir);
        $fontCache = __DIR__ . '/../storage/font_cache';
        if (!is_dir($fontCache)) {
            mkdir($fontCache, 0755, true);
        }
        $options->set('fontCache', $fontCache);

        $dompdf = new Dompdf($options);

        // Load Arabic font
        $arabicFontPath = $fontDir . '/NotoSansArabic-Regular.ttf';

        if (file_exists($arabicFontPath)) {
            $dompdf->getFontMetrics()->registerFont(
                ['family' => 'NotoSansArabic', 'style' => 'normal', 'weight' => 'normal'],
                $arabicFontPath
            );
        }

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function generatePDFWithChrome(string $html): ?string
    {
        $chromePath = $this->findChromePath();
        if ($chromePath === null) {
            return null;
        }

        $tempDir = __DIR__ . '/../storage/chrome_pdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $stamp = bin2hex(random_bytes(8));
        $htmlPath = $tempDir . '/invoice_' . $stamp . '.html';
        $pdfPath = $tempDir . '/invoice_' . $stamp . '.pdf';

        file_put_contents($htmlPath, $html);

        // Use localhost URL instead of file:// to avoid Chrome security restrictions
        $httpUrl = 'http://localhost/salamehtools/storage/chrome_pdf/invoice_' . $stamp . '.html';
        $pdfArgPath = str_replace('\\', '/', $pdfPath);
        $command = '"' . $chromePath . '" --headless=new --disable-gpu --no-sandbox --run-all-compositor-stages-before-draw --virtual-time-budget=10000 --print-to-pdf-no-header --print-to-pdf="' . $pdfArgPath . '" "' . $httpUrl . '"';

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        $pdfContent = null;
        if (file_exists($pdfPath)) {
            $pdfContent = file_get_contents($pdfPath);
            // Verify the PDF is not an error page (check for PDF magic bytes)
            if ($pdfContent && strpos($pdfContent, '%PDF') !== 0) {
                $pdfContent = null; // Invalid PDF, likely an error page
            }
        }

        if (file_exists($htmlPath)) {
            unlink($htmlPath);
        }
        if (file_exists($pdfPath)) {
            unlink($pdfPath);
        }

        return $pdfContent;
    }

    private function findChromePath(): ?string
    {
        $candidates = [
            getenv('CHROME_PATH') ?: '',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
        ];

        foreach ($candidates as $candidate) {
            if ($candidate && file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function formatText(string $text, bool $shapeArabic = false): string
    {
        $value = $text;

        if ($shapeArabic && $this->containsArabic($value)) {
            $value = $this->shapeArabic($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function containsArabic(string $text): bool
    {
        return (bool)preg_match('/\p{Arabic}/u', $text);
    }

    private function shapeArabic(string $text): string
    {
        if ($this->arabicShaper === null) {
            if (!class_exists('I18N_Arabic')) {
                return $text;
            }
            $this->arabicShaper = new \I18N_Arabic('Glyphs');
        }

        return $this->arabicShaper->utf8Glyphs($text);
    }

    private function generatePDFWithMpdf(string $html): string
    {
        $fontDir = __DIR__ . '/../fonts';
        $tempDir = __DIR__ . '/../storage/mpdf_tmp';

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = (new ConfigVariables())->getDefaults();
        $fontDirs = $config['fontDir'];

        $fontConfig = (new FontVariables())->getDefaults();
        $fontData = $fontConfig['fontdata'];

        $fontData['notosansarabic'] = [
            'R' => 'NotoSansArabic-Regular.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75
        ];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'tempDir' => $tempDir,
            'fontDir' => array_merge($fontDirs, [$fontDir]),
            'fontdata' => $fontData,
            'default_font' => 'notosansarabic',
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'autoArabic' => true,
            'useLang' => true,
            'useOTL' => 0xFF
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->SetAutoFont();
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    /**
     * Generate and save PDF to storage
     */
    public function savePDF(int $invoiceId, ?int $salesRepId = null): ?string
    {
        $invoice = $this->getInvoiceData($invoiceId, $salesRepId);

        if (!$invoice) {
            return null;
        }

        $pdfContent = $this->generatePDF($invoice);

        // Create year/month subdirectories
        $year = date('Y', strtotime($invoice['issued_at']));
        $month = date('m', strtotime($invoice['issued_at']));
        $subDir = $this->storagePath . '/' . $year . '/' . $month;

        if (!is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }

        // Generate filename
        $safeInvoiceNumber = preg_replace('/[^a-zA-Z0-9\-]/', '_', $invoice['invoice_number']);
        $filename = 'INV-' . $safeInvoiceNumber . '-' . date('Ymd_His') . '.pdf';
        $filepath = $subDir . '/' . $filename;

        // Save PDF
        file_put_contents($filepath, $pdfContent);

        // Update database with PDF path (relative to storage)
        $relativePath = 'invoices/' . $year . '/' . $month . '/' . $filename;
        $this->updateInvoicePDFPath($invoiceId, $relativePath);

        return $filepath;
    }

    /**
     * Get or generate PDF for invoice
     */
    public function getOrGeneratePDF(int $invoiceId, ?int $salesRepId = null): ?string
    {
        // Check if PDF already exists
        $stmt = $this->pdo->prepare("SELECT pdf_path FROM invoices WHERE id = :id");
        $stmt->execute([':id' => $invoiceId]);
        $pdfPath = $stmt->fetchColumn();

        if ($pdfPath) {
            $fullPath = __DIR__ . '/../storage/' . $pdfPath;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        // Generate new PDF
        return $this->savePDF($invoiceId, $salesRepId);
    }

    /**
     * Stream PDF directly to browser
     */
    public function streamPDF(int $invoiceId, ?int $salesRepId = null, bool $download = false): void
    {
        $invoice = $this->getInvoiceData($invoiceId, $salesRepId);

        if (!$invoice) {
            http_response_code(404);
            echo 'Invoice not found';
            return;
        }

        $pdfContent = $this->generatePDF($invoice);

        $filename = 'Invoice-' . $invoice['invoice_number'] . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdfContent));

        if ($download) {
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        } else {
            header('Content-Disposition: inline; filename="' . $filename . '"');
        }

        echo $pdfContent;
    }

    /**
     * Convert a local file path into a file:// URL for Dompdf assets.
     */
    private function toFileUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('/^([A-Za-z]):/', '/$1', $normalized);
        return 'file://' . $normalized;
    }

    /**
     * Update invoice with PDF path
     */
    private function updateInvoicePDFPath(int $invoiceId, string $pdfPath): void
    {
        // Check if pdf_path column exists
        try {
            $stmt = $this->pdo->prepare("UPDATE invoices SET pdf_path = :path, pdf_generated_at = NOW() WHERE id = :id");
            $stmt->execute([':path' => $pdfPath, ':id' => $invoiceId]);
        } catch (PDOException $e) {
            // Column might not exist yet, ignore
        }
    }

    /**
     * Get all invoices with PDF info for archive listing
     */
    public function getInvoicesWithPDF(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        // Date range filter
        if (!empty($filters['date_from'])) {
            $where[] = "DATE(i.issued_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "DATE(i.issued_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        // Sales rep filter
        if (!empty($filters['sales_rep_id'])) {
            $where[] = "i.sales_rep_id = :sales_rep_id";
            $params[':sales_rep_id'] = $filters['sales_rep_id'];
        }

        // Customer filter
        if (!empty($filters['customer_id'])) {
            $where[] = "o.customer_id = :customer_id";
            $params[':customer_id'] = $filters['customer_id'];
        }

        // Invoice status filter
        if (!empty($filters['status'])) {
            $where[] = "i.status = :status";
            $params[':status'] = $filters['status'];
        }

        // PDF exists filter
        if (isset($filters['has_pdf'])) {
            if ($filters['has_pdf']) {
                $where[] = "i.pdf_path IS NOT NULL AND i.pdf_path != ''";
            } else {
                $where[] = "(i.pdf_path IS NULL OR i.pdf_path = '')";
            }
        }

        // Search by invoice number
        if (!empty($filters['search'])) {
            $where[] = "(i.invoice_number LIKE :search OR c.name LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countSql = "
            SELECT COUNT(*)
            FROM invoices i
            JOIN orders o ON i.order_id = o.id
            JOIN customers c ON o.customer_id = c.id
            WHERE {$whereClause}
        ";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Get invoices
        $sql = "
            SELECT
                i.id,
                i.invoice_number,
                i.issued_at,
                i.total_usd,
                i.total_lbp,
                i.status,
                i.pdf_path,
                i.pdf_generated_at,
                o.order_number,
                c.name AS customer_name,
                c.phone AS customer_phone,
                u.name AS sales_rep_name,
                u.id AS sales_rep_id,
                COALESCE(pay.paid_usd, 0) AS paid_usd
            FROM invoices i
            JOIN orders o ON i.order_id = o.id
            JOIN customers c ON o.customer_id = c.id
            JOIN users u ON i.sales_rep_id = u.id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount_usd) AS paid_usd
                FROM payments
                GROUP BY invoice_id
            ) pay ON pay.invoice_id = i.id
            WHERE {$whereClause}
            ORDER BY i.issued_at DESC, i.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'invoices' => $invoices,
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Get sales reps for filter dropdown
     */
    public function getSalesReps(): array
    {
        $stmt = $this->pdo->query("
            SELECT DISTINCT u.id, u.name
            FROM users u
            INNER JOIN invoices i ON i.sales_rep_id = u.id
            ORDER BY u.name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Batch generate PDFs for invoices without PDF
     */
    public function batchGeneratePDFs(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM invoices
            WHERE pdf_path IS NULL OR pdf_path = ''
            ORDER BY issued_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $invoiceIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($invoiceIds as $invoiceId) {
            try {
                $path = $this->savePDF((int)$invoiceId);
                if ($path) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Invoice {$invoiceId}: Could not generate PDF";
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Invoice {$invoiceId}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Check if PDF file exists on disk
     */
    public function pdfFileExists(string $relativePath): bool
    {
        $fullPath = __DIR__ . '/../storage/' . $relativePath;
        return file_exists($fullPath);
    }

    /**
     * Get full path to PDF file
     */
    public function getPDFFullPath(string $relativePath): string
    {
        return __DIR__ . '/../storage/' . $relativePath;
    }

    /**
     * Format phone number for WhatsApp (Lebanon)
     */
    public static function formatPhoneForWhatsApp(?string $phone): string
    {
        if (!$phone) {
            return '';
        }

        $phone = trim($phone);
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);

        if (strpos($phone, '+961') === 0) {
            return '961' . substr($phone, 4);
        } elseif (strpos($phone, '00961') === 0) {
            return '961' . substr($phone, 5);
        } elseif (strpos($phone, '961') === 0) {
            return $phone;
        } elseif (strpos($phone, '0') === 0) {
            return '961' . substr($phone, 1);
        }

        return '961' . $phone;
    }
}
