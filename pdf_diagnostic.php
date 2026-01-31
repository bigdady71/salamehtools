<?php

/**
 * PDF Generation Diagnostic Script
 * Place in your web root and access via browser to diagnose PDF issues
 */

echo "<h1>Salameh Tools PDF Diagnostic</h1>";
echo "<hr>";

// Check directories
$dirs = [
    'storage' => __DIR__ . '/storage',
    'storage/invoices' => __DIR__ . '/storage/invoices',
    'fonts' => __DIR__ . '/fonts',
    'vendor' => __DIR__ . '/vendor',
];

echo "<h2>Directory Checks</h2>";
foreach ($dirs as $name => $path) {
    $exists = is_dir($path);
    $writable = is_writable($path);
    $status = $exists ? ($writable ? '✅ EXISTS & WRITABLE' : '⚠️ EXISTS BUT NOT WRITABLE') : '❌ MISSING';
    echo "<p><strong>$name:</strong> $status</p>";
    if ($exists) {
        echo "<small style='color: #666;'>$path</small><br>";
    }
}

// Check required files
echo "<h2>Required Files</h2>";
$files = [
    'vendor/autoload.php' => __DIR__ . '/vendor/autoload.php',
    'fonts/NotoSansArabic-Regular.ttf' => __DIR__ . '/fonts/NotoSansArabic-Regular.ttf',
    'includes/InvoicePDF.php' => __DIR__ . '/includes/InvoicePDF.php',
];

foreach ($files as $name => $path) {
    $exists = file_exists($path);
    $status = $exists ? '✅ EXISTS' : '❌ MISSING';
    echo "<p><strong>$name:</strong> $status</p>";
}

// Check PHP settings
echo "<h2>PHP Settings</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Memory Limit:</strong> " . ini_get('memory_limit') . "</p>";
echo "<p><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . " seconds</p>";
echo "<p><strong>Upload Max Size:</strong> " . ini_get('upload_max_filesize') . "</p>";
echo "<p><strong>Post Max Size:</strong> " . ini_get('post_max_size') . "</p>";

// Check required classes
echo "<h2>Required Classes</h2>";
@require_once __DIR__ . '/vendor/autoload.php';

$classes = [
    'Dompdf\\Dompdf' => 'Dompdf (PDF generation)',
    'Dompdf\\Options' => 'Dompdf Options',
];

foreach ($classes as $class => $name) {
    $exists = class_exists($class);
    $status = $exists ? '✅ AVAILABLE' : '❌ MISSING';
    echo "<p><strong>$name:</strong> $status</p>";
}

// Test temp directory
echo "<h2>Temp Directory Test</h2>";
$tmpDir = sys_get_temp_dir();
echo "<p><strong>Temp Dir:</strong> $tmpDir</p>";
echo "<p><strong>Readable:</strong> " . (is_readable($tmpDir) ? '✅ YES' : '❌ NO') . "</p>";
echo "<p><strong>Writable:</strong> " . (is_writable($tmpDir) ? '✅ YES' : '❌ NO') . "</p>";

// Try to write a test file
$testFile = $tmpDir . '/test_write_' . time() . '.txt';
$canWrite = @file_put_contents($testFile, 'test');
if ($canWrite) {
    @unlink($testFile);
    echo "<p><strong>Can Write Test Files:</strong> ✅ YES</p>";
} else {
    echo "<p><strong>Can Write Test Files:</strong> ❌ NO</p>";
}

// Database check
echo "<h2>Database Connection</h2>";
try {
    @require_once __DIR__ . '/includes/db.php';
    $pdo = @db();
    if ($pdo) {
        echo "<p><strong>Database:</strong> ✅ CONNECTED</p>";
        $result = $pdo->query("SELECT 1");
        echo "<p><strong>Query Test:</strong> " . ($result ? '✅ WORKING' : '❌ FAILED') . "</p>";
    } else {
        echo "<p><strong>Database:</strong> ❌ FAILED TO CONNECT</p>";
    }
} catch (Exception $e) {
    echo "<p><strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><em>Generated: " . date('Y-m-d H:i:s') . "</em></p>";
