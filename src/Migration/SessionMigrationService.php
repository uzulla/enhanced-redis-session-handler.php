<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Migration;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Uzulla\EnhancedRedisSessionHandler\Exception\InvalidSessionIdException;
use Uzulla\EnhancedRedisSessionHandler\Exception\MigrationException;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdValidator;

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
    private SessionSerializerInterface $serializer;

    /**
     * @param RedisConnection $connection Redis connection for session storage
     * @param int $ttl Time to live for session data in seconds
     * @param SessionSerializerInterface|null $serializer Optional serializer for session data (defaults to PhpSerializeSerializer)
     * @param LoggerInterface|null $logger Optional logger for debugging
     */
    public function __construct(
        RedisConnection $connection,
        int $ttl,
        ?SessionSerializerInterface $serializer = null,
        ?LoggerInterface $logger = null
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }

        $this->connection = $connection;
        $this->ttl = $ttl;
        $this->serializer = $serializer ?? new PhpSerializeSerializer();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Migrate the current session to a new session ID.
     *
     * This method:
     * 1. Reads the current session data from $_SESSION
     * 2. Writes the data to the new session ID in Redis
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
     * @throws InvalidSessionIdException If session ID is invalid
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
        /** @var array<string, mixed> $sessionData */
        $sessionData = $_SESSION;

        // Write the session data to the new session ID in Redis
        $serializedData = $this->serializer->encode($sessionData);
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
        // Note: This identity comparison (!==) works correctly for scalar values and simple arrays.
        // However, comparing arrays/objects for equality is inherently difficult:
        // - Objects may not compare equal even if they have the same data after serialize/unserialize
        // - Resource types cannot be serialized
        // - Closure/anonymous functions cannot be compared
        // This check serves as a basic sanity check for typical session data (scalars and arrays).
        // For sessions containing complex objects, additional verification may be needed.
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
     * @throws InvalidSessionIdException If session ID is invalid
     */
    public function copy(string $sourceSessionId, string $targetSessionId, bool $deleteSource = false): void
    {
        $this->validateSessionId($sourceSessionId);
        $this->validateSessionId($targetSessionId);

        if ($sourceSessionId === $targetSessionId) {
            throw new InvalidArgumentException('Source and target session IDs must be different');
        }

        // Check if target session ID already exists to prevent overwriting another user's session
        if ($this->connection->exists($targetSessionId)) {
            throw new MigrationException('Target session ID already exists');
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
        // Sanitize and validate using shared validator
        $sessionId = SessionIdValidator::sanitize($sessionId);

        if (!SessionIdValidator::isValid($sessionId)) {
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
     * @throws InvalidSessionIdException If session ID is invalid
     */
    private function validateSessionId(string $sessionId): void
    {
        // Use shared validator for consistent validation
        SessionIdValidator::validate($sessionId);

        // Warn if session ID is too short (security concern)
        if (SessionIdValidator::isShorterThanRecommended($sessionId)) {
            $this->logger->warning('Session ID is shorter than recommended minimum of 16 characters', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'length' => strlen($sessionId),
            ]);
        }
    }
}
