<?php
/**
 * Reusable product import logic
 * Used by both manual imports (pages/admin/products_import.php) and the auto-import watcher (cli/import_watch.php)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import products from an Excel file path
 *
 * @param PDO $pdo Database connection (should be within a transaction if needed)
 * @param string $path Absolute path to Excel file
 * @param int|null $run_id Optional import_runs.id for tracking
 * @return array ['ok' => bool, 'inserted' => int, 'updated' => int, 'skipped' => int, 'total' => int, 'errors' => array, 'warnings' => array, 'message' => string]
 */
if (!function_exists('import_products_from_path')) {
    function import_products_from_path(PDO $pdo, string $path, ?int $run_id = null): array
    {
    $result = [
        'ok' => false,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'total' => 0,
        'errors' => [],
        'warnings' => [],
        'message' => ''
    ];

    // Validate file exists and is readable
    if (!file_exists($path)) {
        $result['errors'][] = "File not found: {$path}";
        $result['message'] = "File not found";
        return $result;
    }

    if (!is_readable($path)) {
        $result['errors'][] = "Cannot read file: {$path}";
        $result['message'] = "Cannot read file";
        return $result;
    }

    try {
        // Load spreadsheet
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            throw new Exception('Excel file appears to be empty or has no data rows.');
        }

        // Header validation and mapping
        $expectedHeaders = [
            'Type', 'Name', 'SecondName', 'Code', 'Unit', 'SalePrice',
            'WholeSalePrice1', 'WholeSalePrice2', 'Description', 'ExistQuantity',
            'TopCat', 'TopCatName', 'MidCat', 'MidCatName'
        ];

        $headerRow = $rows[1] ?? [];
        $idx = [];
        foreach ($headerRow as $columnKey => $columnValue) {
            $label = trim((string)$columnValue);
            if ($label === '') {
                continue;
            }
            $idx[strtolower($label)] = $columnKey;
        }

        // Check for required headers
        $missingHeaders = [];
        foreach (['Code', 'Name'] as $required) {
            if (!isset($idx[strtolower($required)])) {
                $missingHeaders[] = $required;
            }
        }

        if ($missingHeaders) {
            throw new Exception('Missing required headers: ' . implode(', ', $missingHeaders));
        }

        // Prepare insert/update statement
        $stmt = $pdo->prepare("
            INSERT INTO products
                (sku, code_clean, item_name, second_name,
                 topcat, midcat, topcat_name, midcat_name,
                 type, unit, sale_price_usd, wholesale_price_usd,
                 min_quantity, minqty_disc1, minqty_disc2,
                 description, quantity_on_hand, is_active, created_at, updated_at)
            VALUES
                (:sku, :code_clean, :item_name, :second_name,
                 :topcat, :midcat, :topcat_name, :midcat_name,
                 :type, :unit, :sale_price_usd, :wholesale_price_usd,
                 :min_quantity, :minqty_disc1, :minqty_disc2,
                 :description, :quantity_on_hand, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                code_clean=VALUES(code_clean),
                item_name=VALUES(item_name),
                second_name=VALUES(second_name),
                topcat=VALUES(topcat),
                midcat=VALUES(midcat),
                topcat_name=VALUES(topcat_name),
                midcat_name=VALUES(midcat_name),
                type=VALUES(type),
                unit=VALUES(unit),
                sale_price_usd=VALUES(sale_price_usd),
                wholesale_price_usd=VALUES(wholesale_price_usd),
                min_quantity=VALUES(min_quantity),
                minqty_disc1=VALUES(minqty_disc1),
                minqty_disc2=VALUES(minqty_disc2),
                description=VALUES(description),
                quantity_on_hand=VALUES(quantity_on_hand),
                updated_at=NOW()
        ");

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $lineErrors = [];

        // Process data rows
        for ($r = 2; $r <= count($rows); $r++) {
            $row = $rows[$r];

            // Helper to get column value
            $get = function($name) use ($row, $idx) {
                $key = strtolower($name);
                if (!isset($idx[$key])) {
                    return null;
                }
                $columnKey = $idx[$key];
                return $row[$columnKey] ?? null;
            };

            $sku = trim((string)($get('Code') ?? ''));
            $name = trim((string)($get('Name') ?? ''));

            if ($sku === '' && $name === '') {
                $skipped++;
                continue;
            }

            if ($name === '') {
                $lineErrors[] = "Row {$r}: Product name is required";
                $skipped++;
                continue;
            }

            try {
                // Parse numeric values
                $sale = (float)str_replace(',', '.', (string)($get('SalePrice') ?? 0));
                $ws1 = (float)str_replace(',', '.', (string)($get('WholeSalePrice1') ?? 0));
                $ws2 = (float)str_replace(',', '.', (string)($get('WholeSalePrice2') ?? 0));
                $qoh = (float)str_replace(',', '.', (string)($get('ExistQuantity') ?? 0));

                // Validate prices
                if ($sale < 0 || $ws1 < 0 || $ws2 < 0) {
                    $result['warnings'][] = "Row {$r}: Negative prices detected, setting to 0";
                    $sale = max(0, $sale);
                    $ws1 = max(0, $ws1);
                    $ws2 = max(0, $ws2);
                }

                // Use first available wholesale price, fallback to sale price if needed
                $wholesale = $ws1 > 0 ? $ws1 : ($ws2 > 0 ? $ws2 : $sale);

                $stmt->execute([
                    ':sku' => $sku,
                    ':code_clean' => preg_replace('/[^A-Za-z0-9\-_.]/u', '', $sku),
                    ':item_name' => $name,
                    ':second_name' => trim((string)($get('SecondName') ?? '')),
                    ':topcat' => (int)($get('TopCat') ?? 0),
                    ':midcat' => (int)($get('MidCat') ?? 0),
                    ':topcat_name' => trim((string)($get('TopCatName') ?? '')),
                    ':midcat_name' => trim((string)($get('MidCatName') ?? '')),
                    ':type' => trim((string)($get('Type') ?? '')),
                    ':unit' => trim((string)($get('Unit') ?? '')),
                    ':sale_price_usd' => $sale,
                    ':wholesale_price_usd' => $wholesale,
                    ':min_quantity' => 0,
                    ':minqty_disc1' => $ws1,
                    ':minqty_disc2' => $ws2,
                    ':description' => trim((string)($get('Description') ?? '')),
                    ':quantity_on_hand' => $qoh,
                ]);

                $affected = $stmt->rowCount();
                if ($affected === 1) {
                    $inserted++;
                } elseif ($affected === 2) {
                    $updated++;
                } else {
                    $skipped++;
                }

            } catch (PDOException $e) {
                $lineErrors[] = "Row {$r}: Database error - " . $e->getMessage();
                $skipped++;
            }
        }

        if ($lineErrors) {
            $result['warnings'] = array_merge($result['warnings'], array_slice($lineErrors, 0, 10));
            if (count($lineErrors) > 10) {
                $result['warnings'][] = '... and ' . (count($lineErrors) - 10) . ' more errors';
            }
        }

        $result['ok'] = true;
        $result['inserted'] = $inserted;
        $result['updated'] = $updated;
        $result['skipped'] = $skipped;
        $result['total'] = $inserted + $updated + $skipped;
        $result['message'] = sprintf(
            'Import successful: %d inserted, %d updated, %d skipped',
            $inserted,
            $updated,
            $skipped
        );

    } catch (Exception $e) {
        $result['ok'] = false;
        $result['errors'][] = 'Import failed: ' . $e->getMessage();
        $result['message'] = $e->getMessage();
    }

    return $result;
    }
}
