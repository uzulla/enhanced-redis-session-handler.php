<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;

class WriteHookIntegrationTest extends TestCase
{
    private RedisConnection $primaryConnection;
    private RedisConnection $secondaryConnection;
    private RedisSessionHandler $handler;
    private Logger $logger;
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is not available');
        }

        $this->logger = new Logger('test');
        $this->logHandler = new TestHandler();
        $this->logger->pushHandler($this->logHandler);

        $primaryRedis = new \Redis();
        $primaryConfig = new RedisConnectionConfig('localhost', 6379, 0);
        $this->primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, $this->logger);

        $secondaryRedis = new \Redis();
        $secondaryConfig = new RedisConnectionConfig('localhost', 6379, 1);
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
            }

            try {
                $this->secondaryConnection->connect();
                $keys = $this->secondaryConnection->keys('*');
                foreach ($keys as $key) {
                    $this->secondaryConnection->delete($key);
                }
            } catch (\Exception $e) {
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
        self::assertTrue($this->logHandler->hasDebugRecords());

        $records = $this->logHandler->getRecords();
        $hasBeforeWrite = false;
        $hasAfterWrite = false;

        foreach ($records as $record) {
            if (isset($record['message'])) {
                if ($record['message'] === 'Session write starting') {
                    $hasBeforeWrite = true;
                }
                if ($record['message'] === 'Session write successful') {
                    $hasAfterWrite = true;
                }
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

        self::assertTrue($this->logHandler->hasDebugRecords());

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
