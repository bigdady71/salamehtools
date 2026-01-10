<?php
/**
 * EMERGENCY FIX: Drop ALL triggers in database
 */
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "<h1>EMERGENCY DATABASE FIX</h1>";
echo "<p>Dropping ALL triggers from database...</p>";

// Get all triggers
$allTriggers = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);

if (empty($allTriggers)) {
    echo "<p style='color:green;font-size:18px;'>✓ No triggers found in database. Triggers are not the problem.</p>";
} else {
    echo "<p>Found " . count($allTriggers) . " trigger(s). Dropping them now...</p>";

    foreach ($allTriggers as $t) {
        $triggerName = $t['Trigger'];
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS `{$triggerName}`");
            echo "<p style='color:green;'>✓ Dropped: {$triggerName}</p>";
        } catch (Exception $e) {
            echo "<p style='color:red;'>✗ Failed to drop {$triggerName}: " . $e->getMessage() . "</p>";
        }
    }
}

// Verify no triggers remain
$remaining = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
echo "<hr>";
echo "<h2>Verification</h2>";
if (empty($remaining)) {
    echo "<p style='color:green;font-size:18px;font-weight:bold;'>✓ ALL TRIGGERS REMOVED SUCCESSFULLY</p>";
} else {
    echo "<p style='color:red;'>Still have " . count($remaining) . " triggers!</p>";
}

echo "<hr>";
echo "<h2>If double-deduction still happens, the problem is in the PHP code.</h2>";
echo "<p>Let me check the van_stock_sales.php file for you...</p>";

// Check what's happening in the code
$file = file_get_contents(__DIR__ . '/pages/sales/van_stock_sales.php');

// Count how many times we update s_stock
$updateCount = substr_count($file, 'UPDATE s_stock');
echo "<p>Found <strong>{$updateCount}</strong> UPDATE s_stock statement(s) in van_stock_sales.php</p>";

// Check for loops that might execute the update
preg_match_all('/foreach.*\$itemsWithPrices.*{/s', $file, $foreachMatches);
echo "<p>Found <strong>" . count($foreachMatches[0]) . "</strong> foreach loop(s) over itemsWithPrices</p>";

echo "<hr>";
echo "<p><a href='pages/sales/van_stock_sales.php' style='padding:10px 20px;background:#22c55e;color:white;text-decoration:none;border-radius:5px;'>Go to Create Sale Page</a></p>";
