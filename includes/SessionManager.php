<?php
/**
 * SessionManager - Secure session handling with protection against common attacks
 *
 * Features:
 * - Session fixation protection (regenerate ID on login)
 * - Secure cookie flags (httponly, secure, samesite)
 * - Session timeout (idle timeout)
 * - IP address validation (optional, can break with proxies)
 * - User agent validation
 *
 * Usage:
 *   SessionManager::start();
 *   SessionManager::login($user);
 *   if (!SessionManager::validate()) {
 *       // Session invalid, redirect to login
 *   }
 */

class SessionManager
{
    private const IDLE_TIMEOUT = 1800; // 30 minutes
    private const ABSOLUTE_TIMEOUT = 28800; // 8 hours
    private const VALIDATE_IP = false; // Set to true for stricter security (may break with proxies)
    private const VALIDATE_USER_AGENT = true;

    /**
     * Start session with secure configuration
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Configure secure session parameters
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        session_set_cookie_params([
            'lifetime' => 0, // Session cookie (expires when browser closes)
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'secure' => $secure, // HTTPS only if available
            'httponly' => true, // No JavaScript access
            'samesite' => 'Strict' // CSRF protection
        ]);

        // Use strict session ID format
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_trans_sid', '0');

        session_start();

        // Initialize session metadata if new
        if (!isset($_SESSION['_metadata'])) {
            self::initializeMetadata();
        }
    }

    /**
     * Login user - regenerate session ID and store user data
     *
     * @param array $user User data to store in session
     */
    public static function login(array $user): void
    {
        // Regenerate session ID to prevent fixation attacks
        session_regenerate_id(true);

        // Store user data
        $_SESSION['user'] = $user;

        // Update metadata
        self::initializeMetadata();

        // Mark as authenticated
        $_SESSION['_authenticated'] = true;
    }

    /**
     * Logout user - destroy session completely
     */
    public static function logout(): void
    {
        $_SESSION = [];

        // Delete session cookie
        if (isset($_COOKIE[session_name()])) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Validate current session
     *
     * @return bool True if session is valid, false otherwise
     */
    public static function validate(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        // Check if authenticated
        if (empty($_SESSION['_authenticated'])) {
            return false;
        }

        // Check metadata exists
        if (!isset($_SESSION['_metadata'])) {
            self::destroy('Missing metadata');
            return false;
        }

        $metadata = $_SESSION['_metadata'];

        // Validate IP address (optional)
        if (self::VALIDATE_IP) {
            if (!isset($metadata['ip_address']) || $metadata['ip_address'] !== self::getClientIp()) {
                self::destroy('IP address mismatch');
                return false;
            }
        }

        // Validate user agent
        if (self::VALIDATE_USER_AGENT) {
            if (!isset($metadata['user_agent']) || $metadata['user_agent'] !== self::getUserAgent()) {
                self::destroy('User agent mismatch');
                return false;
            }
        }

        // Check idle timeout
        if (isset($metadata['last_activity'])) {
            $idleTime = time() - $metadata['last_activity'];
            if ($idleTime > self::IDLE_TIMEOUT) {
                self::destroy('Session idle timeout');
                return false;
            }
        }

        // Check absolute timeout
        if (isset($metadata['created_at'])) {
            $sessionAge = time() - $metadata['created_at'];
            if ($sessionAge > self::ABSOLUTE_TIMEOUT) {
                self::destroy('Session absolute timeout');
                return false;
            }
        }

        // Update last activity timestamp
        $_SESSION['_metadata']['last_activity'] = time();

        return true;
    }

    /**
     * Regenerate session ID (call periodically for added security)
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);

        // Update metadata
        if (isset($_SESSION['_metadata'])) {
            $_SESSION['_metadata']['regenerated_at'] = time();
        }
    }

    /**
     * Get current user from session
     *
     * @return array|null User data or null if not authenticated
     */
    public static function getUser(): ?array
    {
        if (!self::validate()) {
            return null;
        }

        return $_SESSION['user'] ?? null;
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public static function isAuthenticated(): bool
    {
        return self::validate() && !empty($_SESSION['_authenticated']);
    }

    /**
     * Get session age in seconds
     *
     * @return int
     */
    public static function getAge(): int
    {
        if (!isset($_SESSION['_metadata']['created_at'])) {
            return 0;
        }

        return time() - $_SESSION['_metadata']['created_at'];
    }

    /**
     * Get idle time in seconds
     *
     * @return int
     */
    public static function getIdleTime(): int
    {
        if (!isset($_SESSION['_metadata']['last_activity'])) {
            return 0;
        }

        return time() - $_SESSION['_metadata']['last_activity'];
    }

    /**
     * Get remaining time before idle timeout
     *
     * @return int Seconds remaining
     */
    public static function getRemainingTime(): int
    {
        $idleTime = self::getIdleTime();
        $remaining = self::IDLE_TIMEOUT - $idleTime;

        return max(0, $remaining);
    }

    /**
     * Set flash message (one-time message)
     *
     * @param string $key Message key
     * @param mixed $value Message value
     */
    public static function flash(string $key, $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Get and clear flash message
     *
     * @param string $key Message key
     * @return mixed Message value or null
     */
    public static function getFlash(string $key)
    {
        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    /**
     * Initialize session metadata
     */
    private static function initializeMetadata(): void
    {
        $_SESSION['_metadata'] = [
            'created_at' => time(),
            'last_activity' => time(),
            'ip_address' => self::getClientIp(),
            'user_agent' => self::getUserAgent(),
            'regenerated_at' => time(),
        ];
    }

    /**
     * Destroy session with reason logging
     *
     * @param string $reason Reason for destruction
     */
    private static function destroy(string $reason): void
    {
        // Log the destruction reason if logger is available
        if (class_exists('Logger')) {
            $logger = new Logger();
            $logger->warning('Session destroyed', [
                'reason' => $reason,
                'session_id' => session_id(),
                'ip' => self::getClientIp(),
            ]);
        }

        self::logout();
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    private static function getClientIp(): string
    {
        // Check for proxy headers (be careful with these in production)
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // If multiple IPs, take the first one
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Get user agent string
     *
     * @return string
     */
    private static function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
}
