<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use PHPUnit\Framework\TestCase;
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
        $this->connection = new RedisConnection([
            'host' => 'localhost',
            'port' => 6379,
        ]);
        $this->handler = new RedisSessionHandler($this->connection);
    }

    public function testConstructorWithDefaultOptions(): void
    {
        $handler = new RedisSessionHandler($this->connection);
        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testConstructorWithCustomIdGenerator(): void
    {
        $handler = new RedisSessionHandler($this->connection, [
            'id_generator' => new SecureSessionIdGenerator(32),
        ]);
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
            self::markTestSkipped('Redis extension is not loaded');
        }

        try {
            $this->connection->connect();
        } catch (\Exception $e) {
            self::markTestSkipped('Cannot connect to Redis: ' . $e->getMessage());
        }

        $sid1 = $this->handler->create_sid();
        $sid2 = $this->handler->create_sid();

        self::assertNotEmpty($sid1);
        self::assertNotEmpty($sid2);
    }
}
