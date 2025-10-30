<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\FallbackReadHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Redis;

class ReadHookIntegrationTest extends TestCase
{
    private Logger $logger;
    private RedisConnection $primaryConnection;
    private RedisConnection $fallbackConnection;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        $redisHost = getenv('SESSION_REDIS_HOST');
        $redisPort = getenv('SESSION_REDIS_PORT');

        self::assertNotFalse($redisHost, 'SESSION_REDIS_HOST environment variable must be set');
        self::assertNotFalse($redisPort, 'SESSION_REDIS_PORT environment variable must be set');

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new NullHandler());

        $primaryRedis = new Redis();
        $primaryConfig = new RedisConnectionConfig(
            $redisHost,
            (int)$redisPort,
            2.5,
            null,
            0,
            'primary:'
        );
        $this->primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, $this->logger);

        $fallbackRedis = new Redis();
        $fallbackConfig = new RedisConnectionConfig(
            $redisHost,
            (int)$redisPort,
            2.5,
            null,
            0,
            'fallback:'
        );
        $this->fallbackConnection = new RedisConnection($fallbackRedis, $fallbackConfig, $this->logger);

        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $this->handler = new RedisSessionHandler($this->primaryConnection, new PhpSerializeSerializer(), $options);
    }

    protected function tearDown(): void
    {
        $this->primaryConnection->connect();
        $this->fallbackConnection->connect();

        $primaryKeys = $this->primaryConnection->keys('*');
        foreach ($primaryKeys as $key) {
            $this->primaryConnection->delete($key);
        }

        $fallbackKeys = $this->fallbackConnection->keys('*');
        foreach ($fallbackKeys as $key) {
            $this->fallbackConnection->delete($key);
        }
    }

    public function testFallbackReadHookIntegration(): void
    {
        $invalidRedis = new Redis();
        $invalidConfig = new RedisConnectionConfig('invalid-host', 6379);
        $invalidConnection = new RedisConnection($invalidRedis, $invalidConfig, $this->logger);

        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($invalidConnection, new PhpSerializeSerializer(), $options);

        $this->fallbackConnection->connect();
        $this->fallbackConnection->set('test-session', 'fallback-session-data', 3600);

        $fallbackHook = new FallbackReadHook([$this->fallbackConnection], $this->logger);
        $handler->addReadHook($fallbackHook);

        $handler->open('', '');
        $data = $handler->read('test-session');

        self::assertSame('fallback-session-data', $data);
    }

    public function testReadTimestampHookIntegration(): void
    {
        $this->primaryConnection->connect();

        $timestampHook = new ReadTimestampHook(
            $this->primaryConnection,
            $this->logger,
            'read_at:',
            3600
        );
        $this->handler->addReadHook($timestampHook);

        $this->primaryConnection->set('test-session', 'session-data', 3600);

        $this->handler->open('', '');
        $data = $this->handler->read('test-session');

        self::assertSame('session-data', $data);

        $timestampKey = 'read_at:test-session';
        $timestamp = $this->primaryConnection->get($timestampKey);
        self::assertNotFalse($timestamp);
    }

    public function testMultipleHooksWorkTogether(): void
    {
        $this->primaryConnection->connect();
        $this->fallbackConnection->connect();

        $fallbackHook = new FallbackReadHook([$this->fallbackConnection], $this->logger);
        $timestampHook = new ReadTimestampHook(
            $this->primaryConnection,
            $this->logger,
            'read_at:',
            3600
        );

        $this->handler->addReadHook($fallbackHook);
        $this->handler->addReadHook($timestampHook);

        $this->primaryConnection->set('test-session', 'primary-data', 3600);

        $this->handler->open('', '');
        $data = $this->handler->read('test-session');

        self::assertSame('primary-data', $data);
    }

    public function testFallbackHookActivatesOnPrimaryFailure(): void
    {
        $invalidRedis = new Redis();
        $invalidConfig = new RedisConnectionConfig('invalid-host', 6379);
        $invalidConnection = new RedisConnection($invalidRedis, $invalidConfig, $this->logger);

        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($invalidConnection, new PhpSerializeSerializer(), $options);

        $this->fallbackConnection->connect();
        $this->fallbackConnection->set('test-session', 'fallback-data', 3600);

        $fallbackHook = new FallbackReadHook([$this->fallbackConnection], $this->logger);
        $handler->addReadHook($fallbackHook);

        $handler->open('', '');
        $data = $handler->read('test-session');

        self::assertSame('fallback-data', $data);
    }

    public function testCompleteSessionLifecycleWithHooks(): void
    {
        $this->primaryConnection->connect();
        $this->fallbackConnection->connect();

        $fallbackHook = new FallbackReadHook([$this->fallbackConnection], $this->logger);
        $this->handler->addReadHook($fallbackHook);

        $this->handler->open('', '');

        $sessionId = $this->handler->create_sid();
        self::assertNotEmpty($sessionId);

        $sessionData = serialize(['user_id' => 123, 'username' => 'testuser']);
        $writeResult = $this->handler->write($sessionId, $sessionData);
        self::assertTrue($writeResult);

        $readData = $this->handler->read($sessionId);
        self::assertSame($sessionData, $readData);

        $validateResult = $this->handler->validateId($sessionId);
        self::assertTrue($validateResult);

        $destroyResult = $this->handler->destroy($sessionId);
        self::assertTrue($destroyResult);

        $validateAfterDestroy = $this->handler->validateId($sessionId);
        self::assertFalse($validateAfterDestroy);
    }
}
