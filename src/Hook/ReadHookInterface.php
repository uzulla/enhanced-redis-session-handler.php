<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook;

use Throwable;

/**
 * Interface for hooks that intercept session read operations.
 *
 * Implementations can perform operations before reading, modify session data
 * after reading, or handle read errors.
 *
 * @see Storage\HookStorageInterface For safe Redis operations within hooks
 */
interface ReadHookInterface
{
    /**
     * Called before reading session data from Redis.
     *
     * @param string $sessionId The session ID
     */
    public function beforeRead(string $sessionId): void;

    /**
     * Called after reading session data from Redis.
     *
     * Implementations can modify the session data after it is read.
     * If HookStorage is provided, it should be used for any Redis operations
     * to prevent infinite recursion through depth tracking.
     *
     * @param string $sessionId The session ID
     * @param string $data The session data read from Redis
     * @param Storage\HookStorageInterface|null $storage Optional HookStorage for safe Redis operations
     * @return string The modified session data
     */
    public function afterRead(string $sessionId, string $data, ?Storage\HookStorageInterface $storage = null): string;

    /**
     * Called when an error occurs during read operation.
     *
     * @param string $sessionId The session ID
     * @param Throwable $e The exception that occurred
     * @return string|null Return string data to use as fallback, or null to propagate the error
     */
    public function onReadError(string $sessionId, Throwable $e): ?string;
}
