<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Uzulla\EnhancedRedisSessionHandler\RedisConnection;
use Uzulla\EnhancedRedisSessionHandler\Serializer\PhpSerializeSerializer;
use Uzulla\EnhancedRedisSessionHandler\Serializer\SessionSerializerInterface;
use Uzulla\EnhancedRedisSessionHandler\Support\SessionIdMasker;

/**
 * Hook implementation that migrates session data to a new session ID during write.
 *
 * This hook allows you to programmatically migrate a session by:
 * 1. Setting the target session ID via setMigrationTarget()
 * 2. On the next write operation, the data will be written to the new session ID
 * 3. The old session can optionally be deleted
 *
 * Note: This hook only handles the Redis-side migration. To complete the migration,
 * you need to update the browser's session cookie separately (e.g., by calling
 * session_id($newId) before session_start() on the next request).
 *
 * For a complete migration solution that also handles the session cookie,
 * use SessionMigrationService instead.
 *
 * Usage:
 * ```php
 * $hook = new SessionMigrationHook($connection, $ttl);
 * $hook->setMigrationTarget($newSessionId, true);
 * // On next session write, data will be copied to new session ID
 * ```
 */
class SessionMigrationHook implements WriteHookInterface
{
    private RedisConnection $connection;
    private SessionSerializerInterface $serializer;
    private LoggerInterface $logger;
    private int $ttl;
    private bool $failOnMigrationError;

    private ?string $targetSessionId = null;
    private bool $deleteOldSession = true;

    /** @var array<string, array<string, mixed>> */
    private array $pendingWrites = [];

    /**
     * @param RedisConnection $connection Redis connection for session storage
     * @param int $ttl Time to live for session data in seconds
     * @param bool $failOnMigrationError If true, throw exception when migration fails
     * @param LoggerInterface|null $logger Optional logger for debugging
     * @param SessionSerializerInterface|null $serializer Optional serializer (defaults to PhpSerializeSerializer)
     */
    public function __construct(
        RedisConnection $connection,
        int $ttl = 1440,
        bool $failOnMigrationError = false,
        ?LoggerInterface $logger = null,
        ?SessionSerializerInterface $serializer = null
    ) {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }
        $this->connection = $connection;
        $this->ttl = $ttl;
        $this->failOnMigrationError = $failOnMigrationError;
        $this->logger = $logger ?? new NullLogger();
        $this->serializer = $serializer ?? new PhpSerializeSerializer();
    }

    /**
     * Set the target session ID for migration.
     *
     * After calling this method, the next write operation will:
     * 1. Write the session data to the target session ID
     * 2. Optionally delete the old session
     *
     * @param string $targetSessionId The new session ID to migrate to
     * @param bool $deleteOldSession Whether to delete the old session after migration (default: true)
     * @throws InvalidArgumentException If session ID is invalid
     */
    public function setMigrationTarget(string $targetSessionId, bool $deleteOldSession = true): void
    {
        if ($targetSessionId === '') {
            throw new InvalidArgumentException('Target session ID cannot be empty');
        }

        // Validate session ID format
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $targetSessionId) !== 1) {
            throw new InvalidArgumentException('Session ID contains invalid characters');
        }

        $this->targetSessionId = $targetSessionId;
        $this->deleteOldSession = $deleteOldSession;

        $this->logger->debug('Migration target set', [
            'target_session_id' => SessionIdMasker::mask($targetSessionId),
            'delete_old_session' => $deleteOldSession,
        ]);
    }

    /**
     * Clear the migration target (cancel pending migration).
     */
    public function clearMigrationTarget(): void
    {
        $this->targetSessionId = null;
        $this->deleteOldSession = true;

        $this->logger->debug('Migration target cleared');
    }

    /**
     * Check if a migration is pending.
     *
     * @return bool True if migration target is set
     */
    public function hasPendingMigration(): bool
    {
        return $this->targetSessionId !== null;
    }

    /**
     * Get the current migration target session ID.
     *
     * @return string|null The target session ID, or null if no migration is pending
     */
    public function getMigrationTarget(): ?string
    {
        return $this->targetSessionId;
    }

    public function beforeWrite(string $sessionId, array $data): array
    {
        // Store the data for afterWrite to use
        $this->pendingWrites[$sessionId] = $data;
        return $data;
    }

    public function afterWrite(string $sessionId, bool $success): void
    {
        if (!$success) {
            $this->logger->warning('Primary write failed, skipping migration', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            unset($this->pendingWrites[$sessionId]);
            return;
        }

        // Check if migration is requested
        if ($this->targetSessionId === null) {
            unset($this->pendingWrites[$sessionId]);
            return;
        }

        // Skip if target is same as current
        if ($this->targetSessionId === $sessionId) {
            $this->logger->debug('Migration skipped: target session ID is same as current', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            $this->targetSessionId = null;
            unset($this->pendingWrites[$sessionId]);
            return;
        }

        if (!isset($this->pendingWrites[$sessionId])) {
            $this->logger->warning('No pending write data found for session migration', [
                'session_id' => SessionIdMasker::mask($sessionId),
            ]);
            return;
        }

        try {
            $data = $this->pendingWrites[$sessionId];
            $serializedData = $this->serializer->encode($data);
            $targetId = $this->targetSessionId;

            $this->logger->info('Starting session migration via hook', [
                'old_session_id' => SessionIdMasker::mask($sessionId),
                'new_session_id' => SessionIdMasker::mask($targetId),
            ]);

            // Write to new session ID
            $migrationSuccess = $this->connection->set($targetId, $serializedData, $this->ttl);

            if (!$migrationSuccess) {
                $message = 'Failed to write session data to migration target';
                $this->logger->error($message, [
                    'old_session_id' => SessionIdMasker::mask($sessionId),
                    'new_session_id' => SessionIdMasker::mask($targetId),
                ]);

                if ($this->failOnMigrationError) {
                    throw new RuntimeException($message);
                }
                return;
            }

            $this->logger->debug('Session data written to migration target', [
                'new_session_id' => SessionIdMasker::mask($targetId),
            ]);

            // Delete old session if requested
            if ($this->deleteOldSession) {
                $deleted = $this->connection->delete($sessionId);
                if ($deleted) {
                    $this->logger->debug('Old session deleted after migration', [
                        'old_session_id' => SessionIdMasker::mask($sessionId),
                    ]);
                } else {
                    $this->logger->warning('Failed to delete old session after migration', [
                        'old_session_id' => SessionIdMasker::mask($sessionId),
                    ]);
                }
            }

            $this->logger->info('Session migration via hook completed successfully', [
                'old_session_id' => SessionIdMasker::mask($sessionId),
                'new_session_id' => SessionIdMasker::mask($targetId),
                'old_session_deleted' => $this->deleteOldSession,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Exception during session migration', [
                'session_id' => SessionIdMasker::mask($sessionId),
                'exception' => $e,
            ]);

            if ($this->failOnMigrationError) {
                throw $e;
            }
        } finally {
            // Clear migration target after attempt (one-shot)
            $this->targetSessionId = null;
            unset($this->pendingWrites[$sessionId]);
        }
    }

    public function onWriteError(string $sessionId, Throwable $exception): void
    {
        $this->logger->error('Primary write error, migration skipped', [
            'session_id' => SessionIdMasker::mask($sessionId),
            'exception' => $exception,
        ]);

        // Clear pending state
        $this->targetSessionId = null;
        unset($this->pendingWrites[$sessionId]);
    }
}
