<?php
/**
 * Export Buttons Component
 * Reusable export buttons for CSV, Excel, PDF
 *
 * Usage:
 *   include __DIR__ . '/../../includes/export_buttons.php';
 *   renderExportButtons();
 */

function renderExportButtons(array $options = []): string
{
    $currentUrl = $_SERVER['REQUEST_URI'];
    $separator = strpos($currentUrl, '?') !== false ? '&' : '?';

    $csvUrl = $currentUrl . $separator . 'export=csv';
    $excelUrl = $currentUrl . $separator . 'export=excel';
    $pdfUrl = $currentUrl . $separator . 'export=pdf';

    $showCsv = $options['csv'] ?? true;
    $showExcel = $options['excel'] ?? true;
    $showPdf = $options['pdf'] ?? true;

    ob_start();
    ?>
    <div class="export-buttons" style="margin: 10px 0; display: flex; gap: 10px; flex-wrap: wrap;">
        <span style="color: #00ff88; font-weight: bold; line-height: 35px;">Export:</span>

        <?php if ($showCsv): ?>
        <a href="<?= htmlspecialchars($csvUrl) ?>"
           class="export-btn export-csv"
           style="display: inline-block; padding: 8px 16px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; transition: background 0.3s;">
            📊 CSV
        </a>
        <?php endif; ?>

        <?php if ($showExcel): ?>
        <a href="<?= htmlspecialchars($excelUrl) ?>"
           class="export-btn export-excel"
           style="display: inline-block; padding: 8px 16px; background: #217346; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; transition: background 0.3s;">
            📗 Excel
        </a>
        <?php endif; ?>

        <?php if ($showPdf): ?>
        <a href="<?= htmlspecialchars($pdfUrl) ?>"
           class="export-btn export-pdf"
           style="display: inline-block; padding: 8px 16px; background: #D32F2F; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; transition: background 0.3s;">
            📄 PDF
        </a>
        <?php endif; ?>
    </div>

    <style>
    .export-btn:hover {
        opacity: 0.8;
        transform: translateY(-2px);
    }
    </style>
    <?php
    return ob_get_clean();
}
