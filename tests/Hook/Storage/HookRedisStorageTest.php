<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook\Storage;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Hook\Storage\HookContext;
use Uzulla\EnhancedRedisSessionHandler\Hook\Storage\HookRedisStorage;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

class HookRedisStorageTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $connection;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private HookContext $context;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(RedisConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->context = new HookContext(3);
    }

    public function testConstructorWithLogger(): void
    {
        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertInstanceOf(HookRedisStorage::class, $storage);
        self::assertSame(0, $storage->getDepth());
    }

    public function testConstructorWithoutLogger(): void
    {
        $storage = new HookRedisStorage($this->connection, $this->context);

        self::assertInstanceOf(HookRedisStorage::class, $storage);
    }

    public function testGetOperationDelegatesToConnection(): void
    {
        $this->connection->expects(self::once())
            ->method('get')
            ->with('test_key')
            ->willReturn('test_value');

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);
        $result = $storage->get('test_key');

        self::assertSame('test_value', $result);
    }

    public function testGetOperationReturnsFalseWhenNotFound(): void
    {
        $this->connection->expects(self::once())
            ->method('get')
            ->with('nonexistent_key')
            ->willReturn(false);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);
        $result = $storage->get('nonexistent_key');

        self::assertFalse($result);
    }

    public function testGetOperationTracksDepth(): void
    {
        $this->connection->method('get')->willReturn('value');

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertSame(0, $storage->getDepth());
        $storage->get('key');
        self::assertSame(0, $storage->getDepth()); // Depth should be decremented after operation
    }

    public function testSetOperationDelegatesToConnection(): void
    {
        $this->connection->expects(self::once())
            ->method('set')
            ->with('test_key', 'test_value', 3600)
            ->willReturn(true);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);
        $result = $storage->set('test_key', 'test_value', 3600);

        self::assertTrue($result);
    }

    public function testSetOperationReturnsFalseOnFailure(): void
    {
        $this->connection->expects(self::once())
            ->method('set')
            ->with('test_key', 'test_value', 3600)
            ->willReturn(false);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);
        $result = $storage->set('test_key', 'test_value', 3600);

        self::assertFalse($result);
    }

    public function testSetOperationTracksDepth(): void
    {
        $this->connection->method('set')->willReturn(true);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertSame(0, $storage->getDepth());
        $storage->set('key', 'value', 3600);
        self::assertSame(0, $storage->getDepth()); // Depth should be decremented after operation
    }

    public function testDeleteOperationDelegatesToConnection(): void
    {
        $this->connection->expects(self::once())
            ->method('delete')
            ->with('test_key')
            ->willReturn(true);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);
        $result = $storage->delete('test_key');

        self::assertTrue($result);
    }

    public function testDeleteOperationReturnsFalseWhenKeyNotFound(): void
    {
        $this->connection->expects(self::once())
            ->method('delete')
            ->with('nonexistent_key')
            ->willReturn(false);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);
        $result = $storage->delete('nonexistent_key');

        self::assertFalse($result);
    }

    public function testDeleteOperationTracksDepth(): void
    {
        $this->connection->method('delete')->willReturn(true);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertSame(0, $storage->getDepth());
        $storage->delete('key');
        self::assertSame(0, $storage->getDepth()); // Depth should be decremented after operation
    }

    public function testDepthIncrementsAndDecrementsCorrectly(): void
    {
        $depthDuringOperation = null;
        $context = $this->context;

        $this->connection->expects(self::once())
            ->method('get')
            ->willReturnCallback(function () use (&$depthDuringOperation, $context) {
                $depthDuringOperation = $context->getDepth();
                return 'value';
            });

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertSame(0, $storage->getDepth());
        $storage->get('key');

        self::assertSame(1, $depthDuringOperation);
        self::assertSame(0, $storage->getDepth());
    }

    public function testDepthLimitExceededLogsWarningForGet(): void
    {
        $this->connection->method('get')->willReturn('value');

        // Set up context with max depth of 2
        $context = new HookContext(2);

        // Manually increment depth to exceed limit
        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Hook storage depth limit exceeded for GET operation',
                [
                    'current_depth' => 4,
                    'max_depth' => 2,
                    'operation' => 'get',
                ]
            );

        $storage = new HookRedisStorage($this->connection, $context, $this->logger);
        $storage->get('key');
    }

    public function testDepthLimitExceededLogsWarningForSet(): void
    {
        $this->connection->method('set')->willReturn(true);

        // Set up context with max depth of 2
        $context = new HookContext(2);

        // Manually increment depth to exceed limit
        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Hook storage depth limit exceeded for SET operation',
                [
                    'current_depth' => 4,
                    'max_depth' => 2,
                    'operation' => 'set',
                ]
            );

        $storage = new HookRedisStorage($this->connection, $context, $this->logger);
        $storage->set('key', 'value', 3600);
    }

    public function testDepthLimitExceededLogsWarningForDelete(): void
    {
        $this->connection->method('delete')->willReturn(true);

        // Set up context with max depth of 2
        $context = new HookContext(2);

        // Manually increment depth to exceed limit
        $context->incrementDepth();
        $context->incrementDepth();
        $context->incrementDepth();

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Hook storage depth limit exceeded for DELETE operation',
                [
                    'current_depth' => 4,
                    'max_depth' => 2,
                    'operation' => 'delete',
                ]
            );

        $storage = new HookRedisStorage($this->connection, $context, $this->logger);
        $storage->delete('key');
    }

    public function testOperationContinuesEvenWhenDepthLimitExceeded(): void
    {
        // Set up context with max depth of 1
        $context = new HookContext(1);
        $context->incrementDepth();
        $context->incrementDepth();

        // Operation should still execute despite depth limit being exceeded
        $this->connection->expects(self::once())
            ->method('get')
            ->with('test_key')
            ->willReturn('test_value');

        $this->logger->expects(self::once())
            ->method('warning');

        $storage = new HookRedisStorage($this->connection, $context, $this->logger);
        $result = $storage->get('test_key');

        // Verify graceful degradation: operation succeeds despite depth limit
        self::assertSame('test_value', $result);
    }

    public function testDepthIsDecrementedEvenOnException(): void
    {
        $this->connection->expects(self::once())
            ->method('get')
            ->willThrowException(new \RuntimeException('Redis error'));

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertSame(0, $storage->getDepth());

        try {
            $storage->get('key');
            self::fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            // Exception is expected
        }

        // Depth should be decremented even after exception
        self::assertSame(0, $storage->getDepth());
    }

    public function testGetContextReturnsHookContext(): void
    {
        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertSame($this->context, $storage->getContext());
    }

    public function testNestedOperationsTrackDepthCorrectly(): void
    {
        $this->connection->method('get')->willReturn('value');
        $this->connection->method('set')->willReturn(true);

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        self::assertSame(0, $storage->getDepth());

        // Simulate nested operations
        $this->context->incrementDepth();
        self::assertSame(1, $storage->getDepth());

        $storage->get('key1'); // Will increment to 2, then decrement to 1

        self::assertSame(1, $storage->getDepth());

        $this->context->decrementDepth();
        self::assertSame(0, $storage->getDepth());
    }

    public function testNoWarningLoggedWhenDepthWithinLimit(): void
    {
        $this->connection->method('get')->willReturn('value');
        $this->connection->method('set')->willReturn(true);
        $this->connection->method('delete')->willReturn(true);

        $this->logger->expects(self::never())
            ->method('warning');

        $storage = new HookRedisStorage($this->connection, $this->context, $this->logger);

        $storage->get('key');
        $storage->set('key', 'value', 3600);
        $storage->delete('key');
    }

    public function testNullLoggerDoesNotCauseErrors(): void
    {
        // Use a real NullLogger to ensure no logging errors
        $nullLogger = new NullLogger();
        $storage = new HookRedisStorage($this->connection, $this->context, $nullLogger);

        $this->connection->method('get')->willReturn('value');

        // This should not throw any errors even though logger is null
        $result = $storage->get('key');

        self::assertSame('value', $result);
    }
}
