<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use InvalidArgumentException;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Hook\ReadTimestampHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\Storage\HookContext;
use Uzulla\EnhancedRedisSessionHandler\Hook\Storage\HookRedisStorage;
use Uzulla\EnhancedRedisSessionHandler\Hook\Storage\HookStorageInterface;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Tests\Support\RedisIntegrationTestTrait;

class ReadTimestampHookTest extends TestCase
{
    use RedisIntegrationTestTrait;

    private Logger $logger;
    private RedisConnection $connection;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::fail('Redis extension is required for this test');
        }

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new NullHandler());

        $params = $this->getRedisConnectionParametersWithDefaults();

        $redis = new Redis();
        $config = new RedisConnectionConfig($params['host'], $params['port'], 2.5, null, 0, 'timestamp:');
        $this->connection = new RedisConnection($redis, $config, $this->logger);
        $this->connection->connect();
    }

    public function testBeforeReadDoesNothing(): void
    {
        $hook = new ReadTimestampHook($this->connection, $this->logger);
        $hook->beforeRead('test-session-id');
        $this->addToAssertionCount(1);
    }

    public function testAfterReadRecordsTimestamp(): void
    {
        $hook = new ReadTimestampHook($this->connection, $this->logger, 'read_at:', 3600);

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);

        $timestampKey = 'read_at:test-session-id';
        $timestamp = $this->connection->get($timestampKey);

        self::assertNotFalse($timestamp);
        self::assertGreaterThan(0, (int) $timestamp);

        $this->connection->delete($timestampKey);
    }

    public function testAfterReadReturnsDataUnchanged(): void
    {
        $hook = new ReadTimestampHook($this->connection, $this->logger);

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);

        $this->connection->delete('read_at:test-session-id');
    }

    public function testOnReadErrorReturnsNull(): void
    {
        $hook = new ReadTimestampHook($this->connection, $this->logger);
        $result = $hook->onReadError('test-session-id', new RuntimeException('Test error'));
        self::assertNull($result);
    }

    public function testAfterReadHandlesTimestampErrors(): void
    {
        $redis = new Redis();
        $config = new RedisConnectionConfig('invalid-host', 6379);
        $invalidConnection = new RedisConnection($redis, $config, $this->logger);

        $hook = new ReadTimestampHook($invalidConnection, $this->logger);

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);
    }

    public function testTimestampKeyPrefix(): void
    {
        $hook = new ReadTimestampHook($this->connection, $this->logger, 'custom:', 3600);

        $data = 'test-data';
        $hook->afterRead('test-session-id', $data);

        $timestampKey = 'custom:test-session-id';
        $timestamp = $this->connection->get($timestampKey);

        self::assertNotFalse($timestamp);

        $this->connection->delete($timestampKey);
    }

    public function testConstructorThrowsExceptionWhenTimestampKeyPrefixIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timestamp key prefix cannot be empty');

        new ReadTimestampHook($this->connection, $this->logger, '');
    }

    public function testConstructorThrowsExceptionWhenTtlIsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timestamp TTL must be positive');

        new ReadTimestampHook($this->connection, $this->logger, 'read_at:', 0);
    }

    public function testConstructorThrowsExceptionWhenTtlIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timestamp TTL must be positive');

        new ReadTimestampHook($this->connection, $this->logger, 'read_at:', -1);
    }

    public function testConstructorAcceptsPositiveTtl(): void
    {
        $hook = new ReadTimestampHook($this->connection, $this->logger, 'read_at:', 1);
        self::assertInstanceOf(ReadTimestampHook::class, $hook);

        $hook = new ReadTimestampHook($this->connection, $this->logger, 'read_at:', 86400);
        self::assertInstanceOf(ReadTimestampHook::class, $hook);
    }

    public function testConstructorAcceptsNonEmptyPrefix(): void
    {
        $hook = new ReadTimestampHook($this->connection, $this->logger, 'x');
        self::assertInstanceOf(ReadTimestampHook::class, $hook);
    }

    // ========================================
    // HookStorage対応テスト
    // ========================================

    public function testAfterReadWithHookStorageRecordsTimestamp(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $hook = new ReadTimestampHook($this->connection, $logger, 'storage_read_at:', 3600);

        $context = new HookContext(3);
        $storage = new HookRedisStorage($this->connection, $context, $logger);

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data, $storage);

        self::assertSame($data, $result);

        $timestampKey = 'storage_read_at:test-session-id';
        $timestamp = $this->connection->get($timestampKey);

        self::assertNotFalse($timestamp);
        self::assertGreaterThan(0, (int) $timestamp);

        // HookStorage経由のログメッセージを確認
        self::assertTrue($testHandler->hasDebugThatContains('via HookStorage'));

        $this->connection->delete($timestampKey);
    }

    public function testAfterReadWithNullStorageUsesDirectConnection(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $hook = new ReadTimestampHook($this->connection, $logger, 'direct_read_at:', 3600);

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data, null);

        self::assertSame($data, $result);

        $timestampKey = 'direct_read_at:test-session-id';
        $timestamp = $this->connection->get($timestampKey);

        self::assertNotFalse($timestamp);

        // direct connection経由のログメッセージを確認
        self::assertTrue($testHandler->hasDebugThatContains('via direct connection'));

        $this->connection->delete($timestampKey);
    }

    public function testAfterReadWithoutStorageParameterUsesDirectConnection(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $hook = new ReadTimestampHook($this->connection, $logger, 'no_param_read_at:', 3600);

        $data = 'test-data';
        // storageパラメータを渡さない（後方互換性テスト）
        $result = $hook->afterRead('test-session-id', $data);

        self::assertSame($data, $result);

        $timestampKey = 'no_param_read_at:test-session-id';
        $timestamp = $this->connection->get($timestampKey);

        self::assertNotFalse($timestamp);

        // direct connection経由のログメッセージを確認
        self::assertTrue($testHandler->hasDebugThatContains('via direct connection'));

        $this->connection->delete($timestampKey);
    }

    public function testAfterReadWithHookStorageHandlesErrors(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        // 無効な接続を使用してエラーをシミュレート
        $redis = new Redis();
        $config = new RedisConnectionConfig('invalid-host', 6379);
        $invalidConnection = new RedisConnection($redis, $config, $logger);

        $hook = new ReadTimestampHook($invalidConnection, $logger, 'error_read_at:', 3600);

        $context = new HookContext(3);
        $storage = new HookRedisStorage($invalidConnection, $context, $logger);

        $data = 'test-data';
        // エラーが発生してもデータはそのまま返される
        $result = $hook->afterRead('test-session-id', $data, $storage);

        self::assertSame($data, $result);

        // エラーログが出力されていることを確認
        self::assertTrue($testHandler->hasWarningThatContains('Failed to record session read timestamp'));
    }

    public function testAfterReadWithMockHookStorage(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $hook = new ReadTimestampHook($this->connection, $logger, 'mock_read_at:', 3600);

        $mockStorage = $this->createMock(HookStorageInterface::class);
        $mockStorage->expects(self::once())
            ->method('set')
            ->with(
                'mock_read_at:test-session-id',
                self::anything(),
                3600
            )
            ->willReturn(true);

        $data = 'test-data';
        $result = $hook->afterRead('test-session-id', $data, $mockStorage);

        self::assertSame($data, $result);
    }
}
