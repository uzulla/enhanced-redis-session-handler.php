<?php

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use PHPUnit\Framework\TestCase;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisSessionHandlerOptions;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\LoggingHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\RedisSessionHandler;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\PsrTestLogger;

class WriteHookTest extends TestCase
{
    private RedisConnection $primaryConnection;
    private RedisConnection $secondaryConnection;
    private PsrTestLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new PsrTestLogger();

        $primaryRedis = new \Redis();
        $primaryConfig = new RedisConnectionConfig('localhost', 6379);
        $this->primaryConnection = new RedisConnection($primaryRedis, $primaryConfig, $this->logger);

        $secondaryRedis = new \Redis();
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

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session write starting') {
                $found = true;
                self::assertArrayHasKey('context', $record);
                $context = $record['context'];
                self::assertArrayHasKey('session_id', $context);
                self::assertSame('test_session_id', $context['session_id']);
                self::assertArrayHasKey('data_keys', $context);
                self::assertSame(['user_id', 'username'], $context['data_keys']);
                self::assertArrayHasKey('data_size', $context);
                self::assertSame(2, $context['data_size']);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message not found');
    }

    public function testLoggingHookLogsAfterWriteSuccess(): void
    {
        $hook = new LoggingHook($this->logger);

        $hook->afterWrite('test_session_id', true);

        self::assertTrue($this->logger->hasDebugRecords());

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session write successful') {
                $found = true;
                self::assertArrayHasKey('context', $record);
                $context = $record['context'];
                self::assertArrayHasKey('session_id', $context);
                self::assertSame('test_session_id', $context['session_id']);
                self::assertArrayHasKey('success', $context);
                self::assertTrue($context['success']);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message not found');
    }

    public function testLoggingHookLogsAfterWriteFailure(): void
    {
        $hook = new LoggingHook($this->logger);

        $hook->afterWrite('test_session_id', false);

        self::assertTrue($this->logger->hasDebugRecords());

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session write failed') {
                $found = true;
                self::assertArrayHasKey('context', $record);
                $context = $record['context'];
                self::assertArrayHasKey('session_id', $context);
                self::assertSame('test_session_id', $context['session_id']);
                self::assertArrayHasKey('success', $context);
                self::assertFalse($context['success']);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message not found');
    }

    public function testLoggingHookLogsWriteError(): void
    {
        $hook = new LoggingHook($this->logger);
        $exception = new \RuntimeException('Test error', 123);

        $hook->onWriteError('test_session_id', $exception);

        self::assertTrue($this->logger->hasErrorRecords());

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session write error occurred') {
                $found = true;
                self::assertArrayHasKey('context', $record);
                $context = $record['context'];
                self::assertArrayHasKey('session_id', $context);
                self::assertSame('test_session_id', $context['session_id']);
                self::assertArrayHasKey('exception_class', $context);
                self::assertSame('RuntimeException', $context['exception_class']);
                self::assertArrayHasKey('exception_message', $context);
                self::assertSame('Test error', $context['exception_message']);
                self::assertArrayHasKey('exception_code', $context);
                self::assertSame(123, $context['exception_code']);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message not found');
    }

    public function testLoggingHookWithDataLogging(): void
    {
        $hook = new LoggingHook($this->logger, 'debug', 'debug', 'error', true);
        $data = ['user_id' => 123];

        $hook->beforeWrite('test_session_id', $data);

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if ($record['message'] === 'Session write starting') {
                $found = true;
                self::assertArrayHasKey('context', $record);
                $context = $record['context'];
                self::assertArrayHasKey('data', $context);
                self::assertSame($data, $context['data']);
                break;
            }
        }
        self::assertTrue($found, 'Expected log message with data not found');
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

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if (str_contains($record['message'], 'Primary write failed')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected warning about primary write failure not found');
    }

    public function testDoubleWriteHookCleansUpOnError(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);
        $data = ['user_id' => 123];
        $exception = new \RuntimeException('Test error');

        $hook->beforeWrite('test_session_id', $data);
        $hook->onWriteError('test_session_id', $exception);

        $records = $this->logger->getRecords();
        $found = false;
        foreach ($records as $record) {
            if (str_contains($record['message'], 'Primary write error')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Expected error log not found');
    }

    public function testMultipleHooksCanBeRegistered(): void
    {
        $options = new RedisSessionHandlerOptions(null, null, $this->logger);
        $handler = new RedisSessionHandler($this->primaryConnection, $options);

        $loggingHook = new LoggingHook($this->logger);
        $doubleWriteHook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $this->logger);

        $handler->addWriteHook($loggingHook);
        $handler->addWriteHook($doubleWriteHook);

        self::assertInstanceOf(RedisSessionHandler::class, $handler);
    }
}
