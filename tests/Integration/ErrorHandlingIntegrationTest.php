<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

class ErrorHandlingIntegrationTest extends TestCase
{
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $redisHost = getenv('REDIS_HOST');
        $this->host = $redisHost !== false ? $redisHost : 'localhost';

        $redisPort = getenv('REDIS_PORT');
        $this->port = $redisPort !== false ? (int)$redisPort : 6379;
    }

    public function testSessionHandlerHandlesConnectionFailureGracefully(): void
    {
        $logger = new Logger('test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $redis = new \Redis();
        $config = new RedisConnectionConfig(
            'invalid-host-that-does-not-exist',
            9999,
            0.1,
            null,
            0,
            'session:',
            false,
            10,
            0.1,
            2
        );

        $connection = new RedisConnection($redis, $config, $logger);
        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $handler = new RedisSessionHandler($connection, $options);

        $result = $handler->open('', 'PHPSESSID');
        self::assertFalse($result);

        $records = $testHandler->getRecords();
        /** @phpstan-ignore-next-line */
        $errorRecords = array_filter($records, function ($record): bool {
            /** @phpstan-ignore-next-line */
            if (is_object($record) && property_exists($record, 'level')) {
                /** @phpstan-ignore-next-line */
                $levelName = $record->level->getName();
                /** @phpstan-ignore-next-line */
                $message = $record->message;
            } else {
                /** @var array{level_name?: string, message?: string} $record */
                $levelName = $record['level_name'] ?? null;
                $message = $record['message'] ?? null;
            }
            return $levelName === 'ERROR' &&
                   is_string($message) &&
                   strpos($message, 'Failed to open session') !== false;
        });

        self::assertGreaterThan(0, count($errorRecords));
    }

    public function testSessionHandlerLogsReadErrors(): void
    {
        $logger = new Logger('test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $redis = new \Redis();
        $config = new RedisConnectionConfig(
            $this->host,
            $this->port,
            2.5,
            null,
            0,
            'test:error:',
            false,
            100,
            2.5,
            3
        );

        $connection = new RedisConnection($redis, $config, $logger);
        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $handler = new RedisSessionHandler($connection, $options);

        $handler->open('', 'PHPSESSID');

        $data = $handler->read('test-session-id');

        self::assertEquals('', $data);

        $connection->disconnect();
    }

    public function testSessionHandlerLogsWriteErrors(): void
    {
        $logger = new Logger('test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $redis = new \Redis();
        $config = new RedisConnectionConfig(
            'invalid-host',
            9999,
            0.1,
            null,
            0,
            'session:',
            false,
            10,
            0.1,
            1
        );

        $connection = new RedisConnection($redis, $config, $logger);
        $options = new RedisSessionHandlerOptions(null, null, $logger);
        $handler = new RedisSessionHandler($connection, $options);

        $result = $handler->write('test-session-id', serialize(['test' => 'data']));

        self::assertFalse($result);

        $records = $testHandler->getRecords();
        /** @phpstan-ignore-next-line */
        $errorRecords = array_filter($records, function ($record): bool {
            /** @phpstan-ignore-next-line */
            if (is_object($record) && property_exists($record, 'level')) {
                /** @phpstan-ignore-next-line */
                $levelName = $record->level->getName();
            } else {
                /** @var array{level_name?: string} $record */
                $levelName = $record['level_name'] ?? null;
            }
            return $levelName === 'ERROR' || $levelName === 'WARNING' || $levelName === 'CRITICAL';
        });

        self::assertGreaterThan(0, count($errorRecords));
    }

    public function testRetryLoggingInRealScenario(): void
    {
        $logger = new Logger('test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $redis = new \Redis();
        $config = new RedisConnectionConfig(
            'invalid-host',
            9999,
            0.1,
            null,
            0,
            'session:',
            false,
            10,
            0.1,
            3
        );

        $connection = new RedisConnection($redis, $config, $logger);

        try {
            $connection->connect();
            self::fail('Expected ConnectionException was not thrown');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('Failed to connect to Redis after 3 attempts', $e->getMessage());
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
                /** @var array{level_name?: string, message?: string} $record */
                $levelName = $record['level_name'] ?? null;
                $message = $record['message'] ?? null;
            }
            return $levelName === 'WARNING' &&
                   is_string($message) &&
                   strpos($message, 'Redis connection attempt failed') !== false;
        });

        self::assertCount(3, $warningRecords);

        /** @phpstan-ignore-next-line */
        $criticalRecords = array_filter($records, function ($record): bool {
            /** @phpstan-ignore-next-line */
            if (is_object($record) && property_exists($record, 'level')) {
                /** @phpstan-ignore-next-line */
                $levelName = $record->level->getName();
                /** @phpstan-ignore-next-line */
                $message = $record->message;
            } else {
                /** @var array{level_name?: string, message?: string} $record */
                $levelName = $record['level_name'] ?? null;
                $message = $record['message'] ?? null;
            }
            return $levelName === 'CRITICAL' &&
                   is_string($message) &&
                   strpos($message, 'Redis connection failed after all retries') !== false;
        });

        self::assertCount(1, $criticalRecords);
    }
}
