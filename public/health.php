<?php
/**
 * Health Check Endpoint
 *
 * Returns system health status for monitoring and load balancers
 * HTTP 200 = Healthy
 * HTTP 503 = Unhealthy
 *
 * Usage:
 *   curl http://localhost/health.php
 *   # For load balancer: check for status code 200
 */

header('Content-Type: application/json; charset=UTF-8');

$status = 'healthy';
$checks = [];
$errors = [];

// Check 1: Database connectivity
try {
    require_once __DIR__ . '/../includes/db.php';
    $pdo = db();
    $stmt = $pdo->query('SELECT 1');
    $checks['database'] = [
        'status' => 'up',
        'response_time_ms' => 0,
    ];
} catch (Exception $e) {
    $status = 'unhealthy';
    $checks['database'] = [
        'status' => 'down',
        'error' => $e->getMessage(),
    ];
    $errors[] = 'Database connection failed';
}

// Check 2: Disk space
$freeSpace = @disk_free_space(__DIR__);
$totalSpace = @disk_total_space(__DIR__);

if ($freeSpace !== false && $totalSpace !== false && $totalSpace > 0) {
    $usedPercent = (1 - $freeSpace / $totalSpace) * 100;
    $checks['disk_space'] = [
        'status' => $usedPercent < 90 ? 'ok' : 'critical',
        'used_percent' => round($usedPercent, 2),
        'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
        'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
    ];

    if ($usedPercent >= 90) {
        $status = 'unhealthy';
        $errors[] = 'Disk space critical (>90% used)';
    }
} else {
    $checks['disk_space'] = [
        'status' => 'unknown',
        'error' => 'Could not read disk space',
    ];
}

// Check 3: Log directory writable
$logDir = __DIR__ . '/../storage/logs/';
if (is_dir($logDir) && is_writable($logDir)) {
    $checks['log_directory'] = [
        'status' => 'writable',
        'path' => $logDir,
    ];
} else {
    $checks['log_directory'] = [
        'status' => 'not_writable',
        'path' => $logDir,
    ];
    // Not critical, but warn
    $errors[] = 'Log directory not writable';
}

// Check 4: PHP version
$phpVersion = phpversion();
$checks['php_version'] = [
    'status' => version_compare($phpVersion, '8.0.0', '>=') ? 'ok' : 'outdated',
    'version' => $phpVersion,
];

if (version_compare($phpVersion, '7.4.0', '<')) {
    $status = 'unhealthy';
    $errors[] = 'PHP version too old (< 7.4)';
}

// Check 5: Required extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'json'];
$missingExtensions = [];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

$checks['php_extensions'] = [
    'status' => empty($missingExtensions) ? 'ok' : 'missing',
    'required' => $requiredExtensions,
    'missing' => $missingExtensions,
];

if (!empty($missingExtensions)) {
    $status = 'unhealthy';
    $errors[] = 'Missing PHP extensions: ' . implode(', ', $missingExtensions);
}

// Check 6: Import directory (if auto-import enabled)
$importDir = __DIR__ . '/../imports/';
if (is_dir($importDir)) {
    $checks['import_directory'] = [
        'status' => is_writable($importDir) ? 'ok' : 'not_writable',
        'path' => $importDir,
    ];
} else {
    $checks['import_directory'] = [
        'status' => 'not_found',
        'path' => $importDir,
    ];
}

// Build response
$response = [
    'status' => $status,
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => $checks,
];

if (!empty($errors)) {
    $response['errors'] = $errors;
}

// Add performance metrics if available
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $response['metrics'] = [
        'load_average' => [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2],
        ],
    ];
}

// Memory usage
$response['metrics']['memory'] = [
    'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
    'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
];

// Set HTTP status code
http_response_code($status === 'healthy' ? 200 : 503);

// Output JSON
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
