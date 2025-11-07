<?php

namespace SalamehTools\Middleware;

/**
 * Role-Based Access Control Middleware
 *
 * Enforces permissions based on config/rbac.php configuration
 *
 * Usage:
 *   RBACMiddleware::require('orders:create');
 *   RBACMiddleware::requireRole('admin');
 *   RBACMiddleware::can('invoices:edit') ? ... : ...;
 */
class RBACMiddleware
{
    private static ?array $permissions = null;
    private static ?array $user = null;

    /**
     * Load RBAC configuration
     */
    private static function loadPermissions(): array
    {
        if (self::$permissions === null) {
            $configPath = __DIR__ . '/../../config/rbac.php';
            self::$permissions = file_exists($configPath) ? require $configPath : [];
        }
        return self::$permissions;
    }

    /**
     * Get current authenticated user
     */
    private static function getUser(): ?array
    {
        if (self::$user === null) {
            if (!function_exists('auth_user')) {
                require_once __DIR__ . '/../../includes/auth.php';
            }
            self::$user = auth_user();
        }
        return self::$user;
    }

    /**
     * Check if user has a specific permission
     *
     * @param string $permission Permission string (e.g., "orders:create", "invoices:edit")
     * @param bool $strict If true, exact match required. If false, wildcard matching enabled
     * @return bool
     */
    public static function can(string $permission, bool $strict = false): bool
    {
        $user = self::getUser();
        if (!$user || !isset($user['role'])) {
            return false;
        }

        $role = $user['role'];
        $permissions = self::loadPermissions();

        if (!isset($permissions[$role])) {
            return false;
        }

        $rolePermissions = $permissions[$role];

        // Admin wildcard
        if (in_array('*', $rolePermissions, true)) {
            return true;
        }

        // Exact match
        if (in_array($permission, $rolePermissions, true)) {
            return true;
        }

        // Wildcard matching (e.g., "orders:*" matches "orders:create")
        if (!$strict) {
            foreach ($rolePermissions as $allowed) {
                if (str_ends_with($allowed, ':*')) {
                    $prefix = substr($allowed, 0, -2);
                    if (str_starts_with($permission, $prefix . ':')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Require a specific permission or die with 403
     *
     * @param string $permission Required permission
     * @param string $message Custom error message
     */
    public static function require(string $permission, string $message = 'Access denied'): void
    {
        if (!self::can($permission)) {
            http_response_code(403);
            die($message);
        }
    }

    /**
     * Require one of multiple permissions
     *
     * @param array $permissions Array of permissions
     * @param string $message Custom error message
     */
    public static function requireAny(array $permissions, string $message = 'Access denied'): void
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return;
            }
        }

        http_response_code(403);
        die($message);
    }

    /**
     * Require all permissions
     *
     * @param array $permissions Array of permissions
     * @param string $message Custom error message
     */
    public static function requireAll(array $permissions, string $message = 'Access denied'): void
    {
        foreach ($permissions as $permission) {
            if (!self::can($permission)) {
                http_response_code(403);
                die($message);
            }
        }
    }

    /**
     * Require a specific role
     *
     * @param string $role Required role
     * @param string $message Custom error message
     */
    public static function requireRole(string $role, string $message = 'Access denied'): void
    {
        $user = self::getUser();
        if (!$user || ($user['role'] ?? '') !== $role) {
            http_response_code(403);
            die($message);
        }
    }

    /**
     * Require one of multiple roles
     *
     * @param array $roles Array of roles
     * @param string $message Custom error message
     */
    public static function requireAnyRole(array $roles, string $message = 'Access denied'): void
    {
        $user = self::getUser();
        if (!$user || !in_array($user['role'] ?? '', $roles, true)) {
            http_response_code(403);
            die($message);
        }
    }

    /**
     * Get all permissions for current user's role
     *
     * @return array
     */
    public static function getUserPermissions(): array
    {
        $user = self::getUser();
        if (!$user || !isset($user['role'])) {
            return [];
        }

        $permissions = self::loadPermissions();
        return $permissions[$user['role']] ?? [];
    }

    /**
     * Check if current user is admin
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        $user = self::getUser();
        return $user && ($user['role'] ?? '') === 'admin';
    }

    /**
     * Filter items based on ownership (for :own permissions)
     *
     * @param string $permission Base permission (e.g., "orders:view")
     * @param array $item Item to check
     * @param string $ownerField Field name containing owner user_id
     * @return bool
     */
    public static function canAccessOwn(string $permission, array $item, string $ownerField = 'user_id'): bool
    {
        // If user has full permission, allow
        if (self::can($permission)) {
            return true;
        }

        // Check for :own variant
        if (self::can($permission . ':own')) {
            $user = self::getUser();
            return $user && isset($item[$ownerField]) && (int)$item[$ownerField] === (int)$user['id'];
        }

        return false;
    }
}
