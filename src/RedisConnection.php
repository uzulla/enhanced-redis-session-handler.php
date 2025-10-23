<?php

namespace Uzulla\EnhancedRedisSessionHandler;

use Redis;
use RedisException;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
use Uzulla\EnhancedRedisSessionHandler\Exception\OperationException;

class RedisConnection
{
    private Redis $redis;
    /** @var array<string, mixed> */
    private array $config;
    private bool $connected = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 6379,
            'timeout' => 2.5,
            'password' => null,
            'database' => 0,
            'prefix' => 'session:',
            'persistent' => false,
            'retry_interval' => 100,
            'read_timeout' => 2.5,
        ], $config);

        $this->redis = new Redis();
    }

    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        try {
            $isPersistent = $this->config['persistent'];
            assert(is_bool($isPersistent));

            $host = $this->config['host'];
            assert(is_string($host));

            $port = $this->config['port'];
            assert(is_int($port));

            $timeout = $this->config['timeout'];
            assert(is_float($timeout) || is_int($timeout));
            $timeout = (float)$timeout;

            $retryInterval = $this->config['retry_interval'];
            assert(is_int($retryInterval));

            if ($isPersistent) {
                $result = $this->redis->pconnect($host, $port, $timeout, null, $retryInterval);
            } else {
                $result = $this->redis->connect($host, $port, $timeout, null, $retryInterval);
            }

            if (!$result) {
                throw new ConnectionException('Failed to connect to Redis');
            }

            $password = $this->config['password'];
            if ($password !== null) {
                assert(is_string($password));
                if (!$this->redis->auth($password)) {
                    throw new ConnectionException('Redis authentication failed');
                }
            }

            $database = $this->config['database'];
            assert(is_int($database));
            if ($database !== 0) {
                if (!$this->redis->select($database)) {
                    throw new ConnectionException('Failed to select Redis database');
                }
            }

            $readTimeout = $this->config['read_timeout'];
            assert(is_float($readTimeout) || is_int($readTimeout));
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, (float)$readTimeout);

            $prefix = $this->config['prefix'];
            assert(is_string($prefix));
            $this->redis->setOption(Redis::OPT_PREFIX, $prefix);

            $this->connected = true;
            return true;
        } catch (RedisException $e) {
            $host = $this->config['host'];
            assert(is_string($host));
            $port = $this->config['port'];
            assert(is_int($port));

            error_log(sprintf(
                '[CRITICAL] Redis connection failed: %s (host: %s, port: %d)',
                $e->getMessage(),
                $host,
                $port
            ));
            throw new ConnectionException('Failed to connect to Redis: ' . $e->getMessage(), 0, $e);
        }
    }

    public function disconnect(): void
    {
        $persistent = $this->config['persistent'];
        assert(is_bool($persistent));

        if ($this->connected && !$persistent) {
            $this->redis->close();
        }
        $this->connected = false;
    }

    public function get(string $key): string|false
    {
        $this->connect();

        try {
            $value = $this->redis->get($key);
            if (is_string($value)) {
                return $value;
            }
            return false;
        } catch (RedisException $e) {
            error_log(sprintf(
                '[ERROR] Redis GET operation failed: %s (key: %s)',
                $e->getMessage(),
                $key
            ));
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
            error_log(sprintf(
                '[ERROR] Redis SET operation failed: %s (key: %s)',
                $e->getMessage(),
                $key
            ));
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
            error_log(sprintf(
                '[ERROR] Redis DELETE operation failed: %s (key: %s)',
                $e->getMessage(),
                $key
            ));
            return false;
        }
    }

    public function exists(string $key): bool
    {
        $this->connect();

        try {
            return $this->redis->exists($key) > 0;
        } catch (RedisException $e) {
            error_log(sprintf(
                '[ERROR] Redis EXISTS operation failed: %s (key: %s)',
                $e->getMessage(),
                $key
            ));
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
            error_log(sprintf(
                '[ERROR] Redis EXPIRE operation failed: %s (key: %s)',
                $e->getMessage(),
                $key
            ));
            return false;
        }
    }

    /**
     * @return array<string>
     */
    public function keys(string $pattern): array
    {
        $this->connect();

        $prefix = $this->config['prefix'];
        assert(is_string($prefix));
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
            error_log(sprintf(
                '[ERROR] Redis SCAN operation failed: %s (pattern: %s)',
                $e->getMessage(),
                $pattern
            ));
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
