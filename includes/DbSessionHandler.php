<?php

/**
 * Database-based session handler to work around hosting providers
 * with broken file-based session storage.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    private ?PDO $pdo = null;

    public function open(string $path, string $name): bool
    {
        try {
            $config = require __DIR__ . '/../config/db.php';
            $this->pdo = new PDO($config['dsn'], $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Auto-create sessions table if it doesn't exist
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS sessions (
                    id VARCHAR(128) NOT NULL PRIMARY KEY,
                    data TEXT NOT NULL,
                    expires_at DATETIME NOT NULL,
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            return true;
        } catch (PDOException $e) {
            error_log("DbSessionHandler::open failed: " . $e->getMessage());
            return false;
        }
    }

    public function close(): bool
    {
        $this->pdo = null;
        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->pdo) return '';
        try {
            $stmt = $this->pdo->prepare(
                "SELECT data FROM sessions WHERE id = ? AND expires_at > NOW()"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ? $row['data'] : '';
        } catch (PDOException $e) {
            error_log("DbSessionHandler::read failed: " . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->pdo) return false;
        try {
            $lifetime = (int)ini_get('session.gc_maxlifetime');
            if ($lifetime < 1) $lifetime = 1440;

            $stmt = $this->pdo->prepare(
                "INSERT INTO sessions (id, data, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                 ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)"
            );
            $stmt->execute([$id, $data, $lifetime]);
            return true;
        } catch (PDOException $e) {
            error_log("DbSessionHandler::write failed: " . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("DbSessionHandler::destroy failed: " . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("DbSessionHandler::gc failed: " . $e->getMessage());
            return false;
        }
    }
}
