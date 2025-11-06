<?php
/**
 * CacheManager - Flexible caching layer with multiple backend support
 *
 * Supports:
 * - Redis (recommended for production)
 * - Memcached (alternative)
 * - File-based cache (fallback)
 * - Array cache (testing/development)
 *
 * Usage:
 *   $cache = new CacheManager('redis', ['host' => '127.0.0.1', 'port' => 6379]);
 *   $value = $cache->remember('key', 3600, function() {
 *       return expensive_operation();
 *   });
 */

class CacheManager
{
    private $driver;
    private $prefix;
    private $defaultTtl;
    private ?Logger $logger;

    // Supported cache drivers
    const DRIVER_REDIS = 'redis';
    const DRIVER_MEMCACHED = 'memcached';
    const DRIVER_FILE = 'file';
    const DRIVER_ARRAY = 'array';

    /**
     * @param string $driver Cache driver (redis, memcached, file, array)
     * @param array $config Driver configuration
     * @param string $prefix Key prefix for namespacing
     * @param int $defaultTtl Default TTL in seconds
     * @param Logger|null $logger Logger instance
     */
    public function __construct(
        string $driver = self::DRIVER_FILE,
        array $config = [],
        string $prefix = 'salameh:',
        int $defaultTtl = 3600,
        ?Logger $logger = null
    ) {
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
        $this->logger = $logger;

        $this->driver = $this->createDriver($driver, $config);
    }

    /**
     * Get value from cache
     *
     * @param string $key Cache key
     * @return mixed|null Value or null if not found
     */
    public function get(string $key)
    {
        $fullKey = $this->prefix . $key;

        try {
            $value = $this->driver->get($fullKey);

            if ($this->logger && $value !== null) {
                $this->logger->debug('Cache hit', ['key' => $key]);
            }

            return $value;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
            return null;
        }
    }

    /**
     * Store value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time to live in seconds (null = default)
     * @return bool Success
     */
    public function put(string $key, $value, ?int $ttl = null): bool
    {
        $fullKey = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            $result = $this->driver->set($fullKey, $value, $ttl);

            if ($this->logger) {
                $this->logger->debug('Cache set', ['key' => $key, 'ttl' => $ttl]);
            }

            return $result;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Cache set failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Get value from cache or execute callback and store result
     *
     * @param string $key Cache key
     * @param int $ttl Time to live in seconds
     * @param callable $callback Function to execute if cache miss
     * @return mixed Cached or computed value
     */
    public function remember(string $key, int $ttl, callable $callback)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        if ($this->logger) {
            $this->logger->debug('Cache miss', ['key' => $key]);
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Delete value from cache
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function forget(string $key): bool
    {
        $fullKey = $this->prefix . $key;

        try {
            $result = $this->driver->delete($fullKey);

            if ($this->logger) {
                $this->logger->debug('Cache delete', ['key' => $key]);
            }

            return $result;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Cache delete failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Clear all cache entries
     *
     * @return bool Success
     */
    public function flush(): bool
    {
        try {
            $result = $this->driver->flush();

            if ($this->logger) {
                $this->logger->info('Cache flushed');
            }

            return $result;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Cache flush failed', ['error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Check if key exists in cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment
     * @return int|bool New value or false on failure
     */
    public function increment(string $key, int $value = 1)
    {
        $fullKey = $this->prefix . $key;

        try {
            return $this->driver->increment($fullKey, $value);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Cache increment failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Decrement numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement
     * @return int|bool New value or false on failure
     */
    public function decrement(string $key, int $value = 1)
    {
        $fullKey = $this->prefix . $key;

        try {
            return $this->driver->decrement($fullKey, $value);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Cache decrement failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /**
     * Get multiple values
     *
     * @param array $keys Array of cache keys
     * @return array Associative array of key => value
     */
    public function many(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * Store multiple values
     *
     * @param array $values Associative array of key => value
     * @param int|null $ttl Time to live in seconds
     * @return bool Success
     */
    public function putMany(array $values, ?int $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->put($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Create cache driver instance
     *
     * @param string $driver Driver name
     * @param array $config Driver configuration
     * @return CacheDriverInterface
     */
    private function createDriver(string $driver, array $config): CacheDriverInterface
    {
        switch ($driver) {
            case self::DRIVER_REDIS:
                return new RedisCacheDriver($config, $this->logger);

            case self::DRIVER_MEMCACHED:
                return new MemcachedCacheDriver($config, $this->logger);

            case self::DRIVER_FILE:
                return new FileCacheDriver($config, $this->logger);

            case self::DRIVER_ARRAY:
                return new ArrayCacheDriver($config, $this->logger);

            default:
                throw new InvalidArgumentException("Unsupported cache driver: $driver");
        }
    }

    /**
     * Get cache statistics
     *
     * @return array Driver-specific stats
     */
    public function stats(): array
    {
        if (method_exists($this->driver, 'stats')) {
            return $this->driver->stats();
        }

        return ['supported' => false];
    }
}

/**
 * Cache Driver Interface
 */
interface CacheDriverInterface
{
    public function get(string $key);
    public function set(string $key, $value, int $ttl): bool;
    public function delete(string $key): bool;
    public function flush(): bool;
    public function increment(string $key, int $value);
    public function decrement(string $key, int $value);
}

/**
 * Redis Cache Driver
 */
class RedisCacheDriver implements CacheDriverInterface
{
    private $redis;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->logger = $logger;

        if (!extension_loaded('redis')) {
            throw new RuntimeException('Redis extension not loaded');
        }

        $this->redis = new Redis();
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 2.5;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;

        $connected = $this->redis->connect($host, $port, $timeout);

        if (!$connected) {
            throw new RuntimeException("Failed to connect to Redis at $host:$port");
        }

        if ($password) {
            $this->redis->auth($password);
        }

        if ($database > 0) {
            $this->redis->select($database);
        }
    }

    public function get(string $key)
    {
        $value = $this->redis->get($key);
        return $value === false ? null : unserialize($value);
    }

    public function set(string $key, $value, int $ttl): bool
    {
        return $this->redis->setex($key, $ttl, serialize($value));
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }

    public function flush(): bool
    {
        return $this->redis->flushDB();
    }

    public function increment(string $key, int $value)
    {
        return $this->redis->incrBy($key, $value);
    }

    public function decrement(string $key, int $value)
    {
        return $this->redis->decrBy($key, $value);
    }

    public function stats(): array
    {
        return $this->redis->info();
    }
}

/**
 * Memcached Cache Driver
 */
class MemcachedCacheDriver implements CacheDriverInterface
{
    private $memcached;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->logger = $logger;

        if (!extension_loaded('memcached')) {
            throw new RuntimeException('Memcached extension not loaded');
        }

        $this->memcached = new Memcached();
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 11211;

        $this->memcached->addServer($host, $port);
    }

    public function get(string $key)
    {
        $value = $this->memcached->get($key);
        return $value === false ? null : $value;
    }

    public function set(string $key, $value, int $ttl): bool
    {
        return $this->memcached->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    public function flush(): bool
    {
        return $this->memcached->flush();
    }

    public function increment(string $key, int $value)
    {
        return $this->memcached->increment($key, $value);
    }

    public function decrement(string $key, int $value)
    {
        return $this->memcached->decrement($key, $value);
    }

    public function stats(): array
    {
        return $this->memcached->getStats();
    }
}

/**
 * File-based Cache Driver (Fallback)
 */
class FileCacheDriver implements CacheDriverInterface
{
    private string $cacheDir;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->logger = $logger;
        $this->cacheDir = $config['path'] ?? __DIR__ . '/../storage/cache/';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = unserialize(file_get_contents($file));

        if ($data['expires_at'] < time()) {
            unlink($file);
            return null;
        }

        return $data['value'];
    }

    public function set(string $key, $value, int $ttl): bool
    {
        $file = $this->getFilePath($key);
        $data = [
            'value' => $value,
            'expires_at' => time() + $ttl,
        ];

        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return unlink($file);
        }

        return true;
    }

    public function flush(): bool
    {
        $files = glob($this->cacheDir . '*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    public function increment(string $key, int $value)
    {
        $current = (int)$this->get($key);
        $new = $current + $value;
        $this->set($key, $new, 3600);
        return $new;
    }

    public function decrement(string $key, int $value)
    {
        $current = (int)$this->get($key);
        $new = $current - $value;
        $this->set($key, $new, 3600);
        return $new;
    }

    private function getFilePath(string $key): string
    {
        return $this->cacheDir . md5($key) . '.cache';
    }
}

/**
 * Array Cache Driver (Testing/Development)
 */
class ArrayCacheDriver implements CacheDriverInterface
{
    private array $storage = [];
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    public function get(string $key)
    {
        if (!isset($this->storage[$key])) {
            return null;
        }

        $data = $this->storage[$key];

        if ($data['expires_at'] < time()) {
            unset($this->storage[$key]);
            return null;
        }

        return $data['value'];
    }

    public function set(string $key, $value, int $ttl): bool
    {
        $this->storage[$key] = [
            'value' => $value,
            'expires_at' => time() + $ttl,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function flush(): bool
    {
        $this->storage = [];
        return true;
    }

    public function increment(string $key, int $value)
    {
        $current = (int)$this->get($key);
        $new = $current + $value;
        $this->set($key, $new, 3600);
        return $new;
    }

    public function decrement(string $key, int $value)
    {
        $current = (int)$this->get($key);
        $new = $current - $value;
        $this->set($key, $new, 3600);
        return $new;
    }
}
