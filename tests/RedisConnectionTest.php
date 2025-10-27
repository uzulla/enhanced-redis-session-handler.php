<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;

class RedisConnectionTest extends TestCase
{
    public function testConstructorWithDefaultConfig(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);
        self::assertInstanceOf(RedisConnection::class, $connection);
    }

    public function testConstructorWithCustomConfig(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $config = new RedisConnectionConfig(
            '127.0.0.1',
            6380,
            2.5,
            null,
            0,
            'test:'
        );
        $connection = new RedisConnection($redis, $config, $logger);
        self::assertInstanceOf(RedisConnection::class, $connection);
    }

    public function testIsConnectedReturnsFalseWhenNotConnected(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = new \Redis();
        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);
        self::assertFalse($connection->isConnected());
    }

    public function testDeleteReturnsTrueWhenKeyExists(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('del')
            ->with('test_key')
            ->willReturn(1); // 1つのキーが削除された

        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);

        $result = $connection->delete('test_key');
        self::assertTrue($result);
    }

    public function testDeleteReturnsFalseWhenKeyDoesNotExist(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('del')
            ->with('nonexistent_key')
            ->willReturn(0); // キーが存在しないため0が返る

        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);

        $result = $connection->delete('nonexistent_key');
        self::assertFalse($result);
    }

    public function testDeleteReturnsTrueWhenMultipleKeysDeleted(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('del')
            ->with('test_key')
            ->willReturn(2); // 2つのキーが削除された（複数キー指定の場合）

        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);

        $result = $connection->delete('test_key');
        self::assertTrue($result);
    }

    public function testDeleteReturnsFalseOnRedisException(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('del')
            ->willThrowException(new \RedisException('Connection failed'));

        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($redis, $config, $logger);

        $result = $connection->delete('test_key');
        self::assertFalse($result);
    }
}
