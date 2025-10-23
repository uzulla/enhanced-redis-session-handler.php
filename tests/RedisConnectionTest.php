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

        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($config, $logger);
        self::assertInstanceOf(RedisConnection::class, $connection);
    }

    public function testConstructorWithCustomConfig(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $config = new RedisConnectionConfig(
            host: '127.0.0.1',
            port: 6380,
            prefix: 'test:'
        );
        $connection = new RedisConnection($config, $logger);
        self::assertInstanceOf(RedisConnection::class, $connection);
    }

    public function testIsConnectedReturnsFalseWhenNotConnected(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $config = new RedisConnectionConfig();
        $connection = new RedisConnection($config, $logger);
        self::assertFalse($connection->isConnected());
    }
}
