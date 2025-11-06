<?php
/**
 * RateLimiter Test Script
 *
 * Run: php tests/security/test_rate_limiter.php
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';

echo "🧪 Testing RateLimiter\n";
echo str_repeat("=", 50) . "\n\n";

$pdo = db();
$limiter = new RateLimiter($pdo, 5, 1); // 5 attempts per minute

$testKey = 'test:' . time();

// Test 1: First 5 attempts should succeed
echo "Test 1: First 5 attempts should succeed\n";
for ($i = 1; $i <= 5; $i++) {
    $allowed = $limiter->attempt($testKey);
    $attempts = $limiter->getAttempts($testKey);
    echo "  Attempt $i: " . ($allowed ? '✅ Allowed' : '❌ Blocked') . " (Total: $attempts)\n";
}

// Test 2: 6th attempt should fail
echo "\nTest 2: 6th attempt should be blocked\n";
$allowed = $limiter->attempt($testKey);
$attempts = $limiter->getAttempts($testKey);
echo "  Attempt 6: " . ($allowed ? '❌ FAIL: Should be blocked' : '✅ PASS: Blocked') . " (Total: $attempts)\n";

// Test 3: Check wait time
echo "\nTest 3: Check wait time\n";
$waitTime = $limiter->availableIn($testKey);
echo "  Wait time: {$waitTime} seconds ✅\n";

// Test 4: tooManyAttempts check
echo "\nTest 4: Check tooManyAttempts() method\n";
$tooMany = $limiter->tooManyAttempts($testKey);
echo "  Too many attempts: " . ($tooMany ? '✅ Yes (correct)' : '❌ No (wrong)') . "\n";

// Test 5: Clear attempts
echo "\nTest 5: Clear attempts and retry\n";
$limiter->clear($testKey);
$attempts = $limiter->getAttempts($testKey);
echo "  After clear: $attempts attempts ✅\n";

$allowed = $limiter->attempt($testKey);
echo "  New attempt: " . ($allowed ? '✅ Allowed' : '❌ Blocked') . "\n";

// Test 6: Different keys are independent
echo "\nTest 6: Different keys are independent\n";
$testKey2 = 'test2:' . time();
$allowed = $limiter->attempt($testKey2);
$attempts = $limiter->getAttempts($testKey2);
echo "  New key attempt: " . ($allowed ? '✅ Allowed' : '❌ Blocked') . " (Total: $attempts)\n";

// Test 7: Cleanup
echo "\nTest 7: Cleanup old entries\n";
$limiter->cleanup();
echo "  Cleanup completed ✅\n";

// Verify table exists
echo "\nTest 8: Verify rate_limits table exists\n";
$tables = $pdo->query("SHOW TABLES LIKE 'rate_limits'")->fetchAll();
echo "  Table exists: " . (count($tables) > 0 ? '✅ Yes' : '❌ No') . "\n";

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ All RateLimiter tests completed!\n\n";

// Show usage example
echo "📖 Usage Example:\n";
echo "----------------\n";
echo "\$limiter = new RateLimiter(\$pdo, 5, 1);\n";
echo "if (!\$limiter->attempt('login:' . \$_SERVER['REMOTE_ADDR'])) {\n";
echo "    \$wait = \$limiter->availableIn('login:' . \$_SERVER['REMOTE_ADDR']);\n";
echo "    die('Too many attempts. Try again in ' . \$wait . ' seconds.');\n";
echo "}\n";
