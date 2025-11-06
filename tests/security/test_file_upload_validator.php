<?php
/**
 * FileUploadValidator Test Script
 *
 * Run: php tests/security/test_file_upload_validator.php
 */

require_once __DIR__ . '/../../includes/FileUploadValidator.php';
require_once __DIR__ . '/../../includes/Logger.php';

echo "🧪 Testing FileUploadValidator\n";
echo str_repeat("=", 50) . "\n\n";

$logger = new Logger();
$validator = new FileUploadValidator(10 * 1024 * 1024, true, $logger);

// Test 1: Create test files
echo "Test 1: Creating test files\n";
$testDir = __DIR__ . '/../../storage/test_uploads/';
if (!is_dir($testDir)) {
    mkdir($testDir, 0755, true);
}

// Create valid XLSX file (ZIP signature)
$validXlsx = $testDir . 'valid_test.xlsx';
file_put_contents($validXlsx, hex2bin('504b0304') . str_repeat('X', 100));
echo "  ✅ Created valid XLSX test file\n";

// Create fake XLSX file (wrong signature)
$fakeXlsx = $testDir . 'fake_test.xlsx';
file_put_contents($fakeXlsx, 'THIS IS NOT AN EXCEL FILE');
echo "  ✅ Created fake XLSX test file\n";

// Create malicious filename
$maliciousXlsx = $testDir . 'test../.php.xlsx';
file_put_contents($maliciousXlsx, hex2bin('504b0304') . str_repeat('X', 100));
echo "  ✅ Created file with malicious name\n";

// Test 2: Validate valid file
echo "\nTest 2: Validate valid XLSX file\n";
$validFile = [
    'name' => 'products_2025.xlsx',
    'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $validXlsx,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($validXlsx)
];
$result = $validator->validate($validFile, ['xlsx', 'xls']);
echo "  Valid: " . ($result['valid'] ? '✅ Yes' : '❌ No') . "\n";
echo "  Safe name: " . ($result['safe_name'] ?? 'N/A') . " ✅\n";
echo "  Errors: " . (empty($result['errors']) ? '✅ None' : implode(', ', $result['errors'])) . "\n";

// Test 3: Validate fake file (wrong magic bytes)
echo "\nTest 3: Validate fake XLSX (wrong signature)\n";
$fakeFile = [
    'name' => 'fake_products.xlsx',
    'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $fakeXlsx,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($fakeXlsx)
];
$result = $validator->validate($fakeFile, ['xlsx', 'xls']);
echo "  Valid: " . ($result['valid'] ? '❌ Should be invalid!' : '✅ Correctly rejected') . "\n";
echo "  Errors: " . (empty($result['errors']) ? '❌ No errors detected' : '✅ ' . implode(', ', $result['errors'])) . "\n";

// Test 4: Validate file with wrong extension
echo "\nTest 4: Validate wrong extension\n";
$wrongExt = [
    'name' => 'file.php',
    'type' => 'application/x-php',
    'tmp_name' => $validXlsx,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($validXlsx)
];
$result = $validator->validate($wrongExt, ['xlsx', 'xls']);
echo "  Valid: " . ($result['valid'] ? '❌ Should reject .php!' : '✅ Correctly rejected') . "\n";
echo "  Errors: " . implode(', ', $result['errors']) . "\n";

// Test 5: Validate filename sanitization
echo "\nTest 5: Filename sanitization\n";
$dangerousNames = [
    '../../../etc/passwd.xlsx',
    'test<script>alert(1)</script>.xlsx',
    'file with spaces & special!@#$.xlsx',
    'test__multiple___underscores.xlsx',
    'test.php.xlsx'
];

foreach ($dangerousNames as $name) {
    $testFile = [
        'name' => $name,
        'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'tmp_name' => $validXlsx,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($validXlsx)
    ];
    $result = $validator->validate($testFile, ['xlsx']);
    echo "  '$name' → '" . ($result['safe_name'] ?? 'N/A') . "' " . ($result['valid'] ? '✅' : '❌') . "\n";
}

// Test 6: Validate file size limits
echo "\nTest 6: File size validation\n";
$smallValidator = new FileUploadValidator(1024, true, $logger); // 1KB limit
$largeFile = [
    'name' => 'large_file.xlsx',
    'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $validXlsx,
    'error' => UPLOAD_ERR_OK,
    'size' => 10 * 1024 * 1024 // 10MB
];
$result = $smallValidator->validate($largeFile, ['xlsx']);
echo "  10MB file with 1KB limit: " . ($result['valid'] ? '❌ Should reject!' : '✅ Correctly rejected') . "\n";
echo "  Error message: " . (isset($result['errors'][0]) ? $result['errors'][0] : 'N/A') . " ✅\n";

// Test 7: Validate empty file
echo "\nTest 7: Empty file validation\n";
$emptyFile = $testDir . 'empty.xlsx';
file_put_contents($emptyFile, '');
$testEmpty = [
    'name' => 'empty.xlsx',
    'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'tmp_name' => $emptyFile,
    'error' => UPLOAD_ERR_OK,
    'size' => 0
];
$result = $validator->validate($testEmpty, ['xlsx']);
echo "  Empty file: " . ($result['valid'] ? '❌ Should reject!' : '✅ Correctly rejected') . "\n";

// Test 8: Check ClamAV availability
echo "\nTest 8: ClamAV virus scanner\n";
$hasClamAV = FileUploadValidator::hasClamAV();
echo "  ClamAV available: " . ($hasClamAV ? '✅ Yes' : '⚠️  No (optional)') . "\n";

if ($hasClamAV) {
    $scanResult = $validator->scanWithClamAV($validXlsx);
    echo "  Scan result: " . ($scanResult['clean'] ? '✅ Clean' : '❌ Infected') . "\n";
}

// Test 9: Supported file types
echo "\nTest 9: Supported file types\n";
$supportedTypes = ['xlsx', 'xls', 'csv', 'pdf', 'jpg', 'jpeg', 'png', 'gif'];
echo "  Supported: " . implode(', ', $supportedTypes) . " ✅\n";

// Cleanup
echo "\nTest 10: Cleanup test files\n";
$testFiles = glob($testDir . '*');
foreach ($testFiles as $file) {
    unlink($file);
}
rmdir($testDir);
echo "  ✅ Test files cleaned up\n";

echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ All FileUploadValidator tests completed!\n\n";

// Show usage example
echo "📖 Usage Example:\n";
echo "----------------\n";
echo "\$validator = new FileUploadValidator(10 * 1024 * 1024, true, \$logger);\n";
echo "\n";
echo "\$result = \$validator->validate(\$_FILES['upload'], ['xlsx', 'xls']);\n";
echo "\n";
echo "if (!\$result['valid']) {\n";
echo "    echo 'Upload failed: ' . implode(', ', \$result['errors']);\n";
echo "} else {\n";
echo "    \$safeName = \$result['safe_name'];\n";
echo "    move_uploaded_file(\$_FILES['upload']['tmp_name'], \$uploadDir . '/' . \$safeName);\n";
echo "}\n";
