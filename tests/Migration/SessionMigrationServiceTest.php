<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Uzulla\EnhancedRedisSessionHandler\Exception\InvalidSessionIdException;
use Uzulla\EnhancedRedisSessionHandler\Exception\MigrationException;
use Uzulla\EnhancedRedisSessionHandler\Migration\SessionMigrationService;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

class SessionMigrationServiceTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(RedisConnection::class);
    }

    public function testConstructorWithDefaultValues(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        self::assertInstanceOf(SessionMigrationService::class, $service);
    }

    public function testConstructorWithCustomLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $service = new SessionMigrationService($this->connection, 3600, null, $logger);

        self::assertInstanceOf(SessionMigrationService::class, $service);
    }

    public function testConstructorThrowsExceptionWhenTtlIsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be positive');

        new SessionMigrationService($this->connection, 0);
    }

    public function testConstructorThrowsExceptionWhenTtlIsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be positive');

        new SessionMigrationService($this->connection, -1);
    }

    public function testSessionExists(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('test_session_id')
            ->willReturn(true);

        $service = new SessionMigrationService($this->connection, 1440);

        self::assertTrue($service->sessionExists('test_session_id'));
    }

    public function testSessionExistsReturnsFalse(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('nonexistent_session_id')
            ->willReturn(false);

        $service = new SessionMigrationService($this->connection, 1440);

        self::assertFalse($service->sessionExists('nonexistent_session_id'));
    }

    public function testCopySuccess(): void
    {
        $sessionData = serialize(['user_id' => 123, 'username' => 'test']);

        $this->connection->expects(self::once())
            ->method('exists')
            ->with('target_session')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('get')
            ->with('source_session')
            ->willReturn($sessionData);

        $this->connection->expects(self::once())
            ->method('set')
            ->with('target_session', $sessionData, 1440)
            ->willReturn(true);

        $this->connection->expects(self::never())
            ->method('delete');

        $service = new SessionMigrationService($this->connection, 1440);
        $service->copy('source_session', 'target_session');

        $this->addToAssertionCount(1);
    }

    public function testCopyWithDeleteSource(): void
    {
        $sessionData = serialize(['user_id' => 123]);

        $this->connection->expects(self::once())
            ->method('exists')
            ->with('target_session')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('get')
            ->with('source_session')
            ->willReturn($sessionData);

        $this->connection->expects(self::once())
            ->method('set')
            ->willReturn(true);

        $this->connection->expects(self::once())
            ->method('delete')
            ->with('source_session')
            ->willReturn(true);

        $service = new SessionMigrationService($this->connection, 1440);
        $service->copy('source_session', 'target_session', true);

        $this->addToAssertionCount(1);
    }

    public function testCopyThrowsExceptionWhenSourceNotFound(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('target_session')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('get')
            ->with('nonexistent_session')
            ->willReturn(false);

        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Source session not found or could not be read');

        $service->copy('nonexistent_session', 'target_session');
    }

    public function testCopyThrowsExceptionWhenWriteFails(): void
    {
        $sessionData = serialize(['user_id' => 123]);

        $this->connection->expects(self::once())
            ->method('exists')
            ->with('target_session')
            ->willReturn(false);

        $this->connection->expects(self::once())
            ->method('get')
            ->willReturn($sessionData);

        $this->connection->expects(self::once())
            ->method('set')
            ->willReturn(false);

        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Failed to write session data to target session ID');

        $service->copy('source_session', 'target_session');
    }

    public function testCopyThrowsExceptionWhenSameSourceAndTarget(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source and target session IDs must be different');

        $service->copy('same_session', 'same_session');
    }

    public function testCopyThrowsExceptionOnEmptySourceId(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(InvalidSessionIdException::class);

        $service->copy('', 'target_session');
    }

    public function testCopyThrowsExceptionOnEmptyTargetId(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(InvalidSessionIdException::class);

        $service->copy('source_session', '');
    }

    public function testCopyThrowsExceptionOnInvalidSourceIdCharacters(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(InvalidSessionIdException::class);

        $service->copy('invalid/source', 'target_session');
    }

    public function testCopyThrowsExceptionOnInvalidTargetIdCharacters(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(InvalidSessionIdException::class);

        $service->copy('source_session', 'invalid<target>');
    }

    public function testCopyAcceptsValidSessionIdCharacters(): void
    {
        $sessionData = serialize(['data' => 'test']);

        $this->connection->method('exists')->willReturn(false);
        $this->connection->method('get')->willReturn($sessionData);
        $this->connection->method('set')->willReturn(true);

        $service = new SessionMigrationService($this->connection, 1440);

        // Test alphanumeric
        $service->copy('abc123ABC', 'xyz789XYZ');

        // Test with underscore
        $service->copy('session_id_1', 'session_id_2');

        // Test with hyphen
        $service->copy('session-id-1', 'session-id-2');

        $this->addToAssertionCount(3);
    }

    public function testCopyThrowsExceptionWhenTargetSessionExists(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('existing_target_session')
            ->willReturn(true);

        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Target session ID already exists');

        $service->copy('source_session', 'existing_target_session');
    }

    public function testMigrateThrowsExceptionWhenSessionNotActive(): void
    {
        // Ensure no session is active
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(MigrationException::class);

        $service->migrate('new_session_id');
    }

    public function testMigrateThrowsExceptionOnEmptySessionId(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(InvalidSessionIdException::class);

        $service->migrate('');
    }

    public function testMigrateThrowsExceptionOnInvalidSessionIdCharacters(): void
    {
        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(InvalidSessionIdException::class);

        $service->migrate('invalid/session/id');
    }

    public function testMigrateThrowsExceptionWhenTargetSessionExists(): void
    {
        // Ensure no session is active
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->connection->expects(self::once())
            ->method('exists')
            ->with('existing_session_id')
            ->willReturn(true);

        $service = new SessionMigrationService($this->connection, 1440);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Target session ID already exists');

        $service->migrate('existing_session_id');
    }

    public function testSessionExistsReturnsFalseForEmptyId(): void
    {
        $this->connection->expects(self::never())
            ->method('exists');

        $service = new SessionMigrationService($this->connection, 1440);

        self::assertFalse($service->sessionExists(''));
        self::assertFalse($service->sessionExists('   '));
    }

    public function testSessionExistsReturnsFalseForInvalidCharacters(): void
    {
        $this->connection->expects(self::never())
            ->method('exists');

        $service = new SessionMigrationService($this->connection, 1440);

        self::assertFalse($service->sessionExists('invalid/session'));
        self::assertFalse($service->sessionExists('invalid<session>'));
        self::assertFalse($service->sessionExists('session with spaces'));
    }

    public function testSessionExistsReturnsFalseForTooLongId(): void
    {
        $this->connection->expects(self::never())
            ->method('exists');

        $service = new SessionMigrationService($this->connection, 1440);

        // 257 characters - too long
        $tooLongId = str_repeat('a', 257);
        self::assertFalse($service->sessionExists($tooLongId));
    }

    public function testSessionExistsAcceptsValidIdAtMaxLength(): void
    {
        // 256 characters - at the limit
        $maxLengthId = str_repeat('a', 256);

        $this->connection->expects(self::once())
            ->method('exists')
            ->with($maxLengthId)
            ->willReturn(true);

        $service = new SessionMigrationService($this->connection, 1440);

        self::assertTrue($service->sessionExists($maxLengthId));
    }

    public function testSessionExistsTrimsWhitespace(): void
    {
        $this->connection->expects(self::once())
            ->method('exists')
            ->with('valid_session_id')
            ->willReturn(true);

        $service = new SessionMigrationService($this->connection, 1440);

        self::assertTrue($service->sessionExists('  valid_session_id  '));
    }
}
