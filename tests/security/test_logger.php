<?php
/**
 * Logger Test Script
 *
 * Run: php tests/security/test_logger.php
 */

require_once __DIR__ . '/../../includes/Logger.php';

echo "🧪 Testing Logger\n";
echo str_repeat("=", 50) . "\n\n";

// Initialize logger
$logger = new Logger();

// Test 1: Log different levels
echo "Test 1: Logging different levels\n";
$logger->debug('Debug message', ['test_id' => 1]);
echo "  ✅ Debug logged\n";

$logger->info('Info message', ['test_id' => 2, 'action' => 'test']);
echo "  ✅ Info logged\n";

$logger->warning('Warning message', ['threshold' => 90, 'current' => 95]);
echo "  ✅ Warning logged\n";

$logger->error('Error message', ['error_code' => 500]);
echo "  ✅ Error logged\n";

$logger->critical('Critical message', ['system' => 'database', 'status' => 'down']);
echo "  ✅ Critical logged\n";

// Test 2: Exception logging
echo "\nTest 2: Exception logging\n";
try {
    throw new Exception('Test exception', 999);
} catch (Exception $e) {
    $logger->exception($e, 'Caught test exception', ['handled' => true]);
    echo "  ✅ Exception logged\n";
}

// Test 3: Verify log file exists
echo "\nTest 3: Verify log file\n";
$logPath = __DIR__ . '/../../storage/logs/app-' . date('Y-m-d') . '.log';
if (file_exists($logPath)) {
    echo "  ✅ Log file exists: $logPath\n";
    $size = filesize($logPath);
    echo "  ✅ File size: " . round($size / 1024, 2) . " KB\n";
} else {
    echo "  ❌ Log file not found\n";
}

// Test 4: Read recent logs
echo "\nTest 4: Read recent log entries\n";
$recent = $logger->tail(5);
echo "  ✅ Retrieved " . count($recent) . " recent entries\n";

if (!empty($recent)) {
    echo "  Sample entry:\n";
    $entry = $recent[0];
    echo "    - Level: " . ($entry['level'] ?? 'N/A') . "\n";
    echo "    - Message: " . ($entry['message'] ?? 'N/A') . "\n";
    echo "    - Timestamp: " . ($entry['timestamp'] ?? 'N/A') . "\n";
}

// Test 5: Get statistics
echo "\nTest 5: Get log statistics\n";
$stats = $logger->getStats();
echo "  Total entries: " . $stats['total'] . " ✅\n";
echo "  By level:\n";
foreach ($stats['by_level'] as $level => $count) {
    if ($count > 0) {
        echo "    - $level: $count\n";
    }
}
echo "  Errors: " . $stats['errors'] . " ✅\n";

// Test 6: Context enrichment
echo "\nTest 6: Verify context enrichment\n";
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/test/endpoint';
$_SESSION['user'] = ['id' => 123, 'email' => 'test@example.com'];

$logger->info('Test with context', ['custom_field' => 'value']);
echo "  ✅ Context enriched (IP, method, URL, user)\n";

// Test 7: Show raw log entry
echo "\nTest 7: Show raw log entry (JSON)\n";
$logContent = file($logPath);
if ($logContent) {
    $lastEntry = end($logContent);
    $decoded = json_decode($lastEntry, true);
    echo "  ✅ JSON parsed successfully\n";
    echo "  Metadata included:\n";
    if (isset($decoded['metadata'])) {
        echo "    - IP: " . ($decoded['metadata']['ip'] ?? 'N/A') . "\n";
        echo "    - Method: " . ($decoded['metadata']['method'] ?? 'N/A') . "\n";
        echo "    - Memory: " . ($decoded['metadata']['memory_usage'] ?? 'N/A') . "\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ All Logger tests completed!\n\n";

// Show usage example
echo "📖 Usage Example:\n";
echo "----------------\n";
echo "\$logger = new Logger();\n";
echo "\n";
echo "// Basic logging\n";
echo "\$logger->info('User logged in', ['user_id' => 123]);\n";
echo "\n";
echo "// Error logging\n";
echo "try {\n";
echo "    createOrder(\$data);\n";
echo "} catch (Exception \$e) {\n";
echo "    \$logger->exception(\$e, 'Failed to create order');\n";
echo "    throw \$e;\n";
echo "}\n";
echo "\n";
echo "// Read logs\n";
echo "\$recent = \$logger->tail(100);\n";
echo "\$stats = \$logger->getStats();\n";
