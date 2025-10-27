<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Redis;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;

class WriteHookIntegrationTest extends TestCase
{
    private RedisConnection $primaryConnection;
    private RedisConnection $secondaryConnection;
    private RedisSessionHandler $handler;
    private PsrTestLogger $logger;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis (phpredis) extension is required for this integration test');
        }

        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        if ($redisHost === false) {
            self::fail('SESSION_REDIS_HOST environment variable must be set');
        }
        if ($redisPort === false) {
            self::fail('SESSION_REDIS_PORT environment variable must be set');
        }

        if (!ctype_digit($redisPort)) {
            self::fail('SESSION_REDIS_PORT must be a positive integer');
        }

        $host = $redisHost;
        $port = (int)$redisPort;

        $probe = new Redis();
        if (!@$probe->connect($host, $port, 1.5)) {
            self::fail("Redis/Valkey server not reachable at {$host}:{$port}");
        }

        try {
            $pong = $probe->ping();
            if ($pong !== true && $pong !== '+PONG' && $pong !== 'PONG') {
                self::fail('Redis/Valkey server ping failed');
            }
        } catch (Throwable $e) {
            self::fail('Redis/Valkey server check failed: ' . $e->getMessage());
        } finally {
            try {
                $probe->close();
            } catch (Throwable $e) {
                // クリーンアップ中のエラーは無視：テストセットアップ時の接続切断失敗は影響しない
            }
        }

        $this->logger = new PsrTestLogger();

        $primaryRedis = new Redis();
        $primaryConfig = new RedisConnectionConfig(
            $host,
            $port,
            2.5,
            null,
            0
        );
        $this->primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, $this->logger);

        $secondaryRedis = new Redis();
        $secondaryConfig = new RedisConnectionConfig(
            $host,
            $port,
            2.5,
            null,
            1
        );
        $this->secondaryConnection = new RedisConnection($secondaryRedis, $secondaryConfig, $this->logger);

        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $this->handler = new RedisSessionHandler($this->primaryConnection, $options);
    }

    protected function tearDown(): void
    {
        if (extension_loaded('redis')) {
            try {
                $this->primaryConnection->connect();
                $keys = $this->primaryConnection->keys('*');
                foreach ($keys as $key) {
                    $this->primaryConnection->delete($key);
                }
            } catch (\Exception $e) {
                // テスト後のクリーンアップ失敗は無視：次のテストに影響を与えない
            }

            try {
                $this->secondaryConnection->connect();
                $keys = $this->secondaryConnection->keys('*');
                foreach ($keys as $key) {
                    $this->secondaryConnection->delete($key);
                }
            } catch (\Exception $e) {
                // テスト後のクリーンアップ失敗は無視：次のテストに影響を与えない
            }
        }
    }

    public function testLoggingHookIntegration(): void
    {
        $loggingHook = new LoggingHook($this->logger);
        $this->handler->addWriteHook($loggingHook);

        $this->handler->open('', '');
        $sessionId = 'test_session_' . uniqid();
        $sessionData = serialize(['user_id' => 123, 'username' => 'testuser']);

        $result = $this->handler->write($sessionId, $sessionData);

        self::assertTrue($result);
        self::assertTrue($this->logger->hasDebugRecords());

        $records = $this->logger->getRecords();
        $hasBeforeWrite = false;
        $hasAfterWrite = false;

        foreach ($records as $record) {
            if ($record['message'] === 'Session write starting') {
                $hasBeforeWrite = true;
            }
            if ($record['message'] === 'Session write successful') {
                $hasAfterWrite = true;
            }
        }

        self::assertTrue($hasBeforeWrite, 'beforeWrite log not found');
        self::assertTrue($hasAfterWrite, 'afterWrite log not found');
    }

    public function testDoubleWriteHookIntegration(): void
    {
        $doubleWriteHook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);
        $this->handler->addWriteHook($doubleWriteHook);

        $this->handler->open('', '');
        $this->secondaryConnection->connect();

        $sessionId = 'test_session_' . uniqid();
        $sessionData = serialize(['user_id' => 456, 'username' => 'doublewrite']);

        $result = $this->handler->write($sessionId, $sessionData);

        self::assertTrue($result);

        $primaryData = $this->primaryConnection->get($sessionId);
        $secondaryData = $this->secondaryConnection->get($sessionId);

        self::assertNotFalse($primaryData);
        self::assertNotFalse($secondaryData);
        self::assertSame($primaryData, $secondaryData);

        $unserializedPrimary = unserialize($primaryData);
        self::assertIsArray($unserializedPrimary);
        self::assertSame(456, $unserializedPrimary['user_id']);
        self::assertSame('doublewrite', $unserializedPrimary['username']);
    }

    public function testMultipleHooksWorkTogether(): void
    {
        $loggingHook = new LoggingHook($this->logger);
        $doubleWriteHook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);

        $this->handler->addWriteHook($loggingHook);
        $this->handler->addWriteHook($doubleWriteHook);

        $this->handler->open('', '');
        $this->secondaryConnection->connect();

        $sessionId = 'test_session_' . uniqid();
        $sessionData = serialize(['user_id' => 789, 'action' => 'multiple_hooks']);

        $result = $this->handler->write($sessionId, $sessionData);

        self::assertTrue($result);

        self::assertTrue($this->logger->hasDebugRecords());

        $primaryData = $this->primaryConnection->get($sessionId);
        $secondaryData = $this->secondaryConnection->get($sessionId);

        self::assertNotFalse($primaryData);
        self::assertNotFalse($secondaryData);
        self::assertSame($primaryData, $secondaryData);
    }

    public function testHookErrorHandling(): void
    {
        $loggingHook = new LoggingHook($this->logger);
        $this->handler->addWriteHook($loggingHook);

        $this->handler->open('', '');

        $sessionId = 'test_session_' . uniqid();
        $invalidData = 'invalid serialized data that will cause issues';

        $result = $this->handler->write($sessionId, $invalidData);

        self::assertTrue($result);
    }
}
