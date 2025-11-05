# Issue #29: 設計案のコード例

このドキュメントでは、提案されたCompositeRedisConnection設計の具体的なコード例を示します。

## RedisConnectionInterface

```php
<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler;

/**
 * Interface for Redis connection operations.
 *
 * This interface allows both single Redis connections (RedisConnection)
 * and composite connections (FailoverRedisConnection, MultiWriteRedisConnection)
 * to be used interchangeably.
 */
interface RedisConnectionInterface
{
    /**
     * Connect to Redis.
     *
     * @return bool True on success, throws exception on failure
     * @throws \Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException
     */
    public function connect(): bool;

    /**
     * Disconnect from Redis.
     */
    public function disconnect(): void;

    /**
     * Get a value from Redis by key.
     *
     * @param string $key The key to retrieve
     * @return string|false Returns the value as string if found, false if not found or on error
     */
    public function get(string $key);

    /**
     * Set a value in Redis with TTL.
     *
     * @param string $key The key to set
     * @param string $value The value to store
     * @param int $ttl Time to live in seconds
     * @return bool True on success, false on failure
     */
    public function set(string $key, string $value, int $ttl): bool;

    /**
     * Delete a key from Redis.
     *
     * @param string $key The key to delete
     * @return bool True if key was deleted, false otherwise
     */
    public function delete(string $key): bool;

    /**
     * Check if a key exists in Redis.
     *
     * @param string $key The key to check
     * @return bool True if key exists, false otherwise
     */
    public function exists(string $key): bool;

    /**
     * Set expiration time for a key.
     *
     * @param string $key The key to set expiration for
     * @param int $ttl Time to live in seconds
     * @return bool True on success, false on failure
     */
    public function expire(string $key, int $ttl): bool;

    /**
     * Get keys matching a pattern.
     *
     * @param string $pattern The pattern to match (e.g., 'session:*')
     * @return array<string> Array of matching keys
     */
    public function keys(string $pattern): array;

    /**
     * Check if connected to Redis.
     *
     * @return bool True if connected, false otherwise
     */
    public function isConnected(): bool;
}
```

## CompositeRedisConnection 基底クラス

```php
<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Composite;

use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\RedisConnectionInterface;

/**
 * Abstract base class for composite Redis connections.
 *
 * A composite connection manages multiple RedisConnectionInterface instances
 * and delegates operations to them according to specific strategies
 * (failover, multi-write, etc.).
 */
abstract class CompositeRedisConnection implements RedisConnectionInterface, LoggerAwareInterface
{
    /** @var array<RedisConnectionInterface> */
    protected array $connections;

    protected LoggerInterface $logger;

    /**
     * @param array<RedisConnectionInterface> $connections Array of Redis connections to manage
     * @param LoggerInterface|null $logger Optional logger instance
     * @throws InvalidArgumentException If connections array is empty
     */
    public function __construct(array $connections, ?LoggerInterface $logger = null)
    {
        if (count($connections) === 0) {
            throw new InvalidArgumentException('At least one connection is required');
        }

        $this->connections = $connections;
        $this->logger = $logger ?? new NullLogger();

        // Propagate logger to child connections
        foreach ($this->connections as $connection) {
            if ($connection instanceof LoggerAwareInterface) {
                $connection->setLogger($this->logger);
            }
        }
    }

    /**
     * Connect to all Redis instances.
     *
     * @return bool True if at least one connection succeeds
     */
    public function connect(): bool
    {
        $results = [];

        foreach ($this->connections as $index => $connection) {
            try {
                $result = $connection->connect();
                $results[] = $result;

                if ($result) {
                    $this->logger->debug('Composite: Connection succeeded', [
                        'connection_index' => $index,
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Composite: Connection failed', [
                    'connection_index' => $index,
                    'exception' => $e,
                ]);
                $results[] = false;
            }
        }

        // Success if at least one connection succeeded
        return in_array(true, $results, true);
    }

    /**
     * Disconnect from all Redis instances.
     */
    public function disconnect(): void
    {
        foreach ($this->connections as $index => $connection) {
            try {
                $connection->disconnect();
            } catch (\Throwable $e) {
                $this->logger->error('Composite: Disconnect failed', [
                    'connection_index' => $index,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Check if at least one connection is active.
     *
     * @return bool True if at least one connection is connected
     */
    public function isConnected(): bool
    {
        foreach ($this->connections as $connection) {
            if ($connection->isConnected()) {
                return true;
            }
        }
        return false;
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

        // Propagate to all child connections
        foreach ($this->connections as $connection) {
            if ($connection instanceof LoggerAwareInterface) {
                $connection->setLogger($logger);
            }
        }
    }

    /**
     * Get count of managed connections.
     *
     * @return int Number of connections
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    // Abstract methods to be implemented by concrete classes
    abstract public function get(string $key);
    abstract public function set(string $key, string $value, int $ttl): bool;
    abstract public function delete(string $key): bool;
    abstract public function exists(string $key): bool;
    abstract public function expire(string $key, int $ttl): bool;
    abstract public function keys(string $pattern): array;
}
```

## FailoverRedisConnection 実装例

```php
<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Composite;

/**
 * Failover Redis connection that tries connections in order.
 *
 * Operations are attempted on the first connection (primary).
 * If it fails, the next connection is tried (failover), and so on.
 *
 * This is useful for high availability scenarios where you have
 * a primary Redis and one or more backup Redis instances.
 */
class FailoverRedisConnection extends CompositeRedisConnection
{
    /**
     * Get value from Redis with failover.
     *
     * Tries each connection in order until one succeeds or all fail.
     *
     * @param string $key
     * @return string|false
     */
    public function get(string $key)
    {
        foreach ($this->connections as $index => $connection) {
            try {
                $result = $connection->get($key);

                if ($result !== false) {
                    if ($index > 0) {
                        $this->logger->warning('Failover: Used backup connection for GET', [
                            'key' => $key,
                            'connection_index' => $index,
                        ]);
                    }
                    return $result;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failover: GET failed, trying next connection', [
                    'key' => $key,
                    'connection_index' => $index,
                    'exception' => $e,
                ]);
                continue;
            }
        }

        $this->logger->error('Failover: All connections failed for GET', [
            'key' => $key,
        ]);

        return false;
    }

    /**
     * Set value to Redis with failover.
     *
     * Tries each connection in order until one succeeds.
     *
     * @param string $key
     * @param string $value
     * @param int $ttl
     * @return bool
     */
    public function set(string $key, string $value, int $ttl): bool
    {
        foreach ($this->connections as $index => $connection) {
            try {
                $result = $connection->set($key, $value, $ttl);

                if ($result) {
                    if ($index > 0) {
                        $this->logger->warning('Failover: Used backup connection for SET', [
                            'key' => $key,
                            'connection_index' => $index,
                        ]);
                    }
                    return true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failover: SET failed, trying next connection', [
                    'key' => $key,
                    'connection_index' => $index,
                    'exception' => $e,
                ]);
                continue;
            }
        }

        $this->logger->error('Failover: All connections failed for SET', [
            'key' => $key,
        ]);

        return false;
    }

    /**
     * Delete key from Redis with failover.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        foreach ($this->connections as $index => $connection) {
            try {
                $result = $connection->delete($key);

                if ($result) {
                    if ($index > 0) {
                        $this->logger->warning('Failover: Used backup connection for DELETE', [
                            'key' => $key,
                            'connection_index' => $index,
                        ]);
                    }
                    return true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failover: DELETE failed, trying next connection', [
                    'key' => $key,
                    'connection_index' => $index,
                    'exception' => $e,
                ]);
                continue;
            }
        }

        return false;
    }

    /**
     * Check if key exists in Redis with failover.
     *
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool
    {
        foreach ($this->connections as $connection) {
            try {
                if ($connection->exists($key)) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Set expiration for key with failover.
     *
     * @param string $key
     * @param int $ttl
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        foreach ($this->connections as $connection) {
            try {
                if ($connection->expire($key, $ttl)) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * Get keys matching pattern with failover.
     *
     * Returns keys from the first connection that responds successfully.
     *
     * @param string $pattern
     * @return array<string>
     */
    public function keys(string $pattern): array
    {
        foreach ($this->connections as $connection) {
            try {
                $keys = $connection->keys($pattern);
                if (!empty($keys)) {
                    return $keys;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [];
    }
}
```

## MultiWriteRedisConnection 実装例

```php
<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Composite;

/**
 * Multi-write Redis connection that writes to all connections.
 *
 * Write operations are performed on all connections (primary and replicas).
 * Read operations are performed on the first connection (primary) only.
 *
 * This is useful for replication scenarios where you want to write
 * to multiple Redis instances simultaneously.
 */
class MultiWriteRedisConnection extends CompositeRedisConnection
{
    private bool $requireAllWrites;

    /**
     * @param array<RedisConnectionInterface> $connections Array of Redis connections
     * @param bool $requireAllWrites If true, all writes must succeed; if false, partial success is OK
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        array $connections,
        bool $requireAllWrites = true,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($connections, $logger);
        $this->requireAllWrites = $requireAllWrites;
    }

    /**
     * Get value from primary Redis only.
     *
     * @param string $key
     * @return string|false
     */
    public function get(string $key)
    {
        // Read from primary (first connection) only
        try {
            return $this->connections[0]->get($key);
        } catch (\Throwable $e) {
            $this->logger->error('MultiWrite: Primary GET failed', [
                'key' => $key,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Set value to all Redis instances.
     *
     * @param string $key
     * @param string $value
     * @param int $ttl
     * @return bool
     */
    public function set(string $key, string $value, int $ttl): bool
    {
        $results = [];
        $errors = [];

        foreach ($this->connections as $index => $connection) {
            try {
                $result = $connection->set($key, $value, $ttl);
                $results[] = $result;

                if (!$result) {
                    $errors[] = $index;
                }
            } catch (\Throwable $e) {
                $this->logger->error('MultiWrite: SET failed on connection', [
                    'key' => $key,
                    'connection_index' => $index,
                    'exception' => $e,
                ]);
                $results[] = false;
                $errors[] = $index;
            }
        }

        if (!empty($errors)) {
            $this->logger->warning('MultiWrite: SET failed on some connections', [
                'key' => $key,
                'failed_connections' => $errors,
            ]);
        }

        if ($this->requireAllWrites) {
            // All writes must succeed
            return !in_array(false, $results, true);
        } else {
            // At least one write must succeed
            return in_array(true, $results, true);
        }
    }

    /**
     * Delete key from all Redis instances.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $results = [];

        foreach ($this->connections as $connection) {
            try {
                $results[] = $connection->delete($key);
            } catch (\Throwable $e) {
                $results[] = false;
            }
        }

        if ($this->requireAllWrites) {
            return !in_array(false, $results, true);
        } else {
            return in_array(true, $results, true);
        }
    }

    /**
     * Check if key exists in primary Redis.
     *
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool
    {
        try {
            return $this->connections[0]->exists($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Set expiration for key in all Redis instances.
     *
     * @param string $key
     * @param int $ttl
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        $results = [];

        foreach ($this->connections as $connection) {
            try {
                $results[] = $connection->expire($key, $ttl);
            } catch (\Throwable $e) {
                $results[] = false;
            }
        }

        if ($this->requireAllWrites) {
            return !in_array(false, $results, true);
        } else {
            return in_array(true, $results, true);
        }
    }

    /**
     * Get keys matching pattern from all Redis instances and merge.
     *
     * @param string $pattern
     * @return array<string>
     */
    public function keys(string $pattern): array
    {
        $allKeys = [];

        foreach ($this->connections as $connection) {
            try {
                $keys = $connection->keys($pattern);
                $allKeys = array_merge($allKeys, $keys);
            } catch (\Throwable $e) {
                // Log and continue
            }
        }

        // Remove duplicates and return
        return array_unique($allKeys);
    }
}
```

## 使用例

### 例1: フォールバック構成でのReadTimestampHook

```php
<?php

use Psr\Log\NullLogger;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Composite\FailoverRedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

// Primary Redis
$primaryRedis = new Redis();
$primaryConfig = new RedisConnectionConfig(host: '192.168.1.1', port: 6379);
$primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, new NullLogger());

// Fallback Redis
$fallbackRedis = new Redis();
$fallbackConfig = new RedisConnectionConfig(host: '192.168.1.2', port: 6379);
$fallbackConnection = new RedisConnection($fallbackRedis, $fallbackConfig, new NullLogger());

// Create failover composite
$failoverConnection = new FailoverRedisConnection(
    [$primaryConnection, $fallbackConnection],
    new NullLogger()
);

// Use failover connection in ReadTimestampHook
$hook = new ReadTimestampHook($failoverConnection, new NullLogger());

// Now, if primary fails, timestamp will be written to fallback Redis!
```

### 例2: ダブルライト構成でのDoubleWriteHook

```php
<?php

use Psr\Log\NullLogger;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Composite\MultiWriteRedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

// Primary Redis
$primaryRedis = new Redis();
$primaryConfig = new RedisConnectionConfig(host: '192.168.1.1', port: 6379);
$primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, new NullLogger());

// Secondary Redis 1
$secondary1Redis = new Redis();
$secondary1Config = new RedisConnectionConfig(host: '192.168.1.2', port: 6379);
$secondary1Connection = new RedisConnection($secondary1Redis, $secondary1Config, new NullLogger());

// Secondary Redis 2
$secondary2Redis = new Redis();
$secondary2Config = new RedisConnectionConfig(host: '192.168.1.3', port: 6379);
$secondary2Connection = new RedisConnection($secondary2Redis, $secondary2Config, new NullLogger());

// Create multi-write composite for all three Redis instances
$multiWriteConnection = new MultiWriteRedisConnection(
    [$primaryConnection, $secondary1Connection, $secondary2Connection],
    requireAllWrites: false,  // Allow partial success
    logger: new NullLogger()
);

// Now DoubleWriteHook (if used for additional logic) can benefit from multi-write
// Or, simply use multiWriteConnection directly in RedisSessionHandler
$handler = new RedisSessionHandler($multiWriteConnection, $serializer, $options);

// All session data AND hook data (like timestamps) will be written to all 3 Redis!
```

### 例3: Composite同士のネスト

```php
<?php

// You can even nest composites!

// DC1: Primary + Fallback
$dc1Failover = new FailoverRedisConnection([$dc1Primary, $dc1Fallback]);

// DC2: Primary + Fallback
$dc2Failover = new FailoverRedisConnection([$dc2Primary, $dc2Fallback]);

// Multi-datacenter replication
$multiDC = new MultiWriteRedisConnection(
    [$dc1Failover, $dc2Failover],
    requireAllWrites: false
);

// Now you have:
// - Writes go to both DC1 and DC2
// - Within each DC, if primary fails, fallback is used
// - Reads come from DC1 primary (or fallback if primary is down)
```

## 移行ガイド

### Before (現状)

```php
$primary = new RedisConnection($redis1, $config1, $logger);

$handler = new RedisSessionHandler($primary, $serializer);
$handler->addReadHook(new FallbackReadHook([$fallback1, $fallback2], $logger));
$handler->addReadHook(new ReadTimestampHook($primary, $logger));  // ← Problem!
```

**問題点:**
- `FallbackReadHook`はセッションデータのフォールバックを提供
- しかし、`ReadTimestampHook`は常に`$primary`にしか書き込めない
- `$primary`がダウンすると、タイムスタンプが記録されない

### After (Composite使用)

```php
$failover = new FailoverRedisConnection([$primary, $fallback1, $fallback2], $logger);

$handler = new RedisSessionHandler($failover, $serializer);
// FallbackReadHook不要（Composite層で対応）
$handler->addReadHook(new ReadTimestampHook($failover, $logger));  // ← Solution!
```

**改善点:**
- セッションデータもタイムスタンプも同じフォールバック戦略を使用
- `FallbackReadHook`が不要（Composite層で統一的に処理）
- Hookの実装コードは一切変更不要

## テスト例

```php
<?php

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Composite\FailoverRedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisConnectionInterface;

class FailoverRedisConnectionTest extends TestCase
{
    public function testFailoverOnPrimaryFailure(): void
    {
        // Mock primary connection that fails
        $primary = $this->createMock(RedisConnectionInterface::class);
        $primary->expects($this->once())
            ->method('get')
            ->with('test_key')
            ->willReturn(false);  // Simulate failure

        // Mock fallback connection that succeeds
        $fallback = $this->createMock(RedisConnectionInterface::class);
        $fallback->expects($this->once())
            ->method('get')
            ->with('test_key')
            ->willReturn('fallback_data');

        // Create failover composite
        $composite = new FailoverRedisConnection([$primary, $fallback]);

        // Test
        $result = $composite->get('test_key');

        // Assert fallback data was returned
        $this->assertSame('fallback_data', $result);
    }

    public function testSetWithFailover(): void
    {
        // Mock primary connection that throws exception
        $primary = $this->createMock(RedisConnectionInterface::class);
        $primary->expects($this->once())
            ->method('set')
            ->willThrowException(new \RuntimeException('Connection lost'));

        // Mock fallback connection that succeeds
        $fallback = $this->createMock(RedisConnectionInterface::class);
        $fallback->expects($this->once())
            ->method('set')
            ->with('test_key', 'test_value', 100)
            ->willReturn(true);

        // Create failover composite
        $composite = new FailoverRedisConnection([$primary, $fallback]);

        // Test
        $result = $composite->set('test_key', 'test_value', 100);

        // Assert fallback succeeded
        $this->assertTrue($result);
    }
}
```

## パフォーマンスベンチマーク例

```php
<?php

// Benchmark: Single Redis vs MultiWrite vs Failover

$iterations = 1000;

// Single Redis
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $singleConnection->set("key_$i", "value_$i", 100);
}
$singleTime = microtime(true) - $start;

// MultiWrite (2 Redis)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $multiWrite->set("key_$i", "value_$i", 100);
}
$multiWriteTime = microtime(true) - $start;

// Failover (primary healthy)
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $failover->set("key_$i", "value_$i", 100);
}
$failoverTime = microtime(true) - $start;

echo "Single:     {$singleTime}s\n";
echo "MultiWrite: {$multiWriteTime}s (overhead: " . ($multiWriteTime / $singleTime) . "x)\n";
echo "Failover:   {$failoverTime}s (overhead: " . ($failoverTime / $singleTime) . "x)\n";

// Expected output:
// Single:     0.5s
// MultiWrite: 1.0s (overhead: 2x)  ← 2台への書き込みなので約2倍
// Failover:   0.5s (overhead: 1x)  ← Primary健全時はほぼ同じ
```

## まとめ

この設計により：

1. **既存コードとの互換性**: `RedisConnectionInterface`を実装するため、Hookやハンドラのコード変更不要
2. **透過的な複数Redis対応**: Composite層で複数Redisを管理、アプリケーション層は意識不要
3. **柔軟な構成**: Failover、MultiWrite、カスタム戦略など、要件に応じて選択可能
4. **段階的導入**: 既存コードはそのまま、必要な箇所だけCompositeを使用

これにより、Issue #29で指摘された「Hook内部のRedis操作が他のHookの恩恵を受けない」問題が解決されます。
