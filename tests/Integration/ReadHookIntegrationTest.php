<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\FallbackReadHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\RedisIntegrationTestTrait;

class ReadHookIntegrationTest extends TestCase
{
    use RedisIntegrationTestTrait;

    private Logger $logger;
    private RedisConnection $primaryConnection;
    private RedisConnection $fallbackConnection;
    private RedisSessionHandler $handler;

    protected function setUp(): void
    {
        $params = $this->getRedisConnectionParameters();
        $this->assertRedisAvailable($params['host'], $params['port']);

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new NullHandler());

        $this->primaryConnection = $this->createRedisConnection(
            $params['host'],
            $params['port'],
            $this->logger,
            'primary:'
        );

        $this->fallbackConnection = $this->createRedisConnection(
            $params['host'],
            $params['port'],
            $this->logger,
            'fallback:'
        );

        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $this->handler = new RedisSessionHandler($this->primaryConnection, new PhpSerializeSerializer(), $options);
    }

    protected function tearDown(): void
    {
        $this->cleanupRedisKeys($this->primaryConnection, $this->fallbackConnection);
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
