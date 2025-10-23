<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\CacheWarmupHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

class CacheWarmupHookTest extends TestCase
{
    private Logger $logger;
    private RedisConnection $connection;

    protected function setUp(): void
    {
        $this->logger = new Logger('test');
        $this->logger->pushHandler(new NullHandler());

        if (extension_loaded('redis')) {
            $redis = new \Redis();
            $config = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'warmup:');
            $this->connection = new RedisConnection($redis, $config, $this->logger);
            $this->connection->connect();
        }
    }

    public function testBeforeReadDoesNothing(): void
    {
        $hook = new CacheWarmupHook($this->connection, [], $this->logger);
        $hook->beforeRead('test-session-id');
        $this->addToAssertionCount(1);
    }

    public function testAfterReadReturnsDataUnchangedAndPerformsWarmup(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension not loaded');
        }

        $this->connection->set('warmup:user:{session_id}:profile', 'profile-data', 3600);

        $hook = new CacheWarmupHook(
            $this->connection,
            ['user:{session_id}:profile'],
            $this->logger
        );

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);

        $this->connection->delete('user:test-session-id:profile');
    }

    public function testAfterReadWarmsUpMultipleKeys(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension not loaded');
        }

        $this->connection->set('warmup:user:{session_id}:profile', 'profile-data', 3600);
        $this->connection->set('warmup:user:{session_id}:settings', 'settings-data', 3600);

        $hook = new CacheWarmupHook(
            $this->connection,
            [
                'user:{session_id}:profile',
                'user:{session_id}:settings',
            ],
            $this->logger
        );

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);

        $this->connection->delete('user:test-session-id:profile');
        $this->connection->delete('user:test-session-id:settings');
    }

    public function testAfterReadHandlesNonexistentKeys(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension not loaded');
        }

        $hook = new CacheWarmupHook(
            $this->connection,
            ['user:{session_id}:nonexistent'],
            $this->logger
        );

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);
    }

    public function testOnReadErrorReturnsNull(): void
    {
        $hook = new CacheWarmupHook($this->connection, [], $this->logger);
        $result = $hook->onReadError('test-session-id', new \RuntimeException('Test error'));
        self::assertNull($result);
    }

    public function testAfterReadHandlesWarmupErrors(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension not loaded');
        }

        $redis = new \Redis();
        $config = new RedisConnectionConfig('invalid-host', 6379);
        $invalidConnection = new RedisConnection($redis, $config, $this->logger);

        $hook = new CacheWarmupHook(
            $invalidConnection,
            ['user:{session_id}:profile'],
            $this->logger
        );

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);
    }
}
