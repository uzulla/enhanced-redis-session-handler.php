<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Uzulla\EnhancedRedisSessionHandler\Hook\DoubleWriteHook;
use Uzulla\EnhancedRedisSessionHandler\Hook\Storage\HookStorageInterface;
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
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be positive');

        new DoubleWriteHook($this->secondaryConnection, 0);
    }

    public function testConstructorThrowsExceptionWhenTtlIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
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

        $this->expectException(RuntimeException::class);
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

    // ========================================
    // HookStorage対応テスト
    // ========================================

    public function testConstructorWithUseHookStorageFlag(): void
    {
        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, null, true);
        self::assertInstanceOf(DoubleWriteHook::class, $hook);
    }

    public function testBeforeWriteWithHookStorageStoresStorage(): void
    {
        $mockStorage = $this->createMock(HookStorageInterface::class);

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, null, true);
        $data = ['user_id' => 123, 'username' => 'test'];

        $result = $hook->beforeWrite('test_session', $data, $mockStorage);

        self::assertSame($data, $result);
    }

    public function testAfterWriteWithHookStorageUsesStorageForSecondaryWrite(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $mockStorage = $this->createMock(HookStorageInterface::class);
        $mockStorage->expects(self::once())
            ->method('set')
            ->with(
                'test_session',
                self::anything(),
                1440
            )
            ->willReturn(true);

        // secondaryConnectionは使われない
        $this->secondaryConnection->expects(self::never())
            ->method('set');

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger, true);
        $hook->beforeWrite('test_session', ['key' => 'value'], $mockStorage);
        $hook->afterWrite('test_session', true);

        // HookStorage経由のログメッセージを確認
        self::assertTrue($testHandler->hasDebugThatContains('via HookStorage'));
    }

    public function testAfterWriteWithoutHookStorageUsesDirectConnection(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $this->secondaryConnection->expects(self::once())
            ->method('set')
            ->with(
                'test_session',
                self::anything(),
                1440
            )
            ->willReturn(true);

        // useHookStorage=trueでも、storageが渡されなければdirect connectionを使用
        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger, true);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', true);

        // direct connection経由のログメッセージを確認
        self::assertTrue($testHandler->hasDebugThatContains('via direct connection'));
    }

    public function testAfterWriteWithHookStorageDisabledUsesDirectConnection(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $mockStorage = $this->createMock(HookStorageInterface::class);
        // storageは使われない
        $mockStorage->expects(self::never())
            ->method('set');

        // useHookStorage=false（デフォルト）の場合、storageが渡されてもdirect connectionを使用
        $this->secondaryConnection->expects(self::once())
            ->method('set')
            ->willReturn(true);

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger, false);
        $hook->beforeWrite('test_session', ['key' => 'value'], $mockStorage);
        $hook->afterWrite('test_session', true);

        // direct connection経由のログメッセージを確認
        self::assertTrue($testHandler->hasDebugThatContains('via direct connection'));
    }

    public function testAfterWriteWithHookStorageHandlesFailure(): void
    {
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $mockStorage = $this->createMock(HookStorageInterface::class);
        $mockStorage->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger, true);
        $hook->beforeWrite('test_session', ['key' => 'value'], $mockStorage);
        $hook->afterWrite('test_session', true);

        // 書き込み失敗のエラーログが出力されていることを確認
        self::assertTrue($testHandler->hasErrorThatContains('Secondary Redis write failed'));
    }

    public function testAfterWriteWithHookStorageThrowsExceptionOnFailure(): void
    {
        $mockStorage = $this->createMock(HookStorageInterface::class);
        $mockStorage->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Secondary Redis write failed');

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, true, null, true);
        $hook->beforeWrite('test_session', ['key' => 'value'], $mockStorage);
        $hook->afterWrite('test_session', true);
    }

    public function testOnWriteErrorCleansUpPendingStorages(): void
    {
        $mockStorage = $this->createMock(HookStorageInterface::class);
        // storageは使われない（エラーでクリーンアップされるため）
        $mockStorage->expects(self::never())
            ->method('set');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Primary write error, secondary write skipped', self::anything());

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger, true);
        $hook->beforeWrite('test_session', ['key' => 'value'], $mockStorage);
        $hook->onWriteError('test_session', new \Exception('Test error'));

        // 後続のafterWriteでstorageが使われないことを確認
        $this->secondaryConnection->expects(self::never())
            ->method('set');
    }

    public function testPrimaryWriteFailureCleansUpPendingStorages(): void
    {
        $mockStorage = $this->createMock(HookStorageInterface::class);
        // storageは使われない（プライマリ失敗でスキップされるため）
        $mockStorage->expects(self::never())
            ->method('set');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Primary write failed, skipping secondary write', self::anything());

        // secondaryConnectionも使われない
        $this->secondaryConnection->expects(self::never())
            ->method('set');

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger, true);
        $hook->beforeWrite('test_session', ['key' => 'value'], $mockStorage);
        $hook->afterWrite('test_session', false);
    }

    public function testBackwardCompatibilityWithoutStorageParameter(): void
    {
        // 新しいパラメータを使わずに従来の使い方ができることを確認
        $testHandler = new TestHandler();
        $logger = new Logger('test');
        $logger->pushHandler($testHandler);

        $this->secondaryConnection->expects(self::once())
            ->method('set')
            ->willReturn(true);

        $hook = new DoubleWriteHook($this->secondaryConnection, 1440, false, $logger);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', true);

        // direct connection経由のログメッセージを確認
        self::assertTrue($testHandler->hasDebugThatContains('via direct connection'));
    }
}
