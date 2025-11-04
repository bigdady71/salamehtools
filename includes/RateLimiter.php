<?php
/**
 * RateLimiter - Prevents brute force attacks by limiting request frequency
 *
 * Usage:
 *   $limiter = new RateLimiter();
 *   if (!$limiter->attempt('login:' . $_SERVER['REMOTE_ADDR'])) {
 *       die('Too many attempts. Try again later.');
 *   }
 */

class RateLimiter
{
    private PDO $pdo;
    private int $maxAttempts;
    private int $decayMinutes;

    /**
     * @param PDO $pdo Database connection
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $decayMinutes Time window in minutes
     */
    public function __construct(PDO $pdo, int $maxAttempts = 5, int $decayMinutes = 1)
    {
        $this->pdo = $pdo;
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
        $this->ensureTable();
    }

    /**
     * Check if the key is allowed to proceed
     *
     * @param string $key Unique identifier (e.g., 'login:192.168.1.1')
     * @return bool True if allowed, false if rate limit exceeded
     */
    public function attempt(string $key): bool
    {
        $this->clearOldAttempts($key);

        $attempts = $this->getAttempts($key);

        if ($attempts >= $this->maxAttempts) {
            return false;
        }

        $this->incrementAttempts($key);
        return true;
    }

    /**
     * Check if key has exceeded rate limit without incrementing
     *
     * @param string $key Unique identifier
     * @return bool True if rate limited, false if allowed
     */
    public function tooManyAttempts(string $key): bool
    {
        $this->clearOldAttempts($key);
        return $this->getAttempts($key) >= $this->maxAttempts;
    }

    /**
     * Get number of attempts for a key
     *
     * @param string $key Unique identifier
     * @return int Number of attempts
     */
    public function getAttempts(string $key): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM rate_limits
            WHERE key_name = :key
            AND expires_at > NOW()
        ");
        $stmt->execute([':key' => $key]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get seconds until rate limit expires
     *
     * @param string $key Unique identifier
     * @return int Seconds remaining, or 0 if not rate limited
     */
    public function availableIn(string $key): int
    {
        $stmt = $this->pdo->prepare("
            SELECT MAX(expires_at)
            FROM rate_limits
            WHERE key_name = :key
            AND expires_at > NOW()
        ");
        $stmt->execute([':key' => $key]);
        $expiresAt = $stmt->fetchColumn();

        if (!$expiresAt) {
            return 0;
        }

        $now = new DateTime();
        $expires = new DateTime($expiresAt);
        $diff = $expires->getTimestamp() - $now->getTimestamp();

        return max(0, $diff);
    }

    /**
     * Clear all attempts for a key (use after successful action)
     *
     * @param string $key Unique identifier
     */
    public function clear(string $key): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE key_name = :key");
        $stmt->execute([':key' => $key]);
    }

    /**
     * Reset rate limit for a key (useful for admin override)
     *
     * @param string $key Unique identifier
     */
    public function resetAttempts(string $key): void
    {
        $this->clear($key);
    }

    /**
     * Increment attempt counter
     */
    private function incrementAttempts(string $key): void
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->decayMinutes} minutes"));

        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (key_name, expires_at)
            VALUES (:key, :expires)
        ");
        $stmt->execute([
            ':key' => $key,
            ':expires' => $expiresAt
        ]);
    }

    /**
     * Remove expired attempts
     */
    private function clearOldAttempts(string $key): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM rate_limits
            WHERE key_name = :key
            AND expires_at <= NOW()
        ");
        $stmt->execute([':key' => $key]);
    }

    /**
     * Ensure rate_limits table exists
     */
    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_key_expires (key_name, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Clean up expired entries (call periodically via cron)
     */
    public function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM rate_limits WHERE expires_at <= NOW()");
    }
}
