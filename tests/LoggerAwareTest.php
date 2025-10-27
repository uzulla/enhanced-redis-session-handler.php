<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

class LoggerAwareTest extends TestCase
{
    public function testRedisSessionHandlerImplementsLoggerAwareInterface(): void
    {
        $redis = new Redis();
        $config = new RedisConnectionConfig();
        $logger = new Logger('test');
        $connection = new RedisConnection($redis, $config, $logger);

        $handler = new RedisSessionHandler($connection);

        self::assertInstanceOf(LoggerAwareInterface::class, $handler);
    }

    public function testRedisSessionHandlerSetLogger(): void
    {
        $redis = new Redis();
        $config = new RedisConnectionConfig();
        $logger = new Logger('test');
        $connection = new RedisConnection($redis, $config, $logger);

        $handler = new RedisSessionHandler($connection);

        $newLogger = new Logger('new-test');
        $testHandler = new TestHandler();
        $newLogger->pushHandler($testHandler);

        $handler->setLogger($newLogger);

        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }

    public function testRedisConnectionImplementsLoggerAwareInterface(): void
    {
        $redis = new Redis();
        $config = new RedisConnectionConfig();
        $logger = new Logger('test');
        $connection = new RedisConnection($redis, $config, $logger);

        self::assertInstanceOf(LoggerAwareInterface::class, $connection);
    }

    public function testRedisConnectionSetLogger(): void
    {
        $redis = new Redis();
        $config = new RedisConnectionConfig();
        $logger = new Logger('test');
        $connection = new RedisConnection($redis, $config, $logger);

        $newLogger = new Logger('new-test');
        $testHandler = new TestHandler();
        $newLogger->pushHandler($testHandler);

        $connection->setLogger($newLogger);

        self::assertInstanceOf(RedisConnection::class, $connection);
    }
}
