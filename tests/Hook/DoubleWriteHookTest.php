<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

class DoubleWriteHookTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $secondaryConnection;

    protected function setUp(): void
    {
        $this->secondaryConnection = $this->createMock(RedisConnection::class);
    }

    public function testConstructorWithDefaultValues(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection);

        // デフォルトでは例外を投げずに構築される
        self::assertInstanceOf(DoubleWriteHook::class, $hook);
    }

    public function testConstructorWithCustomValues(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $hook = new DoubleWriteHook($this->secondaryConnection, 3600, true, $logger);

        self::assertInstanceOf(DoubleWriteHook::class, $hook);
    }

    public function testConstructorThrowsExceptionWhenTtlIsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be positive');

        new DoubleWriteHook($this->secondaryConnection, 0);
    }

    public function testConstructorThrowsExceptionWhenTtlIsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be positive');

        new DoubleWriteHook($this->secondaryConnection, -1);
    }

    public function testConstructorAcceptsPositiveTtl(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection, 1);
        self::assertInstanceOf(DoubleWriteHook::class, $hook);

        $hook = new DoubleWriteHook($this->secondaryConnection, 86400);
        self::assertInstanceOf(DoubleWriteHook::class, $hook);
    }

    public function testConstructorAcceptsNullLogger(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, null);
        self::assertInstanceOf(DoubleWriteHook::class, $hook);
    }

    public function testBeforeWriteStoresData(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection);
        $data = ['user_id' => 123, 'username' => 'test'];

        $result = $hook->beforeWrite('test_session', $data);

        self::assertSame($data, $result);
    }

    public function testAfterWriteSkipsSecondaryWriteOnPrimaryFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Primary write failed, skipping secondary write', self::anything());

        $this->secondaryConnection->expects(self::never())
            ->method('set');

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', false);
    }

    public function testAfterWritePerformsSecondaryWriteOnPrimarySuccess(): void
    {
        $this->secondaryConnection->expects(self::once())
            ->method('set')
            ->with(
                'test_session',
                self::anything(),
                1440
            )
            ->willReturn(true);

        $hook = new DoubleWriteHook($this->secondaryConnection);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', true);
    }

    public function testOnWriteErrorCleansUpPendingWrites(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Primary write error, secondary write skipped', self::anything());
        $logger->expects(self::once())
            ->method('warning')
            ->with('No pending write data found for session', self::anything());

        // Ensure no secondary write attempt occurs
        $this->secondaryConnection->expects(self::never())
            ->method('set');

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->onWriteError('test_session', new \Exception('Test error'));

        // 後続のafterWriteでデータが見つからないことを確認（同じインスタンスを使用）
        $hook->afterWrite('test_session', true);
    }

    public function testAfterWriteThrowsExceptionWhenFailOnSecondaryErrorIsTrue(): void
    {
        $this->secondaryConnection->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secondary Redis write failed');

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, true);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', true);
    }

    public function testAfterWriteDoesNotThrowExceptionWhenFailOnSecondaryErrorIsFalse(): void
    {
        $this->secondaryConnection->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', true);

        // 例外が投げられないことを確認（モックの期待値がチェックされる）
        $this->addToAssertionCount(1);
    }
}
