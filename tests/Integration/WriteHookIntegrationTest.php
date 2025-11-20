<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\RedisIntegrationTestTrait;

class WriteHookIntegrationTest extends TestCase
{
    use RedisIntegrationTestTrait;

    private RedisConnection $primaryConnection;
    private RedisConnection $secondaryConnection;
    private RedisSessionHandler $handler;
    private PsrTestLogger $logger;

    protected function setUp(): void
    {
        $params = $this->getRedisConnectionParameters();
        $this->assertRedisAvailable($params['host'], $params['port']);

        $this->logger = new PsrTestLogger();

        $this->primaryConnection = $this->createRedisConnection(
            $params['host'],
            $params['port'],
            $this->logger,
            'session:',
            0
        );

        $this->secondaryConnection = $this->createRedisConnection(
            $params['host'],
            $params['port'],
            $this->logger,
            'session:',
            1
        );

        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $this->handler = new RedisSessionHandler($this->primaryConnection, new PhpSerializeSerializer(), $options);
    }

    protected function tearDown(): void
    {
        $this->cleanupRedisKeys($this->primaryConnection, $this->secondaryConnection);
    }

    public function testLoggingHookIntegration(): void
    {
        $loggingHook = new LoggingHook($this->logger);
        $this->handler->addWriteHook($loggingHook);

        $this->handler->open('', '');
        $sessionId = $this->generateTestSessionId();
        $sessionData = $this->createTestSessionData(['user_id' => 123, 'username' => 'testuser']);

        $result = $this->handler->write($sessionId, $sessionData);

        self::assertTrue($result);
        self::assertTrue($this->logger->hasDebugRecords());
        self::assertTrue($this->logger->hasLogMessage('Session write starting'), 'beforeWrite log not found');
        self::assertTrue($this->logger->hasLogMessage('Session write successful'), 'afterWrite log not found');
    }

    public function testDoubleWriteHookIntegration(): void
    {
        $doubleWriteHook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);
        $this->handler->addWriteHook($doubleWriteHook);

        $this->handler->open('', '');
        $this->secondaryConnection->connect();

        $sessionId = $this->generateTestSessionId();
        $sessionData = $this->createTestSessionData(['user_id' => 456, 'username' => 'doublewrite']);

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

        $sessionId = $this->generateTestSessionId();
        $sessionData = $this->createTestSessionData(['user_id' => 789, 'action' => 'multiple_hooks']);

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
