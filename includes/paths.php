<?php

declare(strict_types=1);

/**
 * Path Configuration
 * 
 * Handles path differences between local development and Hostinger production.
 * 
 * LOCAL STRUCTURE:
 *   C:\xampp\htdocs\salamehtools\
 *   ├── index.php
 *   ├── bootstrap.php
 *   ├── includes/
 *   ├── pages/
 *   └── ...
 * 
 * HOSTINGER STRUCTURE:
 *   /public_html/
 *   ├── index.php
 *   ├── bootstrap.php
 *   ├── includes/
 *   ├── pages/
 *   └── ...
 * 
 * Key difference: On Hostinger, there's NO parent "salamehtools" folder.
 * Files are directly inside /public_html
 */

// Detect environment based on server characteristics
function is_hostinger(): bool
{
    // Check for Hostinger-specific indicators
    if (isset($_SERVER['SERVER_NAME'])) {
        // Check if running on a Hostinger domain
        if (strpos($_SERVER['SERVER_NAME'], 'hostinger') !== false) {
            return true;
        }
    }

    // Check for public_html path (common on shared hosting)
    if (isset($_SERVER['DOCUMENT_ROOT'])) {
        if (strpos($_SERVER['DOCUMENT_ROOT'], 'public_html') !== false) {
            return true;
        }
    }

    // Check for Linux server (Hostinger uses Linux)
    if (PHP_OS_FAMILY === 'Linux' && !file_exists('/xampp')) {
        // Additional check: if we're in public_html
        if (strpos(__DIR__, 'public_html') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Get the application root directory
 * 
 * @return string Absolute path to application root (no trailing slash)
 */
function get_app_root(): string
{
    // __DIR__ is the includes/ directory
    return dirname(__DIR__);
}

/**
 * Get the base URL for the application
 * 
 * @return string Base URL (no trailing slash)
 */
function get_base_url(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    if (is_hostinger()) {
        // On Hostinger, files are directly in public_html (no subfolder)
        return "{$protocol}://{$host}";
    } else {
        // Local development with salamehtools subfolder
        return "{$protocol}://{$host}/salamehtools";
    }
}

/**
 * Get the relative path from current script to app root
 * Useful for require/include statements
 * 
 * @param string $currentDir The __DIR__ of the calling script
 * @return string Relative path to app root
 */
function get_relative_root(string $currentDir): string
{
    $appRoot = get_app_root();

    // Normalize paths
    $currentDir = str_replace('\\', '/', realpath($currentDir) ?: $currentDir);
    $appRoot = str_replace('\\', '/', realpath($appRoot) ?: $appRoot);

    // Calculate relative path
    $currentParts = explode('/', trim($currentDir, '/'));
    $rootParts = explode('/', trim($appRoot, '/'));

    // Find common prefix
    $commonLength = 0;
    $minLength = min(count($currentParts), count($rootParts));
    for ($i = 0; $i < $minLength; $i++) {
        if ($currentParts[$i] === $rootParts[$i]) {
            $commonLength++;
        } else {
            break;
        }
    }

    // Calculate ups needed
    $upsNeeded = count($currentParts) - $commonLength;

    // Build relative path
    $relativePath = str_repeat('../', $upsNeeded);

    // Add remaining root parts
    $remainingRoot = array_slice($rootParts, $commonLength);
    if (!empty($remainingRoot)) {
        $relativePath .= implode('/', $remainingRoot) . '/';
    }

    return rtrim($relativePath, '/') ?: '.';
}

/**
 * Build a URL path that works on both local and production
 * 
 * @param string $path Path relative to app root (e.g., 'pages/login.php')
 * @return string Full URL
 */
function url(string $path): string
{
    return get_base_url() . '/' . ltrim($path, '/');
}

/**
 * Build an asset URL (for images, CSS, JS)
 * 
 * @param string $path Path relative to app root
 * @return string Full URL to asset
 */
function asset_url(string $path): string
{
    return url($path);
}

/**
 * Get the storage path
 * 
 * @param string $subpath Optional subpath within storage
 * @return string Absolute path to storage directory
 */
function storage_path(string $subpath = ''): string
{
    $path = get_app_root() . '/storage';
    if ($subpath) {
        $path .= '/' . ltrim($subpath, '/');
    }
    return $path;
}

/**
 * Redirect to a URL that works on both local and production
 * 
 * @param string $path Path relative to app root
 * @param int $statusCode HTTP status code (default 302)
 */
function redirect_to(string $path, int $statusCode = 302): void
{
    header('Location: ' . url($path), true, $statusCode);
    exit;
}

/**
 * Get the current environment name
 * 
 * @return string 'production' or 'development'
 */
function get_environment(): string
{
    return is_hostinger() ? 'production' : 'development';
}
