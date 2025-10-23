<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
use Uzulla\EnhancedRedisSessionHandler\Exception\OperationException;

class RedisConnection
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

        try {
            $isPersistent = $this->config->isPersistent();
            $host = $this->config->getHost();
            $port = $this->config->getPort();
            $timeout = $this->config->getTimeout();
            $retryInterval = $this->config->getRetryInterval();

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
            return true;
        } catch (RedisException $e) {
            $this->logger->critical('Redis connection failed', [
                'error' => $e->getMessage(),
                'host' => $this->config->getHost(),
                'port' => $this->config->getPort(),
            ]);
            throw new ConnectionException('Failed to connect to Redis: ' . $e->getMessage(), 0, $e);
        }
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
            $this->redis->del($key);
            return true;
        } catch (RedisException $e) {
            $this->logger->error('Redis DELETE operation failed', [
                'error' => $e->getMessage(),
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
     * @return array<string>
     */
    public function keys(string $pattern): array
    {
        $this->connect();

        $prefix = $this->config->getPrefix();
        $fullPattern = $prefix . $pattern;

        $keys = [];
        $iterator = null;

        try {
            while (false !== ($scanKeys = $this->redis->scan($iterator, $fullPattern, 100))) {
                foreach ($scanKeys as $key) {
                    $keys[] = str_replace($prefix, '', $key);
                }
            }

            return $keys;
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
}
