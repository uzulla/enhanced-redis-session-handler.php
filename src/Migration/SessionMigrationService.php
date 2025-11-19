<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Migration;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Exception\MigrationException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

/**
 * Service for migrating session data to a new session ID.
 *
 * This service allows you to:
 * - Copy session data from one session ID to another
 * - Update the browser's session cookie to use the new ID
 * - Delete the old session (effectively logging out other browsers)
 *
 * Usage:
 * ```php
 * $migrator = new SessionMigrationService($redisConnection, $ttl);
 * $migrator->migrate($newSessionId, $deleteOldSession);
 * ```
 */
class SessionMigrationService
{
    private RedisConnection $connection;
    private int $ttl;
    private LoggerInterface $logger;

    /**
     * @param RedisConnection $connection Redis connection for session storage
     * @param int $ttl Time to live for session data in seconds
     * @param LoggerInterface|null $logger Optional logger for debugging
     */
    public function __construct(
        RedisConnection $connection,
        int $ttl,
        ?LoggerInterface $logger = null
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }

        $this->connection = $connection;
        $this->ttl = $ttl;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Migrate the current session to a new session ID.
     *
     * This method:
     * 1. Reads the current session data from Redis
     * 2. Writes the data to the new session ID
     * 3. Updates the PHP session ID and cookie
     * 4. Optionally deletes the old session
     *
     * IMPORTANT: This must be called when a session is active (after session_start()).
     * After calling this method, the browser will have a new session cookie,
     * and other browsers using the old session ID will be logged out.
     *
     * @param string $newSessionId The target session ID to migrate to
     * @param bool $deleteOldSession Whether to delete the old session data (default: true)
     * @throws MigrationException If migration fails
     * @throws InvalidArgumentException If session ID is invalid
     */
    public function migrate(string $newSessionId, bool $deleteOldSession = true): void
    {
        $this->validateSessionId($newSessionId);

        // Check if target session ID already exists to prevent overwriting another user's session
        if ($this->connection->exists($newSessionId)) {
            throw new MigrationException('Target session ID already exists');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new MigrationException('Session must be active before migration. Call session_start() first.');
        }

        $oldSessionId = session_id();
        if ($oldSessionId === false || $oldSessionId === '') {
            throw new MigrationException('Could not get current session ID');
        }

        if ($oldSessionId === $newSessionId) {
            $this->logger->debug('Migration skipped: new session ID is same as current', [
                'session_id' => SessionIdMasker::mask($newSessionId),
            ]);
            return;
        }

        $this->logger->info('Starting session migration', [
            'old_session_id' => SessionIdMasker::mask($oldSessionId),
            'new_session_id' => SessionIdMasker::mask($newSessionId),
        ]);

        // Get current session data from $_SESSION
        $sessionData = $_SESSION;

        // Write the session data to the new session ID in Redis
        $serializedData = serialize($sessionData);
        $writeSuccess = $this->connection->set($newSessionId, $serializedData, $this->ttl);

        if (!$writeSuccess) {
            throw new MigrationException('Failed to write session data to new session ID');
        }

        $this->logger->debug('Session data written to new session ID', [
            'new_session_id' => SessionIdMasker::mask($newSessionId),
        ]);

        // Close the current session without saving (we'll use the new ID)
        session_write_close();

        // Set the new session ID
        session_id($newSessionId);

        // Restart the session with the new ID
        if (!session_start()) {
            throw new MigrationException('Failed to restart session with new ID');
        }

        // Verify session data was preserved
        if ($_SESSION !== $sessionData) {
            // If data mismatch, restore from our backup
            $_SESSION = $sessionData;
            $this->logger->warning('Session data mismatch after migration, restored from backup', [
                'new_session_id' => SessionIdMasker::mask($newSessionId),
            ]);
        }

        // Delete the old session if requested
        if ($deleteOldSession) {
            $this->deleteOldSession($oldSessionId);
        }

        $this->logger->info('Session migration completed successfully', [
            'old_session_id' => SessionIdMasker::mask($oldSessionId),
            'new_session_id' => SessionIdMasker::mask($newSessionId),
            'old_session_deleted' => $deleteOldSession,
        ]);
    }

    /**
     * Copy session data from one session ID to another without changing the current session.
     *
     * This is useful for scenarios where you want to prepare a new session
     * without immediately switching to it.
     *
     * @param string $sourceSessionId The source session ID to copy from
     * @param string $targetSessionId The target session ID to copy to
     * @param bool $deleteSource Whether to delete the source session after copying (default: false)
     * @throws MigrationException If copy fails
     * @throws InvalidArgumentException If session ID is invalid
     */
    public function copy(string $sourceSessionId, string $targetSessionId, bool $deleteSource = false): void
    {
        $this->validateSessionId($sourceSessionId);
        $this->validateSessionId($targetSessionId);

        if ($sourceSessionId === $targetSessionId) {
            throw new InvalidArgumentException('Source and target session IDs must be different');
        }

        $this->logger->info('Starting session copy', [
            'source_session_id' => SessionIdMasker::mask($sourceSessionId),
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
        ]);

        // Read session data from source
        $sessionData = $this->connection->get($sourceSessionId);

        if ($sessionData === false) {
            throw new MigrationException('Source session not found or could not be read');
        }

        // Write to target
        $writeSuccess = $this->connection->set($targetSessionId, $sessionData, $this->ttl);

        if (!$writeSuccess) {
            throw new MigrationException('Failed to write session data to target session ID');
        }

        $this->logger->debug('Session data copied successfully', [
            'source_session_id' => SessionIdMasker::mask($sourceSessionId),
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
        ]);

        // Delete source if requested
        if ($deleteSource) {
            $this->deleteOldSession($sourceSessionId);
        }

        $this->logger->info('Session copy completed', [
            'source_session_id' => SessionIdMasker::mask($sourceSessionId),
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
            'source_deleted' => $deleteSource,
        ]);
    }

    /**
     * Check if a session ID exists in Redis.
     *
     * @param string $sessionId The session ID to check
     * @return bool True if session exists, false if not exists or invalid ID
     */
    public function sessionExists(string $sessionId): bool
    {
        // Lightweight validation to avoid unnecessary Redis queries
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            return false;
        }

        // Check for valid characters (alphanumeric, underscore, hyphen)
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $sessionId) !== 1) {
            return false;
        }

        // Sensible max length check (256 characters)
        if (strlen($sessionId) > 256) {
            return false;
        }

        return $this->connection->exists($sessionId);
    }

    /**
     * Delete the old session from Redis.
     *
     * @param string $sessionId The session ID to delete
     */
    private function deleteOldSession(string $sessionId): void
    {
        $deleted = $this->connection->delete($sessionId);

        if ($deleted) {
            $this->logger->debug('Old session deleted', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
        } else {
            $this->logger->warning('Failed to delete old session', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
        }
    }

    /**
     * Validate that a session ID is in the expected format.
     *
     * @param string $sessionId The session ID to validate
     * @throws InvalidArgumentException If session ID is invalid
     */
    private function validateSessionId(string $sessionId): void
    {
        if ($sessionId === '') {
            throw new InvalidArgumentException('Session ID cannot be empty');
        }

        // Session IDs should only contain alphanumeric characters, hyphens, and underscores
        // This matches PHP's default session ID format
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $sessionId) !== 1) {
            throw new InvalidArgumentException(
                'Session ID contains invalid characters. Only alphanumeric, hyphen, and underscore allowed.'
            );
        }

        // Warn if session ID is too short (security concern)
        if (strlen($sessionId) < 16) {
            $this->logger->warning('Session ID is shorter than recommended minimum of 16 characters', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'length' => strlen($sessionId),
            ]);
        }
    }
}
