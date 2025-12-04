<?php

declare(strict_types=1);

/**
 * CSV Export Helper Functions
 * Provides utilities for exporting data to CSV format
 */

/**
 * Export data to CSV and force download
 *
 * @param array $data Array of associative arrays (rows)
 * @param string $filename Filename for download (without .csv extension)
 * @param array|null $headers Custom header labels (if null, uses array keys)
 * @return void
 */
function exportToCSV(array $data, string $filename, ?array $headers = null): void
{
    // Set headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream
    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (empty($data)) {
        fclose($output);
        exit;
    }

    // Write headers
    $firstRow = reset($data);
    if ($headers === null) {
        // Use array keys as headers
        $headers = array_keys($firstRow);
    }
    fputcsv($output, $headers);

    // Write data rows
    foreach ($data as $row) {
        fputcsv($output, array_values($row));
    }

    fclose($output);
    exit;
}

/**
 * Format a value for CSV export
 *
 * @param mixed $value Value to format
 * @param string $type Type hint: 'date', 'datetime', 'number', 'currency'
 * @return string Formatted value
 */
function formatCSVValue($value, string $type = 'auto'): string
{
    if ($value === null || $value === '') {
        return '';
    }

    switch ($type) {
        case 'date':
            return date('Y-m-d', strtotime((string)$value));

        case 'datetime':
            return date('Y-m-d H:i:s', strtotime((string)$value));

        case 'number':
            return number_format((float)$value, 2, '.', '');

        case 'currency':
            return number_format((float)$value, 2, '.', '');

        default:
            return (string)$value;
    }
}

/**
 * Sanitize data for CSV export (remove sensitive info)
 *
 * @param array $data Data to sanitize
 * @param array $excludeColumns Columns to exclude
 * @return array Sanitized data
 */
function sanitizeCSVData(array $data, array $excludeColumns = []): array
{
    if (empty($excludeColumns)) {
        return $data;
    }

    return array_map(function ($row) use ($excludeColumns) {
        foreach ($excludeColumns as $col) {
            unset($row[$col]);
        }
        return $row;
    }, $data);
}
