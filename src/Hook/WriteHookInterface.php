<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Throwable;

/**
 * Interface for hooks that intercept session write operations.
 *
 * Implementations can modify session data before writing, perform additional
 * operations after writing, or handle write errors.
 *
 * @see Storage\HookStorageInterface For safe Redis operations within hooks
 */
interface WriteHookInterface
{
    /**
     * Called before writing session data to Redis.
     *
     * Implementations can modify the session data before it is written.
     * If HookStorage is provided, it should be used for any Redis operations
     * to prevent infinite recursion through depth tracking.
     *
     * @param string $sessionId The session ID
     * @param array<string, mixed> $data The unserialized session data
     * @param Storage\HookStorageInterface|null $storage Optional HookStorage for safe Redis operations
     * @return array<string, mixed> The modified session data
     */
    public function beforeWrite(string $sessionId, array $data, ?Storage\HookStorageInterface $storage = null): array;

    /**
     * Called after writing session data to Redis.
     *
     * @param string $sessionId The session ID
     * @param bool $success Whether the write operation was successful
     */
    public function afterWrite(string $sessionId, bool $success): void;

    /**
     * Called when an error occurs during the write operation.
     *
     * @param string $sessionId The session ID
     * @param Throwable $exception The exception that occurred
     */
    public function onWriteError(string $sessionId, Throwable $exception): void;
}
