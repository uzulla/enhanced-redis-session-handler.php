<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

class RetryTest extends TestCase
{
    public function testRetryConfigurationIsSet(): void
    {
        $config = new RedisConnectionConfig(
            'localhost',
            6379,
            2.5,
            null,
            0,
            'session:',
            false,
            100,
            2.5,
            5 // maxRetries
        );

        self::assertEquals(5, $config->getMaxRetries());
        self::assertEquals(100, $config->getRetryInterval());
    }

    public function testDefaultRetryConfiguration(): void
    {
        $config = new RedisConnectionConfig();

        self::assertEquals(3, $config->getMaxRetries());
        self::assertEquals(100, $config->getRetryInterval());
    }

    public function testConnectionFailsAfterMaxRetries(): void
    {
        $logger = new Logger('test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $redis = $this->createMock(\Redis::class);
        $redis->method('connect')
            ->willReturn(false);

        $config = new RedisConnectionConfig(
            'invalid-host',
            9999,
            0.1,
            null,
            0,
            'session:',
            false,
            10, // Short retry interval for testing
            2.5,
            2 // Only 2 retries for faster testing
        );

        $connection = new RedisConnection($redis, $config, $logger);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Failed to connect to Redis after 2 attempts');

        $connection->connect();
    }

    public function testConnectionLogsRetryAttempts(): void
    {
        $logger = new Logger('test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $redis = $this->createMock(\Redis::class);
        $redis->method('connect')
            ->willReturn(false);

        $config = new RedisConnectionConfig(
            'invalid-host',
            9999,
            0.1,
            null,
            0,
            'session:',
            false,
            10,
            2.5,
            2
        );

        $connection = new RedisConnection($redis, $config, $logger);

        try {
            $connection->connect();
        } catch (ConnectionException $e) {
        }

        $records = $testHandler->getRecords();
        $warningRecords = array_filter($records, function ($record) {
            /** @phpstan-ignore-next-line */
            if (is_object($record) && property_exists($record, 'level')) {
                $levelName = $record->level->getName();
                $message = $record->message;
            } else {
                $levelName = $record['level_name'] ?? null;
                $message = $record['message'] ?? null;
            }
            return $levelName === 'WARNING' &&
                   is_string($message) &&
                   strpos($message, 'Redis connection attempt failed') !== false;
        });

        self::assertCount(2, $warningRecords);
    }

    public function testSuccessfulConnectionAfterRetry(): void
    {
        $logger = new Logger('test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $redis = $this->createMock(\Redis::class);

        $redis->expects(self::exactly(2))
            ->method('connect')
            ->willReturnOnConsecutiveCalls(false, true);

        $redis->method('setOption')
            ->willReturn(true);

        $config = new RedisConnectionConfig(
            'localhost',
            6379,
            0.1,
            null,
            0,
            'session:',
            false,
            10,
            2.5,
            3
        );

        $connection = new RedisConnection($redis, $config, $logger);

        $result = $connection->connect();

        self::assertTrue($result);

        $records = $testHandler->getRecords();
        $infoRecords = array_filter($records, function ($record) {
            /** @phpstan-ignore-next-line */
            if (is_object($record) && property_exists($record, 'level')) {
                $levelName = $record->level->getName();
                $message = $record->message;
            } else {
                $levelName = $record['level_name'] ?? null;
                $message = $record['message'] ?? null;
            }
            return $levelName === 'INFO' &&
                   is_string($message) &&
                   strpos($message, 'Redis connection succeeded after retry') !== false;
        });

        self::assertCount(1, $infoRecords);
    }
}
