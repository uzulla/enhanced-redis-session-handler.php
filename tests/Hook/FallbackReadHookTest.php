<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\FallbackReadHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

class FallbackReadHookTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger('test');
        $this->logger->pushHandler(new NullHandler());
    }

    public function testBeforeReadDoesNothing(): void
    {
        $hook = new FallbackReadHook([], $this->logger);
        $hook->beforeRead('test-session-id');
        $this->addToAssertionCount(1);
    }

    public function testAfterReadReturnsDataUnchanged(): void
    {
        $hook = new FallbackReadHook([], $this->logger);
        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);
        self::assertSame($data, $result);
    }

    public function testOnReadErrorReturnsNullWhenNoFallbacks(): void
    {
        $hook = new FallbackReadHook([], $this->logger);
        $result = $hook->onReadError('test-session-id', new \RuntimeException('Test error'));
        self::assertNull($result);
    }

    public function testOnReadErrorReturnsDataFromFirstSuccessfulFallback(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        $redis1 = new \Redis();
        $config1 = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'fallback1:');
        $connection1 = new RedisConnection($redis1, $config1, $this->logger);
        $connection1->connect();

        $redis2 = new \Redis();
        $config2 = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'fallback2:');
        $connection2 = new RedisConnection($redis2, $config2, $this->logger);
        $connection2->connect();

        $connection2->set('test-session-id', 'fallback-data', 3600);

        $hook = new FallbackReadHook([$connection1, $connection2], $this->logger);
        $result = $hook->onReadError('test-session-id', new \RuntimeException('Test error'));

        self::assertSame('fallback-data', $result);

        $connection2->delete('test-session-id');
    }

    public function testOnReadErrorReturnsNullWhenAllFallbacksFail(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        $redis1 = new \Redis();
        $config1 = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'fallback1:');
        $connection1 = new RedisConnection($redis1, $config1, $this->logger);
        $connection1->connect();

        $redis2 = new \Redis();
        $config2 = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'fallback2:');
        $connection2 = new RedisConnection($redis2, $config2, $this->logger);
        $connection2->connect();

        $hook = new FallbackReadHook([$connection1, $connection2], $this->logger);
        $result = $hook->onReadError('nonexistent-session', new \RuntimeException('Test error'));

        self::assertNull($result);
    }

    public function testOnReadErrorSkipsFailedFallbacksAndContinues(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        $redis1 = new \Redis();
        $config1 = new RedisConnectionConfig('invalid-host', 6379);
        $connection1 = new RedisConnection($redis1, $config1, $this->logger);

        $redis2 = new \Redis();
        $config2 = new RedisConnectionConfig('localhost', 6379, 2.5, null, 0, 'fallback2:');
        $connection2 = new RedisConnection($redis2, $config2, $this->logger);
        $connection2->connect();
        $connection2->set('test-session-id', 'fallback-data-2', 3600);

        $hook = new FallbackReadHook([$connection1, $connection2], $this->logger);
        $result = $hook->onReadError('test-session-id', new \RuntimeException('Test error'));

        self::assertSame('fallback-data-2', $result);

        $connection2->delete('test-session-id');
    }
}
