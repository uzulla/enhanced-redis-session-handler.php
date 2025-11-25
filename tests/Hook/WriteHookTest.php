<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;

class WriteHookTest extends TestCase
{
    private RedisConnection $primaryConnection;
    private RedisConnection $secondaryConnection;
    private PsrTestLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new PsrTestLogger();

        $primaryRedis = new Redis();
        $primaryConfig = new RedisConnectionConfig('localhost', 6379);
        $this->primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, $this->logger);

        $secondaryRedis = new Redis();
        $secondaryConfig = new RedisConnectionConfig('localhost', 6379, 1);
        $this->secondaryConnection = new RedisConnection($secondaryRedis, $secondaryConfig, $this->logger);
    }

    public function testLoggingHookLogsBeforeWrite(): void
    {
        $hook = new LoggingHook($this->logger);
        $data = ['user_id' => 123, 'username' => 'testuser'];

        $result = $hook->beforeWrite('test_session_id', $data);

        self::assertSame($data, $result);
        self::assertTrue($this->logger->hasDebugRecords());
        self::assertTrue($this->logger->hasLogMessage('Session write starting'), 'Expected log message not found');

        $logRecord = $this->logger->findLogByMessage('Session write starting');
        self::assertNotNull($logRecord);
        self::assertSame('...n_id', $logRecord['context']['session_id']);
        self::assertSame(['user_id', 'username'], $logRecord['context']['data_keys']);
        self::assertSame(2, $logRecord['context']['data_size']);
    }

    public function testLoggingHookLogsAfterWriteSuccess(): void
    {
        $hook = new LoggingHook($this->logger);

        $hook->afterWrite('test_session_id', true);

        self::assertTrue($this->logger->hasDebugRecords());
        self::assertTrue($this->logger->hasLogWithContext('Session write successful', [
            'session_id' => '...n_id',
            'success' => true,
        ]), 'Expected log message not found');
    }

    public function testLoggingHookLogsAfterWriteFailure(): void
    {
        $hook = new LoggingHook($this->logger);

        $hook->afterWrite('test_session_id', false);

        self::assertTrue($this->logger->hasDebugRecords());
        self::assertTrue($this->logger->hasLogWithContext('Session write failed', [
            'session_id' => '...n_id',
            'success' => false,
        ]), 'Expected log message not found');
    }

    public function testLoggingHookLogsWriteError(): void
    {
        $hook = new LoggingHook($this->logger);
        $exception = new RuntimeException('Test error', 123);

        $hook->onWriteError('test_session_id', $exception);

        self::assertTrue($this->logger->hasErrorRecords());
        self::assertTrue($this->logger->hasLogWithContext('Session write error occurred', [
            'session_id' => '...n_id',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Test error',
            'exception_code' => 123,
        ]), 'Expected log message not found');
    }

    public function testLoggingHookWithDataLogging(): void
    {
        $hook = new LoggingHook($this->logger, 'debug', 'debug', 'error', true);
        $data = ['user_id' => 123];

        $hook->beforeWrite('test_session_id', $data);

        self::assertTrue($this->logger->hasLogWithContext('Session write starting', [
            'data' => $data,
        ]), 'Expected log message with data not found');
    }

    public function testDoubleWriteHookStoresDataInBeforeWrite(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);
        $data = ['user_id' => 123];

        $result = $hook->beforeWrite('test_session_id', $data);

        self::assertSame($data, $result);
    }

    public function testDoubleWriteHookSkipsSecondaryWriteOnPrimaryFailure(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);
        $data = ['user_id' => 123];

        $hook->beforeWrite('test_session_id', $data);
        $hook->afterWrite('test_session_id', false);

        self::assertTrue(
            $this->logger->hasLogMessageContaining('Primary write failed'),
            'Expected warning about primary write failure not found'
        );
    }

    public function testDoubleWriteHookCleansUpOnError(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);
        $data = ['user_id' => 123];
        $exception = new RuntimeException('Test error');

        $hook->beforeWrite('test_session_id', $data);
        $hook->onWriteError('test_session_id', $exception);

        self::assertTrue(
            $this->logger->hasLogMessageContaining('Primary write error'),
            'Expected error log not found'
        );
    }

    public function testMultipleHooksCanBeRegistered(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($this->primaryConnection, new PhpSerializeSerializer(), $options);

        $loggingHook = new LoggingHook($this->logger);
        $doubleWriteHook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);

        $handler->addWriteHook($loggingHook);
        $handler->addWriteHook($doubleWriteHook);

        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }
}
