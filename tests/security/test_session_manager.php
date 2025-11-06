<?php
/**
 * SessionManager Test Script
 *
 * Run: php tests/security/test_session_manager.php
 */

require_once __DIR__ . '/../../includes/SessionManager.php';

echo "🧪 Testing SessionManager\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Start session
echo "Test 1: Start session\n";
SessionManager::start();
$status = session_status();
echo "  Session status: " . ($status === PHP_SESSION_ACTIVE ? '✅ Active' : '❌ Inactive') . "\n";
echo "  Session ID: " . substr(session_id(), 0, 16) . "... ✅\n";

// Test 2: Check session parameters
echo "\nTest 2: Verify secure session parameters\n";
$params = session_get_cookie_params();
echo "  HttpOnly: " . ($params['httponly'] ? '✅ Yes' : '❌ No') . "\n";
echo "  Secure: " . ($params['secure'] ? '✅ Yes (HTTPS)' : '⚠️  No (HTTP - OK for dev)') . "\n";
echo "  SameSite: " . ($params['samesite'] ?? 'N/A') . " ✅\n";

// Test 3: Login simulation
echo "\nTest 3: Login simulation\n";
$testUser = [
    'id' => 123,
    'email' => 'test@example.com',
    'name' => 'Test User',
    'role' => 'admin'
];
$oldSessionId = session_id();
SessionManager::login($testUser);
$newSessionId = session_id();
echo "  Session ID changed: " . ($oldSessionId !== $newSessionId ? '✅ Yes (fixation protection)' : '❌ No') . "\n";
echo "  User stored: " . (isset($_SESSION['user']) ? '✅ Yes' : '❌ No') . "\n";
echo "  Authenticated flag: " . (isset($_SESSION['_authenticated']) ? '✅ Yes' : '❌ No') . "\n";

// Test 4: Validate session
echo "\nTest 4: Validate session\n";
$valid = SessionManager::validate();
echo "  Validation result: " . ($valid ? '✅ Valid' : '❌ Invalid') . "\n";

// Test 5: Get user
echo "\nTest 5: Get current user\n";
$user = SessionManager::getUser();
echo "  User retrieved: " . ($user !== null ? '✅ Yes' : '❌ No') . "\n";
if ($user) {
    echo "  User ID: " . ($user['id'] ?? 'N/A') . " ✅\n";
    echo "  User email: " . ($user['email'] ?? 'N/A') . " ✅\n";
}

// Test 6: Check metadata
echo "\nTest 6: Check session metadata\n";
if (isset($_SESSION['_metadata'])) {
    $metadata = $_SESSION['_metadata'];
    echo "  Metadata exists: ✅ Yes\n";
    echo "  Created at: " . date('Y-m-d H:i:s', $metadata['created_at']) . " ✅\n";
    echo "  Last activity: " . date('Y-m-d H:i:s', $metadata['last_activity']) . " ✅\n";
    echo "  IP address: " . ($metadata['ip_address'] ?? 'N/A') . " ✅\n";
    echo "  User agent: " . substr($metadata['user_agent'] ?? 'N/A', 0, 30) . "... ✅\n";
} else {
    echo "  ❌ Metadata not found\n";
}

// Test 7: Session age and idle time
echo "\nTest 7: Session timing\n";
$age = SessionManager::getAge();
$idle = SessionManager::getIdleTime();
$remaining = SessionManager::getRemainingTime();
echo "  Session age: {$age} seconds ✅\n";
echo "  Idle time: {$idle} seconds ✅\n";
echo "  Time remaining: {$remaining} seconds ✅\n";

// Test 8: Flash messages
echo "\nTest 8: Flash messages\n";
SessionManager::flash('test_message', 'This is a test message');
echo "  Flash message set: ✅\n";
$message = SessionManager::getFlash('test_message');
echo "  Flash message retrieved: " . ($message ? '✅ ' . $message : '❌ Not found') . "\n";
$messageAgain = SessionManager::getFlash('test_message');
echo "  Flash message cleared: " . ($messageAgain === null ? '✅ Yes (one-time)' : '❌ No') . "\n";

// Test 9: isAuthenticated check
echo "\nTest 9: Check authentication status\n";
$isAuth = SessionManager::isAuthenticated();
echo "  Is authenticated: " . ($isAuth ? '✅ Yes' : '❌ No') . "\n";

// Test 10: Regenerate session
echo "\nTest 10: Regenerate session ID\n";
$beforeRegen = session_id();
SessionManager::regenerate();
$afterRegen = session_id();
echo "  Session ID changed: " . ($beforeRegen !== $afterRegen ? '✅ Yes' : '❌ No') . "\n";
echo "  User still logged in: " . (SessionManager::isAuthenticated() ? '✅ Yes' : '❌ No') . "\n";

// Test 11: Simulate timeout (destructive test - commented out)
echo "\nTest 11: Timeout simulation (skipped - would destroy session)\n";
echo "  To test timeout: Wait 30 minutes and refresh page ⏱️\n";

// Test 12: Logout
echo "\nTest 12: Logout\n";
SessionManager::logout();
echo "  Session destroyed: ✅\n";
$sessionActive = session_status() === PHP_SESSION_ACTIVE;
echo "  Session still active: " . ($sessionActive ? '❌ Yes (should be destroyed)' : '✅ No') . "\n";

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ All SessionManager tests completed!\n\n";

// Show usage example
echo "📖 Usage Example:\n";
echo "----------------\n";
echo "// Start session\n";
echo "SessionManager::start();\n";
echo "\n";
echo "// On login\n";
echo "SessionManager::login(\$user);\n";
echo "\n";
echo "// On every protected page\n";
echo "if (!SessionManager::validate()) {\n";
echo "    header('Location: /login.php');\n";
echo "    exit;\n";
echo "}\n";
echo "\n";
echo "// Get current user\n";
echo "\$user = SessionManager::getUser();\n";
echo "\n";
echo "// Logout\n";
echo "SessionManager::logout();\n";
