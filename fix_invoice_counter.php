<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

try {
    $pdo->beginTransaction();
    
    // Get the highest invoice number from the invoices table
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_num
        FROM invoices
        WHERE invoice_number LIKE 'INV-%'
    ");
    $maxNum = (int)$stmt->fetchColumn();
    
    echo "Current highest invoice number in database: INV-" . str_pad((string)$maxNum, 6, '0', STR_PAD_LEFT) . "\n";
    
    // Update the counter to be at least as high as the max invoice number
    $updateStmt = $pdo->prepare("
        UPDATE counters
        SET current_value = GREATEST(current_value, :value)
        WHERE name = 'invoice_number'
    ");
    $updateStmt->execute([':value' => $maxNum]);
    
    // Verify the counter
    $checkStmt = $pdo->query("SELECT current_value FROM counters WHERE name = 'invoice_number'");
    $counterValue = (int)$checkStmt->fetchColumn();
    
    echo "Counter updated to: {$counterValue}\n";
    echo "Next invoice will be: INV-" . str_pad((string)($counterValue + 1), 6, '0', STR_PAD_LEFT) . "\n";
    
    $pdo->commit();
    echo "\nSuccess! Invoice counter is now in sync.\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
