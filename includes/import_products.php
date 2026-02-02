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
 * @param array $options Optional filter options:
 *   - skip_zero_qty: bool - Skip products with quantity = 0
 *   - skip_zero_retail_price: bool - Skip products with retail price = 0
 *   - skip_zero_wholesale_price: bool - Skip products with wholesale price = 0
 *   - skip_same_prices: bool - Skip products where retail price = wholesale price
 * @return array ['ok' => bool, 'inserted' => int, 'updated' => int, 'skipped' => int, 'filtered' => int, 'total' => int, 'errors' => array, 'warnings' => array, 'message' => string]
 */
if (!function_exists('import_products_from_path')) {
    function import_products_from_path(PDO $pdo, string $path, ?int $run_id = null, array $options = []): array
    {
    // Default options
    $options = array_merge([
        'skip_zero_qty' => false,
        'skip_zero_retail_price' => false,
        'skip_zero_wholesale_price' => false,
        'skip_same_prices' => false,
    ], $options);
    $result = [
        'ok' => false,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'filtered' => 0, // Count of products filtered out by options
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
        $newProducts = []; // Track newly inserted products for notifications

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

                // Apply import filters
                $filterReason = null;
                
                if ($options['skip_zero_qty'] && $qoh <= 0) {
                    $filterReason = 'quantity = 0';
                } elseif ($options['skip_zero_retail_price'] && $sale <= 0) {
                    $filterReason = 'retail price = 0';
                } elseif ($options['skip_zero_wholesale_price'] && $wholesale <= 0) {
                    $filterReason = 'wholesale price = 0';
                } elseif ($options['skip_same_prices'] && $sale > 0 && abs($sale - $wholesale) < 0.001) {
                    $filterReason = 'retail price = wholesale price';
                }

                if ($filterReason !== null) {
                    $result['filtered']++;
                    $skipped++;
                    continue;
                }

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
                    // Track new product for notifications
                    $newProducts[] = [
                        'sku' => $sku,
                        'item_name' => $name,
                        'price_usd' => $sale,
                        'quantity' => $qoh,
                    ];
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
        
        // Build message with filter info
        $msgParts = [
            sprintf('%d inserted', $inserted),
            sprintf('%d updated', $updated),
        ];
        if ($result['filtered'] > 0) {
            $msgParts[] = sprintf('%d filtered out', $result['filtered']);
        }
        if ($skipped - $result['filtered'] > 0) {
            $msgParts[] = sprintf('%d skipped (errors)', $skipped - $result['filtered']);
        }
        $result['message'] = 'Import successful: ' . implode(', ', $msgParts);

        // Send notifications to all sales reps for new products
        if (!empty($newProducts)) {
            try {
                // Get all sales rep user IDs
                $salesRepStmt = $pdo->query("SELECT id FROM users WHERE role = 'sales_rep' AND is_active = 1");
                $salesRepIds = $salesRepStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($salesRepIds)) {
                    // Get image URLs for new products
                    $skus = array_column($newProducts, 'sku');
                    $placeholders = implode(',', array_fill(0, count($skus), '?'));
                    $imgStmt = $pdo->prepare("SELECT sku, image_url FROM products WHERE sku IN ($placeholders)");
                    $imgStmt->execute($skus);
                    $imageMap = $imgStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                    // Prepare notification insert
                    $notifStmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, payload, created_at)
                        VALUES (:user_id, 'new_product', :payload, NOW())
                    ");

                    // Send notification for each new product to each sales rep
                    foreach ($newProducts as $product) {
                        $payload = json_encode([
                            'message' => 'New product added: ' . $product['item_name'],
                            'sku' => $product['sku'],
                            'item_name' => $product['item_name'],
                            'price_usd' => $product['price_usd'],
                            'quantity' => $product['quantity'],
                            'image_url' => $imageMap[$product['sku']] ?? null,
                        ], JSON_UNESCAPED_UNICODE);

                        foreach ($salesRepIds as $repId) {
                            $notifStmt->execute([
                                ':user_id' => $repId,
                                ':payload' => $payload,
                            ]);
                        }
                    }
                }
            } catch (Exception $e) {
                // Don't fail import if notifications fail
                $result['warnings'][] = 'Failed to send notifications: ' . $e->getMessage();
            }
        }

    } catch (Exception $e) {
        $result['ok'] = false;
        $result['errors'][] = 'Import failed: ' . $e->getMessage();
        $result['message'] = $e->getMessage();
    }

    return $result;
    }
}
