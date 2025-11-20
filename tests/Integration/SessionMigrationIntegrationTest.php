<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Redis;
use Uzulla\EnhancedRedisSessionHandler\Config\RedisConnectionConfig;
use Uzulla\EnhancedRedisSessionHandler\Migration\SessionMigrationService;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;

class SessionMigrationIntegrationTest extends TestCase
{
    private RedisConnection $connection;
    private Redis $redis;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for integration tests');
        }

        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisPortEnv = getenv('SESSION_REDIS_PORT');

        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPort = $redisPortEnv !== false ? $redisPortEnv : '6379';

        $logger = new NullLogger();

        $config = new RedisConnectionConfig(
            $redisHost,
            (int)$redisPort,
            2.5,
            null,
            0,
            'test:session:'
        );

        $this->redis = new Redis();
        $this->connection = new RedisConnection($this->redis, $config, $logger);

        // Clean up any existing test sessions
        $this->cleanupTestSessions();
    }

    protected function tearDown(): void
    {
        // Close any active session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Clean up test sessions
        $this->cleanupTestSessions();
    }

    private function cleanupTestSessions(): void
    {
        $keys = $this->connection->scan('test-session-*');
        foreach ($keys as $key) {
            $this->connection->delete($key);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testMigrateSuccessfully(): void
    {
        // Recreate connection in separate process
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for integration tests');
        }

        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisPortEnv = getenv('SESSION_REDIS_PORT');

        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPort = $redisPortEnv !== false ? $redisPortEnv : '6379';

        $logger = new NullLogger();

        $config = new RedisConnectionConfig(
            $redisHost,
            (int)$redisPort,
            2.5,
            null,
            0,
            'test:session:'
        );

        $redis = new Redis();
        $connection = new RedisConnection($redis, $config, $logger);

        // Start a session with old ID
        $oldSessionId = 'test-session-old-' . bin2hex(random_bytes(8));
        $newSessionId = 'test-session-new-' . bin2hex(random_bytes(8));

        // Ensure sessions are closed before starting
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Set and start the old session
        session_id($oldSessionId);
        self::assertTrue(session_start(), 'Failed to start session');

        // Populate session data
        $_SESSION['user_id'] = 123;
        $_SESSION['username'] = 'testuser';
        $_SESSION['roles'] = ['admin', 'editor'];

        // Capture the expected data
        $expectedData = $_SESSION;

        // Create migration service
        $service = new SessionMigrationService($connection, 1440);

        // Perform migration
        $service->migrate($newSessionId, true);

        // Verify new session ID is active
        self::assertSame($newSessionId, session_id(), 'Session ID should be updated');

        // Verify session data is preserved
        self::assertSame($expectedData['user_id'], $_SESSION['user_id']);
        self::assertSame($expectedData['username'], $_SESSION['username']);
        self::assertSame($expectedData['roles'], $_SESSION['roles']);

        // Close session
        session_write_close();

        // Verify old session was deleted
        self::assertFalse($connection->exists($oldSessionId), 'Old session should be deleted');

        // Verify new session exists
        self::assertTrue($connection->exists($newSessionId), 'New session should exist');

        // Clean up
        $connection->delete($newSessionId);
    }

    /**
     * @runInSeparateProcess
     */
    public function testMigrateWithoutDeletingOldSession(): void
    {
        // Recreate connection in separate process
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is required for integration tests');
        }

        $redisHostEnv = getenv('SESSION_REDIS_HOST');
        $redisPortEnv = getenv('SESSION_REDIS_PORT');

        $redisHost = $redisHostEnv !== false ? $redisHostEnv : 'localhost';
        $redisPort = $redisPortEnv !== false ? $redisPortEnv : '6379';

        $logger = new NullLogger();

        $config = new RedisConnectionConfig(
            $redisHost,
            (int)$redisPort,
            2.5,
            null,
            0,
            'test:session:'
        );

        $redis = new Redis();
        $connection = new RedisConnection($redis, $config, $logger);

        // Start a session with old ID
        $oldSessionId = 'test-session-old-' . bin2hex(random_bytes(8));
        $newSessionId = 'test-session-new-' . bin2hex(random_bytes(8));

        // Ensure sessions are closed before starting
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Set and start the old session
        session_id($oldSessionId);
        self::assertTrue(session_start(), 'Failed to start session');

        // Populate session data
        $_SESSION['data'] = 'test_value';

        // Create migration service
        $service = new SessionMigrationService($connection, 1440);

        // Perform migration without deleting old session
        $service->migrate($newSessionId, false);

        // Verify new session ID is active
        self::assertSame($newSessionId, session_id(), 'Session ID should be updated');

        // Verify session data is preserved
        self::assertSame('test_value', $_SESSION['data']);

        // Close session
        session_write_close();

        // Verify old session still exists
        self::assertTrue($connection->exists($oldSessionId), 'Old session should still exist');

        // Verify new session exists
        self::assertTrue($connection->exists($newSessionId), 'New session should exist');

        // Clean up
        $connection->delete($oldSessionId);
        $connection->delete($newSessionId);
    }

    public function testCopySessionData(): void
    {
        $sourceSessionId = 'test-session-source-' . bin2hex(random_bytes(8));
        $targetSessionId = 'test-session-target-' . bin2hex(random_bytes(8));

        // Create source session data
        $sourceData = serialize(['user_id' => 456, 'email' => 'test@example.com']);
        $this->connection->set($sourceSessionId, $sourceData, 1440);

        // Create migration service
        $service = new SessionMigrationService($this->connection, 1440);

        // Copy session data
        $service->copy($sourceSessionId, $targetSessionId, false);

        // Verify both sessions exist
        self::assertTrue($this->connection->exists($sourceSessionId), 'Source session should exist');
        self::assertTrue($this->connection->exists($targetSessionId), 'Target session should exist');

        // Verify data was copied correctly
        $targetData = $this->connection->get($targetSessionId);
        self::assertSame($sourceData, $targetData, 'Target session should have same data as source');
    }

    public function testCopySessionDataWithDeleteSource(): void
    {
        $sourceSessionId = 'test-session-source-' . bin2hex(random_bytes(8));
        $targetSessionId = 'test-session-target-' . bin2hex(random_bytes(8));

        // Create source session data
        $sourceData = serialize(['test' => 'data']);
        $this->connection->set($sourceSessionId, $sourceData, 1440);

        // Create migration service
        $service = new SessionMigrationService($this->connection, 1440);

        // Copy session data and delete source
        $service->copy($sourceSessionId, $targetSessionId, true);

        // Verify source was deleted
        self::assertFalse($this->connection->exists($sourceSessionId), 'Source session should be deleted');

        // Verify target exists
        self::assertTrue($this->connection->exists($targetSessionId), 'Target session should exist');
    }
}
