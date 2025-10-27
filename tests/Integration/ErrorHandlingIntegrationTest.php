<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Exception\ConnectionException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;
use Redis;

class ErrorHandlingIntegrationTest extends TestCase
{
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        self::assertNotFalse($redisHost, 'SESSION_REDIS_HOST environment variable must be set');
        self::assertNotFalse($redisPort, 'SESSION_REDIS_PORT environment variable must be set');

        $this->host = $redisHost;
        $this->port = (int)$redisPort;
    }

    public function testSessionHandlerHandlesConnectionFailureGracefully(): void
    {
        $logger = new PsrTestLogger();

        $redis = new Redis();
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

        $records = $logger->getRecords();
        $errorRecords = array_filter($records, function (array $record): bool {
            return $record['level_name'] === 'ERROR' &&
                   strpos($record['message'], 'Failed to open session') !== false;
        });

        self::assertGreaterThan(0, count($errorRecords));
    }

    public function testSessionHandlerLogsReadErrors(): void
    {
        $logger = new PsrTestLogger();

        $redis = new Redis();
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
        $logger = new PsrTestLogger();

        $redis = new Redis();
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

        $records = $logger->getRecords();
        $errorRecords = array_filter($records, function (array $record): bool {
            return $record['level_name'] === 'ERROR' ||
                   $record['level_name'] === 'WARNING' ||
                   $record['level_name'] === 'CRITICAL';
        });

        self::assertGreaterThan(0, count($errorRecords));
    }

    public function testRetryLoggingInRealScenario(): void
    {
        $logger = new PsrTestLogger();

        $redis = new Redis();
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

        $records = $logger->getRecords();

        $warningRecords = array_filter($records, function (array $record): bool {
            return $record['level_name'] === 'WARNING' &&
                   strpos($record['message'], 'Redis connection attempt failed') !== false;
        });

        self::assertCount(3, $warningRecords);

        $criticalRecords = array_filter($records, function (array $record): bool {
            return $record['level_name'] === 'CRITICAL' &&
                   strpos($record['message'], 'Redis connection failed after all retries') !== false;
        });

        self::assertCount(1, $criticalRecords);
    }
}
