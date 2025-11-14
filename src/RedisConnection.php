<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
use Uzulla\EnhancedRedisSessionHandler\Exception\OperationException;

class RedisConnection implements LoggerAwareInterface
{
    private Redis $redis;
    private RedisConnectionConfig $config;
    private LoggerInterface $logger;
    private bool $connected = false;

    public function __construct(Redis $redis, RedisConnectionConfig $config, LoggerInterface $logger)
    {
        $this->redis = $redis;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        $maxRetries = $this->config->getMaxRetries();
        $retryInterval = $this->config->getRetryInterval();
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $isPersistent = $this->config->isPersistent();
                $host = $this->config->getHost();
                $port = $this->config->getPort();
                $timeout = $this->config->getTimeout();

                if ($isPersistent) {
                    $result = $this->redis->pconnect($host, $port, $timeout, null, $retryInterval);
                } else {
                    $result = $this->redis->connect($host, $port, $timeout, null, $retryInterval);
                }

                if (!$result) {
                    throw new ConnectionException('Failed to connect to Redis');
                }

                $password = $this->config->getPassword();
                if ($password !== null) {
                    if (!$this->redis->auth($password)) {
                        throw new ConnectionException('Redis authentication failed');
                    }
                }

                $database = $this->config->getDatabase();
                if ($database !== 0) {
                    if (!$this->redis->select($database)) {
                        throw new ConnectionException('Failed to select Redis database');
                    }
                }

                $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config->getReadTimeout());
                $this->redis->setOption(Redis::OPT_PREFIX, $this->config->getPrefix());

                $this->connected = true;

                if ($attempt > 1) {
                    $this->logger->info('Redis connection succeeded after retry', [
                        'attempt' => $attempt,
                        'host' => $host,
                        'port' => $port,
                    ]);
                }

                return true;
            } catch (RedisException | ConnectionException $e) {
                $lastException = $e;

                $this->logger->warning('Redis connection attempt failed', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'exception' => $e,
                    'host' => $this->config->getHost(),
                    'port' => $this->config->getPort(),
                ]);

                if ($attempt < $maxRetries) {
                    $sleepMs = $retryInterval * $attempt;
                    $this->logger->debug('Retrying Redis connection', [
                        'next_attempt' => $attempt + 1,
                        'sleep_ms' => $sleepMs,
                    ]);
                    usleep($sleepMs * 1000);
                }
            }
        }

        $errorMessage = $lastException !== null ? $lastException->getMessage() : 'Unknown error';

        $this->logger->critical('Redis connection failed after all retries', [
            'attempts' => $maxRetries,
            'error' => $errorMessage,
            'exception' => $lastException ?? null,
            'host' => $this->config->getHost(),
            'port' => $this->config->getPort(),
        ]);

        throw new ConnectionException(
            'Failed to connect to Redis after ' . $maxRetries . ' attempts: ' . $errorMessage,
            0,
            $lastException
        );
    }

    public function disconnect(): void
    {
        if ($this->connected && !$this->config->isPersistent()) {
            $this->redis->close();
        }
        $this->connected = false;
    }

    /**
     * Get a value from Redis by key.
     *
     * Returns string|false instead of throwing exceptions because SessionHandlerInterface::read()
     * requires a string|false return type. This method is designed to be compatible with that interface.
     *
     * @return string|false Returns the value as string if found, false if not found or on error
     */
    public function get(string $key)
    {
        $this->connect();

        try {
            $value = $this->redis->get($key);
            if (is_string($value)) {
                return $value;
            }
            return false;
        } catch (RedisException $e) {
            $this->logger->error('Redis GET operation failed', [
                'error' => $e->getMessage(),
                'key' => $key,
            ]);
            return false;
        }
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }

        $this->connect();

        try {
            $result = $this->redis->setex($key, $ttl, $value);
            return $result !== false;
        } catch (RedisException $e) {
            $this->logger->error('Redis SET operation failed', [
                'error' => $e->getMessage(),
                'key' => $key,
            ]);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->connect();

        try {
            $result = $this->redis->del($key);
            // del()は削除された要素数を返す。0より大きければ削除成功
            return $result > 0;
        } catch (RedisException $e) {
            $this->logger->error('Redis DELETE operation failed', [
                'exception' => $e,
                'key' => $key,
            ]);
            return false;
        }
    }

    public function exists(string $key): bool
    {
        $this->connect();

        try {
            $result = $this->redis->exists($key);
            if (is_int($result)) {
                return $result > 0;
            }
            if (is_bool($result)) {
                return $result;
            }
            return false;
        } catch (RedisException $e) {
            $this->logger->error('Redis EXISTS operation failed', [
                'error' => $e->getMessage(),
                'key' => $key,
            ]);
            return false;
        }
    }

    public function expire(string $key, int $ttl): bool
    {
        $this->connect();

        try {
            $result = $this->redis->expire($key, $ttl);
            return $result === true;
        } catch (RedisException $e) {
            $this->logger->error('Redis EXPIRE operation failed', [
                'error' => $e->getMessage(),
                'key' => $key,
            ]);
            return false;
        }
    }

    /**
     * Scan keys matching a pattern using Redis SCAN command (non-blocking)
     *
     * This method uses SCAN instead of KEYS to avoid blocking Redis in production environments.
     * Unlike KEYS which is O(N) and blocks the server, SCAN uses cursor-based iteration that
     * allows other operations to execute during the scan process.
     *
     * Implementation details:
     * - Scans 100 keys per iteration (configurable in redis->scan() call)
     * - Automatically handles key prefix configured in RedisConnectionConfig
     * - Returns only unique keys (Redis SCAN may return duplicates across iterations)
     * - Non-blocking: safe to use with large keyspaces in production
     *
     * Performance characteristics:
     * - Time complexity: O(N) where N is the number of keys in the database
     * - Does not block other Redis operations during execution
     * - Memory efficient: processes keys in batches
     *
     * @param string $pattern Pattern to match (e.g., "user123_*", "session:*")
     *                        Supports Redis glob-style patterns: *, ?, [abc], [^a], [a-z]
     * @return array<string> Array of unique matching keys (without prefix)
     *                       Empty array if no keys match or on error
     */
    public function scan(string $pattern): array
    {
        $this->connect();

        $prefix = $this->config->getPrefix();
        $fullPattern = $prefix . $pattern;

        $keys = [];
        $iterator = null;

        try {
            while (false !== ($scanKeys = $this->redis->scan($iterator, $fullPattern, 100))) {
                foreach ($scanKeys as $key) {
                    // Use key as array key to automatically deduplicate
                    $keyWithoutPrefix = str_replace($prefix, '', $key);
                    $keys[$keyWithoutPrefix] = true;
                }
            }

            // Return array of unique keys (array keys, not values)
            return array_keys($keys);
        } catch (RedisException $e) {
            $this->logger->error('Redis SCAN operation failed', [
                'error' => $e->getMessage(),
                'pattern' => $pattern,
            ]);
            return [];
        }
    }

    public function isConnected(): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            return $this->redis->ping() === '+PONG';
        } catch (RedisException $e) {
            return false;
        }
    }

    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
