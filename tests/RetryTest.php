<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

/**
 * Note: This test file contains multiple @phpstan-ignore-next-line annotations.
 *
 * Reason: Monolog's TestHandler::getRecords() returns different types across PHP versions:
 * - PHP 7.4-8.2 (Monolog 2.x): Returns array<array{level_name: string, message: string, ...}>
 * - PHP 8.3+ (Monolog 3.x): Returns array<Monolog\LogRecord>
 *
 * PHPStan cannot properly infer the correct type across all PHP versions, resulting in:
 * - "Parameter #1 $array of function array_filter expects array, mixed given" (PHP 8.1)
 * - "Cannot access offset 'level_name' on mixed" (PHP 8.0)
 * - "PHPDoc tag @var with type array<...> is not subtype of native type Monolog\LogRecord" (PHP 8.3+)
 *
 * The @phpstan-ignore-next-line annotations suppress these warnings while maintaining
 * runtime compatibility with both Monolog 2.x and 3.x.
 *
 * TODO: See issue #31 for tracking the removal of these suppressions once a better solution is found.
 */
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
        /** @phpstan-ignore-next-line */
        $warningRecords = array_filter($records, function ($record): bool {
            /** @phpstan-ignore-next-line */
            if (is_object($record) && property_exists($record, 'level')) {
                /** @phpstan-ignore-next-line */
                $levelName = $record->level->getName();
                /** @phpstan-ignore-next-line */
                $message = $record->message;
            } else {
                /** @phpstan-ignore-next-line */
                $levelName = $record['level_name'] ?? null;
                /** @phpstan-ignore-next-line */
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
        /** @phpstan-ignore-next-line */
        $infoRecords = array_filter($records, function ($record): bool {
            /** @phpstan-ignore-next-line */
            if (is_object($record) && property_exists($record, 'level')) {
                /** @phpstan-ignore-next-line */
                $levelName = $record->level->getName();
                /** @phpstan-ignore-next-line */
                $message = $record->message;
            } else {
                /** @phpstan-ignore-next-line */
                $levelName = $record['level_name'] ?? null;
                /** @phpstan-ignore-next-line */
                $message = $record['message'] ?? null;
            }
            return $levelName === 'INFO' &&
                   is_string($message) &&
                   strpos($message, 'Redis connection succeeded after retry') !== false;
        });

        self::assertCount(1, $infoRecords);
    }
}
