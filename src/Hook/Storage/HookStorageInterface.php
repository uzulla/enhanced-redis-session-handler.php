<?php

declare(strict_types=1);

namespace Uzulla\EnhancedRedisSessionHandler\Hook\Storage;

/**
 * Interface for storage operations within hooks.
 *
 * This interface abstracts storage operations (get, set, delete) to prevent
 * infinite recursion when hooks perform Redis operations. By using this
 * interface with depth tracking, we can safely execute storage operations
 * within hook contexts while maintaining visibility into the execution chain.
 *
 * Implementations should:
 * - Track execution depth to prevent infinite recursion
 * - Log warnings when depth limits are approached or exceeded
 * - Provide graceful degradation when depth limits are exceeded
 * - Maintain compatibility with PSR-12 and PHPStan strict rules
 *
 * @see HookRedisStorage for the Redis-backed implementation
 * @see HookContext for execution depth tracking
 */
interface HookStorageInterface
{
    /**
     * Get a value from storage by key.
     *
     * @param string $key The storage key
     * @return string|false Returns the value as string if found, false if not found or on error
     */
    public function get(string $key);

    /**
     * Set a value in storage with a time-to-live.
     *
     * @param string $key The storage key
     * @param string $value The value to store
     * @param int $ttl Time-to-live in seconds (must be positive)
     * @return bool True if the operation succeeded, false otherwise
     */
    public function set(string $key, string $value, int $ttl): bool;

    /**
     * Delete a value from storage by key.
     *
     * @param string $key The storage key
     * @return bool True if the key was deleted, false if the key didn't exist or on error
     */
    public function delete(string $key): bool;
}
