<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Hook;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Uzulla\EnhancedRedisSessionHandler\Exception\InvalidSessionIdException;
use Uzulla\EnhancedRedisSessionHandler\Hook\SessionMigrationHook;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;

class SessionMigrationHookTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(RedisConnection::class);
    }

    public function testConstructorWithDefaultValues(): void
    {
        $hook = new SessionMigrationHook($this->connection);

        self::assertInstanceOf(SessionMigrationHook::class, $hook);
        self::assertFalse($hook->hasPendingMigration());
        self::assertNull($hook->getMigrationTarget());
    }

    public function testConstructorWithCustomValues(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $hook = new SessionMigrationHook($this->connection, 3600, true, $logger);

        self::assertInstanceOf(SessionMigrationHook::class, $hook);
    }

    public function testConstructorThrowsExceptionWhenTtlIsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be positive');

        new SessionMigrationHook($this->connection, 0);
    }

    public function testConstructorThrowsExceptionWhenTtlIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be positive');

        new SessionMigrationHook($this->connection, -1);
    }

    public function testSetMigrationTarget(): void
    {
        $hook = new SessionMigrationHook($this->connection);

        $hook->setMigrationTarget('new_session_id');

        self::assertTrue($hook->hasPendingMigration());
        self::assertSame('new_session_id', $hook->getMigrationTarget());
    }

    public function testSetMigrationTargetThrowsExceptionOnEmptyId(): void
    {
        $hook = new SessionMigrationHook($this->connection);

        $this->expectException(InvalidSessionIdException::class);

        $hook->setMigrationTarget('');
    }

    public function testSetMigrationTargetThrowsExceptionOnInvalidCharacters(): void
    {
        $hook = new SessionMigrationHook($this->connection);

        $this->expectException(InvalidSessionIdException::class);

        $hook->setMigrationTarget('invalid/session/id');
    }

    public function testSetMigrationTargetAcceptsValidCharacters(): void
    {
        $hook = new SessionMigrationHook($this->connection);

        // Test alphanumeric
        $hook->setMigrationTarget('abc123ABC');
        self::assertSame('abc123ABC', $hook->getMigrationTarget());

        // Test with underscore
        $hook->setMigrationTarget('session_id_123');
        self::assertSame('session_id_123', $hook->getMigrationTarget());

        // Test with hyphen
        $hook->setMigrationTarget('session-id-123');
        self::assertSame('session-id-123', $hook->getMigrationTarget());
    }

    public function testClearMigrationTarget(): void
    {
        $hook = new SessionMigrationHook($this->connection);
        $hook->setMigrationTarget('new_session_id');

        self::assertTrue($hook->hasPendingMigration());

        $hook->clearMigrationTarget();

        self::assertFalse($hook->hasPendingMigration());
        self::assertNull($hook->getMigrationTarget());
    }

    public function testBeforeWriteStoresData(): void
    {
        $hook = new SessionMigrationHook($this->connection);
        $data = ['user_id' => 123, 'username' => 'test'];

        $result = $hook->beforeWrite('test_session', $data);

        self::assertSame($data, $result);
    }

    public function testAfterWriteSkipsWhenNoMigrationTarget(): void
    {
        $this->connection->expects(self::never())
            ->method('set');
        $this->connection->expects(self::never())
            ->method('delete');

        $hook = new SessionMigrationHook($this->connection);
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', true);

        $this->addToAssertionCount(1);
    }

    public function testAfterWriteSkipsWhenPrimaryWriteFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('Primary write failed, skipping migration', self::anything());

        $this->connection->expects(self::never())
            ->method('set');

        $hook = new SessionMigrationHook($this->connection, 1440, false, $logger);
        $hook->setMigrationTarget('new_session_id');
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->afterWrite('test_session', false);
    }

    public function testAfterWritePerformsMigration(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('new_session_id')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('set')
            ->with(
                'new_session_id',
                self::callback(function ($data): bool {
                    if (!is_string($data)) {
                        return false;
                    }
                    $unserialized = unserialize($data);
                    return $unserialized === ['key' => 'value'];
                }),
                1440
            )
            ->willReturn(true);

        $this->connection->expects(self::once())
            ->method('delete')
            ->with('old_session_id')
            ->willReturn(true);

        $hook = new SessionMigrationHook($this->connection);
        $hook->setMigrationTarget('new_session_id', true);
        $hook->beforeWrite('old_session_id', ['key' => 'value']);
        $hook->afterWrite('old_session_id', true);

        // Migration target should be cleared after migration
        self::assertFalse($hook->hasPendingMigration());
    }

    public function testAfterWriteSkipsDeleteWhenDeleteOldSessionIsFalse(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('new_session_id')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('set')
            ->willReturn(true);

        $this->connection->expects(self::never())
            ->method('delete');

        $hook = new SessionMigrationHook($this->connection);
        $hook->setMigrationTarget('new_session_id', false);
        $hook->beforeWrite('old_session_id', ['key' => 'value']);
        $hook->afterWrite('old_session_id', true);
    }

    public function testAfterWriteSkipsMigrationWhenTargetIsSameAsCurrent(): void
    {
        $this->connection->expects(self::never())
            ->method('set');
        $this->connection->expects(self::never())
            ->method('delete');

        $hook = new SessionMigrationHook($this->connection);
        $hook->setMigrationTarget('same_session_id');
        $hook->beforeWrite('same_session_id', ['key' => 'value']);
        $hook->afterWrite('same_session_id', true);

        // Migration target should be cleared
        self::assertFalse($hook->hasPendingMigration());
    }

    public function testAfterWriteThrowsExceptionWhenFailOnMigrationErrorIsTrue(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('new_session_id')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to write session data to migration target');

        $hook = new SessionMigrationHook($this->connection, 1440, true);
        $hook->setMigrationTarget('new_session_id');
        $hook->beforeWrite('old_session_id', ['key' => 'value']);
        $hook->afterWrite('old_session_id', true);
    }

    public function testAfterWriteDoesNotThrowExceptionWhenFailOnMigrationErrorIsFalse(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('new_session_id')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $hook = new SessionMigrationHook($this->connection, 1440, false);
        $hook->setMigrationTarget('new_session_id');
        $hook->beforeWrite('old_session_id', ['key' => 'value']);
        $hook->afterWrite('old_session_id', true);

        // Migration target should be cleared even on failure
        self::assertFalse($hook->hasPendingMigration());
    }

    public function testOnWriteErrorClearsState(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Primary write error, migration skipped', self::anything());

        $this->connection->expects(self::never())
            ->method('set');

        $hook = new SessionMigrationHook($this->connection, 1440, false, $logger);
        $hook->setMigrationTarget('new_session_id');
        $hook->beforeWrite('test_session', ['key' => 'value']);
        $hook->onWriteError('test_session', new \Exception('Test error'));

        // Migration target should be cleared
        self::assertFalse($hook->hasPendingMigration());
    }

    public function testMigrationIsOneShotOperation(): void
    {
        $this->connection->expects(self::once())
            ->method('set')
            ->willReturn(true);

        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturn(true);

        $hook = new SessionMigrationHook($this->connection);
        $hook->setMigrationTarget('new_session_id');

        // First write - should migrate
        $hook->beforeWrite('old_session_id', ['key' => 'value1']);
        $hook->afterWrite('old_session_id', true);

        // Second write - should NOT migrate (target cleared)
        $hook->beforeWrite('old_session_id', ['key' => 'value2']);
        $hook->afterWrite('old_session_id', true);
    }

    public function testConstructorWithCustomSerializer(): void
    {
        $serializer = $this->createMock(SessionSerializerInterface::class);
        $hook = new SessionMigrationHook($this->connection, 1440, false, null, $serializer);

        self::assertInstanceOf(SessionMigrationHook::class, $hook);
    }

    public function testAfterWriteUsesCustomSerializer(): void
    {
        $serializer = $this->createMock(SessionSerializerInterface::class);
        $serializer->expects(self::once())
            ->method('encode')
            ->with(['key' => 'value'])
            ->willReturn('custom_serialized_data');

        $this->connection->expects(self::once())
            ->method('exists')
            ->with('new_session_id')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('set')
            ->with(
                'new_session_id',
                'custom_serialized_data',
                1440
            )
            ->willReturn(true);

        $this->connection->expects(self::once())
            ->method('delete')
            ->with('old_session_id')
            ->willReturn(true);

        $hook = new SessionMigrationHook($this->connection, 1440, false, null, $serializer);
        $hook->setMigrationTarget('new_session_id', true);
        $hook->beforeWrite('old_session_id', ['key' => 'value']);
        $hook->afterWrite('old_session_id', true);

        self::assertFalse($hook->hasPendingMigration());
    }

    public function testAfterWriteThrowsExceptionWhenTargetSessionExistsAndFailOnMigrationErrorIsTrue(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('existing_session_id')
            ->willReturn(true);

        $this->connection->expects(self::never())
            ->method('set');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Target session ID already exists');

        $hook = new SessionMigrationHook($this->connection, 1440, true);
        $hook->setMigrationTarget('existing_session_id');
        $hook->beforeWrite('old_session_id', ['key' => 'value']);
        $hook->afterWrite('old_session_id', true);
    }

    public function testAfterWriteDoesNotThrowExceptionWhenTargetSessionExistsAndFailOnMigrationErrorIsFalse(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('existing_session_id')
            ->willReturn(true);

        $this->connection->expects(self::never())
            ->method('set');

        $hook = new SessionMigrationHook($this->connection, 1440, false);
        $hook->setMigrationTarget('existing_session_id');
        $hook->beforeWrite('old_session_id', ['key' => 'value']);
        $hook->afterWrite('old_session_id', true);

        // Migration target should be cleared even on failure
        self::assertFalse($hook->hasPendingMigration());
    }
}
