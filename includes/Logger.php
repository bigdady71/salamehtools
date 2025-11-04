<?php
/**
 * Logger - Centralized logging system with JSON structured output
 *
 * Features:
 * - Multiple log levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
 * - JSON formatted output for easy parsing
 * - Automatic context enrichment (user, IP, URL)
 * - Daily log rotation
 * - Backtrace for errors
 *
 * Usage:
 *   $logger = new Logger();
 *   $logger->error('Order creation failed', ['order_id' => 123, 'error' => $e->getMessage()]);
 *   $logger->info('User logged in', ['user_id' => 456]);
 */

class Logger
{
    private const LEVEL_DEBUG = 100;
    private const LEVEL_INFO = 200;
    private const LEVEL_WARNING = 300;
    private const LEVEL_ERROR = 400;
    private const LEVEL_CRITICAL = 500;

    private string $logPath;
    private int $minLevel;

    /**
     * @param string|null $logPath Custom log path (defaults to storage/logs/)
     * @param string $minLevel Minimum log level ('debug', 'info', 'warning', 'error', 'critical')
     */
    public function __construct(?string $logPath = null, string $minLevel = 'info')
    {
        // Default log path
        if ($logPath === null) {
            $logPath = __DIR__ . '/../storage/logs/app-' . date('Y-m-d') . '.log';
        }

        $this->logPath = $logPath;
        $this->minLevel = $this->getLevelValue($minLevel);

        // Ensure log directory exists
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Log debug message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log informational message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error message with backtrace
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function error(string $message, array $context = []): void
    {
        // Add backtrace for errors
        if (!isset($context['trace'])) {
            $context['trace'] = $this->getBacktrace();
        }

        $this->log('ERROR', self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log critical message with backtrace
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function critical(string $message, array $context = []): void
    {
        // Add backtrace for critical errors
        if (!isset($context['trace'])) {
            $context['trace'] = $this->getBacktrace();
        }

        $this->log('CRITICAL', self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Log exception
     *
     * @param Throwable $exception Exception to log
     * @param string $message Optional message prefix
     * @param array $context Additional context
     */
    public function exception(Throwable $exception, string $message = 'Exception occurred', array $context = []): void
    {
        $context = array_merge($context, [
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_code' => $exception->getCode(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->error($message, $context);
    }

    /**
     * Core logging method
     *
     * @param string $level Level name
     * @param int $levelValue Level numeric value
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log(string $level, int $levelValue, string $message, array $context): void
    {
        // Check minimum level
        if ($levelValue < $this->minLevel) {
            return;
        }

        $record = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'metadata' => $this->getMetadata(),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        // Write to log file with file locking
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

        // If critical, also write to error log
        if ($levelValue >= self::LEVEL_ERROR) {
            error_log("[$level] $message " . json_encode($context));
        }
    }

    /**
     * Get metadata about current request
     *
     * @return array
     */
    private function getMetadata(): array
    {
        $metadata = [
            'request_id' => $this->getRequestId(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'url' => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];

        // Add user info if session available
        if (isset($_SESSION['user'])) {
            $metadata['user_id'] = $_SESSION['user']['id'] ?? null;
            $metadata['user_email'] = $_SESSION['user']['email'] ?? null;
            $metadata['user_role'] = $_SESSION['user']['role'] ?? null;
        }

        // Add memory usage
        $metadata['memory_usage'] = round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB';
        $metadata['memory_peak'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB';

        return $metadata;
    }

    /**
     * Get simplified backtrace
     *
     * @return array
     */
    private function getBacktrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        // Remove logger entries from trace
        $filteredTrace = array_filter($trace, function ($item) {
            return !isset($item['class']) || $item['class'] !== 'Logger';
        });

        // Format trace
        $formatted = [];
        foreach ($filteredTrace as $item) {
            $entry = '';

            if (isset($item['file'])) {
                $entry .= basename($item['file']) . ':' . ($item['line'] ?? '?');
            }

            if (isset($item['class'])) {
                $entry .= ' ' . $item['class'] . $item['type'] . $item['function'] . '()';
            } elseif (isset($item['function'])) {
                $entry .= ' ' . $item['function'] . '()';
            }

            if ($entry) {
                $formatted[] = $entry;
            }
        }

        return $formatted;
    }

    /**
     * Get unique request ID
     *
     * @return string
     */
    private function getRequestId(): string
    {
        static $requestId = null;

        if ($requestId === null) {
            $requestId = bin2hex(random_bytes(8));
        }

        return $requestId;
    }

    /**
     * Get numeric value for log level
     *
     * @param string $level Level name
     * @return int
     */
    private function getLevelValue(string $level): int
    {
        $levels = [
            'debug' => self::LEVEL_DEBUG,
            'info' => self::LEVEL_INFO,
            'warning' => self::LEVEL_WARNING,
            'error' => self::LEVEL_ERROR,
            'critical' => self::LEVEL_CRITICAL,
        ];

        return $levels[strtolower($level)] ?? self::LEVEL_INFO;
    }

    /**
     * Read recent log entries
     *
     * @param int $lines Number of lines to read
     * @return array
     */
    public function tail(int $lines = 100): array
    {
        if (!file_exists($this->logPath)) {
            return [];
        }

        $content = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($content === false) {
            return [];
        }

        $recent = array_slice($content, -$lines);
        $parsed = [];

        foreach ($recent as $line) {
            $decoded = json_decode($line, true);
            if ($decoded !== null) {
                $parsed[] = $decoded;
            }
        }

        return $parsed;
    }

    /**
     * Clean old log files (call via cron)
     *
     * @param int $daysToKeep Number of days to keep logs
     * @param string|null $logDir Log directory path
     */
    public static function cleanup(int $daysToKeep = 30, ?string $logDir = null): void
    {
        if ($logDir === null) {
            $logDir = __DIR__ . '/../storage/logs/';
        }

        if (!is_dir($logDir)) {
            return;
        }

        $cutoffTime = time() - ($daysToKeep * 86400);

        $files = glob($logDir . 'app-*.log');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
    }

    /**
     * Get log statistics for today
     *
     * @return array
     */
    public function getStats(): array
    {
        if (!file_exists($this->logPath)) {
            return [
                'total' => 0,
                'by_level' => [],
                'errors' => 0,
            ];
        }

        $content = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $stats = [
            'total' => count($content),
            'by_level' => [
                'DEBUG' => 0,
                'INFO' => 0,
                'WARNING' => 0,
                'ERROR' => 0,
                'CRITICAL' => 0,
            ],
            'errors' => 0,
        ];

        foreach ($content as $line) {
            $decoded = json_decode($line, true);
            if ($decoded && isset($decoded['level'])) {
                $level = $decoded['level'];
                if (isset($stats['by_level'][$level])) {
                    $stats['by_level'][$level]++;
                }
                if (in_array($level, ['ERROR', 'CRITICAL'])) {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }
}
