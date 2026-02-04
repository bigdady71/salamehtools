<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/accounting_portal.php';
require_once __DIR__ . '/../../includes/flash.php';
require_once __DIR__ . '/../../includes/InvoicePDF.php';

$user = require_accounting_access();
$pdo = db();

$pdfGenerator = new InvoicePDF($pdo);

// Handle batch PDF generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'batch_generate') {
        $results = $pdfGenerator->batchGeneratePDFs(100);
        if ($results['success'] > 0) {
            set_flash('success', "Generated {$results['success']} PDFs successfully.");
        }
        if ($results['failed'] > 0) {
            set_flash('warning', "Failed to generate {$results['failed']} PDFs.");
        }
        header('Location: pdf_archive.php');
        exit;
    }

    if ($_POST['action'] === 'regenerate' && isset($_POST['invoice_id'])) {
        $invoiceId = (int)$_POST['invoice_id'];
        try {
            $path = $pdfGenerator->savePDF($invoiceId);
            if ($path) {
                set_flash('success', 'PDF regenerated successfully.');
            } else {
                set_flash('error', 'Failed to regenerate PDF.');
            }
        } catch (Exception $e) {
            set_flash('error', 'Error: ' . $e->getMessage());
        }
        header('Location: pdf_archive.php?' . http_build_query($_GET));
        exit;
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$salesRepId = trim($_GET['sales_rep'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$status = trim($_GET['status'] ?? '');
// Only apply PDF filter when user explicitly chose "with PDF" (1) or "without PDF" (0); "All" (empty) = no filter
$hasPdf = isset($_GET['has_pdf']) && $_GET['has_pdf'] !== '' ? ($_GET['has_pdf'] === '1') : null;

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Build filters array
$filters = [];
if ($search !== '') $filters['search'] = $search;
if ($salesRepId !== '') $filters['sales_rep_id'] = $salesRepId;
if ($dateFrom !== '') $filters['date_from'] = $dateFrom;
if ($dateTo !== '') $filters['date_to'] = $dateTo;
if ($status !== '') $filters['status'] = $status;
if ($hasPdf !== null) $filters['has_pdf'] = $hasPdf;

// Get invoices
$result = $pdfGenerator->getInvoicesWithPDF($filters, $perPage, $offset);
$invoices = $result['invoices'];
$totalInvoices = $result['total'];
$totalPages = ceil($totalInvoices / $perPage);

// Get sales reps for filter
$salesReps = $pdfGenerator->getSalesReps();

// Count invoices without PDF
$noPdfCountStmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE pdf_path IS NULL OR pdf_path = ''");
$noPdfCount = (int)$noPdfCountStmt->fetchColumn();

// Page header
$pageTitle = 'PDF Archive - أرشيف الفواتير';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.archive-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.page-title {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
}

.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    border: none;
    transition: all 0.2s;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-success:hover {
    background: #059669;
}

.btn-warning {
    background: #f59e0b;
    color: white;
}

.btn-warning:hover {
    background: #d97706;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 13px;
}

.filters-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-label {
    font-size: 13px;
    font-weight: 600;
    color: #4b5563;
}

.filter-input {
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    width: 100%;
}

.filter-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.stats-bar {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 15px 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    min-width: 150px;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #1f2937;
}

.stat-label {
    font-size: 13px;
    color: #6b7280;
    margin-top: 2px;
}

.invoices-table {
    width: 100%;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.invoices-table th {
    background: #f3f4f6;
    padding: 12px 15px;
    text-align: right;
    font-weight: 600;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
}

.invoices-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}

.invoices-table tbody tr:hover {
    background: #f9fafb;
}

.invoice-number {
    font-weight: 600;
    color: #3b82f6;
}

.customer-name {
    font-weight: 500;
}

.customer-phone {
    font-size: 12px;
    color: #6b7280;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.badge-success {
    background: #d1fae5;
    color: #065f46;
}

.badge-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-info {
    background: #dbeafe;
    color: #1e40af;
}

.badge-gray {
    background: #f3f4f6;
    color: #374151;
}

.pdf-status {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pdf-exists {
    color: #059669;
}

.pdf-missing {
    color: #dc2626;
}

.actions-cell {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.pagination a,
.pagination span {
    padding: 8px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
    font-size: 14px;
}

.pagination a {
    background: white;
    color: #374151;
    border: 1px solid #d1d5db;
}

.pagination a:hover {
    background: #f3f4f6;
}

.pagination .active {
    background: #3b82f6;
    color: white;
    border: 1px solid #3b82f6;
}

.pagination .disabled {
    background: #f3f4f6;
    color: #9ca3af;
    border: 1px solid #e5e7eb;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
}

.empty-icon {
    font-size: 48px;
    margin-bottom: 15px;
}

.empty-title {
    font-size: 18px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}

.empty-text {
    color: #6b7280;
}

@media (max-width: 768px) {
    .invoices-table {
        display: block;
        overflow-x: auto;
    }

    .filters-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="archive-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">📁 أرشيف فواتير PDF</h1>
        <div class="header-actions">
            <?php if ($noPdfCount > 0): ?>
            <form method="POST" style="display:inline;"
                onsubmit="return confirm('Generate PDFs for <?= $noPdfCount ?> invoices without PDF?');">
                <input type="hidden" name="action" value="batch_generate">
                <button type="submit" class="btn btn-warning">
                    ⚡ توليد <?= number_format($noPdfCount) ?> PDF مفقود
                </button>
            </form>
            <?php endif; ?>
            <a href="invoices.php" class="btn btn-secondary">← قائمة الفواتير</a>
        </div>
    </div>

    <?php include __DIR__ . '/../../includes/flash_messages.php'; ?>

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalInvoices) ?></div>
            <div class="stat-label">إجمالي الفواتير</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #059669;"><?= number_format($totalInvoices - $noPdfCount) ?></div>
            <div class="stat-label">فواتير لها PDF</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #dc2626;"><?= number_format($noPdfCount) ?></div>
            <div class="stat-label">بدون PDF</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-card">
        <form method="GET" class="filters-grid">
            <div class="filter-group">
                <label class="filter-label">بحث</label>
                <input type="text" name="search" class="filter-input" placeholder="رقم الفاتورة أو اسم العميل..."
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label">مندوب المبيعات</label>
                <select name="sales_rep" class="filter-input">
                    <option value="">الكل</option>
                    <?php foreach ($salesReps as $rep): ?>
                    <option value="<?= $rep['id'] ?>" <?= $salesRepId == $rep['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rep['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">من تاريخ</label>
                <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label">إلى تاريخ</label>
                <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label">حالة الفاتورة</label>
                <select name="status" class="filter-input">
                    <option value="">الكل</option>
                    <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>صادرة</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>مدفوعة</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">حالة PDF</label>
                <select name="has_pdf" class="filter-input">
                    <option value="">الكل</option>
                    <option value="1" <?= $hasPdf === true ? 'selected' : '' ?>>يوجد PDF</option>
                    <option value="0" <?= $hasPdf === false ? 'selected' : '' ?>>بدون PDF</option>
                </select>
            </div>
            <div class="filter-group">
                <button type="submit" class="btn btn-primary">🔍 بحث</button>
            </div>
        </form>
    </div>

    <!-- Invoices Table -->
    <?php if (empty($invoices)): ?>
    <div class="empty-state">
        <div class="empty-icon">📄</div>
        <div class="empty-title">لا توجد فواتير</div>
        <div class="empty-text">لم يتم العثور على فواتير تطابق معايير البحث</div>
    </div>
    <?php else: ?>
    <table class="invoices-table">
        <thead>
            <tr>
                <th>رقم الفاتورة</th>
                <th>التاريخ</th>
                <th>العميل</th>
                <th>المندوب</th>
                <th>المبلغ</th>
                <th>الحالة</th>
                <th>PDF</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $invoice):
                $hasPdfFile = !empty($invoice['pdf_path']) && $pdfGenerator->pdfFileExists($invoice['pdf_path']);
                $remaining = (float)$invoice['total_usd'] - (float)$invoice['paid_usd'];
                $isPaid = $remaining < 0.01;
            ?>
            <tr>
                <td>
                    <span class="invoice-number"><?= htmlspecialchars($invoice['invoice_number']) ?></span>
                </td>
                <td><?= date('d/m/Y', strtotime($invoice['issued_at'])) ?></td>
                <td>
                    <div class="customer-name"><?= htmlspecialchars($invoice['customer_name']) ?></div>
                    <?php if ($invoice['customer_phone']): ?>
                    <div class="customer-phone"><?= htmlspecialchars($invoice['customer_phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($invoice['sales_rep_name']) ?></td>
                <td>
                    <strong>$<?= number_format((float)$invoice['total_usd'], 2) ?></strong>
                    <?php if (!$isPaid): ?>
                    <br><small style="color: #dc2626;">متبقي: $<?= number_format($remaining, 2) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($invoice['status'] === 'paid' || $isPaid): ?>
                    <span class="badge badge-success">مدفوعة</span>
                    <?php else: ?>
                    <span class="badge badge-warning">صادرة</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="pdf-status">
                        <?php if ($hasPdfFile): ?>
                        <span class="pdf-exists">✓ موجود</span>
                        <?php if ($invoice['pdf_generated_at']): ?>
                        <small
                            style="color: #6b7280;"><?= date('d/m H:i', strtotime($invoice['pdf_generated_at'])) ?></small>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="pdf-missing">✗ غير موجود</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div class="actions-cell">
                        <a href="invoice_pdf.php?invoice_id=<?= $invoice['id'] ?>&action=view" target="_blank"
                            class="btn btn-primary btn-sm">
                            👁️ عرض
                        </a>
                        <a href="invoice_pdf.php?invoice_id=<?= $invoice['id'] ?>&action=download"
                            class="btn btn-success btn-sm">
                            ⬇️ تحميل
                        </a>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="regenerate">
                            <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                            <?php
                            // Preserve current filters in form
                            foreach ($_GET as $key => $value) {
                                if ($value !== '') {
                                    echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
                                }
                            }
                            ?>
                            <button type="submit" class="btn btn-secondary btn-sm" title="إعادة توليد PDF">
                                🔄
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $queryParams = $_GET;
        unset($queryParams['page']);
        $baseUrl = '?' . http_build_query($queryParams) . '&page=';

        if ($page > 1): ?>
        <a href="<?= $baseUrl . ($page - 1) ?>">← السابق</a>
        <?php else: ?>
        <span class="disabled">← السابق</span>
        <?php endif;

        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);

        if ($startPage > 1): ?>
        <a href="<?= $baseUrl ?>1">1</a>
        <?php if ($startPage > 2): ?><span class="disabled">...</span><?php endif;
        endif;

        for ($p = $startPage; $p <= $endPage; $p++):
            if ($p == $page): ?>
        <span class="active"><?= $p ?></span>
        <?php else: ?>
        <a href="<?= $baseUrl . $p ?>"><?= $p ?></a>
        <?php endif;
        endfor;

        if ($endPage < $totalPages):
            if ($endPage < $totalPages - 1): ?><span class="disabled">...</span><?php endif; ?>
        <a href="<?= $baseUrl . $totalPages ?>"><?= $totalPages ?></a>
        <?php endif;

        if ($page < $totalPages): ?>
        <a href="<?= $baseUrl . ($page + 1) ?>">التالي →</a>
        <?php else: ?>
        <span class="disabled">التالي →</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>