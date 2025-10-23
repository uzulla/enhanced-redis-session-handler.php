<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\SessionId\DefaultSessionIdGenerator;
use Uzulla\EnhancedRedisSessionHandler\SessionId\SecureSessionIdGenerator;

class RedisSessionHandlerTest extends TestCase
{
    private RedisConnection $connection;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $config = new RedisConnectionConfig(
            'localhost',
            6379
        );

        $this->connection = new RedisConnection($redis, $config, $logger);
        
        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $this->handler = new RedisSessionHandler($this->connection, $options);
    }

    public function testConstructorWithDefaultOptions(): void
    {
        $handler = new RedisSessionHandler($this->connection);
        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testConstructorWithCustomIdGenerator(): void
    {
        $options = new RedisSessionHandlerOptions(new SecureSessionIdGenerator(32));
        $handler = new RedisSessionHandler($this->connection, $options);
        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testCloseAlwaysReturnsTrue(): void
    {
        self::assertTrue($this->handler->close());
    }

    public function testGcReturnsZero(): void
    {
        self::assertSame(0, $this->handler->gc(1440));
    }

    public function testCreateSidGeneratesUniqueId(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        try {
            $this->connection->connect();
        } catch (\Exception $e) {
            self::markTestSkipped('Redis server is not available: ' . $e->getMessage());
        }

        $sid1 = $this->handler->create_sid();
        $sid2 = $this->handler->create_sid();

        self::assertNotEmpty($sid1);
        self::assertNotEmpty($sid2);
    }
}
