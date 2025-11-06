<?php
/**
 * ExportManager - Universal data export handler
 *
 * Supports:
 * - CSV Export (lightweight, fast)
 * - Excel Export (XLSX format via PhpSpreadsheet)
 * - PDF Export (via TCPDF)
 *
 * Usage:
 *   $exporter = new ExportManager();
 *   $exporter->exportToCSV($data, 'invoices', ['ID', 'Date', 'Amount']);
 *   $exporter->exportToExcel($data, 'invoices', ['ID', 'Date', 'Amount']);
 *   $exporter->exportToPDF($data, 'invoices', ['ID', 'Date', 'Amount']);
 */

class ExportManager
{
    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Export data to CSV format
     *
     * @param array $data Array of associative arrays
     * @param string $filename Base filename (without extension)
     * @param array|null $headers Optional custom headers (uses array keys if null)
     * @param array $options Export options
     */
    public function exportToCSV(
        array $data,
        string $filename,
        ?array $headers = null,
        array $options = []
    ): void {
        $delimiter = $options['delimiter'] ?? ',';
        $enclosure = $options['enclosure'] ?? '"';
        $bom = $options['bom'] ?? true; // UTF-8 BOM for Excel compatibility

        // Set headers for download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: max-age=0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        if ($bom) {
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        }

        if (empty($data)) {
            fputcsv($output, ['No data available'], $delimiter, $enclosure);
            fclose($output);
            exit;
        }

        // Write headers
        if ($headers === null) {
            $headers = array_keys($data[0]);
        }
        fputcsv($output, $headers, $delimiter, $enclosure);

        // Write data rows
        foreach ($data as $row) {
            $values = [];
            foreach ($headers as $header) {
                $key = is_numeric($header) ? $header : array_search($header, $headers);
                $values[] = $row[array_keys($row)[$key]] ?? '';
            }
            fputcsv($output, $values, $delimiter, $enclosure);
        }

        fclose($output);

        if ($this->logger) {
            $this->logger->info('CSV export completed', [
                'filename' => $filename,
                'rows' => count($data)
            ]);
        }

        exit;
    }

    /**
     * Export data to Excel format (XLSX)
     * Requires PhpSpreadsheet library
     *
     * @param array $data Array of associative arrays
     * @param string $filename Base filename (without extension)
     * @param array|null $headers Optional custom headers
     * @param array $options Export options
     */
    public function exportToExcel(
        array $data,
        string $filename,
        ?array $headers = null,
        array $options = []
    ): void {
        // Check if PhpSpreadsheet is available
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            // Fallback to CSV if PhpSpreadsheet not available
            $this->exportToCSV($data, $filename, $headers, $options);
            return;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $title = $options['title'] ?? ucfirst(str_replace('_', ' ', $filename));
        $sheet->setTitle(substr($title, 0, 31)); // Excel limit

        if (empty($data)) {
            $sheet->setCellValue('A1', 'No data available');
            $this->outputExcel($spreadsheet, $filename);
            return;
        }

        // Determine headers
        if ($headers === null) {
            $headers = array_keys($data[0]);
        }

        // Write headers (bold)
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col++;
        }

        // Write data rows
        $row = 2;
        foreach ($data as $dataRow) {
            $col = 'A';
            foreach ($headers as $idx => $header) {
                $key = array_keys($dataRow)[$idx] ?? $idx;
                $value = $dataRow[$key] ?? '';
                $sheet->setCellValue($col . $row, $value);
                $col++;
            }
            $row++;
        }

        // Apply auto-filter
        $lastCol = chr(65 + count($headers) - 1);
        $sheet->setAutoFilter("A1:{$lastCol}1");

        $this->outputExcel($spreadsheet, $filename);
    }

    /**
     * Output Excel file to browser
     */
    private function outputExcel($spreadsheet, string $filename): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d_His') . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');

        if ($this->logger) {
            $this->logger->info('Excel export completed', ['filename' => $filename]);
        }

        exit;
    }

    /**
     * Export data to PDF format
     * Uses TCPDF library
     *
     * @param array $data Array of associative arrays
     * @param string $filename Base filename (without extension)
     * @param array|null $headers Optional custom headers
     * @param array $options Export options (title, orientation, etc.)
     */
    public function exportToPDF(
        array $data,
        string $filename,
        ?array $headers = null,
        array $options = []
    ): void {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            // Fallback to CSV if TCPDF not available
            $this->exportToCSV($data, $filename, $headers, $options);
            return;
        }

        $orientation = $options['orientation'] ?? 'L'; // L=Landscape, P=Portrait
        $title = $options['title'] ?? ucfirst(str_replace('_', ' ', $filename));

        // Create PDF
        $pdf = new \TCPDF($orientation, 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('SalamehTools');
        $pdf->SetAuthor('SalamehTools');
        $pdf->SetTitle($title);

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);

        // Add page
        $pdf->AddPage();

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, $title, 0, 1, 'C');
        $pdf->Ln(5);

        if (empty($data)) {
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(0, 10, 'No data available', 0, 1, 'C');
            $this->outputPDF($pdf, $filename);
            return;
        }

        // Determine headers
        if ($headers === null) {
            $headers = array_keys($data[0]);
        }

        // Calculate column widths
        $pageWidth = $orientation === 'L' ? 277 : 190; // A4 width minus margins
        $colWidth = $pageWidth / count($headers);

        // Table header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(0, 255, 136); // Green header
        foreach ($headers as $header) {
            $pdf->Cell($colWidth, 7, $header, 1, 0, 'C', true);
        }
        $pdf->Ln();

        // Table data
        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        foreach ($data as $dataRow) {
            $pdf->SetFillColor(240, 240, 240);
            foreach ($headers as $idx => $header) {
                $key = array_keys($dataRow)[$idx] ?? $idx;
                $value = $dataRow[$key] ?? '';

                // Truncate long values
                if (strlen($value) > 50) {
                    $value = substr($value, 0, 47) . '...';
                }

                $pdf->Cell($colWidth, 6, $value, 1, 0, 'L', $fill);
            }
            $pdf->Ln();
            $fill = !$fill;
        }

        // Footer with timestamp
        $pdf->SetY(-15);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 10, 'Generated: ' . date('Y-m-d H:i:s'), 0, 0, 'C');

        $this->outputPDF($pdf, $filename);
    }

    /**
     * Output PDF file to browser
     */
    private function outputPDF($pdf, string $filename): void
    {
        if ($this->logger) {
            $this->logger->info('PDF export completed', ['filename' => $filename]);
        }

        $pdf->Output($filename . '_' . date('Y-m-d_His') . '.pdf', 'D');
        exit;
    }

    /**
     * Prepare data for export by flattening nested arrays
     *
     * @param array $data Raw data
     * @param array $fieldMap Map of field names to display names
     * @return array Flattened data ready for export
     */
    public static function prepareData(array $data, array $fieldMap = []): array
    {
        $prepared = [];

        foreach ($data as $row) {
            $preparedRow = [];

            foreach ($row as $key => $value) {
                // Use mapped name if available
                $displayKey = $fieldMap[$key] ?? ucfirst(str_replace('_', ' ', $key));

                // Flatten nested values
                if (is_array($value)) {
                    $preparedRow[$displayKey] = json_encode($value);
                } elseif (is_bool($value)) {
                    $preparedRow[$displayKey] = $value ? 'Yes' : 'No';
                } elseif (is_null($value)) {
                    $preparedRow[$displayKey] = '';
                } else {
                    $preparedRow[$displayKey] = $value;
                }
            }

            $prepared[] = $preparedRow;
        }

        return $prepared;
    }

    /**
     * Handle export request from GET/POST parameters
     *
     * @param array $data Data to export
     * @param string $filename Base filename
     * @param array|null $headers Optional headers
     * @param array $options Export options
     */
    public function handleExportRequest(
        array $data,
        string $filename,
        ?array $headers = null,
        array $options = []
    ): void {
        $format = $_GET['export'] ?? $_POST['export'] ?? null;

        if (!$format) {
            return; // Not an export request
        }

        switch (strtolower($format)) {
            case 'csv':
                $this->exportToCSV($data, $filename, $headers, $options);
                break;
            case 'excel':
            case 'xlsx':
                $this->exportToExcel($data, $filename, $headers, $options);
                break;
            case 'pdf':
                $this->exportToPDF($data, $filename, $headers, $options);
                break;
            default:
                // Invalid format, ignore
                break;
        }
    }
}
