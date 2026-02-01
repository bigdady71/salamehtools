<?php

declare(strict_types=1);

/**
 * Get the base URL path for the application.
 * Works on both local (with /salamehtools) and Hostinger (root /public_html).
 */
function get_base_path(): string
{
    static $basePath = null;

    if ($basePath === null) {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';

        // Check if we're in a subdirectory like /salamehtools
        if (strpos($scriptPath, '/salamehtools/') !== false) {
            $basePath = '/salamehtools';
        } elseif (preg_match('#^(/[^/]+)/pages/#', $scriptPath, $matches)) {
            // Extract base path from script path (e.g., /myapp/pages/... -> /myapp)
            $basePath = $matches[1];
        } else {
            // Root installation (Hostinger)
            $basePath = '';
        }
    }

    return $basePath;
}

/**
 * Generate a URL for an asset file with cache-busting version parameter.
 */
function asset_url(string $path): string
{
    $basePath = get_base_path();
    $normalized = '/' . ltrim($path, '/');
    $root = dirname(__DIR__);
    $fsPath = $root . $normalized;

    $fullPath = $basePath . $normalized;

    if (is_file($fsPath)) {
        return $fullPath . '?v=' . filemtime($fsPath);
    }

    return $fullPath;
}
